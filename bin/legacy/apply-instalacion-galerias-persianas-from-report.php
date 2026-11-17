<?php
declare(strict_types=1);

/*
 * Aplica por API la configuracion de instalacion de galerias o persianas.
 *
 * Configuracion objetivo:
 * - SkuServiceTypeId: 13
 * - SkuServiceValueId: 13
 * - sku de servicio / PriceName: 1009726
 * - Value/Cost: 125
 *
 * Contexto para otra IA:
 * - Lee SKUs desde la primera columna de reporte_prev_config_galerias_y_persianas_instalacion.csv.
 * - Es idempotente: si ya existe la relacion, asegura Name/Text/IsActive; si no existe, la crea.
 * - Escribe auditoria incremental en reporte_instalacion_galerias_persianas_aplicacion.csv.
 */

// Archivado en bin/legacy/ el 2026-10-23. Escribe en VTEX al ejecutarse (sin dry-run) y borra
// relaciones. No corre sin --ejecutar-legacy. Para configurar, usar bin/apply-instalacion-bracket.php.
if (!in_array('--ejecutar-legacy', $argv, true)) {
    fwrite(STDERR, "Script archivado: escribe en VTEX sin dry-run. Usa bin/apply-instalacion-bracket.php.\n"
        . "Si de verdad necesitas correrlo, agrega --ejecutar-legacy.\n");
    exit(1);
}

ini_set('memory_limit', '768M');

require_once __DIR__ . '/../../config/env.php';

loadEnv(__DIR__ . '/../../.env');

$appKey = trim((string) env('API_KEY', ''));
$appToken = trim((string) env('API_TOKEN', ''));
$baseUrl = 'https://cemacogt.myvtex.com';
$input = __DIR__ . '/../../reporte_prev_config_galerias_y_persianas_instalacion.csv';
$configName = 'instalacion de galerias o persianas';
$serviceTypeId = 13;
$serviceValueId = 13;
$serviceName = 'Servicio de Instalación';
$serviceText = 'Servicio de Instalación';
$outputJson = __DIR__ . '/../../reporte_instalacion_galerias_persianas_aplicacion.json';
$outputCsv = __DIR__ . '/../../reporte_instalacion_galerias_persianas_aplicacion.csv';

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

function requestJson(string $method, string $url, string $appKey, string $appToken, ?array $body = null, int $attempts = 4): array
{
    // Los lotes grandes pueden recibir HTTP 429; esta funcion reintenta antes de marcar error.
    $last = null;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                "X-VTEX-API-AppKey: $appKey",
                "X-VTEX-API-AppToken: $appToken",
            ],
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        $decoded = json_decode((string) $response, true);

        $last = [
            'ok' => $response !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'error' => $error ?: null,
            'json' => is_array($decoded) ? $decoded : null,
            'body' => substr((string) $response, 0, 1000),
            'url' => $url,
            'attempt' => $attempt,
        ];

        if ($last['ok']) {
            return $last;
        }

        if ($attempt < $attempts && ($status === 0 || $status === 429 || $status >= 500)) {
            sleep($attempt * 2);
            continue;
        }

        return $last;
    }

    return $last ?? [
        'ok' => false,
        'status' => 0,
        'error' => 'sin intento',
        'json' => null,
        'body' => '',
        'url' => $url,
        'attempt' => 0,
    ];
}

function readSkusFromFirstColumn(string $path): array
{
    $file = fopen($path, 'r');
    if ($file === false) {
        throw new RuntimeException("No se pudo leer $path");
    }

    $skus = [];
    $isFirstRow = true;
    while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
        $sku = trim((string) ($row[0] ?? ''));

        if ($isFirstRow) {
            $isFirstRow = false;
            if (!preg_match('/^\d+$/', $sku)) {
                continue;
            }
        }

        if (preg_match('/^\d+$/', $sku)) {
            $skus[] = $sku;
        }
    }
    fclose($file);

    return array_values(array_unique($skus));
}

function payloadForSku(string $sku, int $serviceTypeId, int $serviceValueId, string $name, string $text, ?int $id = null): array
{
    // Payload compatible con /api/catalog/pvt/skuservice para POST y PUT.
    $payload = [
        'SkuServiceTypeId' => $serviceTypeId,
        'SkuServiceValueId' => $serviceValueId,
        'SkuId' => (int) $sku,
        'Name' => $name,
        'Text' => $text,
        'IsActive' => true,
    ];

    if ($id !== null) {
        $payload['Id'] = $id;
    }

    return $payload;
}

