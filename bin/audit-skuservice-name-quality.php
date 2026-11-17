<?php
declare(strict_types=1);

/*
 * Audita la calidad del campo Name (y Text) de todas las relaciones SkuService del catalogo.
 *
 * Uso:
 * php bin/audit-skuservice-name-quality.php --output=auditoria_skuservice_names.csv
 * php bin/audit-skuservice-name-quality.php --output=reporte.csv --sin-precios
 *
 * SOLO LECTURA: este script nunca hace POST, PUT ni DELETE. Solo GET.
 *
 * Contexto para otra IA:
 * - Baja UNA sola vez /api/catalog/pvt/skuservice (~164k relaciones) y clasifica en memoria.
 *   No consulta SKU por SKU: eso evita el problema de falsos negativos documentado en la
 *   bitacora (el endpoint stockkeepingunitbyid a veces devuelve el bloque Services vacio).
 * - Hallazgos que detecta (no son excluyentes entre si; una relacion puede tener varios):
 *     stray_quote            Name empieza con comilla doble y al quitarla queda igual a Text.
 *                            Unico caso considerado seguro para auto-corregir con PUT.
 *     stray_quote_dudoso     Name empieza con comilla pero al quitarla NO coincide con Text.
 *                            Se reporta, no se auto-corrige.
 *     stray_quote_duplicado  Ademas de la comilla, el mismo SKU tiene otra relacion con el
 *                            mismo nombre normalizado. Un PUT dejaria dos registros identicos:
 *                            aca el remedio es DELETE, no PUT.
 *     nombre_duplicado       El mismo SKU tiene >=2 relaciones ACTIVAS de distinto
 *                            SkuServiceTypeId cuyo Name normalizado coincide. El cliente ve
 *                            dos opciones indistinguibles en la ficha del producto.
 *     placeholder_suspect    Name o Text contienen TITULO / TEXTO / DESCRIPCION. Nunca se
 *                            auto-corrige: hubo un caso (SKU 1047307) que era una prueba
 *                            intencional, no un bug.
 * - Normalizacion para comparar nombres: trim, quitar comilla inicial, minusculas,
 *   colapsar espacios internos.
 * - Los precios y el SKU de servicio se resuelven con /api/catalog/pvt/skuservicevalue/{id}
 *   SOLO para las relaciones marcadas como nombre_duplicado (son pocas). Bajar todos los
 *   values no es viable: los planes GEX generan uno por SKU.
 */

ini_set('memory_limit', '2048M');

// Valor que debe tener SkuService.Name en los service types de instalacion que se auditen.
const CANONICAL_NAME = 'Servicio de Instalación';

/*
 * Excepciones aprobadas por el usuario (2026-08-04): estas relaciones NO se corrigen nunca,
 * aunque su Name se desvie del canonico.
 *
 * 297864 / 297865 pertenecen al mismo SKU (983865) y ofrecen instalacion de bracket de TV
 * sobre dos superficies distintas. El sufijo descriptivo ("tabla yeso" / "pared de concreto")
 * es lo unico que le permite al cliente distinguir una opcion de la otra: renombrarlas al
 * canonico dejaria dos opciones identicas con precios distintos.
 *
 * La lista es un cinturon de seguridad ADEMAS de la deteccion heuristica (sufijo_descriptivo
 * y colision_tras_fix): si alguien cambia la heuristica, estas dos siguen protegidas.
 */
// La lista vive en lib/reglas.php (PROTECTED_SKU_SERVICE_IDS).

require_once __DIR__ . '/../lib/bootstrap.php';

/**
 * Normaliza un Name para poder compararlo entre relaciones distintas.
 * Quita la comilla suelta inicial, colapsa espacios y pasa a minusculas.
 */
function normalizeName(string $value): string
{
    $value = trim($value);
    $value = ltrim($value, '"');
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return mb_strtolower($value, 'UTF-8');
}

function hasStrayQuote(string $name): bool
{
    return str_starts_with(trim($name), '"');
}

function stripStrayQuote(string $name): string
{
    return trim(ltrim(trim($name), '"'));
}

