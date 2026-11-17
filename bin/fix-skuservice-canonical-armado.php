<?php
declare(strict_types=1);

/*
 * Corrige SkuService.Name y SkuService.Text al valor canonico en los service types de
 * ARMADO (3 Baja, 4 Media, 5 Alta, 47 Avanzada).
 *
 * Uso:
 * php bin/fix-skuservice-canonical-armado.php --output=aplicacion.csv
 * php bin/fix-skuservice-canonical-armado.php --output=aplicacion.csv --types=4
 * php bin/fix-skuservice-canonical-armado.php --output=aplicacion.csv --skus=1059053,1077764
 * php bin/fix-skuservice-canonical-armado.php --output=aplicacion.csv --limit=20
 * php bin/fix-skuservice-canonical-armado.php --output=aplicacion.csv --listado=skuservice.json
 * php bin/fix-skuservice-canonical-armado.php --output=aplicacion.csv --aplicar
 *
 * Por defecto corre en DRY-RUN: no escribe nada. Solo con --aplicar hace PUT.
 *
 * Contexto para otra IA:
 * - Es la version para armado de bin/fix-skuservice-canonical-name.php. Se separo porque
 *   aquel tiene la lista de types de instalacion y su canonico fijos en constantes, y
 *   entra por un registro CSV revisado a mano; este entra por el listado completo.
 * - Canonico: Name = Text = "Servicio de armado" en los cuatro types (decision de Eduardo
 *   del 2026-09-17, que REEMPLAZA la del 09-11 de poner la complejidad en el Text).
 * - La entrada NO es un registro CSV: baja UNA vez /api/catalog/pvt/skuservice (~164k
 *   relaciones, no acepta paginacion) y se queda con las de los types de armado. Es la
 *   fuente de verdad; stockkeepingunitbyid sirve datos cacheados (incidencia A5).
 * - Con --listado=<json> usa una descarga previa del listado en vez de bajarlo otra vez.
 *   Sirve para repetir dry-runs sin esperar; la relectura en vivo sigue protegiendo.
 * - Antes de cada escritura re-consulta la relacion en vivo. Si desaparecio, si ya esta
 *   correcta, si cambio de type, o si Name/Text ya no coinciden con lo que traia el listado,
 *   NO escribe y lo reporta. El usuario corrige cosas por la UI de VTEX entre corridas.
 * - Solo hace PUT con el objeto completo. Nunca crea ni borra relaciones SkuService.
 *   Conserva SkuServiceValueId e IsActive tal como estan en vivo.
 * - Es idempotente: correrlo dos veces no cambia nada la segunda vez.
 * - El 09-11 se midieron 3 defectos visibles que este script corrige de paso porque
 *   sobreescribe Text: 12 relaciones del type 5 con Text "avanzada", una del type 4 con
 *   Text = "1181933", y tres con comillas literales o espacio inicial.
 * - Criterios de aceptacion: SETIEMBRE/semana_3/canonico_armado/criterios_aceptacion.md
 */

ini_set('memory_limit', '2048M');

const CANONICAL_NAME = 'Servicio de armado';
const CANONICAL_TEXT = 'Servicio de armado';

// Service types de armado. Solo estos se tocan.
const CANONICAL_TEXT_BY_TYPE = [
    3 => CANONICAL_TEXT,   // Armado Complejidad Baja
    4 => CANONICAL_TEXT,   // Armado Complejidad Media
    5 => CANONICAL_TEXT,   // Armado Complejidad Alta
    47 => CANONICAL_TEXT,  // Armado Complejidad Avanzada
];

require_once __DIR__ . '/../lib/bootstrap.php';

/**
 * @return int[]
 */
function optionIntList(array $options, string $name): array
{
    $raw = optionValue($options, $name);
    if ($raw === null) {
        return [];
    }
    $list = [];
    foreach (explode(',', $raw) as $item) {
        $item = trim($item);
        if ($item !== '' && ctype_digit($item)) {
            $list[] = (int) $item;
        }
    }
    return array_values(array_unique($list));
}

/**
 * @return array{0:int,1:?array} status y cuerpo decodificado
 */
$options = getopt('', ['output:', 'types:', 'skus:', 'limit:', 'listado:', 'aplicar']);
$output = optionValue($options, 'output');
$aplicar = array_key_exists('aplicar', $options);
$typesFiltro = optionIntList($options, 'types');
$skusFiltro = optionIntList($options, 'skus');
$limit = (int) (optionValue($options, 'limit') ?? 0);
$listadoPath = optionValue($options, 'listado');

if ($output === null) {
    fwrite(STDERR, "Uso: php bin/fix-skuservice-canonical-armado.php --output=<csv> [--types=3,4,5,47] [--skus=a,b] [--limit=N] [--listado=<json>] [--aplicar]\n");
    exit(1);
}

foreach ($typesFiltro as $t) {
    if (!array_key_exists($t, CANONICAL_TEXT_BY_TYPE)) {
        fwrite(STDERR, "El type $t no es de armado. Permitidos: " . implode(',', array_keys(CANONICAL_TEXT_BY_TYPE)) . "\n");
        exit(1);
    }
}

