<?php
declare(strict_types=1);

/*
 * Exporta reporte de 9 columnas filtrado a instalacion de repisas.
 *
 * Contexto para otra IA:
 * - Lee SKUs desde batch_repisas_junio_exclusion.csv.
 * - Solo marca tiene_attachment=si cuando encuentra ServiceTypeId 37 en el detalle del SKU.
 * - Mantiene el mismo formato de 9 columnas usado por los reportes estandar.
 */

// Archivado en bin/legacy/ el 2026-10-23. Solo lee VTEX, pero sobrescribe un CSV fijo de la raiz
// con una entrada fija de junio. No corre sin --ejecutar-legacy. Para reportes, usar
// bin/query-skus-9-columns.php.
if (!in_array('--ejecutar-legacy', $argv, true)) {
    fwrite(STDERR, "Script archivado: sobrescribe un reporte fijo de junio. Usa bin/query-skus-9-columns.php.\n"
        . "Si de verdad necesitas correrlo, agrega --ejecutar-legacy.\n");
    exit(1);
}

require_once __DIR__ . '/../../config/env.php';

loadEnv(__DIR__ . '/../../.env');

$appKey = trim((string) env('API_KEY', ''));
$appToken = trim((string) env('API_TOKEN', ''));
$baseUrl = 'https://cemacogt.myvtex.com';
$input = __DIR__ . '/../../batch_repisas_junio_exclusion.csv';
$output = __DIR__ . '/../../reporte_instalacion_repisas_9_columnas.csv';
$serviceTypeId = 37;
$serviceTypeName = 'Instalación de Repisa';

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

function requestJson(string $url, string $appKey, string $appToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            "X-VTEX-API-AppKey: $appKey",
            "X-VTEX-API-AppToken: $appToken",
        ],
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    $decoded = json_decode((string) $response, true);

    return [
        'ok' => $response !== false && $status >= 200 && $status < 300,
        'status' => $status,
        'error' => $error ?: null,
        'json' => is_array($decoded) ? $decoded : null,
        'body' => substr((string) $response, 0, 500),
    ];
}

function normalizeCsvText(string $raw): string
{
    if (mb_check_encoding($raw, 'UTF-8')) {
        return $raw;
    }

    $converted = iconv('ISO-8859-1', 'UTF-8//IGNORE', $raw);

    return $converted === false ? $raw : $converted;
}

function readSkusFromBatch(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("No se pudo leer $path");
    }

    $text = normalizeCsvText($raw);
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);

    fgetcsv($fh, 0, ';', '"', '');
    $skus = [];
    while (($row = fgetcsv($fh, 0, ';', '"', '')) !== false) {
        if (count($row) < 3) {
            continue;
        }
        $sku = trim((string) $row[2]);
        if ($sku !== '') {
            $skus[] = $sku;
        }
    }

    fclose($fh);

    return array_values(array_unique($skus));
}

function flattenCategories(array $nodes, array $path = [], array &$map = []): void
{
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }

        $id = (string) ($node['id'] ?? $node['Id'] ?? '');
        $name = (string) ($node['name'] ?? $node['Name'] ?? '');
        $nextPath = array_values(array_filter([...$path, $name]));

        if ($id !== '') {
            $map[$id] = implode(' > ', $nextPath);
        }

        $children = $node['children'] ?? $node['Children'] ?? [];
        if (is_array($children)) {
            flattenCategories($children, $nextPath, $map);
        }
    }
}

function categoryPathForSku(array $sku, array $categoryMap): string
{
    // Termina en la categoria hoja: el nombre del producto NO se agrega,
    // para eso ya existe la columna "Nombre del producto".
    $paths = $sku['CategoriesFullPath'] ?? [];
    if (!is_array($paths) || $paths === []) {
        return 'sin_categoria';
    }

    $bestLeaf = '';
    $bestScore = -1;
    foreach ($paths as $path) {
        if (!is_string($path)) {
            continue;
        }
        preg_match_all('/\d+/', $path, $matches);
        $ids = $matches[0] ?? [];
        if ($ids === []) {
            continue;
        }
        $score = count($ids);
        $leaf = (string) end($ids);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestLeaf = $leaf;
        }
    }

    return $categoryMap[$bestLeaf] ?? ($bestLeaf !== '' ? "categoria_$bestLeaf" : 'sin_categoria');
}

function availableInventory(array $inventory): string
{
    $balance = $inventory['balance'] ?? [];
    if (!is_array($balance)) {
        return '0';
    }

    $available = 0;
    foreach ($balance as $warehouse) {
        if (!is_array($warehouse)) {
            continue;
        }
        $total = (int) ($warehouse['totalQuantity'] ?? 0);
        $reserved = (int) ($warehouse['reservedQuantity'] ?? 0);
        $available += max(0, $total - $reserved);
    }

    return (string) $available;
}

