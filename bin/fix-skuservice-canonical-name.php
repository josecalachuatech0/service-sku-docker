<?php
declare(strict_types=1);

/*
 * Corrige SkuService.Name y SkuService.Text al valor canonico en los service types de
 * instalacion auditados.
 *
 * Uso:
 * php bin/fix-skuservice-canonical-name.php --registro=auditoria-skus-catalogo/registro_por_service_type --output=aplicacion.csv
 * php bin/fix-skuservice-canonical-name.php --registro=... --output=aplicacion.csv --aplicar
 *
 * Por defecto corre en DRY-RUN: no escribe nada. Solo con --aplicar hace PUT.
 *
 * Contexto para otra IA:
 * - La entrada son los CSV de auditoria-skus-catalogo/registro_por_service_type/, que ya
 *   traen una columna "accion" con CORREGIR / NO TOCAR revisada por el usuario. Este script
 *   NO reclasifica nada: solo obedece esa columna.
 * - Antes de cada escritura re-consulta el registro en vivo. Si desaparecio, si ya esta
 *   correcto, o si cambio respecto de lo que decia el registro, NO escribe y lo reporta.
 *   Esto pasa de verdad: el usuario corrige cosas por la UI de VTEX entre una corrida y otra.
 * - Solo hace PUT. Nunca crea ni borra relaciones SkuService.
 * - Es idempotente: correrlo dos veces no cambia nada la segunda vez.
 */

ini_set('memory_limit', '1024M');

const CANONICAL_NAME = 'Servicio de Instalación';

// Los SkuService.Id protegidos (excepcion aprobada el 2026-08-04: el sufijo descriptivo
// distingue variantes del servicio) viven en lib/reglas.php y no se tocan aunque el registro
// diga lo contrario.

// Service types de instalacion aprobados para la correccion.
const ALLOWED_TYPE_IDS = [
    7, 9, 11, 13, 14, 15, 16, 18, 20, 21, 22, 23, 25, 26, 27, 29, 30, 31, 36, 37, 38,
    39, 40, 41, 42, 43, 44, 45, 48, 49, 50, 51, 52, 53, 63, 64, 65, 66, 67, 68, 69,
];

require_once __DIR__ . '/../lib/bootstrap.php';

/**
 * @return array{0:int,1:?array} status y cuerpo decodificado
 */
$options = getopt('', ['registro:', 'output:', 'aplicar']);
$registroDir = optionValue($options, 'registro');
$output = optionValue($options, 'output');
$aplicar = array_key_exists('aplicar', $options);

if ($registroDir === null || $output === null) {
    fwrite(STDERR, "Uso: php bin/fix-skuservice-canonical-name.php --registro=<carpeta> --output=<csv> [--aplicar]\n");
    exit(1);
}

if (!is_dir($registroDir)) {
    fwrite(STDERR, "No existe la carpeta $registroDir\n");
    exit(1);
}

$appKey = envString('API_KEY');
$appToken = envString('API_TOKEN');
$baseUrl = vtexBaseUrl();

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

// Paso 1: leer el registro y quedarse solo con las filas marcadas CORREGIR.
$pendientes = [];
foreach (glob(rtrim($registroDir, '/') . '/type_*.csv') as $file) {
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        continue;
    }

    $header = fgetcsv($handle, 0, ',', '"', '');
    if ($header === false) {
        fclose($handle);
        continue;
    }

    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $record = array_combine($header, $row);
        if (!is_array($record) || ($record['accion'] ?? '') !== 'CORREGIR') {
            continue;
        }
        $record['__archivo'] = basename($file);
        $pendientes[] = $record;
    }

    fclose($handle);
}

echo ($aplicar ? "MODO APLICAR" : "MODO DRY-RUN (no se escribe nada)") . "\n";
echo "Registros marcados CORREGIR: " . count($pendientes) . "\n\n";

// Paso 2: procesar uno por uno.
$resultados = [];
$contador = ['aplicado' => 0, 'sin_cambios' => 0, 'omitido' => 0, 'error' => 0];
$procesados = 0;