$typesObjetivo = $typesFiltro === [] ? array_keys(CANONICAL_TEXT_BY_TYPE) : $typesFiltro;

$appKey = envString('API_KEY');
$appToken = envString('API_TOKEN');
$baseUrl = vtexBaseUrl();

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

echo ($aplicar ? "MODO APLICAR" : "MODO DRY-RUN (no se escribe nada)") . "\n";
echo "Types objetivo: " . implode(',', $typesObjetivo) . "\n";
if ($skusFiltro !== []) {
    echo "Solo SKUs: " . implode(',', $skusFiltro) . "\n";
}
if ($limit > 0) {
    echo "Limite: $limit relaciones\n";
}

// Paso 1: bajar el listado completo y quedarse con armado.
if ($listadoPath !== null) {
    echo "\nLeyendo listado desde $listadoPath...\n";
    $relations = is_file($listadoPath) ? json_decode((string) file_get_contents($listadoPath), true) : null;
    if (!is_array($relations)) {
        fwrite(STDERR, "No se pudo leer el listado $listadoPath\n");
        exit(1);
    }
} else {
    echo "\nDescargando /api/catalog/pvt/skuservice (puede tardar)...\n";
    [$listStatus, $relations] = vtexRequest('GET', "$baseUrl/api/catalog/pvt/skuservice", $appKey, $appToken, null, 600);
    if ($listStatus < 200 || $listStatus >= 300 || !is_array($relations)) {
        fwrite(STDERR, "No se pudo bajar el listado (HTTP $listStatus)\n");
        exit(1);
    }
}
echo "Relaciones en el catalogo: " . count($relations) . "\n";

$candidatas = [];
$porType = [];
foreach ($relations as $rel) {
    $typeId = (int) ($rel['SkuServiceTypeId'] ?? 0);
    if (!in_array($typeId, $typesObjetivo, true)) {
        continue;
    }
    $skuId = (int) ($rel['SkuId'] ?? 0);
    if ($skusFiltro !== [] && !in_array($skuId, $skusFiltro, true)) {
        continue;
    }
    $porType[$typeId] = ($porType[$typeId] ?? 0) + 1;
    $candidatas[] = $rel;
}
unset($relations);

usort($candidatas, static fn(array $a, array $b): int => ((int) $a['Id']) <=> ((int) $b['Id']));

ksort($porType);
echo "Relaciones de armado en alcance: " . count($candidatas) . "\n";
foreach ($porType as $t => $n) {
    printf("  type %-3d %d\n", $t, $n);
}

if ($limit > 0) {
    $candidatas = array_slice($candidatas, 0, $limit);
}

// Paso 2: procesar una por una.
$resultados = [];
$contador = ['aplicado' => 0, 'sin_cambios' => 0, 'omitido' => 0, 'error' => 0];
$procesados = 0;
$total = count($candidatas);

echo "\nProcesando $total relaciones...\n";

