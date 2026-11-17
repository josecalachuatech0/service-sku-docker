<?php

declare(strict_types=1);

/**
 * Audita el catalogo buscando PRODUCTOS SIN SKU y arma un reporte por categoria.
 *
 * - Solo lectura. No escribe nada en VTEX.
 * - Barre por categoria RAIZ: GetProductAndSkuIds ya incluye las subcategorias,
 *   asi que un barrido por departamento cubre toda su rama.
 * - Un producto sin SKU es una entrada de 'data' cuyo arreglo de SKUs viene vacio.
 * - El detalle (nombre y categoria hoja) sale de /api/catalog/pvt/product/{id}.
 *
 * Uso:
 *   php bin/audit-productos-sin-sku.php --output-dir=AGOSTO/semana_4 --max-categorias=10
 */

require_once __DIR__ . '/../lib/bootstrap.php';

ini_set('memory_limit', '2048M');

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

function parseProductDetail(string $response, int $statusCode, string $error, string $url): array
{
    if ($statusCode < 200 || $statusCode >= 300) {
        return ['ok' => false, 'error' => $statusCode === 0 ? "HTTP 0 / $error" : "HTTP $statusCode"];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'JSON invalido'];
    }

    return [
        'ok' => true,
        'name' => (string) ($decoded['Name'] ?? ''),
        'categoryId' => (string) ($decoded['CategoryId'] ?? ''),
        'departmentId' => (string) ($decoded['DepartmentId'] ?? ''),
        'isActive' => !empty($decoded['IsActive']),
        'isVisible' => !empty($decoded['IsVisible']),
    ];
}

function fetchBatch(array $ids, callable $urlBuilder, string $appKey, string $appToken, int $concurrency, callable $parser): array
{
    $results = [];
    $total = count($ids);
    $processed = 0;

    foreach (array_chunk($ids, $concurrency) as $chunk) {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($chunk as $id) {
            $url = $urlBuilder((string) $id);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => vtexHeaders($appKey, $appToken),
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[(int) $ch] = [$ch, (string) $id, $url];
        }

        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($handles as [$ch, $id, $url]) {
            $response = curl_multi_getcontent($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            $results[$id] = $parser((string) $response, (int) $statusCode, $error, $url);
            curl_multi_remove_handle($multi, $ch);
            $processed++;
        }
        curl_multi_close($multi);
        echo "  detalle de productos: $processed/$total\n";
    }

    return $results;
}

function slugify(string $text): string
{
    // iconv//TRANSLIT deja residuos ("Sabanas" -> "S'abanas"), asi que se mapea a mano.
    $acentos = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
    ];
    $ascii = strtr($text, $acentos);
    $ascii = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $ascii) ?? '');
    return trim($ascii, '_') ?: 'sin_nombre';
}

// --- Parametros ---------------------------------------------------------

$options = getopt('', ['output-dir:', 'max-categorias:', 'concurrency:']);
$outputDir = optionValue($options, 'output-dir') ?? 'AGOSTO/semana_4';
$maxCategorias = (int) (optionValue($options, 'max-categorias') ?? '0');
$concurrency = max(1, min(20, (int) (optionValue($options, 'concurrency') ?? '6')));

$appKey = envString('API_KEY');
$appToken = envString('API_TOKEN');
$baseUrl = vtexBaseUrl();
$pageSize = 250;

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "No se pudo crear el directorio $outputDir\n");
    exit(1);
}

// --- Paso 1: arbol de categorias ---------------------------------------

echo "Paso 1 — descargando el arbol de categorias...\n";
$tree = vtexGetJson(rtrim($baseUrl, '/') . '/api/catalog_system/pub/category/tree/10', $appKey, $appToken, 120);

$categoryMap = [];
flattenCategories($tree, [], $categoryMap);

$roots = [];
foreach ($tree as $node) {
    $id = (string) ($node['id'] ?? $node['Id'] ?? '');
    $name = (string) ($node['name'] ?? $node['Name'] ?? '');
    if ($id !== '') {
        $roots[] = ['id' => $id, 'name' => $name];
    }
}

