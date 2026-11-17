<?php
declare(strict_types=1);

/*
 * Generador de reportes de 8 columnas para listas de SKUs.
 *
 * Uso tipico:
 * php bin/query-skus-8-columns.php --input=input/skus.txt --output=reporte.csv --concurrency=6
 *
 * Contexto para otra IA:
 * - Lee SKUs desde TXT, uno por linea.
 * - Consulta detalle del SKU, categorias, inventario y datos de attachments/services.
 * - Estructura de salida (8 columnas):
 *   SKU, productId, Nombre del producto, categoria de producto, inventario disponible,
 *   tiene_attachment, sku de servicio, Tipo de Servicio.
 * - Es la estructura de 9 columnas SIN la ultima ("Attachments Fields"). Esa columna
 *   mostraba Attachments[].Fields[].FieldName, en la practica casi siempre los campos
 *   del empaque de regalo ("De:", "Para:", "Mensaje: ") o el campo del attachment
 *   global Id 18 ("Servicio de armado"), ninguno de los dos util para el analisis.
 * - Los campos se siguen leyendo internamente porque cuentan para "tiene_attachment";
 *   lo unico que cambia es que ya no se escriben en el CSV.
 * - NO reemplaza a bin/query-skus-9-columns.php, que se conserva sin cambios.
 * - Si aparecen errores HTTP 429, reintentar solo esos SKUs con concurrencia baja.
 */

require_once __DIR__ . '/../lib/bootstrap.php';

function readSkuList(string $path): array
{
    // Los SKUs son identificadores; se conservan como strings para evitar perdidas de formato.
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("No se pudo leer $path");
    }

    $skus = [];
    foreach ($lines as $line) {
        $sku = trim(str_replace(["\r", '"'], '', $line));
        if ($sku !== '') {
            $skus[] = $sku;
        }
    }

    return array_values(array_unique($skus));
}

function flattenCategories(array $nodes, array $path = [], array &$categoryMap = []): void
{
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }

        $id = (string) ($node['id'] ?? $node['Id'] ?? '');
        $name = (string) ($node['name'] ?? $node['Name'] ?? '');
        $nextPath = array_values(array_filter([...$path, $name]));

        if ($id !== '') {
            $categoryMap[$id] = [
                'name' => $name,
                'path' => implode(' > ', $nextPath),
            ];
        }

        $children = $node['children'] ?? $node['Children'] ?? [];
        if (is_array($children)) {
            flattenCategories($children, $nextPath, $categoryMap);
        }
    }
}

function categoryPathForSku(array $sku, array $categoryMap): string
{
    // Elige la ruta de categoria mas profunda disponible. Termina en la categoria hoja:
    // el nombre del producto NO se agrega, para eso ya existe la columna "Nombre del producto".
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

    return $categoryMap[$bestLeaf]['path'] ?? ($bestLeaf !== '' ? "categoria_$bestLeaf" : 'sin_categoria');
}

function addScalarsFromFields(mixed $fields, array &$fieldNames): void
{
    if (!is_array($fields)) {
        return;
    }

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $name = $field['FieldName'] ?? $field['fieldName'] ?? $field['Name'] ?? $field['name'] ?? null;
        if (is_scalar($name)) {
            $fieldNames[] = (string) $name;
        }
    }
}

function addAttachmentNames(mixed $attachments, array &$names, array &$fields): void
{
    if (!is_array($attachments)) {
        return;
    }

    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        $name = $attachment['Name'] ?? $attachment['name'] ?? null;
        if (is_scalar($name)) {
            $names[] = (string) $name;
        }
        addScalarsFromFields($attachment['Fields'] ?? $attachment['fields'] ?? [], $fields);
    }
}

