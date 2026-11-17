<?php
declare(strict_types=1);

/*
 * Revisa si una lista puntual de SKUs tiene configurado un SkuServiceTypeId dado.
 *
 * Uso:
 * php bin/check-skus-servicetype.php --skus=1096284,1139010 --servicetype=39
 * php bin/check-skus-servicetype.php --input=input/algo_skus.txt --servicetype=39
 *
 * Contexto para otra IA:
 * - A diferencia de count-skus-with-servicetype-in-category.php, aqui la lista de SKUs
 *   se pasa directamente (no se deriva de una categoria).
 * - Lista todas las relaciones de /api/catalog/pvt/skuservice y filtra por SkuId + SkuServiceTypeId.
 */

ini_set('memory_limit', '2048M');

require_once __DIR__ . '/../lib/bootstrap.php';

$options = getopt('', ['skus:', 'input:', 'servicetype:']);
$serviceTypeId = (int) (optionValue($options, 'servicetype') ?? '0');
$skusOption = optionValue($options, 'skus');
$inputOption = optionValue($options, 'input');

if ($serviceTypeId <= 0 || ($skusOption === null && $inputOption === null)) {
    fwrite(STDERR, "Uso: php bin/check-skus-servicetype.php --skus=1,2,3 --servicetype=39\n");
    fwrite(STDERR, "  o: php bin/check-skus-servicetype.php --input=archivo.txt --servicetype=39\n");
    exit(1);
}

if ($inputOption !== null) {
    $lines = file($inputOption, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("No se pudo leer $inputOption");
    }
    $skuList = array_map(fn ($l) => trim(str_replace(["\r", '"'], '', $l)), $lines);
} else {
    $skuList = array_map('trim', explode(',', (string) $skusOption));
}

$skuList = array_values(array_unique(array_filter($skuList, fn ($s) => $s !== '')));

$appKey = envString('API_KEY');
$appToken = envString('API_TOKEN');
$baseUrl = vtexBaseUrl();

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

echo "Listando skuservice...\n";
$allServices = vtexGetJson(rtrim($baseUrl, '/') . '/api/catalog/pvt/skuservice', $appKey, $appToken);

$skuSet = array_fill_keys($skuList, true);
$matches = [];

foreach ($allServices as $service) {
    if (!is_array($service)) {
        continue;
    }

    $skuId = (string) ($service['SkuId'] ?? '');
    $typeId = (int) ($service['SkuServiceTypeId'] ?? 0);

    if ($skuId === '' || $typeId !== $serviceTypeId || !isset($skuSet[$skuId])) {
        continue;
    }

    $matches[$skuId][] = [
        'Id' => $service['Id'] ?? null,
        'SkuServiceValueId' => $service['SkuServiceValueId'] ?? null,
        'IsActive' => $service['IsActive'] ?? null,
    ];
}

echo "\n== Resultado ==\n";
echo "SkuServiceTypeId: $serviceTypeId\n";
echo "SKUs revisados: " . count($skuList) . "\n\n";

foreach ($skuList as $sku) {
    if (isset($matches[$sku])) {
        $details = array_map(
            fn ($m) => "Id={$m['Id']} SkuServiceValueId={$m['SkuServiceValueId']} IsActive=" . ($m['IsActive'] ? 'true' : 'false'),
            $matches[$sku]
        );
        echo "$sku: SI configurado (" . implode('; ', $details) . ")\n";
    } else {
        echo "$sku: NO configurado\n";
    }
}

$configured = count($matches);
$notConfigured = count($skuList) - $configured;
echo "\nTotal con SkuServiceTypeId $serviceTypeId: $configured\n";
echo "Total sin SkuServiceTypeId $serviceTypeId: $notConfigured\n";