function getAllSkuServices(string $baseUrl, string $appKey, string $appToken): array
{
    $response = requestJson('GET', "$baseUrl/api/catalog/pvt/skuservice", $appKey, $appToken);
    if (!$response['ok'] || !is_array($response['json'])) {
        throw new RuntimeException("No se pudo listar skuservice. HTTP {$response['status']} {$response['body']}");
    }

    return $response['json'];
}

function buildServiceMap(array $allServices, array $targetSkus): array
{
    $target = array_fill_keys($targetSkus, true);
    $map = [];

    foreach ($allServices as $service) {
        if (!is_array($service)) {
            continue;
        }

        $sku = (string) ($service['SkuId'] ?? '');
        if ($sku === '' || !isset($target[$sku])) {
            continue;
        }

        $map[$sku][] = $service;
    }

    return $map;
}

function isTargetService(array $service, int $serviceTypeId, int $serviceValueId): bool
{
    return (int) ($service['SkuServiceTypeId'] ?? 0) === $serviceTypeId
        && (int) ($service['SkuServiceValueId'] ?? 0) === $serviceValueId
        && ($service['IsActive'] ?? false) === true;
}

function isSameServiceType(array $service, int $serviceTypeId): bool
{
    return (int) ($service['SkuServiceTypeId'] ?? 0) === $serviceTypeId;
}

