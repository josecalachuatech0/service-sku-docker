<?php
declare(strict_types=1);

/*
 * Cuenta cuantos SKUs de una categoria tienen configurado un SkuServiceTypeId dado.
 *
 * Uso:
 * php bin/count-skus-with-servicetype-in-category.php --category=1529 --servicetype=65
 *
 * Contexto para otra IA:
 * - Pagina GetProductAndSkuIds para obtener todos los SKUs de la categoria (incluye subcategorias).
 * - Lista todas las relaciones SkuService via /api/catalog/pvt/skuservice y filtra por SkuId + SkuServiceTypeId.
 * - Usa la base privada https://cemacogt.myvtex.com (misma que usan los scripts apply-*).
 */

ini_set('memory_limit', '2048M');

require_once __DIR__ . '/../lib/bootstrap.php';

$options = getopt('', ['category:', 'servicetype:']);
$categoryId = (int) (optionValue($options, 'category') ?? '0');
$serviceTypeId = (int) (optionValue($options, 'servicetype') ?? '0');

if ($categoryId <= 0 || $serviceTypeId <= 0) {
    fwrite(STDERR, "Uso: php bin/count-skus-with-servicetype-in-category.php --category=1529 --servicetype=65\n");
    exit(1);
}

$appKey = envString('API_KEY');
$appToken = envString('API_TOKEN');
$baseUrl = vtexBaseUrl();
$pageSize = 250;

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

// Paso 1: recolectar todos los SKUs de la categoria.
$productSkuPairs = [];
$from = 1;
$totalProducts = null;
$productsRead = 0;

do {
    $to = $from + $pageSize - 1;
    $response = vtexGetJson(buildProductsAndSkusUrl($baseUrl, $categoryId, $from, $to), $appKey, $appToken);

    if (!isset($response['data']) || !is_array($response['data'])) {
        throw new RuntimeException("Respuesta sin 'data': " . json_encode($response, JSON_UNESCAPED_UNICODE));
    }

    if (isset($response['range']['total']) && is_numeric($response['range']['total'])) {
        $totalProducts = (int) $response['range']['total'];
    }

    foreach ($response['data'] as $productId => $skuIds) {
        $productsRead++;
        if (!is_array($skuIds)) {
            continue;
        }
        foreach ($skuIds as $skuId) {
            $productSkuPairs[(string) $skuId] = (string) $productId;
        }
    }

    echo "Productos leidos rango $from-$to" . ($totalProducts !== null ? " de $totalProducts" : '') . ". SKUs acumulados: " . count($productSkuPairs) . "\n";

    $from = $to + 1;
} while ($totalProducts === null || $from <= $totalProducts);

$categorySkus = array_keys($productSkuPairs);
echo "Total SKUs en categoria $categoryId: " . count($categorySkus) . "\n";

if ($categorySkus === []) {
    echo "No hay SKUs en la categoria; nada que contar.\n";
    exit(0);
}

// Paso 2: listar todas las relaciones SkuService y filtrar por categoria + service type.
echo "Listando skuservice...\n";
$allServices = vtexGetJson(rtrim($baseUrl, '/') . '/api/catalog/pvt/skuservice', $appKey, $appToken);

$categorySkuSet = array_fill_keys($categorySkus, true);
$matchedSkus = [];
$matchedActive = 0;

foreach ($allServices as $service) {
    if (!is_array($service)) {
        continue;
    }

    $skuId = (string) ($service['SkuId'] ?? '');
    $typeId = (int) ($service['SkuServiceTypeId'] ?? 0);

    if ($skuId === '' || $typeId !== $serviceTypeId || !isset($categorySkuSet[$skuId])) {
        continue;
    }

    $matchedSkus[$skuId] = true;
    if (($service['IsActive'] ?? false) === true) {
        $matchedActive++;
    }
}

$matchedList = array_keys($matchedSkus);
sort($matchedList, SORT_NUMERIC);

$matchedProductIds = [];
foreach ($matchedList as $skuId) {
    $productId = $productSkuPairs[$skuId] ?? null;
    if ($productId !== null) {
        $matchedProductIds[$productId] = true;
    }
}

echo "\n== Resultado ==\n";
echo "Categoria: $categoryId\n";
echo "SkuServiceTypeId: $serviceTypeId\n";
echo "SKUs configurados con ese service type: " . count($matchedList) . "\n";
echo "De esos, con IsActive=true: $matchedActive\n";
echo "Productos distintos con ese service type: " . count($matchedProductIds) . "\n";
echo "SKUs: " . implode(', ', $matchedList) . "\n";
echo "ProductIds: " . implode(', ', array_keys($matchedProductIds)) . "\n";