$totalRoots = count($roots);
if ($maxCategorias > 0) {
    $roots = array_slice($roots, 0, $maxCategorias);
}

echo "  categorias en el arbol: " . count($categoryMap) . "\n";
echo "  departamentos raiz: $totalRoots — se barren " . count($roots) . "\n\n";

// --- Paso 2: barrido por departamento ----------------------------------

echo "Paso 2 — barriendo productos por departamento raiz...\n";

$sinSku = [];          // productId => departamento raiz donde aparecio
$productosVistos = [];
$resumenRaices = [];

foreach ($roots as $root) {
    $from = 1;
    $total = null;
    $productosRaiz = 0;
    $sinSkuRaiz = 0;

    do {
        $to = $from + $pageSize - 1;
        $response = vtexGetJson(buildProductsAndSkusUrl($baseUrl, $root['id'], $from, $to), $appKey, $appToken, 120);

        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new RuntimeException("Respuesta sin 'data' para la categoria {$root['id']}");
        }

        if (isset($response['range']['total']) && is_numeric($response['range']['total'])) {
            $total = (int) $response['range']['total'];
        }

        foreach ($response['data'] as $productId => $skuIds) {
            $productId = (string) $productId;
            $productosRaiz++;
            $productosVistos[$productId] = true;

            if (!is_array($skuIds) || $skuIds === []) {
                $sinSkuRaiz++;
                if (!isset($sinSku[$productId])) {
                    $sinSku[$productId] = $root['name'];
                }
            }
        }

        $from = $to + 1;
    } while ($total !== null && $from <= $total);

    $resumenRaices[] = [
        'id' => $root['id'],
        'name' => $root['name'],
        'total' => $total ?? 0,
        'productos' => $productosRaiz,
        'sin_sku' => $sinSkuRaiz,
    ];

    printf("  %-40s total=%-6d sin SKU=%d\n", mb_strimwidth($root['name'], 0, 40), $total ?? 0, $sinSkuRaiz);
}

$idsSinSku = array_keys($sinSku);
sort($idsSinSku, SORT_NUMERIC);

echo "\n  productos distintos vistos: " . count($productosVistos) . "\n";
echo "  productos SIN SKU: " . count($idsSinSku) . "\n\n";

if ($idsSinSku === []) {
    echo "No se encontraron productos sin SKU en el alcance barrido.\n";
    exit(0);
}

// --- Paso 3: detalle de cada producto sin SKU --------------------------

echo "Paso 3 — consultando el detalle de los productos sin SKU...\n";

$detalles = fetchBatch(
    $idsSinSku,
    fn (string $id): string => rtrim($baseUrl, '/') . "/api/catalog/pvt/product/$id",
    $appKey,
    $appToken,
    $concurrency,
    'parseProductDetail'
);

// --- Paso 4: agrupar por categoria y escribir --------------------------

echo "\nPaso 4 — agrupando por categoria y escribiendo reportes...\n";

$porCategoria = [];
$errores = 0;

foreach ($idsSinSku as $productId) {
    $detalle = $detalles[$productId] ?? ['ok' => false, 'error' => 'sin respuesta'];

    if (empty($detalle['ok'])) {
        $errores++;
        $categoryId = 'sin_categoria';
        $fila = [
            'productId' => $productId,
            'nombre' => 'ERROR: ' . ($detalle['error'] ?? 'desconocido'),
            'categoria' => 'sin_categoria',
            'activo' => '',
            'visible' => '',
            'raiz' => $sinSku[$productId],
        ];
    } else {
        $categoryId = $detalle['categoryId'] !== '' ? $detalle['categoryId'] : 'sin_categoria';
        $fila = [
            'productId' => $productId,
            'nombre' => $detalle['name'],
            'categoria' => $categoryMap[$categoryId]['path'] ?? 'sin_categoria',
            'activo' => $detalle['isActive'] ? 'si' : 'no',
            'visible' => $detalle['isVisible'] ? 'si' : 'no',
            'raiz' => $sinSku[$productId],
        ];
    }

    $porCategoria[$categoryId][] = $fila;
}

ksort($porCategoria, SORT_NATURAL);

