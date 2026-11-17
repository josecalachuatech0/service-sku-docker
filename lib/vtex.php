<?php
declare(strict_types=1);

/*
 * Cliente HTTP de VTEX compartido por los scripts de bin/.
 *
 * Contexto para otra IA:
 * - vtexCall es el unico lugar donde se hace curl contra VTEX. Las demas funciones son
 *   envoltorios que conservan el contrato que tenia cada script antes de la libreria
 *   (lanzar excepcion, devolver null, devolver [status, json] o el arreglo de requestJson).
 * - Politica de reintentos, igual para todos los scripts:
 *   - Hasta VTEX_MAX_ATTEMPTS intentos. Espera lo que diga Retry-After o, si no viene,
 *     2 s por intento, con tope de VTEX_MAX_BACKOFF_SECONDS.
 *   - 429 se reintenta siempre: VTEX rechaza la peticion antes de procesarla.
 *   - HTTP 0, 5xx y transferencias cortadas se reintentan en GET, PUT y DELETE, que son
 *     idempotentes. En POST NO: un POST que se corta pudo haber creado la relacion, y
 *     repetirlo la duplicaria. Ese caso queda como error para revisarlo a mano.
 *   - 404 y los demas 4xx no se reintentan.
 * - Log (lib/log.php): cada reintento y cada fallo final de vtexFalloParaLog; nunca las credenciales.
 * - vtexBaseUrl lee VTEX_BASE_URL y por defecto usa la base privada cemacogt.myvtex.com.
 *   Antes la mayoria de los scripts la tenia escrita a mano y query-skus-* caia al
 *   storefront publico, que corta el arbol de categorias.
 */

require_once __DIR__ . '/log.php';   // vtexCall anota reintentos y fallos

const VTEX_DEFAULT_BASE_URL = 'https://cemacogt.myvtex.com';
const VTEX_MAX_ATTEMPTS = 4;
const VTEX_MAX_BACKOFF_SECONDS = 30;

function vtexBaseUrl(): string
{
    return rtrim(envString('VTEX_BASE_URL', VTEX_DEFAULT_BASE_URL), '/');
}

function vtexHeaders(string $appKey, string $appToken): array
{
    return [
        'Accept: application/json',
        'Content-Type: application/json',
        "X-VTEX-API-AppKey: $appKey",
        "X-VTEX-API-AppToken: $appToken",
    ];
}

function buildProductsAndSkusUrl(string $baseUrl, int|string $categoryId, int $from, int $to): string
{
    $query = http_build_query([
        'categoryId' => $categoryId,
        '_from' => $from,
        '_to' => $to,
    ]);

    return rtrim($baseUrl, '/') . "/api/catalog_system/pvt/products/GetProductAndSkuIds?$query";
}

function vtexIsRetryable(string $method, int $statusCode, bool $transferFailed): bool
{
    if ($statusCode === 429) {
        return true;
    }
    if ($method === 'POST') {
        return false;
    }
    return $transferFailed || $statusCode === 0 || $statusCode >= 500;
}

// Fallos de VTEX que van al log: sin respuesta, 429 o 5xx en cualquier método, y cualquier rechazo de una
// escritura. Un 404 en GET no se anota: es la respuesta normal a «no existe» (SKUs, values, relaciones).
function vtexFalloParaLog(string $method, int $statusCode): bool
{
    return $statusCode === 0 || $statusCode === 429 || $statusCode >= 500 || ($method !== 'GET' && $statusCode >= 400);
}

// Ruta de la petición sin el host: el log no necesita saber a qué cuenta se llamó.
function vtexRutaLog(string $url): string
{
    $p = parse_url($url);
    return ($p['path'] ?? '') . (isset($p['query']) ? '?' . $p['query'] : '');
}

/**
 * @return array{ok: bool, status: int, json: ?array, raw: string, error: string, attempts: int}
 */
