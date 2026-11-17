<?php
declare(strict_types=1);

/*
 * Extrae todos los SKUs (incluye subcategorias) de una categoria de VTEX.
 *
 * Uso:
 * php bin/get-skus-by-category.php --category=1604 --output=input/categoria_1604_skus.txt
 *
 * Contexto para otra IA:
 * - Pagina GetProductAndSkuIds para obtener todos los SKUs de la categoria.
 * - Usa la base privada https://cemacogt.myvtex.com (misma que usan los scripts apply-*).
 * - Escribe un SKU por linea en el archivo de salida, listo para bin/query-skus-9-columns.php.
 */

require_once __DIR__ . '/../lib/bootstrap.php';

$options = getopt('', ['category:', 'output:']);
$categoryId = (int) (optionValue($options, 'category') ?? '0');
$output = optionValue($options, 'output');

if ($categoryId <= 0 || $output === null) {
    fwrite(STDERR, "Uso: php bin/get-skus-by-category.php --category=1604 --output=input/categoria_1604_skus.txt\n");
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

$productSkuPairs = [];
$from = 1;
$totalProducts = null;

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
sort($categorySkus, SORT_NUMERIC);

$file = fopen($output, 'w');
if ($file === false) {
    throw new RuntimeException("No se pudo crear $output");
}

foreach ($categorySkus as $skuId) {
    fwrite($file, $skuId . "\n");
}

fclose($file);

echo "Total SKUs en categoria $categoryId: " . count($categorySkus) . "\n";
echo "Archivo generado: $output\n";
