<?php
declare(strict_types=1);

/*
 * Reporte de categoria: resumen de una categoria frente a un SkuServiceTypeId dado.
 *
 * Uso:
 * php bin/reporte-de-categoria.php --category=113 --servicetype=39
 *
 * Salida:
 * - Nombre de la categoria
 * - Productos totales / productos sin SKU
 * - SKU totales
 * - SKUs configurados con el service type indicado (y el listado de los que faltan)
 *
 * Contexto para otra IA:
 * - Pagina GetProductAndSkuIds para obtener todos los productos/SKUs de la categoria.
 * - Lista todas las relaciones SkuService via /api/catalog/pvt/skuservice y filtra por SkuId + SkuServiceTypeId.
 * - Usa la base privada https://cemacogt.myvtex.com (misma que usan los scripts apply-* y count-skus-with-servicetype-in-category.php).
 */

ini_set('memory_limit', '2048M');

require_once __DIR__ . '/../lib/bootstrap.php';

$options = getopt('', ['category:', 'servicetype:']);
$categoryId = (int) (optionValue($options, 'category') ?? '0');
$serviceTypeId = (int) (optionValue($options, 'servicetype') ?? '0');

if ($categoryId <= 0 || $serviceTypeId <= 0) {
    fwrite(STDERR, "Uso: php bin/reporte-de-categoria.php --category=113 --servicetype=39\n");
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

// Paso 1: nombre de la categoria.
$category = vtexGetJson(rtrim($baseUrl, '/') . "/api/catalog/pvt/category/$categoryId", $appKey, $appToken);
$categoryName = (string) ($category['Name'] ?? 'sin_nombre');

// Paso 2: recolectar todos los productos/SKUs de la categoria.
$productSkuPairs = [];
$totalProducts = 0;
$productsWithoutSku = 0;
$from = 1;
$total = null;

do {
    $to = $from + $pageSize - 1;
    $response = vtexGetJson(buildProductsAndSkusUrl($baseUrl, $categoryId, $from, $to), $appKey, $appToken);

    if (!isset($response['data']) || !is_array($response['data'])) {
        throw new RuntimeException("Respuesta sin 'data': " . json_encode($response, JSON_UNESCAPED_UNICODE));
    }

    if (isset($response['range']['total']) && is_numeric($response['range']['total'])) {
        $total = (int) $response['range']['total'];
    }

    foreach ($response['data'] as $productId => $skuIds) {
        $totalProducts++;
        if (!is_array($skuIds) || $skuIds === []) {
            $productsWithoutSku++;
            continue;
        }
        foreach ($skuIds as $skuId) {
            $productSkuPairs[(string) $skuId] = (string) $productId;
        }
    }

    $from = $to + 1;
} while ($total === null || $from <= $total);

$categorySkus = array_keys($productSkuPairs);
sort($categorySkus, SORT_NUMERIC);

// Paso 3: listar todas las relaciones SkuService y filtrar por categoria + service type.
$matchedSkus = [];

if ($categorySkus !== []) {
    $allServices = vtexGetJson(rtrim($baseUrl, '/') . '/api/catalog/pvt/skuservice', $appKey, $appToken);
    $categorySkuSet = array_fill_keys($categorySkus, true);

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
    }
}

$missingSkus = array_values(array_diff($categorySkus, array_keys($matchedSkus)));
sort($missingSkus, SORT_NUMERIC);

echo "== Resumen categoria $categoryId ==\n";
echo "Nombre de la categoria: $categoryName\n";
echo "Productos totales: $totalProducts\n";
echo "Productos sin SKU: $productsWithoutSku\n";
echo "SKU totales: " . count($categorySkus) . "\n";
echo "SKUs configurados con SkuServiceTypeId $serviceTypeId: " . count($matchedSkus) . "\n";
echo "SKUs SIN SkuServiceTypeId $serviceTypeId: " . count($missingSkus) . "\n";
if ($missingSkus !== []) {
    echo implode(', ', $missingSkus) . "\n";
}