foreach ($pendientes as $registro) {
    $procesados++;
    $id = (string) ($registro['sku_service_id'] ?? '');
    $skuId = (string) ($registro['sku_id'] ?? '');
    $nameRegistro = (string) ($registro['name_actual'] ?? '');
    $textRegistro = (string) ($registro['text_actual'] ?? '');

    $fila = [
        'sku_service_id' => $id,
        'sku_id' => $skuId,
        'type_id' => '',
        'name_antes' => $nameRegistro,
        'text_antes' => $textRegistro,
        'name_despues' => '',
        'text_despues' => '',
        'estado' => '',
        'detalle' => '',
        'http' => '',
    ];

    if (isProtectedSkuServiceId($id)) {
        $fila['estado'] = 'omitido';
        $fila['detalle'] = 'excepcion aprobada: no se corrige nunca';
        $contador['omitido']++;
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

    $typeId = (int) ($vivo['SkuServiceTypeId'] ?? 0);
    $nameVivo = (string) ($vivo['Name'] ?? '');
    $textVivo = (string) ($vivo['Text'] ?? '');
    $fila['type_id'] = (string) $typeId;

    if (!in_array($typeId, ALLOWED_TYPE_IDS, true)) {
        $fila['estado'] = 'omitido';
        $fila['detalle'] = "el service type $typeId no esta en la lista aprobada";
        $contador['omitido']++;
        $resultados[] = $fila;
        continue;
    }

    if ($nameVivo === CANONICAL_NAME && $textVivo === CANONICAL_NAME) {
        $fila['estado'] = 'sin_cambios';
        $fila['detalle'] = 'ya estaba correcto';
        $fila['name_despues'] = $nameVivo;
        $fila['text_despues'] = $textVivo;
        $contador['sin_cambios']++;
        $resultados[] = $fila;
        continue;
    }

    // Proteccion: si el valor en vivo no coincide con el que registramos, alguien lo cambio.
    if ($nameVivo !== $nameRegistro) {
        $fila['estado'] = 'omitido';
        $fila['detalle'] = 'cambio desde el registro (vivo: ' . $nameVivo . ') - revisar a mano';
        $fila['name_despues'] = $nameVivo;
        $contador['omitido']++;
        $resultados[] = $fila;
        continue;
    }

    if (!$aplicar) {
        $fila['estado'] = 'dry_run';
        $fila['detalle'] = 'se aplicaria';
        $fila['name_despues'] = CANONICAL_NAME;
        $fila['text_despues'] = CANONICAL_NAME;
        $contador['aplicado']++;
        $resultados[] = $fila;
        continue;
    }

    $body = [
        'Id' => (int) $id,
        'SkuServiceTypeId' => $typeId,
        'SkuServiceValueId' => (int) ($vivo['SkuServiceValueId'] ?? 0),
        'SkuId' => (int) $skuId,
        'Name' => CANONICAL_NAME,
        'Text' => CANONICAL_NAME,
        'IsActive' => ($vivo['IsActive'] ?? true) === true,
    ];

    [$putStatus] = vtexRequest('PUT', "$baseUrl/api/catalog/pvt/skuservice/$id", $appKey, $appToken, $body);
    $fila['http'] = (string) $putStatus;

    if ($putStatus >= 200 && $putStatus < 300) {
        $fila['estado'] = 'aplicado';
        $fila['detalle'] = 'Name y Text corregidos';
        $fila['name_despues'] = CANONICAL_NAME;
        $fila['text_despues'] = CANONICAL_NAME;
        $contador['aplicado']++;
    } else {
        $fila['estado'] = 'error';
        $fila['detalle'] = "el PUT fallo (HTTP $putStatus)";
        $contador['error']++;
    }

    $resultados[] = $fila;

    if ($procesados % 25 === 0) {
        echo "  procesados $procesados / " . count($pendientes) . "\n";
    }
}

// Paso 3: auditoria de lo hecho.
$file = fopen($output, 'wb');
if ($file !== false) {
    fputcsv($file, array_keys($resultados[0] ?? [
        'sku_service_id' => '', 'sku_id' => '', 'type_id' => '', 'name_antes' => '',
        'text_antes' => '', 'name_despues' => '', 'text_despues' => '', 'estado' => '',
        'detalle' => '', 'http' => '',
    ]), ',', '"', '');

    foreach ($resultados as $fila) {
        fputcsv($file, array_values($fila), ',', '"', '');
    }

    fclose($file);
}

$jsonPath = preg_replace('/\.csv$/i', '', $output) . '.json';
file_put_contents($jsonPath, json_encode([
    'modo' => $aplicar ? 'aplicar' : 'dry-run',
    'valor_canonico' => CANONICAL_NAME,
    'total' => count($pendientes),
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