function collectAttachmentData(array $sku, ?array $serviceTypeFilter = null): array
{
    // Agrupa attachments directos y services para llenar las columnas de attachment del CSV.
    // Si $serviceTypeFilter viene definido, solo se toman en cuenta los Services cuyo
    // ServiceTypeId este en la lista (se ignoran attachments directos del SKU y otros service types).
    $attachmentNames = [];
    $serviceSkus = [];
    $serviceNames = [];
    $fieldNames = [];

    if ($serviceTypeFilter === null) {
        addAttachmentNames($sku['Attachments'] ?? $sku['attachments'] ?? [], $attachmentNames, $fieldNames);
    }

    $services = $sku['Services'] ?? $sku['services'] ?? [];
    if (is_array($services)) {
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }

            if ($serviceTypeFilter !== null) {
                $typeId = (int) ($service['ServiceTypeId'] ?? $service['serviceTypeId'] ?? 0);
                if (!in_array($typeId, $serviceTypeFilter, true)) {
                    continue;
                }
            }

            $serviceName = $service['Name'] ?? $service['name'] ?? $service['ProductName'] ?? $service['productName'] ?? null;
            if (is_scalar($serviceName)) {
                $attachmentNames[] = (string) $serviceName;
            }

            addAttachmentNames($service['Attachments'] ?? $service['attachments'] ?? [], $attachmentNames, $fieldNames);
            addScalarsFromFields($service['Fields'] ?? $service['fields'] ?? [], $fieldNames);

            $options = $service['Options'] ?? $service['options'] ?? [];
            $optionList = is_array($options) && array_is_list($options) ? $options : (is_array($options) ? [$options] : []);
            foreach ($optionList as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $priceName = $option['PriceName'] ?? $option['priceName'] ?? null;
                if (is_scalar($priceName)) {
                    $serviceSkus[] = (string) $priceName;
                }
                $productName = $option['ProductName'] ?? $option['productName'] ?? null;
                if (is_scalar($productName)) {
                    $serviceNames[] = (string) $productName;
                    $attachmentNames[] = (string) $productName;
                }
            }
        }
    }

    $attachmentNames = array_values(array_unique(array_filter($attachmentNames, fn ($v) => trim((string) $v) !== '')));
    $serviceSkus = array_values(array_unique(array_filter($serviceSkus, fn ($v) => trim((string) $v) !== '')));
    $serviceNames = array_values(array_unique(array_filter($serviceNames, fn ($v) => trim((string) $v) !== '')));
    $fieldNames = array_values(array_unique(array_filter($fieldNames, fn ($v) => trim((string) $v) !== '')));

    return [
        'has_attachment' => $attachmentNames !== [] || $serviceSkus !== [] || $serviceNames !== [] || $fieldNames !== [],
        'service_skus' => $serviceSkus,
        'complexities' => $serviceNames !== [] ? $serviceNames : $attachmentNames,
        'associated' => $fieldNames !== [] ? $fieldNames : $attachmentNames,
    ];
}

function fetchBatch(array $skuIds, callable $urlBuilder, string $appKey, string $appToken, int $concurrency, callable $parser): array
{
    // Consulta en paralelo por bloques para acelerar lotes grandes sin saturar demasiado VTEX.
    $results = [];
    $total = count($skuIds);
    $processed = 0;

    foreach (array_chunk($skuIds, $concurrency) as $chunk) {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($chunk as $skuId) {
            $url = $urlBuilder($skuId);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => vtexHeaders($appKey, $appToken),
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[(int) $ch] = [$ch, $skuId, $url];
        }

        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($handles as [$ch, $skuId, $url]) {
            $response = curl_multi_getcontent($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            $results[$skuId] = $parser((string) $response, (int) $statusCode, $error, $url);
            curl_multi_remove_handle($multi, $ch);
            $processed++;
        }
        curl_multi_close($multi);
        echo "Consultados: $processed/$total\n";
    }

    return $results;
}

function parseInventory(string $response, int $statusCode, string $error, string $url): array
{
    if ($statusCode < 200 || $statusCode >= 300) {
        return ['ok' => false, 'available' => '', 'error' => $statusCode === 0 ? "HTTP 0 / $error" : "HTTP $statusCode"];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'available' => '', 'error' => 'JSON invalido'];
    }

    $available = 0;
    $balance = $decoded['balance'] ?? [];
    if (is_array($balance)) {
        foreach ($balance as $warehouse) {
            if (!is_array($warehouse)) {
                continue;
            }
            $available += max(0, (int) ($warehouse['totalQuantity'] ?? 0) - (int) ($warehouse['reservedQuantity'] ?? 0));
        }
    }

    return ['ok' => true, 'available' => (string) $available, 'error' => ''];
}