// 4a. Un CSV por categoria, con la estructura de 8 columnas.
$cabecera8 = [
    'SKU',
    'productId',
    'Nombre del producto',
    'categoria de producto',
    'inventario disponible',
    'tiene_attachment',
    'sku de servicio',
    'Tipo de Servicio',
];

$indice = [];

foreach ($porCategoria as $categoryId => $filas) {
    $nombreCat = $categoryMap[$categoryId]['name'] ?? 'sin_categoria';
    $rutaCat = $categoryMap[$categoryId]['path'] ?? 'sin_categoria';
    $archivo = rtrim($outputDir, '/') . "/categoria_{$categoryId}_" . slugify($nombreCat) . '.csv';

    $fh = fopen($archivo, 'w');
    if ($fh === false) {
        throw new RuntimeException("No se pudo crear $archivo");
    }
    fputcsv($fh, $cabecera8, ',', '"', '');
    foreach ($filas as $fila) {
        // Columnas 1 y 5-8 van vacias: son datos que viven en el SKU, y aqui no hay SKU.
        fputcsv($fh, ['', $fila['productId'], $fila['nombre'], $fila['categoria'], '', '', '', ''], ',', '"', '');
    }
    fclose($fh);

    $indice[] = [
        'categoria_id' => $categoryId,
        'categoria' => $nombreCat,
        'breadcrumb' => $rutaCat,
        'productos_sin_sku' => count($filas),
        'archivo' => basename($archivo),
    ];
}

// 4b. Indice.
usort($indice, fn (array $a, array $b): int => $b['productos_sin_sku'] <=> $a['productos_sin_sku']);

$fh = fopen(rtrim($outputDir, '/') . '/_indice.csv', 'w');
fputcsv($fh, ['categoria_id', 'categoria', 'breadcrumb', 'productos_sin_sku', 'archivo'], ',', '"', '');
foreach ($indice as $fila) {
    fputcsv($fh, array_values($fila), ',', '"', '');
}
fclose($fh);

// 4c. Consolidado con las columnas de diagnostico que no caben en las 8.
$fh = fopen(rtrim($outputDir, '/') . '/_consolidado.csv', 'w');
fputcsv($fh, ['productId', 'Nombre del producto', 'categoria_id', 'categoria de producto', 'activo', 'visible', 'departamento_raiz'], ',', '"', '');
foreach ($porCategoria as $categoryId => $filas) {
    foreach ($filas as $fila) {
        fputcsv($fh, [
            $fila['productId'],
            $fila['nombre'],
            $categoryId,
            $fila['categoria'],
            $fila['activo'],
            $fila['visible'],
            $fila['raiz'],
        ], ',', '"', '');
    }
}
fclose($fh);

// 4d. Resumen del barrido, para poder auditar el alcance despues.
$fh = fopen(rtrim($outputDir, '/') . '/_resumen_barrido.csv', 'w');
fputcsv($fh, ['categoria_raiz_id', 'departamento', 'productos_segun_range_total', 'productos_recorridos', 'productos_sin_sku'], ',', '"', '');
foreach ($resumenRaices as $fila) {
    fputcsv($fh, array_values($fila), ',', '"', '');
}
fclose($fh);

// --- Cierre -------------------------------------------------------------

$activos = 0;
$inactivos = 0;
foreach ($porCategoria as $filas) {
    foreach ($filas as $fila) {
        if ($fila['activo'] === 'si') {
            $activos++;
        } elseif ($fila['activo'] === 'no') {
            $inactivos++;
        }
    }
}

echo "\n";
echo "Departamentos barridos:      " . count($roots) . " de $totalRoots\n";
echo "Productos distintos vistos:  " . count($productosVistos) . "\n";
echo "Productos SIN SKU:           " . count($idsSinSku) . "\n";
echo "  activos:                   $activos\n";
echo "  inactivos:                 $inactivos\n";
echo "Categorias afectadas:        " . count($porCategoria) . "\n";
if ($errores > 0) {
    echo "Productos con error de detalle: $errores\n";
}
echo "Archivos escritos en:        $outputDir\n";