/**
 * Quita tildes y diacriticos para poder comparar "instalacion" contra "Instalación".
 */
function stripAccents(string $value): string
{
    $map = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
    ];

    return strtr($value, $map);
}

/**
 * Clasifica POR QUE un valor se desvia del canonico. Separa lo cosmetico (espacios,
 * mayusculas, tildes, comilla suelta) — seguro de auto-corregir — de lo que exige
 * decision humana (sufijo descriptivo intencional, vacio, u otro servicio distinto).
 */
function classifyDeviation(string $value): string
{
    if ($value === '') {
        return 'vacio';
    }

    if (trim($value) === CANONICAL_NAME) {
        return 'espacios';
    }

    if (stripStrayQuote($value) === CANONICAL_NAME) {
        return 'comilla_suelta';
    }

    // Mismo texto salvo mayusculas, tildes o espacios internos.
    if (stripAccents(normalizeName($value)) === stripAccents(normalizeName(CANONICAL_NAME))) {
        return 'tildes_o_mayusculas';
    }

    // Empieza con el canonico y le agrega una aclaracion (ej. "... tabla yeso").
    // Es intencional: el sufijo distingue variantes del servicio. Nunca auto-corregir.
    $normalizedValue = stripAccents(normalizeName($value));
    $normalizedCanonical = stripAccents(normalizeName(CANONICAL_NAME));
    if (str_starts_with($normalizedValue, $normalizedCanonical . ' ')) {
        return 'sufijo_descriptivo';
    }

    return 'valor_distinto';
}

/**
 * Solo estos motivos se consideran seguros de corregir sin intervencion humana.
 */
function isAutoFixable(string $motivo): bool
{
    return in_array($motivo, ['espacios', 'comilla_suelta', 'tildes_o_mayusculas'], true);
}

function looksLikePlaceholder(string $name, string $text): bool
{
    foreach (['TITULO', 'TEXTO', 'DESCRIPCION'] as $marker) {
        if (stripos($name, $marker) !== false || stripos($text, $marker) !== false) {
            return true;
        }
    }

    return false;
}

$options = getopt('', ['output:', 'servicetypes:', 'limit-skus:', 'sin-precios']);
$output = optionValue($options, 'output');
$fetchPrices = !array_key_exists('sin-precios', $options);
$limitSkus = (int) (optionValue($options, 'limit-skus') ?? '0');

$typeFilterRaw = optionValue($options, 'servicetypes');
$typeFilter = null;
if ($typeFilterRaw !== null) {
    $typeFilter = [];
    foreach (explode(',', $typeFilterRaw) as $piece) {
        $piece = (int) trim($piece);
        if ($piece > 0) {
            $typeFilter[] = $piece;
        }
    }
    if ($typeFilter === []) {
        $typeFilter = null;
    }
}

if ($output === null) {
    fwrite(STDERR, "Uso: php bin/audit-skuservice-name-quality.php --output=auditoria.csv [--servicetypes=7,9,11] [--sin-precios]\n");
    exit(1);
}

$appKey = envString('API_KEY');
$appToken = envString('API_TOKEN');
$baseUrl = vtexBaseUrl();

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

// Paso 1: bajar todas las relaciones SkuService de una sola vez.
echo "Descargando /api/catalog/pvt/skuservice (puede tardar)...\n";
$relations = vtexGetJson(rtrim($baseUrl, '/') . '/api/catalog/pvt/skuservice', $appKey, $appToken, 300);
echo "Relaciones recibidas: " . count($relations) . "\n";

// Paso 2: indexar por SKU para poder detectar nombres repetidos dentro del mismo SKU.
$bySku = [];
$clean = 0;

