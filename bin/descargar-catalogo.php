<?php

declare(strict_types=1);

/**
 * Descarga a disco una copia local y completa del catálogo de VTEX, para consultarlo sin llamar a la API.
 *
 * - Solo lectura: todo es GET. No escribe nada en VTEX.
 * - Módulos: productos, skus, skus_registro, inventario, precios (ver lib/descarga.php y docs/descarga-catalogo.md).
 * - Reanudable: si se corta, se vuelve a correr igual y solo descarga lo que falta.
 * - Al terminar cada módulo escribe <salida>/<modulo>.estado.json; --estado junta todo en <salida>/estado.json.
 *
 * Uso:
 *   php bin/descargar-catalogo.php --salida=datos/catalogo
 *   php bin/descargar-catalogo.php --salida=datos/catalogo --modulos=inventario,precios --paralelo=20
 *   php bin/descargar-catalogo.php --salida=datos/catalogo --estado
 *
 * Opciones:
 *   --salida=<carpeta>   obligatoria. Ahí quedan ids.tsv, una carpeta por módulo y los estados.
 *   --modulos=<lista>    por defecto todos: productos,skus,skus_registro,inventario,precios.
 *   --paralelo=<n>       peticiones simultáneas por módulo (por defecto 20).
 *   --limite=<n>         descarga como máximo n ids por módulo (para probar).
 *   --refrescar-ids      vuelve a pedir la lista de productos y SKUs aunque ids.tsv exista.
 *   --estado             no descarga nada: solo calcula y escribe <salida>/estado.json.
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/descarga.php';

ini_set('memory_limit', '2048M');

$opts = getopt('', ['salida:', 'modulos:', 'paralelo:', 'limite:', 'refrescar-ids', 'estado']);
$salida = optionValue($opts, 'salida');
if ($salida === null) {
    fwrite(STDERR, "Uso: php bin/descargar-catalogo.php --salida=datos/catalogo [--modulos=productos,skus,skus_registro,inventario,precios]"
        . " [--paralelo=20] [--limite=N] [--refrescar-ids] [--estado]\n");
    exit(1);
}
$salida = rtrim($salida, '/');
$modulos = array_values(array_filter(array_map('trim', explode(',', optionValue($opts, 'modulos') ?? implode(',', array_keys(DESCARGA_MODULOS))))));
$desconocidos = array_diff($modulos, array_keys(DESCARGA_MODULOS));
if ($desconocidos !== [] || $modulos === []) {
    fwrite(STDERR, 'Módulo desconocido: ' . implode(', ', $desconocidos) . '. Válidos: ' . implode(', ', array_keys(DESCARGA_MODULOS)) . "\n");
    exit(1);
}
$paralelo = max(1, (int) (optionValue($opts, 'paralelo') ?? 20));
$limite = optionValue($opts, 'limite') !== null ? max(0, (int) optionValue($opts, 'limite')) : null;

$baseUrl = vtexBaseUrl();
$idsArchivo = "$salida/ids.tsv";

/** Lee ids.tsv: [producto => [sku, ...]]. */
function leerIds(string $archivo): array
{
    $ids = [];
    foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
        $partes = explode("\t", $linea);
        $ids[$partes[0]] = ($partes[1] ?? '') === '' ? [] : explode(',', $partes[1]);
    }
    return $ids;
}

/** Junta los estados de cada módulo en estado.json. */
function escribirEstado(string $salida, string $baseUrl): array
{
    $ids = is_file("$salida/ids.tsv") ? leerIds("$salida/ids.tsv") : [];
    $skus = array_sum(array_map('count', $ids));
    $estado = [
        'generado' => gmdate('Y-m-d\TH:i:s\Z'),
        'cuenta' => vtexCuenta($baseUrl),
        'totales' => ['productos' => count($ids), 'skus' => $skus,
            'productos_sin_sku' => count(array_filter($ids, fn ($s) => $s === []))],
        'modulos' => [],
    ];
    foreach (DESCARGA_MODULOS as $nombre => $def) {
        $archivo = "$salida/$nombre.estado.json";
        $m = is_file($archivo) ? (json_decode((string) file_get_contents($archivo), true) ?: []) : [];
        $esperados = $def['por'] === 'producto' ? count($ids) : $skus;
        $descargados = (int) ($m['descargados'] ?? 0);
        $estado['modulos'][$nombre] = [
            'por' => $def['por'], 'ruta' => $def['ruta'], 'descripcion' => $def['descripcion'],
            'esperados' => $esperados, 'descargados' => $descargados,
            'con_datos' => (int) ($m['con_datos'] ?? 0), 'no_existe_404' => (int) ($m['no_existe_404'] ?? 0),
            'errores' => (int) ($m['errores'] ?? 0), 'pendientes' => max(0, $esperados - $descargados),
            'completo' => $esperados > 0 && $descargados >= $esperados,
            'bytes' => (int) ($m['bytes'] ?? 0), 'actualizado' => $m['actualizado'] ?? null,
        ];
    }
    file_put_contents("$salida/estado.json", json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    return $estado;
}

if (!is_dir($salida) && !mkdir($salida, 0777, true) && !is_dir($salida)) {
    fwrite(STDERR, "No se pudo crear $salida\n");
    exit(1);
}

if (isset($opts['estado'])) {
    $estado = escribirEstado($salida, $baseUrl);
    foreach ($estado['modulos'] as $n => $m) {
        printf("%-11s %7d de %7d  (%s)\n", $n, $m['descargados'], $m['esperados'], $m['completo'] ? 'completo' : "faltan {$m['pendientes']}");
    }
    exit(0);
}

$appKey = envString('API_KEY');
$appToken = envString('API_TOKEN');
if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}
$headers = vtexHeaders($appKey, $appToken);

