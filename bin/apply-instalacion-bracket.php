<?php
declare(strict_types=1);

/*
 * Aplica por API la configuracion de instalacion de brackets de TV.
 *
 * Sirve para los dos types de la familia, segun los parametros que se le pasen:
 *
 * - type 16  value 84871  sku de servicio 1009725  Q150  "Servicio de Instalacion pared de concreto"
 * - type 15  value 84873  sku de servicio 1119955  Q450  "Servicio de Instalacion tabla yeso"
 *
 * El type 14 ("de 43 Pulgadas o mas") NO se puede usar todavia: no tiene ningun
 * SkuServiceValue creado. Ver incidencia E3 en la bitacora.
 *
 * Uso:
 * php bin/apply-instalacion-bracket.php --input=lista.txt --output=aplicacion.csv \
 *     --servicetype=15 --servicevalue=84873 --name="Servicio de Instalación tabla yeso"
 *
 * Agregar --aplicar para que escriba. Sin esa bandera es dry-run.
 *
 * Contexto para otra IA:
 * - --dry-run es el comportamiento POR DEFECTO. Solo escribe con --aplicar.
 * - Idempotente: si el SKU ya tiene el type 16 con el value objetivo, hace PUT para asegurar
 *   Name/Text/IsActive. Si no lo tiene, hace POST.
 * - Un SKU no recibe el mismo type dos veces: un solo --aplicar a la vez (por equipo) y los SKUs se comparan
 *   sin ceros a la izquierda.
 * - NUNCA borra relaciones. A diferencia de los apply-*.php mas viejos, si encuentra una
 *   relacion del mismo type con OTRO value, no la borra: marca la fila como 'revisar_manual'
 *   y no toca ese SKU. Borrar es una decision que se toma aparte.
 * - PROTECTED_SKU_SERVICE_IDS y PROTECTED_SKUS (lib/reglas.php) protegen la excepcion
 *   aprobada del SKU 983865 (brackets de TV, ids 297864 tabla yeso Q450 y 297865 pared
 *   concreto Q150). Sus nombres descriptivos son
 *   lo unico que le permite al cliente distinguir dos servicios que cuestan el triple uno
 *   del otro. Ese SKU no se procesa.
 * - El type 16 declara "hasta 42 pulgadas". El script clasifica cada SKU por su nombre y
 *   escribe la columna 'fuera_de_rango' para dejar visible cuales exceden lo que el type
 *   declara. Es informativo: NO excluye a nadie del lote.
 */

ini_set('memory_limit', '768M');

require_once __DIR__ . '/../lib/bootstrap.php';

$options = getopt('', ['input:', 'output:', 'aplicar', 'servicetype:', 'servicevalue:', 'name:']);

$input = optionValue($options, 'input');
$outputCsv = optionValue($options, 'output') ?? 'aplicacion_bracket_concreto.csv';
$aplicar = array_key_exists('aplicar', $options);
$serviceTypeId = (int) (optionValue($options, 'servicetype') ?? '16');
$serviceValueId = (int) (optionValue($options, 'servicevalue') ?? '84871');

$serviceName = optionValue($options, 'name') ?? 'Servicio de Instalación pared de concreto';
$serviceText = $serviceName;

if ($input === null) {
    fwrite(STDERR, "Uso: php bin/apply-instalacion-bracket.php --input=lista.txt --output=aplicacion.csv"
        . " [--servicetype=16] [--servicevalue=84871] [--name=\"...\"] [--aplicar]\n");
    exit(1);
}

if (!str_starts_with($input, '/')) {
    $input = __DIR__ . '/../' . $input;
}
if (!str_starts_with($outputCsv, '/')) {
    $outputCsv = __DIR__ . '/../' . $outputCsv;
}
$outputJson = preg_replace('/\.csv$/', '.json', $outputCsv);

$appKey = trim((string) env('API_KEY', ''));
$appToken = trim((string) env('API_TOKEN', ''));
$baseUrl = vtexBaseUrl();

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