foreach ($candidatas as $rel) {
    $procesados++;
    $id = (string) ($rel['Id'] ?? '');
    $skuId = (string) ($rel['SkuId'] ?? '');
    $typeListado = (int) ($rel['SkuServiceTypeId'] ?? 0);
    $nameListado = (string) ($rel['Name'] ?? '');
    $textListado = (string) ($rel['Text'] ?? '');

    $fila = [
        'sku_service_id' => $id,
        'sku_id' => $skuId,
        'type_id' => (string) $typeListado,
        'name_antes' => $nameListado,
        'text_antes' => $textListado,
        'name_despues' => '',
        'text_despues' => '',
        'estado' => '',
        'detalle' => '',
        'http' => '',
    ];

    $canonName = CANONICAL_NAME;
    $canonText = CANONICAL_TEXT_BY_TYPE[$typeListado];

    // Filtro barato sobre el listado: si ya esta correcta no hace falta ni releerla.
    if ($nameListado === $canonName && $textListado === $canonText) {
        $fila['estado'] = 'sin_cambios';
        $fila['detalle'] = 'ya estaba correcta (segun listado)';
        $fila['name_despues'] = $nameListado;
        $fila['text_despues'] = $textListado;
        $contador['sin_cambios']++;
        $resultados[] = $fila;
        continue;
    }

    // Re-consultar en vivo antes de decidir.
    [$status, $vivo] = vtexRequest('GET', "$baseUrl/api/catalog/pvt/skuservice/$id", $appKey, $appToken);
    $fila['http'] = (string) $status;

    if ($status === 404 || $vivo === null) {
        $fila['estado'] = $status === 404 ? 'omitido' : 'error';
        $fila['detalle'] = $status === 404
            ? 'la relacion ya no existe en VTEX'
            : "no se pudo leer la relacion (HTTP $status)";
        $contador[$status === 404 ? 'omitido' : 'error']++;
        $resultados[] = $fila;
        continue;
    }

    $typeVivo = (int) ($vivo['SkuServiceTypeId'] ?? 0);
    $nameVivo = (string) ($vivo['Name'] ?? '');
    $textVivo = (string) ($vivo['Text'] ?? '');

    if ($typeVivo !== $typeListado || !array_key_exists($typeVivo, CANONICAL_TEXT_BY_TYPE)) {
        $fila['estado'] = 'omitido';
        $fila['detalle'] = "el type cambio desde el listado (vivo: $typeVivo) - revisar a mano";
        $contador['omitido']++;
        $resultados[] = $fila;
        continue;
    }

    if ($nameVivo === $canonName && $textVivo === $canonText) {
        $fila['estado'] = 'sin_cambios';
        $fila['detalle'] = 'ya estaba correcta (en vivo)';
        $fila['name_despues'] = $nameVivo;
        $fila['text_despues'] = $textVivo;
        $contador['sin_cambios']++;
        $resultados[] = $fila;
        continue;
    }

    // Proteccion: si el valor en vivo no coincide con el del listado, alguien lo cambio.
    if ($nameVivo !== $nameListado || $textVivo !== $textListado) {
        $fila['estado'] = 'omitido';
        $fila['detalle'] = 'cambio desde el listado (vivo Name: ' . $nameVivo . ' | Text: ' . $textVivo . ') - revisar a mano';
        $fila['name_despues'] = $nameVivo;
        $fila['text_despues'] = $textVivo;
        $contador['omitido']++;
        $resultados[] = $fila;
        continue;
    }

    $queCambia = [];
    if ($nameVivo !== $canonName) {
        $queCambia[] = 'Name';
    }
    if ($textVivo !== $canonText) {
        $queCambia[] = 'Text';
    }

    if (!$aplicar) {
        $fila['estado'] = 'dry_run';
        $fila['detalle'] = 'se aplicaria: ' . implode('+', $queCambia);
        $fila['name_despues'] = $canonName;
        $fila['text_despues'] = $canonText;
        $contador['aplicado']++;
        $resultados[] = $fila;
        continue;
    }

    $body = [
        'Id' => (int) $id,
        'SkuServiceTypeId' => $typeVivo,
        'SkuServiceValueId' => (int) ($vivo['SkuServiceValueId'] ?? 0),
        'SkuId' => (int) $skuId,
        'Name' => $canonName,
        'Text' => $canonText,
        'IsActive' => ($vivo['IsActive'] ?? true) === true,
    ];

    [$putStatus] = vtexRequest('PUT', "$baseUrl/api/catalog/pvt/skuservice/$id", $appKey, $appToken, $body);
    $fila['http'] = (string) $putStatus;

    if ($putStatus >= 200 && $putStatus < 300) {
        $fila['estado'] = 'aplicado';
        $fila['detalle'] = 'corregido: ' . implode('+', $queCambia);
        $fila['name_despues'] = $canonName;
        $fila['text_despues'] = $canonText;
        $contador['aplicado']++;
    } else {
        $fila['estado'] = 'error';
        $fila['detalle'] = "el PUT fallo (HTTP $putStatus)";
        $contador['error']++;
    }

    $resultados[] = $fila;

    if ($procesados % 50 === 0) {
        echo "  procesados $procesados / $total\n";
    }
}

// Paso 3: auditoria de lo hecho.
$cabecera = [
    'sku_service_id', 'sku_id', 'type_id', 'name_antes', 'text_antes',
    'name_despues', 'text_despues', 'estado', 'detalle', 'http',
];

$dir = dirname($output);
if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$file = fopen($output, 'wb');
if ($file !== false) {
    fputcsv($file, $cabecera, ',', '"', '');
    foreach ($resultados as $fila) {
        fputcsv($file, array_values($fila), ',', '"', '');
    }
    fclose($file);
}

$jsonPath = preg_replace('/\.csv$/i', '', $output) . '.json';
file_put_contents($jsonPath, json_encode([
    'modo' => $aplicar ? 'aplicar' : 'dry-run',
    'fecha' => date('c'),
    'canonico_name' => CANONICAL_NAME,
    'canonico_text' => CANONICAL_TEXT,
    'types_permitidos' => array_keys(CANONICAL_TEXT_BY_TYPE),
    'listado' => $listadoPath ?? 'descarga en vivo',
    'types_objetivo' => $typesObjetivo,
    'skus_filtro' => $skusFiltro,
    'limit' => $limit,
    'total' => $total,
    'contador' => $contador,
    'resultados' => $resultados,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n== Resultado ==\n";
echo ($aplicar ? "MODO APLICAR\n" : "MODO DRY-RUN: no se escribio nada en VTEX\n");
echo "Auditoria CSV:  $output\n";
echo "Auditoria JSON: $jsonPath\n";
printf("  %-14s %d\n", $aplicar ? 'aplicados' : 'se aplicarian', $contador['aplicado']);
printf("  %-14s %d\n", 'sin cambios', $contador['sin_cambios']);
printf("  %-14s %d\n", 'omitidos', $contador['omitido']);
printf("  %-14s %d\n", 'errores', $contador['error']);
