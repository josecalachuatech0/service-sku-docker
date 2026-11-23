<?php
declare(strict_types=1);

/*
 * Descarga local del catálogo de VTEX: módulos, URLs, partes comprimidas y reanudación.
 * Lo usa bin/descargar-catalogo.php. La documentación está en docs/descarga-catalogo.md.
 *
 * Contexto para otra IA:
 * - Solo lectura: todas las peticiones son GET. Nunca escribe en VTEX.
 * - Cada módulo guarda la respuesta COMPLETA de VTEX, sin recortar campos, para poder
 *   consultarla en local sin volver a llamar a la API.
 * - Formato: <salida>/<modulo>/parte-NNNNN.jsonl.gz. Una línea JSON por id:
 *   {"id": "...", "status": 200|404, "fecha": "ISO-8601", "datos": <respuesta de VTEX o null>}.
 *   Cada parte se escribe en un .tmp y se renombra al final, así una corrida cortada nunca deja
 *   una parte a medias.
 * - 404 cuenta como descargado (el dato no existe en VTEX, por ejemplo un SKU sin precio).
 *   Los demás errores no se guardan: quedan en <modulo>.errores.tsv y se reintentan en la
 *   siguiente corrida.
 * - «skus» usa stockkeepingunitbyid, que es la ficha más completa pero viene de un caché de
 *   VTEX (ver AI_CONTEXT.md): para el estado de SkuService no sirve; para lo demás, sí.
 */

require_once __DIR__ . '/vtex.php';

const DESCARGA_MODULOS = [
    'productos' => [
        'por' => 'producto',
        'ruta' => '/api/catalog/pvt/product/{id}',
        'descripcion' => 'Ficha del producto: nombre, categoría, marca, descripción, palabras clave, fecha de lanzamiento, estado.',
    ],
    'skus' => [
        'por' => 'sku',
        'ruta' => '/api/catalog_system/pvt/sku/stockkeepingunitbyid/{id}',
        'descripcion' => 'Ficha completa del SKU: nombres, dimensiones, imágenes, especificaciones del producto y del SKU, '
            . 'vendedores, canales de venta, colecciones, servicios y ruta de categorías.',
    ],
    'skus_registro' => [
        'por' => 'sku',
        'ruta' => '/api/catalog/pvt/stockkeepingunit/{id}',
        'descripcion' => 'Registro del SKU sin caché: fecha de creación, medidas de empaque, activación automática y videos. '
            . 'Complementa a «skus», que no trae la fecha de creación.',
    ],
    'inventario' => [
        'por' => 'sku',
        'ruta' => '/api/logistics/pvt/inventory/skus/{id}',
        'descripcion' => 'Inventario del SKU en cada bodega: cantidad total, reservada, ilimitado y tiempos de reposición.',
    ],
    'precios' => [
        'por' => 'sku',
        'ruta' => 'https://api.vtex.com/{cuenta}/pricing/prices/{id}',
        'descripcion' => 'Precio del SKU: costo, precio base, precio de lista, margen y precios fijos por política comercial.',
    ],
];

/** Nombre de la cuenta VTEX a partir de la base (https://cemacogt.myvtex.com -> cemacogt). */
function vtexCuenta(string $baseUrl): string
{
    $host = (string) parse_url($baseUrl, PHP_URL_HOST);
    return explode('.', $host)[0];
}

function descargaUrl(string $modulo, string $id, string $baseUrl): string
{
    $ruta = DESCARGA_MODULOS[$modulo]['ruta'];
    $ruta = str_replace(['{id}', '{cuenta}'], [rawurlencode($id), vtexCuenta($baseUrl)], $ruta);
    return str_starts_with($ruta, 'https://') ? $ruta : rtrim($baseUrl, '/') . $ruta;
}