function parseSkuDetail(string $response, int $statusCode, string $error, string $url): array
{
    if ($statusCode < 200 || $statusCode >= 300) {
        return ['__error' => $statusCode === 0 ? "HTTP 0 / $error" : "HTTP $statusCode", '__url' => $url];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['__error' => 'JSON invalido', '__url' => $url];
    }

    return $decoded;
}

$options = getopt('', ['input:', 'output:', 'concurrency::', 'servicetype::']);
$input = optionValue($options, 'input');
$output = optionValue($options, 'output');
$concurrency = (int) (optionValue($options, 'concurrency') ?? '8');
$concurrency = max(1, min($concurrency, 20));
$serviceTypeOption = optionValue($options, 'servicetype');
$serviceTypeFilter = $serviceTypeOption !== null
    ? array_map('intval', array_filter(array_map('trim', explode(',', $serviceTypeOption)), fn ($v) => $v !== ''))
    : null;

if ($input === null || $output === null) {
    fwrite(STDERR, "Uso: php bin/query-skus-9-columns.php --input=archivo.txt --output=archivo.csv\n");
    exit(1);
}

$appKey = envString('API_KEY');
$appToken = envString('API_TOKEN');
$baseUrl = vtexBaseUrl();

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

$skuIds = readSkuList($input);
if ($skuIds === []) {
    fwrite(STDERR, "No hay SKUs para consultar.\n");
    exit(1);
}

$categoryMap = [];
$categoryTree = vtexGetJson(rtrim($baseUrl, '/') . '/api/catalog_system/pub/category/tree/10', $appKey, $appToken, 60);
flattenCategories($categoryTree, [], $categoryMap);

$skuDetails = fetchBatch(
    $skuIds,
    fn (string $skuId): string => rtrim($baseUrl, '/') . '/api/catalog_system/pvt/sku/stockkeepingunitbyid/' . rawurlencode($skuId),
    $appKey,
    $appToken,
    $concurrency,
    'parseSkuDetail'
);

$inventories = fetchBatch(
    $skuIds,
    fn (string $skuId): string => rtrim($baseUrl, '/') . '/api/logistics/pvt/inventory/skus/' . rawurlencode($skuId),
    $appKey,
    $appToken,
    $concurrency,
    'parseInventory'
);

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
    'Tipo de Servicio',
];
fputcsv($file, $headers, ',', '"', '');

$withAttachment = 0;
$withoutAttachment = 0;
$errors = 0;

foreach ($skuIds as $skuId) {
    $sku = $skuDetails[$skuId] ?? ['__error' => 'sin respuesta'];
    if (isset($sku['__error'])) {
        $errors++;
        fputcsv($file, [$skuId, '', 'ERROR: ' . $sku['__error'], '', '', 'no', '', ''], ',', '"', '');
        continue;
    }

    $productId = (string) ($sku['ProductId'] ?? $sku['productId'] ?? '');
    $productName = (string) ($sku['ProductName'] ?? $sku['NameComplete'] ?? $sku['SkuName'] ?? 'sin_nombre');
    $categoryPath = categoryPathForSku($sku, $categoryMap);
    $inventory = $inventories[$skuId]['available'] ?? '';
    $attachment = collectAttachmentData($sku, $serviceTypeFilter);

    if ($attachment['has_attachment']) {
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
        $attachment['has_attachment'] ? 'si' : 'no',
        implode(' | ', $attachment['service_skus']),
        implode(' | ', $attachment['complexities']),
    ], ',', '"', '');
}

fclose($file);

echo "Archivo generado: $output\n";
echo "SKUs solicitados: " . count($skuIds) . "\n";
echo "Con attachment: $withAttachment\n";
echo "Sin attachment: $withoutAttachment\n";
echo "Errores: $errors\n";
