<?php
declare(strict_types=1);

/*
 * Lista todos los SkuServiceType existentes en el catalogo y cuantos SKUs tiene cada uno.
 *
 * Uso:
 * php bin/list-service-types.php --output=service_types.csv
 * php bin/list-service-types.php --output=service_types.csv --max=500 --stop-after=60
 * php bin/list-service-types.php --output=service_types.csv --no-skus   (mas rapido, sin conteos)
 *
 * Contexto para otra IA:
 * - VTEX no expone un listado de SkuServiceType; solo GET /api/catalog/pvt/skuservicetype/{id}.
 *   Por eso se recorren los ids desde 1 y se corta tras --stop-after ids seguidos sin resultado.
 * - Los conteos de SKUs salen de una sola llamada a /api/catalog/pvt/skuservice (la lista completa).
 * - Script de solo lectura: no hace POST/PUT/DELETE.
 */

ini_set('memory_limit', '2048M');

require_once __DIR__ . '/../lib/bootstrap.php';

/**
 * Devuelve el JSON decodificado, o null si VTEX responde 404 (el id no existe).
 */
function boolLabel(mixed $value): string
{
    return ($value === true || $value === 'true' || $value === 1) ? 'si' : 'no';
}

$options = getopt('', ['output:', 'max:', 'stop-after:', 'no-skus']);
$outputPath = optionValue($options, 'output') ?? 'service_types.csv';
$maxId = (int) (optionValue($options, 'max') ?? '500');
$stopAfter = (int) (optionValue($options, 'stop-after') ?? '60');
$withSkus = !array_key_exists('no-skus', $options);

if ($maxId <= 0 || $stopAfter <= 0) {
    fwrite(STDERR, "--max y --stop-after deben ser mayores a 0\n");
    exit(1);
}

if (!str_starts_with($outputPath, '/')) {
    $outputPath = __DIR__ . '/../' . $outputPath;
}

$appKey = envString('API_KEY');
$appToken = envString('API_TOKEN');
$baseUrl = vtexBaseUrl();

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

// Paso 1: conteo de SKUs por service type, de una sola pasada.
$skusPorType = [];
$skusActivosPorType = [];

if ($withSkus) {
    echo "Listando relaciones skuservice para contar SKUs por type...\n";
    $allServices = vtexGetJsonOrNull(rtrim($baseUrl, '/') . '/api/catalog/pvt/skuservice', $appKey, $appToken) ?? [];

    foreach ($allServices as $service) {
        if (!is_array($service)) {
            continue;
        }

        $typeId = (int) ($service['SkuServiceTypeId'] ?? 0);
        $skuId = (string) ($service['SkuId'] ?? '');

        if ($typeId <= 0 || $skuId === '') {
            continue;
        }

        $skusPorType[$typeId][$skuId] = true;
        if (($service['IsActive'] ?? false) === true) {
            $skusActivosPorType[$typeId][$skuId] = true;
        }
    }

    echo "Relaciones leidas: " . count($allServices) . " en " . count($skusPorType) . " types.\n";
}

// Paso 2: recorrer los ids de service type hasta agotar.
$filas = [];
$fallosSeguidos = 0;
$ultimoEncontrado = 0;

for ($id = 1; $id <= $maxId; $id++) {
    $type = vtexGetJsonOrNull(rtrim($baseUrl, '/') . "/api/catalog/pvt/skuservicetype/$id", $appKey, $appToken);

    if ($type === null || ($type['Id'] ?? null) === null) {
        $fallosSeguidos++;
        if ($fallosSeguidos >= $stopAfter) {
            echo "Corte: $stopAfter ids seguidos sin resultado (ultimo encontrado: $ultimoEncontrado).\n";
            break;
        }
        continue;
    }

    $fallosSeguidos = 0;
    $ultimoEncontrado = $id;

    $filas[] = [
        'Id' => (string) $type['Id'],
        'Name' => (string) ($type['Name'] ?? ''),
        'IsActive' => boolLabel($type['IsActive'] ?? false),
        'ShowOnProductFront' => boolLabel($type['ShowOnProductFront'] ?? false),
        'ShowOnCartFront' => boolLabel($type['ShowOnCartFront'] ?? false),
        'ShowOnAttachmentFront' => boolLabel($type['ShowOnAttachmentFront'] ?? false),
        'ShowOnFileUpload' => boolLabel($type['ShowOnFileUpload'] ?? false),
        'IsGiftCard' => boolLabel($type['IsGiftCard'] ?? false),
        'IsRequired' => boolLabel($type['IsRequired'] ?? false),
        'skus_configurados' => $withSkus ? (string) count($skusPorType[$id] ?? []) : '',
        'skus_activos' => $withSkus ? (string) count($skusActivosPorType[$id] ?? []) : '',
    ];

    if ($id % 25 === 0) {
        echo "Revisados ids 1-$id. Encontrados: " . count($filas) . "\n";
    }
}

if ($filas === []) {
    fwrite(STDERR, "No se encontro ningun SkuServiceType.\n");
    exit(1);
}

// Se escribe en un temporal y se renombra al final: quien lee el archivo (el dashboard) nunca ve una
// lista a medias, y si algo falla la copia anterior queda intacta.
$tmpPath = $outputPath . '.tmp.' . getmypid();
$handle = fopen($tmpPath, 'w');
if ($handle === false) {
    fwrite(STDERR, "No se pudo escribir $outputPath\n");
    exit(1);
}

fputcsv($handle, array_keys($filas[0]), ',', '"', '');
foreach ($filas as $fila) {
    fputcsv($handle, array_values($fila), ',', '"', '');
}
if (!fclose($handle) || !rename($tmpPath, $outputPath)) {
    @unlink($tmpPath);
    fwrite(STDERR, "No se pudo escribir $outputPath\n");
    exit(1);
}

$activos = 0;
$instalaciones = 0;
foreach ($filas as $fila) {
    if ($fila['IsActive'] === 'si') {
        $activos++;
    }
    if (stripos($fila['Name'], 'instala') !== false) {
        $instalaciones++;
    }
}

echo "\n== Resultado ==\n";
echo "Service types encontrados: " . count($filas) . " (ultimo id: $ultimoEncontrado)\n";
echo "Activos: $activos\n";
echo "Con 'instala' en el nombre: $instalaciones\n";
echo "Archivo: $outputPath\n";