foreach ($relations as $relation) {
    if (!is_array($relation)) {
        continue;
    }

    $skuId = (string) ($relation['SkuId'] ?? '');
    if ($skuId === '') {
        continue;
    }

    $bySku[$skuId][] = [
        'id' => (string) ($relation['Id'] ?? ''),
        'type_id' => (int) ($relation['SkuServiceTypeId'] ?? 0),
        'value_id' => (int) ($relation['SkuServiceValueId'] ?? 0),
        'sku_id' => $skuId,
        'name' => (string) ($relation['Name'] ?? ''),
        'text' => (string) ($relation['Text'] ?? ''),
        'is_active' => ($relation['IsActive'] ?? false) === true,
        'norm' => normalizeName((string) ($relation['Name'] ?? '')),
    ];
}

unset($relations);
echo "SKUs distintos con relaciones: " . count($bySku) . "\n";

// Modo prueba: recortar a los primeros N SKUs. Ojo, el recorte es sobre el analisis,
// no sobre la descarga: /api/catalog/pvt/skuservice no acepta paginacion.
if ($limitSkus > 0 && count($bySku) > $limitSkus) {
    $bySku = array_slice($bySku, 0, $limitSkus, true);
    echo "MODO PRUEBA: analizando solo los primeros $limitSkus SKUs.\n";
}

// Paso 3: clasificar.
//
// Importante: aunque haya filtro de service types, se recorren TODAS las relaciones de cada
// SKU. El filtro decide que se reporta, no que se mira: para detectar un nombre duplicado
// hay que poder ver la relacion de otro type que colisiona (ej. un Type 13 que se llama
// igual que un Type 3 del mismo SKU).
$findings = [];
$counts = [
    'name_no_canonico' => 0,
    'text_no_canonico' => 0,
    'colision_tras_fix' => 0,
    'placeholder_suspect' => 0,
    'excepcion_aprobada' => 0,
    'auto_corregibles' => 0,
];

// Frecuencias de Name y Text por service type (solo de los types pedidos).
$nameFrequency = [];
$textFrequency = [];
$relationsPerType = [];