function readSkusFromTxt(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("No se pudo leer $path");
    }

    $skus = [];
    foreach ($lines as $line) {
        $sku = trim(str_replace(["\r", '"'], '', $line));
        if (preg_match('/^\d+$/', $sku)) {
            $skus[] = (string) (int) $sku;   // 0123 y 123 son el mismo SKU
        }
    }

    return array_values(array_unique($skus));
}

/**
 * Clasifica por el nombre del producto. Informativo: el type 16 declara "hasta 42 pulgadas".
 */
function clasificarPorNombre(string $nombre): string
{
    $l = mb_strtolower($nombre);

    if (str_contains($l, 'proyector') || str_contains($l, 'techo')) {
        return 'techo_o_proyector';
    }
    if (str_contains($l, 'barra de sonido')) {
        return 'barra_de_sonido';
    }
    if (str_contains($l, 'piso') || str_contains($l, 'móvil') || str_contains($l, 'movil')) {
        return 'piso_o_movil';
    }

    preg_match_all('/(\d{2,3})\s*(?:a|-|hasta)?\s*(\d{2,3})?\s*(?:plg|pulg)/iu', $nombre, $m);
    $max = 0;
    foreach ($m[1] as $i => $desde) {
        $max = max($max, (int) $desde, (int) ($m[2][$i] ?? 0));
    }

    if ($max === 0) {
        return 'sin_medida_clara';
    }

    return $max <= 42 ? 'hasta_42_plg' : 'mas_de_42_plg';
}