function compactService(?array $service): string
{
    if (!is_array($service)) {
        return '';
    }

    return json_encode([
        'Id' => $service['Id'] ?? null,
        'SkuServiceTypeId' => $service['SkuServiceTypeId'] ?? null,
        'SkuServiceValueId' => $service['SkuServiceValueId'] ?? null,
        'SkuId' => $service['SkuId'] ?? null,
        'Name' => $service['Name'] ?? null,
        'Text' => $service['Text'] ?? null,
        'IsActive' => $service['IsActive'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
}

function readCompletedOkSkus(string $path): array
{
    // Permite reanudar una corrida sin volver a escribir SKUs que ya quedaron OK.
    if (!is_file($path)) {
        return [];
    }

    $file = fopen($path, 'r');
    if ($file === false) {
        return [];
    }

    fgetcsv($file, 0, ',', '"', '');
    $completed = [];
    while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
        $sku = (string) ($row[1] ?? '');
        $status = (string) ($row[5] ?? '');
        if ($sku !== '' && $status === 'ok') {
            $completed[$sku] = true;
        }
    }
    fclose($file);

    return $completed;
}

function appendResult(string $path, array $row): void
{
    // Guarda cada resultado inmediatamente para no perder progreso si VTEX corta la corrida.
    $exists = is_file($path) && filesize($path) > 0;
    $file = fopen($path, 'a');
    if ($file === false) {
        throw new RuntimeException("No se pudo escribir $path");
    }

    if (!$exists) {
        fputcsv($file, array_keys($row), ',', '"', '');
    }

    fputcsv($file, $row, ',', '"', '');
    fclose($file);
}

try {
    $skus = readSkusFromFirstColumn($input);
    $completedOk = readCompletedOkSkus($outputCsv);

    $serviceValue = requestJson('GET', "$baseUrl/api/catalog/pvt/skuservicevalue/$serviceValueId", $appKey, $appToken);
    if (!$serviceValue['ok'] || !is_array($serviceValue['json'])) {
        throw new RuntimeException("No se pudo validar SkuServiceValueId $serviceValueId");
    }

    if ((int) ($serviceValue['json']['SkuServiceTypeId'] ?? 0) !== $serviceTypeId) {
        throw new RuntimeException("SkuServiceValueId $serviceValueId no pertenece a SkuServiceTypeId $serviceTypeId");
    }

    echo "SKUs leidos: " . count($skus) . "\n";
    echo "SKUs ya completos en auditoria: " . count($completedOk) . "\n";
    echo "Listando skuservice actuales...\n";
    $allServices = getAllSkuServices($baseUrl, $appKey, $appToken);
    $serviceMap = buildServiceMap($allServices, $skus);

    $results = [];
    $processed = 0;
    $skipped = 0;

    foreach ($skus as $sku) {
        if (isset($completedOk[$sku])) {
            $skipped++;
            continue;
        }

        $processed++;
        $existingServices = $serviceMap[$sku] ?? [];
        $sameTypeServices = array_values(array_filter($existingServices, fn (array $service): bool => isSameServiceType($service, $serviceTypeId)));
        $targetServices = array_values(array_filter($sameTypeServices, fn (array $service): bool => isTargetService($service, $serviceTypeId, $serviceValueId)));

        $row = [
            'configuracion' => $configName,
            'sku' => $sku,
            'target_service_type_id' => (string) $serviceTypeId,
            'target_service_value_id' => (string) $serviceValueId,
            'action' => '',
            'status' => '',
            'previous_services' => json_encode(array_map('compactService', $sameTypeServices), JSON_UNESCAPED_UNICODE),
            'final_sku_service_id' => '',
            'message' => '',
        ];

        if ($targetServices !== []) {
            $target = $targetServices[0];
            $targetId = (int) ($target['Id'] ?? 0);
            $payload = payloadForSku($sku, $serviceTypeId, $serviceValueId, $serviceName, $serviceText, $targetId);
            $put = requestJson('PUT', "$baseUrl/api/catalog/pvt/skuservice/$targetId", $appKey, $appToken, $payload);

            $row['action'] = 'already_exists_updated_text';
            $row['status'] = $put['ok'] ? 'ok' : 'error';
            $row['final_sku_service_id'] = (string) $targetId;
            $row['message'] = $put['ok'] ? 'Ya existia target; se aseguro name/text/active' : "PUT fallo HTTP {$put['status']} {$put['body']}";
        } else {
            foreach ($sameTypeServices as $legacyService) {
                $legacyId = (int) ($legacyService['Id'] ?? 0);
                if ($legacyId <= 0) {
                    continue;
                }

                $delete = requestJson('DELETE', "$baseUrl/api/catalog/pvt/skuservice/$legacyId", $appKey, $appToken);
                if (!$delete['ok']) {
                    $row['action'] = 'delete_legacy';
                    $row['status'] = 'error';
                    $row['message'] = "No se pudo borrar legacy $legacyId HTTP {$delete['status']} {$delete['body']}";
                    appendResult($outputCsv, $row);
                    $results[] = $row;
                    continue 2;
                }
            }

            $payload = payloadForSku($sku, $serviceTypeId, $serviceValueId, $serviceName, $serviceText);
            $post = requestJson('POST', "$baseUrl/api/catalog/pvt/skuservice", $appKey, $appToken, $payload);

            $row['action'] = $sameTypeServices === [] ? 'created' : 'deleted_legacy_and_created';
            $row['status'] = $post['ok'] && is_array($post['json']) ? 'ok' : 'error';
            $row['final_sku_service_id'] = is_array($post['json']) ? (string) ($post['json']['Id'] ?? '') : '';
            $row['message'] = $row['status'] === 'ok' ? 'Configuracion aplicada' : "POST fallo HTTP {$post['status']} {$post['body']}";
        }

        appendResult($outputCsv, $row);
        $results[] = $row;

        if ($processed % 25 === 0 || ($processed + $skipped) === count($skus)) {
            echo "Procesados nuevos: $processed / Pendientes iniciales: " . (count($skus) - count($completedOk)) . "\n";
        }
    }

    $allAuditRows = [];
    if (($audit = fopen($outputCsv, 'r')) !== false) {
        $headers = fgetcsv($audit, 0, ',', '"', '');
        while (($row = fgetcsv($audit, 0, ',', '"', '')) !== false) {
            if (is_array($headers)) {
                $allAuditRows[] = array_combine($headers, $row);
            }
        }
        fclose($audit);
    }

    file_put_contents($outputJson, json_encode([
        'input' => basename($input),
        'configuracion' => $configName,
        'service_value_validado' => $serviceValue['json'],
        'resultados' => $allAuditRows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $summary = [
        'input' => basename($input),
        'total_skus' => count($skus),
        'omitidos_por_auditoria_ok' => $skipped,
        'procesados_en_esta_corrida' => $processed,
        'ok_total_auditoria' => count(array_filter($allAuditRows, fn (array $r): bool => ($r['status'] ?? '') === 'ok')),
        'errores_total_auditoria' => count(array_filter($allAuditRows, fn (array $r): bool => ($r['status'] ?? '') !== 'ok')),
        'acciones_total_auditoria' => array_count_values(array_map(fn (array $r): string => (string) ($r['action'] ?? ''), $allAuditRows)),
        'reporte_json' => basename($outputJson),
        'reporte_csv' => basename($outputCsv),
    ];

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    exit(1);
}