function vtexCall(string $method, string $url, string $appKey, string $appToken, ?array $body = null, int $timeout = 90): array
{
    $method = strtoupper($method);

    for ($attempt = 1; ; $attempt++) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo inicializar cURL.');
        }

        $retryAfter = null;
        $options = [
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => vtexHeaders($appKey, $appToken),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (preg_match('/^Retry-After:\s*(\d+)/i', $line, $m)) {
                    $retryAfter = (int) $m[1];
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        $ok = $response !== false && $statusCode >= 200 && $statusCode < 300;

        if (!$ok && $attempt < VTEX_MAX_ATTEMPTS && vtexIsRetryable($method, $statusCode, $response === false)) {
            $espera = min(VTEX_MAX_BACKOFF_SECONDS, $retryAfter ?? $attempt * 2);
            appLog('aviso', 'vtex.reintento', ['metodo' => $method, 'ruta' => vtexRutaLog($url), 'status' => $statusCode,
                'intento' => $attempt, 'espera_s' => $espera, 'error' => $error]);
            sleep($espera);
            continue;
        }
        if (!$ok && vtexFalloParaLog($method, $statusCode)) {
            appLog('error', 'vtex.fallo', ['metodo' => $method, 'ruta' => vtexRutaLog($url), 'status' => $statusCode,
                'intentos' => $attempt, 'error' => $error]
                + ($method === 'POST' && $statusCode === 0 ? ['revisar_a_mano' => 'El POST se cortó y no se reintenta: pudo haber creado la relación.'] : []));
        }

        $raw = $response === false ? '' : (string) $response;
        $decoded = json_decode($raw, true);

        return [
            'ok' => $ok,
            'status' => $statusCode,
            'json' => is_array($decoded) ? $decoded : null,
            'raw' => $raw,
            'error' => $error,
            'attempts' => $attempt,
        ];
    }
}

/**
 * GET que lanza excepcion si VTEX no responde 2xx con JSON.
 */
function vtexGetJson(string $url, string $appKey, string $appToken, int $timeout = 90): array
{
    return vtexJsonOrThrow(vtexCall('GET', $url, $appKey, $appToken, null, $timeout), $url);
}

function vtexJsonOrThrow(array $r, string $url): array
{
    if ($r['ok']) {
        if ($r['json'] !== null) {
            return $r['json'];
        }
        throw new RuntimeException("JSON invalido para $url");
    }

    $message = $r['status'] === 0 ? "HTTP 0 / {$r['error']}" : "HTTP {$r['status']}";
    throw new RuntimeException("VTEX respondio $message para $url");
}

/**
 * Igual que vtexGetJson, pero un 404 devuelve null (el recurso no existe).
 */
function vtexGetJsonOrNull(string $url, string $appKey, string $appToken, int $timeout = 90): ?array
{
    $r = vtexCall('GET', $url, $appKey, $appToken, null, $timeout);
    if ($r['status'] === 404) {
        return null;
    }
    return vtexJsonOrThrow($r, $url);
}

/**
 * GET que no corta la corrida: avisa por STDERR y devuelve null si falla.
 */
function vtexGetJsonOrWarn(string $url, string $appKey, string $appToken, int $timeout = 90): ?array
{
    $r = vtexCall('GET', $url, $appKey, $appToken, null, $timeout);
    if ($r['ok'] && $r['json'] !== null) {
        return $r['json'];
    }
    fwrite(STDERR, "  [HTTP {$r['status']}] $url\n");
    return null;
}

/**
 * Peticion de escritura o lectura que devuelve [status, json|null] (fix-skuservice-*).
 */
function vtexRequest(string $method, string $url, string $appKey, string $appToken, ?array $body = null, int $timeout = 90): array
{
    $r = vtexCall($method, $url, $appKey, $appToken, $body, $timeout);
    return [$r['status'], $r['ok'] ? $r['json'] : null];
}

/**
 * Peticion que devuelve ok/status/json/body recortado (apply-instalacion-bracket).
 */
function requestJson(string $method, string $url, string $appKey, string $appToken, ?array $body = null): array
{
    $r = vtexCall($method, $url, $appKey, $appToken, $body);
    return [
        'ok' => $r['ok'],
        'status' => $r['status'],
        'json' => $r['json'],
        'body' => substr($r['raw'], 0, 500),
    ];
}
