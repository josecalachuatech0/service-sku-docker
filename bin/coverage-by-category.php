<?php
declare(strict_types=1);

/*
 * Cobertura de servicios por categoria (CA04): SKUs configurados / SKUs elegibles, en total y
 * en cada nivel del arbol: departamento, categoria y subcategoria. La meta del 95% aplica a cada uno.
 *
 * Uso:
 * php bin/coverage-by-category.php --output=dashboard/cache/coverage.json
 * php bin/coverage-by-category.php --output=coverage.json --listado=listado_skuservice.json
 *
 * Solo lectura: nunca escribe en VTEX.
 *
 * Contexto para otra IA:
 * - Elegible: todo SKU del catalogo, activo o no, con o sin stock. Los productos sin SKU no
 *   cuentan, y Bombas Sumergibles (1645) queda fuera por estar mal categorizada (E18).
 * - Configurado: al menos una relacion SkuService activa cuyo type tenga value conocido
 *   (SERVICE_VALUES), con ese value y con el Name canonico del type (serviceDefaultName).
 *   Visita no tiene Name canonico: ahi basta el value. Solo cuentan los types de la lista oficial
 *   (isOfficialServiceType, desde el 2026-11-13).
 * - El arbol tiene 3 niveles (44 departamentos, 444 categorias, 1,817 subcategorias al 2026-10-30).
 *   Los SKUs salen de GetProductAndSkuIds por cada hoja (la subcategoria final, o la categoria o
 *   departamento que no tiene hijas); cada nivel de arriba es la suma de sus hijas, asi que los
 *   tres niveles cuadran entre si. Productos asignados directo a un nodo con hijas no se ven.
 * - Las relaciones salen de /api/catalog/pvt/skuservice (~164k, sin paginacion, 20 s a 2 min).
 *   --listado reutiliza un JSON ya descargado.
 */

ini_set('memory_limit', '2048M');
date_default_timezone_set('America/Guatemala');

require_once __DIR__ . '/../lib/bootstrap.php';

const COVERAGE_TARGET = 95;
const EXCLUDED_CATEGORIES = [1645 => 'Bombas Sumergibles'];
// Grupo de cada type con value conocido; los que no estan aqui son de instalacion.
const VISITA_TYPES = [6, 17, 24, 46, 70];
const ARMADO_TYPES = [3, 4, 5, 47];

$options = getopt('', ['output:', 'listado:']);
$output = optionValue($options, 'output');
$listadoPath = optionValue($options, 'listado');

if ($output === null) {
    fwrite(STDERR, "Uso: php bin/coverage-by-category.php --output=dashboard/cache/coverage.json [--listado=<json>]\n");
    exit(1);
}

$appKey = envString('API_KEY');
$appToken = envString('API_TOKEN');
$baseUrl = vtexBaseUrl();

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

function categorySkus(string $baseUrl, int $categoryId, string $appKey, string $appToken): array
{
    $skus = [];
    $from = 1;
    $total = null;
    do {
        $to = $from + 249;
        $response = vtexGetJson(buildProductsAndSkusUrl($baseUrl, $categoryId, $from, $to), $appKey, $appToken);
        if (isset($response['range']['total']) && is_numeric($response['range']['total'])) {
            $total = (int) $response['range']['total'];
        }
        foreach ($response['data'] ?? [] as $skuIds) {
            foreach (is_array($skuIds) ? $skuIds : [] as $skuId) {
                $skus[(string) $skuId] = true;
            }
        }
        $from = $to + 1;
    } while ($total !== null && $from <= $total);
    return $skus;
}

// Paso 1: relaciones configuradas correctamente, por SKU.
if ($listadoPath !== null) {
    echo "Leyendo listado desde $listadoPath...\n";
    $relations = is_file($listadoPath) ? json_decode((string) file_get_contents($listadoPath), true) : null;
    if (!is_array($relations)) {
        fwrite(STDERR, "No se pudo leer el listado $listadoPath\n");
        exit(1);
    }
} else {
    echo "Descargando /api/catalog/pvt/skuservice (puede tardar)...\n";
    $relations = vtexGetJson("$baseUrl/api/catalog/pvt/skuservice", $appKey, $appToken, 600);
}
echo "Relaciones en el catalogo: " . count($relations) . "\n";

$configured = [];
foreach ($relations as $rel) {
    $type = (int) ($rel['SkuServiceTypeId'] ?? 0);
    if (!isOfficialServiceType($type) || ($rel['IsActive'] ?? false) !== true || !isset(SERVICE_VALUES[$type])
        || (int) ($rel['SkuServiceValueId'] ?? 0) !== SERVICE_VALUES[$type]) {
        continue;
    }
    $group = in_array($type, VISITA_TYPES, true) ? 'visita' : (in_array($type, ARMADO_TYPES, true) ? 'armado' : 'instalacion');
    if ($group !== 'visita' && trim((string) ($rel['Name'] ?? '')) !== serviceDefaultName($type, $group)) {
        continue;
    }
    $configured[(string) ($rel['SkuId'] ?? '')] = true;
}
unset($relations);
echo "SKUs configurados en todo el catalogo: " . count($configured) . "\n";

// Paso 2: SKUs por categoria, de las hojas hacia arriba.
$tree = vtexGetJson("$baseUrl/api/catalog_system/pub/category/tree/3", $appKey, $appToken);

$excluded = [];
foreach (EXCLUDED_CATEGORIES as $id => $name) {
    $excluded += categorySkus($baseUrl, $id, $appKey, $appToken);
}
echo "SKUs excluidos (" . implode(', ', EXCLUDED_CATEGORIES) . "): " . count($excluded) . "\n";

// Devuelve [nodo con su cobertura y la de sus hijas, SKUs del nodo]. Una hoja se consulta en
// VTEX; un nodo con hijas suma los SKUs de ellas (sin duplicar, por si un SKU esta en dos).
$measure = static function (array $node) use (&$measure, $baseUrl, $appKey, $appToken, $configured, $excluded): array {
    $children = [];
    $skus = [];
    if ($node['children'] === []) {
        $skus = array_diff_key(categorySkus($baseUrl, (int) $node['id'], $appKey, $appToken), $excluded);
    }
    foreach ($node['children'] as $child) {
        [$c, $childSkus] = $measure($child);
        $children[] = $c;
        $skus += $childSkus;
    }
    return [['id' => (int) $node['id'], 'name' => $node['name'], 'eligible' => count($skus),
        'configured' => count(array_intersect_key($skus, $configured)), 'children' => $children], $skus];
};

$departments = [];
$allSkus = [];
foreach ($tree as $i => $dept) {
    [$d, $deptSkus] = $measure($dept);
    $departments[] = $d;
    $allSkus += $deptSkus;
    printf("[%d/%d] %s: %d de %d\n", $i + 1, count($tree), $dept['name'], $d['configured'], $d['eligible']);
}

$global = ['eligible' => count($allSkus), 'configured' => count(array_intersect_key($allSkus, $configured))];
$result = [
    'generatedAt' => date('c'),
    'target' => COVERAGE_TARGET,
    'excluded' => ['categories' => EXCLUDED_CATEGORIES, 'skus' => count($excluded)],
    'global' => $global,
    'departments' => $departments,
];

@mkdir(dirname($output), 0775, true);
file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Total: {$global['configured']} de {$global['eligible']} SKUs configurados.\n";
echo "Archivo generado: $output\n";