/** Escribe un bloque de registros como una parte nueva, de forma atómica. Devuelve la ruta. */
function descargaEscribirParte(string $dir, array $registros): string
{
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("No se pudo crear $dir");
    }
    $n = count(glob("$dir/parte-*.jsonl.gz")) + 1;
    do {
        $ruta = sprintf('%s/parte-%05d.jsonl.gz', $dir, $n++);
    } while (is_file($ruta));

    $tmp = "$ruta.tmp";
    $gz = gzopen($tmp, 'wb6');
    if ($gz === false) {
        throw new RuntimeException("No se pudo escribir $tmp");
    }
    $fecha = gmdate('Y-m-d\TH:i:s\Z');
    foreach ($registros as $r) {
        gzwrite($gz, json_encode(['id' => (string) $r['id'], 'status' => (int) $r['status'], 'fecha' => $r['fecha'] ?? $fecha,
            'datos' => $r['datos']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
    gzclose($gz);
    rename($tmp, $ruta);
    return $ruta;
}

/** Ids ya descargados en un módulo, con su status: ['123' => 200, '124' => 404]. */
function descargaIdsHechos(string $dir): array
{
    $hechos = [];
    foreach (glob("$dir/parte-*.jsonl.gz") ?: [] as $parte) {
        $gz = gzopen($parte, 'rb');
        if ($gz === false) {
            continue;
        }
        while (($linea = gzgets($gz)) !== false) {
            // Solo el inicio de la línea: evita decodificar respuestas de decenas de KB.
            if (preg_match('/^\{"id":"([^"]+)","status":(\d+)/', $linea, $m)) {
                $hechos[$m[1]] = (int) $m[2];
            }
        }
        gzclose($gz);
    }
    return $hechos;
}

/**
 * GET en paralelo. $urls: [clave => url]. Devuelve [clave => [status, cuerpo]].
 * Mantiene siempre $paralelo peticiones en curso: apenas una termina, entra la siguiente.
 * Reintenta 429, 5xx y cortes de red hasta 5 veces (misma regla que vtexIsRetryable); cada reintento
 * vuelve a la cola con una espera de 2 s por intento, sin frenar a las demás peticiones.
 */
function descargaGetParalelo(array $urls, array $headers, int $paralelo): array
{
    $salida = [];
    $intentos = [];
    $cola = [];            // [clave, url, no antes de (microtime)]
    foreach ($urls as $k => $u) {
        $cola[] = [$k, $u, 0.0];
    }
    $mh = curl_multi_init();
    $enCurso = [];         // id del handle => [clave, url, handle]

    $lanzar = function () use (&$cola, &$enCurso, $mh, $headers, $paralelo): void {
        $ahora = microtime(true);
        foreach ($cola as $i => [$k, $u, $desde]) {
            if (count($enCurso) >= $paralelo) {
                return;
            }
            if ($desde > $ahora) {
                continue;
            }
            $h = curl_init($u);
            curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15]);
            curl_multi_add_handle($mh, $h);
            $enCurso[spl_object_id($h)] = [$k, $u, $h];
            unset($cola[$i]);
        }
    };

    $lanzar();
    while ($enCurso !== [] || $cola !== []) {
        curl_multi_exec($mh, $activos);
        if (curl_multi_select($mh, 0.5) === -1) {
            usleep(10000);
        }
        while (($info = curl_multi_info_read($mh)) !== false) {
            $h = $info['handle'];
            [$k, $u] = $enCurso[spl_object_id($h)];
            unset($enCurso[spl_object_id($h)]);
            $status = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
            $cuerpo = (string) curl_multi_getcontent($h);
            $cortado = $info['result'] !== CURLE_OK;
            curl_multi_remove_handle($mh, $h);
            $intentos[$k] = ($intentos[$k] ?? 0) + 1;
            if (vtexIsRetryable('GET', $status, $cortado) && $intentos[$k] < 5) {
                $cola[] = [$k, $u, microtime(true) + 2 * $intentos[$k]];
            } else {
                $salida[$k] = [$cortado ? 0 : $status, $cuerpo];
            }
        }
        $lanzar();
        if ($enCurso === [] && $cola !== []) {
            usleep(200000);   // solo quedan reintentos esperando su turno
        }
    }
    curl_multi_close($mh);
    return $salida;
}

/** Cuenta registros por status en las partes de un módulo, y su tamaño en disco. */
function descargaEstadoModulo(string $dir): array
{
    $hechos = descargaIdsHechos($dir);
    $porStatus = array_count_values(array_map('strval', $hechos));
    $bytes = 0;
    foreach (glob("$dir/parte-*.jsonl.gz") ?: [] as $p) {
        $bytes += (int) filesize($p);
    }
    return ['descargados' => count($hechos), 'con_datos' => (int) ($porStatus['200'] ?? 0),
        'no_existe_404' => (int) ($porStatus['404'] ?? 0), 'bytes' => $bytes];
}