foreach ($bySku as $skuId => $rows) {
    // Nombres normalizados de las relaciones ACTIVAS, agrupados por type.
    $activeByNorm = [];
    foreach ($rows as $index => $row) {
        if ($row['is_active'] && $row['norm'] !== '') {
            $activeByNorm[$row['norm']][] = $index;
        }
    }

    foreach ($rows as $index => $row) {
        // Fuera del filtro: la relacion sigue sirviendo como contexto para detectar
        // colisiones de nombre, pero no se reporta ni entra en las frecuencias.
        if ($typeFilter !== null && !in_array($row['type_id'], $typeFilter, true)) {
            continue;
        }

        $relationsPerType[$row['type_id']] = ($relationsPerType[$row['type_id']] ?? 0) + 1;
        $nameFrequency[$row['type_id']][$row['name']] = ($nameFrequency[$row['type_id']][$row['name']] ?? 0) + 1;
        $textFrequency[$row['type_id']][$row['text']] = ($textFrequency[$row['type_id']][$row['text']] ?? 0) + 1;

        $hallazgos = [];
        $accion = '';
        $autoFixable = false;

        // --- Regla principal: Name y Text deben ser exactamente el canonico ---
        $nameMotivo = $row['name'] === CANONICAL_NAME ? null : classifyDeviation($row['name']);
        $textMotivo = $row['text'] === CANONICAL_NAME ? null : classifyDeviation($row['text']);

        if ($nameMotivo !== null) {
            $hallazgos[] = 'name_no_canonico:' . $nameMotivo;
            $counts['name_no_canonico']++;
            $counts['motivo_' . $nameMotivo] = ($counts['motivo_' . $nameMotivo] ?? 0) + 1;
        }

        if ($textMotivo !== null) {
            $hallazgos[] = 'text_no_canonico:' . $textMotivo;
            $counts['text_no_canonico']++;
        }

        if ($nameMotivo !== null || $textMotivo !== null) {
            // Auto-corregible solo si TODOS los campos desviados lo son.
            $autoFixable = ($nameMotivo === null || isAutoFixable($nameMotivo))
                && ($textMotivo === null || isAutoFixable($textMotivo));

            $campos = [];
            if ($nameMotivo !== null) {
                $campos[] = 'Name';
            }
            if ($textMotivo !== null) {
                $campos[] = 'Text';
            }

            $accion = $autoFixable
                ? 'PUT ' . implode(' + ', $campos) . ' => ' . CANONICAL_NAME
                : 'REVISAR: ' . implode('/', array_filter([$nameMotivo, $textMotivo]));
        }

        // --- Riesgo: si dos relaciones del MISMO SKU caen dentro de los types a corregir,
        //     despues del fix las dos se llamarian igual y el cliente veria opciones
        //     indistinguibles. Hay que revisarlas antes de aplicar. ---
        if ($row['is_active'] && $typeFilter !== null) {
            $hermanosEnFiltro = [];
            foreach ($rows as $i => $other) {
                if ($i === $index || !$other['is_active']) {
                    continue;
                }
                if (in_array($other['type_id'], $typeFilter, true)) {
                    $hermanosEnFiltro[] = $other['type_id'];
                }
            }

            if ($hermanosEnFiltro !== []) {
                $hallazgos[] = 'colision_tras_fix';
                // Bloqueante: aunque el nombre sea cosmeticamente corregible, renombrar
                // dejaria dos relaciones del mismo SKU con nombre identico.
                $autoFixable = false;
                $accion = 'BLOQUEADO: el SKU tiene otra relacion en los types a corregir ('
                    . implode('/', array_unique($hermanosEnFiltro)) . '); tras el fix ambas se llamarian igual';
                $counts['colision_tras_fix']++;
            }
        }

        // --- Placeholder de plantilla ---
        if (looksLikePlaceholder($row['name'], $row['text'])) {
            $hallazgos[] = 'placeholder_suspect';
            $autoFixable = false;
            $accion = 'BLOQUEADO: revisar con el usuario (puede ser una prueba intencional)';
            $counts['placeholder_suspect']++;
        }

        // --- Excepcion aprobada: gana sobre cualquier otra clasificacion ---
        if (isProtectedSkuServiceId($row['id'])) {
            $hallazgos[] = 'excepcion_aprobada';
            $autoFixable = false;
            $accion = 'EXCEPCION APROBADA (2026-08-04): no corregir, el sufijo distingue variantes del servicio';
            $counts['excepcion_aprobada']++;
        }

        if ($autoFixable) {
            $counts['auto_corregibles']++;
        }

        if ($hallazgos === []) {
            $clean++;
            continue;
        }

        $findings[] = [
            'sku_service_id' => $row['id'],
            'sku_id' => $row['sku_id'],
            'type_id' => $row['type_id'],
            'value_id' => $row['value_id'],
            'is_active' => $row['is_active'] ? 'si' : 'no',
            'name' => $row['name'],
            'text' => $row['text'],
            'auto_corregible' => $autoFixable ? 'si' : 'no',
            'hallazgos' => implode(' | ', $hallazgos),
            'accion_sugerida' => $accion,
            'sku_servicio' => '',
            'precio' => '',
        ];
    }
}

// Paso 4: resolver SKU de servicio y precio, solo para las filas marcadas.
if ($fetchPrices) {
    $valueIds = [];
    foreach ($findings as $finding) {
        if ($finding['value_id'] > 0) {
            $valueIds[$finding['value_id']] = true;
        }
    }

    $valueIds = array_keys($valueIds);
    echo "Resolviendo " . count($valueIds) . " SkuServiceValue para precio y SKU de servicio...\n";

    $valueCache = [];
    foreach ($valueIds as $position => $valueId) {
        try {
            $value = vtexGetJson(rtrim($baseUrl, '/') . "/api/catalog/pvt/skuservicevalue/$valueId", $appKey, $appToken, 300);
            $valueCache[$valueId] = [
                'sku_servicio' => (string) ($value['Name'] ?? ''),
                'precio' => (string) ($value['Value'] ?? $value['Cost'] ?? ''),
            ];
        } catch (Throwable $exception) {
            $valueCache[$valueId] = ['sku_servicio' => 'ERROR', 'precio' => ''];
            fwrite(STDERR, "  No se pudo leer skuservicevalue/$valueId: " . $exception->getMessage() . "\n");
        }

        if (($position + 1) % 25 === 0) {
            echo "  " . ($position + 1) . "/" . count($valueIds) . "\n";
        }
    }

    foreach ($findings as &$finding) {
        $cached = $valueCache[$finding['value_id']] ?? null;
        if ($cached !== null) {
            $finding['sku_servicio'] = $cached['sku_servicio'];
            $finding['precio'] = $cached['precio'];
        }
    }
    unset($finding);
}