function serviceData(array $sku, int $serviceTypeId, string $serviceTypeName): array
{
    // Extrae solo el servicio objetivo; no mezcla otros attachments que pueda tener el SKU.
    $services = $sku['Services'] ?? [];
    if (!is_array($services)) {
        return [false, '', '', ''];
    }

    foreach ($services as $service) {
        if (!is_array($service) || (int) ($service['ServiceTypeId'] ?? 0) !== $serviceTypeId) {
            continue;
        }

        $options = $service['Options'] ?? [];
        $optionList = is_array($options) && array_is_list($options) ? $options : (is_array($options) ? [$options] : []);
        $serviceSkus = [];
        foreach ($optionList as $option) {
            if (!is_array($option)) {
                continue;
            }
            $priceName = $option['PriceName'] ?? null;
            if (is_scalar($priceName) && trim((string) $priceName) !== '') {
                $serviceSkus[] = (string) $priceName;
            }
        }

        $attachments = $service['Attachments'] ?? [];
        $attachmentNames = [];
        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                if (!is_array($attachment)) {
                    continue;
                }
                $name = $attachment['Name'] ?? null;
                if (is_scalar($name) && trim((string) $name) !== '') {
                    $attachmentNames[] = (string) $name;
                }
            }
        }

        return [
            true,
            implode(' | ', array_values(array_unique($serviceSkus))),
            $serviceTypeName,
            implode(' | ', array_values(array_unique($attachmentNames))),
        ];
    }

    return [false, '', '', ''];
}

try {
    $skus = readSkusFromBatch($input);
    $categoryTree = requestJson("$baseUrl/api/catalog_system/pub/category/tree/10", $appKey, $appToken);
    if (!$categoryTree['ok'] || !is_array($categoryTree['json'])) {
        throw new RuntimeException('No se pudo consultar arbol de categorias.');
    }

    $categoryMap = [];
    flattenCategories($categoryTree['json'], [], $categoryMap);

    $file = fopen($output, 'w');
    if ($file === false) {
        throw new RuntimeException("No se pudo crear $output");
    }

    $headers = [
        'SKU',
        'productId',
        'Nombre del producto',
        'categoria de producto',
        'inventario disponible',
        'tiene_attachment',
        'sku de servicio',
        'Complejidad de attachment',
        'Complejidad de attachment asociado',
    ];
    fputcsv($file, $headers, ',', '"', '');

    $rows = 0;
    $withAttachment = 0;
    $withoutAttachment = 0;
    $errors = 0;

    foreach ($skus as $index => $skuId) {
        $skuResponse = requestJson("$baseUrl/api/catalog_system/pvt/sku/stockkeepingunitbyid/" . rawurlencode($skuId), $appKey, $appToken);
        if (!$skuResponse['ok'] || !is_array($skuResponse['json'])) {
            fputcsv($file, [$skuId, '', 'ERROR: no se pudo consultar SKU', '', '', 'no', '', '', ''], ',', '"', '');
            $errors++;
            $rows++;
            continue;
        }

        $sku = $skuResponse['json'];
        $productId = (string) ($sku['ProductId'] ?? '');
        $productName = (string) ($sku['ProductName'] ?? $sku['NameComplete'] ?? $sku['SkuName'] ?? 'sin_nombre');
        $categoryPath = categoryPathForSku($sku, $categoryMap);

        $inventoryResponse = requestJson("$baseUrl/api/logistics/pvt/inventory/skus/" . rawurlencode($skuId), $appKey, $appToken);
        $inventory = $inventoryResponse['ok'] && is_array($inventoryResponse['json']) ? availableInventory($inventoryResponse['json']) : '';

        [$hasAttachment, $serviceSku, $complexity, $associated] = serviceData($sku, $serviceTypeId, $serviceTypeName);
        if ($hasAttachment) {
            $withAttachment++;
        } else {
            $withoutAttachment++;
        }

        fputcsv($file, [
            $skuId,
            $productId,
            $productName,
            $categoryPath,
            $inventory,
            $hasAttachment ? 'si' : 'no',
            $serviceSku,
            $complexity,
            $associated,
        ], ',', '"', '');
        $rows++;

        if (($index + 1) % 25 === 0 || ($index + 1) === count($skus)) {
            echo 'Procesados: ' . ($index + 1) . '/' . count($skus) . "\n";
        }
    }

    fclose($file);

    echo json_encode([
        'archivo' => basename($output),
        'skus' => count($skus),
        'filas' => $rows,
        'con_attachment' => $withAttachment,
        'sin_attachment' => $withoutAttachment,
        'errores' => $errors,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    if (isset($file) && is_resource($file)) {
        fclose($file);
    }
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    exit(1);
}