// 1. Lista de productos y SKUs.
if (!is_file($idsArchivo) || isset($opts['refrescar-ids'])) {
    $primera = vtexGetJson("$baseUrl/api/catalog_system/pvt/products/GetProductAndSkuIds?_from=1&_to=1", $appKey, $appToken);
    $total = (int) ($primera['range']['total'] ?? 0);
    $urls = [];
    for ($f = 1; $f <= $total; $f += 250) {
        $urls[$f] = "$baseUrl/api/catalog_system/pvt/products/GetProductAndSkuIds?_from=$f&_to=" . min($f + 249, $total);
    }
    $lineas = [];
    $fallidas = [];
    foreach (descargaGetParalelo($urls, $headers, 10) as $f => [$status, $cuerpo]) {
        $data = $status === 200 ? (json_decode($cuerpo, true)['data'] ?? null) : null;
        if (!is_array($data)) {
            $fallidas[] = "$f (HTTP $status)";
            continue;
        }
        foreach ($data as $producto => $skus) {
            $lineas[] = $producto . "\t" . implode(',', $skus);
        }
    }
    if ($fallidas !== []) {
        // Una lista incompleta haría pasar por completo un módulo al que le faltan ids.
        appLog('error', 'descarga.ids_incompletos', ['paginas' => $fallidas]);
        fwrite(STDERR, 'No se pudo leer la lista completa de productos; páginas fallidas: ' . implode(', ', $fallidas) . "\n");
        exit(1);
    }
    file_put_contents("$idsArchivo.tmp", implode("\n", $lineas) . "\n");
    rename("$idsArchivo.tmp", $idsArchivo);
    fwrite(STDERR, "ids: $total productos\n");
}
$ids = leerIds($idsArchivo);

// 2. Módulos.
$salidaCodigo = 0;
foreach ($modulos as $modulo) {
    $def = DESCARGA_MODULOS[$modulo];
    $esperados = $def['por'] === 'producto' ? array_map('strval', array_keys($ids)) : array_merge(...array_values($ids));
    $dir = "$salida/$modulo";
    $hechos = descargaIdsHechos($dir);
    $pendientes = array_values(array_filter($esperados, fn ($id) => !isset($hechos[$id])));
    if ($limite !== null) {
        $pendientes = array_slice($pendientes, 0, $limite);
    }
    fwrite(STDERR, "$modulo: " . count($hechos) . ' ya descargados, ' . count($pendientes) . " por descargar\n");

    $errores = [];
    $hechosAhora = 0;
    foreach (array_chunk($pendientes, 1000) as $bloque) {
        $urls = [];
        foreach ($bloque as $id) {
            $urls[$id] = descargaUrl($modulo, (string) $id, $baseUrl);
        }
        $registros = [];
        foreach (descargaGetParalelo($urls, $headers, $paralelo) as $id => [$status, $cuerpo]) {
            $datos = json_decode($cuerpo, true);
            if ($status === 200 && $datos !== null) {
                $registros[] = ['id' => $id, 'status' => 200, 'datos' => $datos];
                $hechos[(string) $id] = 200;
            } elseif ($status === 404) {
                $registros[] = ['id' => $id, 'status' => 404, 'datos' => null];
                $hechos[(string) $id] = 404;
            } else {
                $errores[] = "$id\t$status\t" . substr(preg_replace('/\s+/', ' ', $cuerpo), 0, 200);
            }
        }
        if ($registros !== []) {
            descargaEscribirParte($dir, $registros);
        }
        $hechosAhora += count($registros);
        fwrite(STDERR, date('H:i:s') . " $modulo: $hechosAhora/" . count($pendientes) . ' (errores ' . count($errores) . ")\n");
    }

    file_put_contents("$salida/$modulo.errores.tsv", $errores === [] ? '' : implode("\n", $errores) . "\n");
    if ($errores !== []) {
        appLog('aviso', 'descarga.errores', ['modulo' => $modulo, 'errores' => count($errores)]);
        $salidaCodigo = 2;
    }
    $porStatus = array_count_values(array_map('strval', $hechos));
    $bytes = array_sum(array_map('filesize', glob("$dir/parte-*.jsonl.gz") ?: []));
    file_put_contents("$salida/$modulo.estado.json", json_encode([
        'descargados' => count($hechos), 'con_datos' => (int) ($porStatus['200'] ?? 0),
        'no_existe_404' => (int) ($porStatus['404'] ?? 0), 'errores' => count($errores), 'bytes' => $bytes,
        'actualizado' => gmdate('Y-m-d\TH:i:s\Z'),
    ], JSON_PRETTY_PRINT) . "\n");
}

$estado = escribirEstado($salida, $baseUrl);
foreach ($modulos as $n) {
    $m = $estado['modulos'][$n];
    fwrite(STDERR, sprintf("%-11s %7d de %7d  (%s)\n", $n, $m['descargados'], $m['esperados'], $m['completo'] ? 'completo' : "faltan {$m['pendientes']}"));
}
exit($salidaCodigo);