// Paso 5: escribir el CSV.
$file = fopen($output, 'wb');
if ($file === false) {
    fwrite(STDERR, "No se pudo escribir $output\n");
    exit(1);
}

fputcsv($file, [
    'sku_service_id',
    'sku_id',
    'type_id',
    'value_id',
    'is_active',
    'name',
    'text',
    'sku de servicio',
    'precio',
    'auto_corregible',
    'hallazgos',
    'accion_sugerida',
], ',', '"', '');

// Agrupar por SKU para que los casos de nombre duplicado queden juntos y sean legibles.
usort($findings, static function (array $a, array $b): int {
    return [$a['sku_id'], $a['type_id']] <=> [$b['sku_id'], $b['type_id']];
});

foreach ($findings as $finding) {
    fputcsv($file, [
        $finding['sku_service_id'],
        $finding['sku_id'],
        $finding['type_id'],
        $finding['value_id'],
        $finding['is_active'],
        $finding['name'],
        $finding['text'],
        $finding['sku_servicio'],
        $finding['precio'],
        $finding['auto_corregible'],
        $finding['hallazgos'],
        $finding['accion_sugerida'],
    ], ',', '"', '');
}

fclose($file);

// Paso 6: resumen.
$affectedSkus = [];
foreach ($findings as $finding) {
    $affectedSkus[$finding['sku_id']] = true;
}

// Tabla de frecuencias: que valores distintos de Name y Text aparecen en cada type.
$frequencyOutput = preg_replace('/\.csv$/i', '', $output) . '_frecuencias.csv';
$frequencyFile = fopen($frequencyOutput, 'wb');

if ($frequencyFile !== false) {
    fputcsv($frequencyFile, ['type_id', 'campo', 'valor', 'relaciones'], ',', '"', '');

    ksort($nameFrequency);
    foreach ($nameFrequency as $typeId => $values) {
        arsort($values);
        foreach ($values as $value => $total) {
            fputcsv($frequencyFile, [$typeId, 'Name', $value, $total], ',', '"', '');
        }
    }

    ksort($textFrequency);
    foreach ($textFrequency as $typeId => $values) {
        arsort($values);
        foreach ($values as $value => $total) {
            fputcsv($frequencyFile, [$typeId, 'Text', $value, $total], ',', '"', '');
        }
    }

    fclose($frequencyFile);
}

echo "\n== Resultado ==\n";
echo "Archivo de hallazgos:   $output\n";
echo "Archivo de frecuencias: $frequencyOutput\n";
if ($typeFilter !== null) {
    echo "Service types revisados: " . implode(', ', $typeFilter) . "\n";
}
echo "Relaciones sin hallazgos: $clean\n";
echo "Relaciones con al menos un hallazgo: " . count($findings) . "\n";
echo "SKUs afectados: " . count($affectedSkus) . "\n\n";
echo "Por tipo de hallazgo (una relacion puede contar en varios):\n";
foreach ($counts as $label => $total) {
    printf("  %-24s %d\n", $label, $total);
}

echo "\n== Valores de Name por service type ==\n";
ksort($nameFrequency);
foreach ($nameFrequency as $typeId => $values) {
    arsort($values);
    $total = $relationsPerType[$typeId] ?? array_sum($values);
    echo "\nType $typeId  ($total relaciones)\n";
    foreach ($values as $value => $count) {
        printf("  %6d  %s\n", $count, $value === '' ? '(vacio)' : $value);
    }
}

echo "\nEste script no modifico nada en VTEX.\n";