// Un SKU no puede recibir el mismo type dos veces: dos Aplicar a la vez leerian VTEX antes de que el otro
// escriba y los dos crearian. El candado va sobre este archivo y el sistema lo suelta al terminar el proceso.
if ($aplicar) {
    $candado = fopen(__FILE__, 'r');
    if ($candado === false || !flock($candado, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Ya hay otro Aplicar en curso. Espera a que termine y genera un previo nuevo. No se escribió nada.\n");
        exit(1);
    }
}

try {
    $skus = readSkusFromTxt($input);

    // Validar que el value pertenezca al type antes de cualquier cosa.
    $serviceValue = requestJson('GET', "$baseUrl/api/catalog/pvt/skuservicevalue/$serviceValueId", $appKey, $appToken);
    if (!$serviceValue['ok'] || !is_array($serviceValue['json'])) {
        throw new RuntimeException("No se pudo validar SkuServiceValueId $serviceValueId");
    }
    if ((int) ($serviceValue['json']['SkuServiceTypeId'] ?? 0) !== $serviceTypeId) {
        throw new RuntimeException("SkuServiceValueId $serviceValueId no pertenece a SkuServiceTypeId $serviceTypeId");
    }

    echo ($aplicar ? "MODO APLICAR (escribe en VTEX)\n" : "MODO DRY-RUN (no escribe nada)\n");
    echo "Type $serviceTypeId / Value $serviceValueId -> sku de servicio "
        . ($serviceValue['json']['Name'] ?? '?') . ", precio " . ($serviceValue['json']['Value'] ?? '?') . "\n";
    echo 'SKUs leidos: ' . count($skus) . "\n";

    echo "Listando skuservice actuales...\n";
    $all = requestJson('GET', "$baseUrl/api/catalog/pvt/skuservice", $appKey, $appToken);
    if (!$all['ok'] || !is_array($all['json'])) {
        throw new RuntimeException("No se pudo listar skuservice. HTTP {$all['status']}");
    }

    $target = array_fill_keys($skus, true);
    $serviceMap = [];
    foreach ($all['json'] as $service) {
        if (!is_array($service)) {
            continue;
        }
        $sku = (string) ($service['SkuId'] ?? '');
        if ($sku !== '' && isset($target[$sku])) {
            $serviceMap[$sku][] = $service;
        }
    }
    unset($all);

    // Nombres de producto, para la clasificacion informativa. Tambien confirma que el SKU exista: un 404
    // es un SKU que no existe (mal escrito, por ejemplo) y otro error deja el SKU sin verificar. A
    // ninguno de los dos se le crea ni actualiza nada, ni en dry-run ni con --aplicar.
    echo "Consultando nombres de producto...\n";
    $nombres = [];
    $sinDetalle = [];   // sku => status HTTP de la consulta fallida
    foreach ($skus as $sku) {
        $detail = requestJson('GET', "$baseUrl/api/catalog_system/pvt/sku/stockkeepingunitbyid/$sku", $appKey, $appToken);
        if (!$detail['ok'] || !is_array($detail['json'])) {
            $sinDetalle[$sku] = (int) $detail['status'];
        }
        $nombres[$sku] = is_array($detail['json'])
            ? (string) ($detail['json']['ProductName'] ?? $detail['json']['SkuName'] ?? '')
            : '';
    }

    $results = [];
    $processed = 0;

    foreach ($skus as $sku) {
        $processed++;
        $existing = $serviceMap[$sku] ?? [];
        $sameType = array_values(array_filter(
            $existing,
            fn (array $s): bool => (int) ($s['SkuServiceTypeId'] ?? 0) === $serviceTypeId
        ));
        $targetRel = array_values(array_filter(
            $sameType,
            fn (array $s): bool => (int) ($s['SkuServiceValueId'] ?? 0) === $serviceValueId
        ));

        $nombre = $nombres[$sku] ?? '';
        $clase = clasificarPorNombre($nombre);

        // El type 16 declara "hasta 42 pulgadas", asi que el tamano lo deja fuera de rango.
        // El type 15 dice "no importa tamano": ahi solo desentona el que no sea montaje en pared.
        $noEsMontajeEnPared = in_array($clase, ['techo_o_proyector', 'piso_o_movil', 'barra_de_sonido'], true);
        $fueraDeRango = $serviceTypeId === 16
            ? $clase !== 'hasta_42_plg'
            : $noEsMontajeEnPared;

        $row = [
            'sku' => $sku,
            'nombre_producto' => $nombre,
            'clasificacion' => $clase,
            'fuera_de_rango' => $fueraDeRango ? 'si' : 'no',
            'target_service_type_id' => (string) $serviceTypeId,
            'target_service_value_id' => (string) $serviceValueId,
            'accion' => '',
            'status' => '',
            'sku_service_id_previo' => '',
            'final_sku_service_id' => '',
            'mensaje' => '',
        ];

        // Proteccion 0: SKU que no existe en VTEX o que no se pudo verificar. No se escribe nada.
        if (isset($sinDetalle[$sku])) {
            $http = $sinDetalle[$sku];
            $row['accion'] = $http === 404 ? 'no_existe' : 'sin_verificar';
            $row['status'] = 'omitido';
            $row['mensaje'] = $http === 404
                ? 'El SKU no existe en VTEX. Revisa si está mal escrito.'
                : "No se pudo consultar el SKU en VTEX (HTTP $http). Vuelve a generar el previo.";
            $results[] = $row;
            continue;
        }

        // Proteccion 1: SKUs excluidos por decision explicita.
        if (in_array($sku, PROTECTED_SKUS, true)) {
            $row['accion'] = 'omitido';
            $row['status'] = 'omitido';
            $row['mensaje'] = 'SKU protegido: excepcion aprobada, ya tiene la configuracion';
            $results[] = $row;
            continue;
        }

        // Proteccion 2: relaciones protegidas.
        foreach ($sameType as $s) {
            if (isProtectedSkuServiceId($s['Id'] ?? 0)) {
                $row['accion'] = 'omitido';
                $row['status'] = 'omitido';
                $row['sku_service_id_previo'] = (string) $s['Id'];
                $row['mensaje'] = 'Relacion protegida en EXCLUDED_SKU_SERVICE_IDS';
                $results[] = $row;
                continue 2;
            }
        }

        // Proteccion 3: mismo type con otro value -> no se borra, se reporta.
        $otroValue = array_values(array_filter(
            $sameType,
            fn (array $s): bool => (int) ($s['SkuServiceValueId'] ?? 0) !== $serviceValueId
        ));
        if ($targetRel === [] && $otroValue !== []) {
            $row['accion'] = 'revisar_manual';
            $row['status'] = 'omitido';
            $row['sku_service_id_previo'] = (string) ($otroValue[0]['Id'] ?? '');
            $row['mensaje'] = 'Ya tiene el type ' . $serviceTypeId . ' con otro value ('
                . ($otroValue[0]['SkuServiceValueId'] ?? '?') . '). No se toca.';
            $results[] = $row;
            continue;
        }

        if ($targetRel !== []) {
            $rel = $targetRel[0];
            $relId = (int) ($rel['Id'] ?? 0);
            $yaCorrecto = ($rel['Name'] ?? null) === $serviceName
                && ($rel['Text'] ?? null) === $serviceText
                && ($rel['IsActive'] ?? false) === true;

            $row['sku_service_id_previo'] = (string) $relId;
            $row['final_sku_service_id'] = (string) $relId;
            $row['accion'] = $yaCorrecto ? 'sin_cambios' : 'actualizar_existente';

            if ($yaCorrecto) {
                $row['status'] = 'ok';
                $row['mensaje'] = 'Ya estaba con el name/text/active correctos';
            } elseif (!$aplicar) {
                $row['status'] = 'dry-run';
                $row['mensaje'] = 'PUT pendiente para asegurar name/text/active';
            } else {
                $put = requestJson('PUT', "$baseUrl/api/catalog/pvt/skuservice/$relId", $appKey, $appToken, [
                    'Id' => $relId,
                    'SkuServiceTypeId' => $serviceTypeId,
                    'SkuServiceValueId' => $serviceValueId,
                    'SkuId' => (int) $sku,
                    'Name' => $serviceName,
                    'Text' => $serviceText,
                    'IsActive' => true,
                ]);
                $row['status'] = $put['ok'] ? 'ok' : 'error';
                $row['mensaje'] = $put['ok'] ? 'Actualizado' : "PUT fallo HTTP {$put['status']} {$put['body']}";
            }

            $results[] = $row;
            continue;
        }

        // No tiene el type: crear.
        $row['accion'] = 'crear';

        if (!$aplicar) {
            $row['status'] = 'dry-run';
            $row['mensaje'] = 'POST pendiente';
            $results[] = $row;
            continue;
        }

        $post = requestJson('POST', "$baseUrl/api/catalog/pvt/skuservice", $appKey, $appToken, [
            'SkuServiceTypeId' => $serviceTypeId,
            'SkuServiceValueId' => $serviceValueId,
            'SkuId' => (int) $sku,
            'Name' => $serviceName,
            'Text' => $serviceText,
            'IsActive' => true,
        ]);

        $row['status'] = $post['ok'] && is_array($post['json']) ? 'ok' : 'error';
        $row['final_sku_service_id'] = is_array($post['json']) ? (string) ($post['json']['Id'] ?? '') : '';
        $row['mensaje'] = $row['status'] === 'ok' ? 'Creado' : "POST fallo HTTP {$post['status']} {$post['body']}";
        $results[] = $row;

        if ($processed % 25 === 0 || $processed === count($skus)) {
            echo "Procesados: $processed/" . count($skus) . "\n";
        }
    }

    $csv = fopen($outputCsv, 'w');
    fputcsv($csv, array_keys($results[0]), ',', '"', '');
    foreach ($results as $r) {
        fputcsv($csv, $r, ',', '"', '');
    }
    fclose($csv);

    file_put_contents($outputJson, json_encode([
        'modo' => $aplicar ? 'aplicar' : 'dry-run',
        'input' => basename($input),
        'service_value_validado' => $serviceValue['json'],
        'name_escrito' => $serviceName,
        'resultados' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $resumen = [
        'modo' => $aplicar ? 'aplicar' : 'dry-run',
        'total' => count($results),
        'acciones' => array_count_values(array_column($results, 'accion')),
        'status' => array_count_values(array_column($results, 'status')),
        'fuera_de_rango' => array_count_values(array_column($results, 'fuera_de_rango')),
        'clasificacion' => array_count_values(array_column($results, 'clasificacion')),
        'csv' => basename($outputCsv),
        'json' => basename((string) $outputJson),
    ];

    echo json_encode($resumen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    exit(1);
}
