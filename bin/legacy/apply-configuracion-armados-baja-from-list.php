<?php
declare(strict_types=1);

/*
 * Aplica por API la configuracion de armado complejidad baja.
 *
 * Configuracion objetivo:
 * - SkuServiceTypeId: 3
 * - SkuServiceValueId: 3
 * - sku de servicio / PriceName: 1080156
 *
 * Contexto para otra IA:
 * - Lee SKUs desde input/prev_config_armados_junio_dificultad_baja_skus.txt.
 * - Si el SKU ya tiene el service type correcto, actualiza/asegura la relacion.
 * - Si tiene un value legacy del mismo type, lo borra antes de crear el objetivo.
 */

// Archivado en bin/legacy/ el 2026-10-23. Escribe en VTEX al ejecutarse (sin dry-run) y borra
// relaciones. No corre sin --ejecutar-legacy. Para configurar, usar bin/apply-instalacion-bracket.php.
if (!in_array('--ejecutar-legacy', $argv, true)) {
    fwrite(STDERR, "Script archivado: escribe en VTEX sin dry-run. Usa bin/apply-instalacion-bracket.php.\n"
        . "Si de verdad necesitas correrlo, agrega --ejecutar-legacy.\n");
    exit(1);
}

ini_set('memory_limit', '768M');

require_once __DIR__ . '/../../config/env.php';

loadEnv(__DIR__ . '/../../.env');

$appKey = trim((string) env('API_KEY', ''));
$appToken = trim((string) env('API_TOKEN', ''));
$baseUrl = 'https://cemacogt.myvtex.com';
$input = __DIR__ . '/../../input/prev_config_armados_junio_dificultad_baja_skus.txt';
$configName = 'configuracion armados complejidad baja';
$serviceTypeId = 3;
$serviceValueId = 3;
$serviceName = 'Servicio de armado';
$serviceText = 'Servicio de armado';
$outputJson = __DIR__ . '/../../reporte_configuracion_armados_complejidad_baja_aplicacion.json';
$outputCsv = __DIR__ . '/../../reporte_configuracion_armados_complejidad_baja_aplicacion.csv';

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

function requestJson(string $method, string $url, string $appKey, string $appToken, ?array $body = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            "X-VTEX-API-AppKey: $appKey",
            "X-VTEX-API-AppToken: $appToken",
        ],
    ];

    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    $decoded = json_decode((string) $response, true);

    return [
        'ok' => $response !== false && $status >= 200 && $status < 300,
        'status' => $status,
        'error' => $error ?: null,
        'json' => is_array($decoded) ? $decoded : null,
        'body' => substr((string) $response, 0, 1000),
        'url' => $url,
    ];
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
            $skus[] = $sku;
        }
    }

    return array_values(array_unique($skus));
}

function payloadForSku(string $sku, int $serviceTypeId, int $serviceValueId, string $name, string $text, ?int $id = null): array
{
    // Payload unico para crear o actualizar relaciones SkuService.
    $payload = [
        'SkuServiceTypeId' => $serviceTypeId,
        'SkuServiceValueId' => $serviceValueId,
        'SkuId' => (int) $sku,
        'Name' => $name,
        'Text' => $text,
        'IsActive' => true,
    ];

    if ($id !== null) {
        $payload['Id'] = $id;
    }

    return $payload;
}

function getAllSkuServices(string $baseUrl, string $appKey, string $appToken): array
{
    $response = requestJson('GET', "$baseUrl/api/catalog/pvt/skuservice", $appKey, $appToken);
    if (!$response['ok'] || !is_array($response['json'])) {
        throw new RuntimeException("No se pudo listar skuservice. HTTP {$response['status']} {$response['body']}");
    }

    return $response['json'];
}

function buildServiceMap(array $allServices, array $targetSkus): array
{
    $target = array_fill_keys($targetSkus, true);
    $map = [];

    foreach ($allServices as $service) {
        if (!is_array($service)) {
            continue;
        }

        $sku = (string) ($service['SkuId'] ?? '');
        if ($sku === '' || !isset($target[$sku])) {
            continue;
        }

        $map[$sku][] = $service;
    }

    return $map;
}

function isTargetService(array $service, int $serviceTypeId, int $serviceValueId): bool
{
    return (int) ($service['SkuServiceTypeId'] ?? 0) === $serviceTypeId
        && (int) ($service['SkuServiceValueId'] ?? 0) === $serviceValueId
        && ($service['IsActive'] ?? false) === true;
}

function isSameServiceType(array $service, int $serviceTypeId): bool
{
    return (int) ($service['SkuServiceTypeId'] ?? 0) === $serviceTypeId;
}

function compactService(?array $service): string
{
    if (!is_array($service)) {
        return '';
    }

    return json_encode([
        'Id' => $service['Id'] ?? null,
        'SkuServiceTypeId' => $service['SkuServiceTypeId'] ?? null,
        'SkuServiceValueId' => $service['SkuServiceValueId'] ?? null,
        'SkuId' => $service['SkuId'] ?? null,
        'Name' => $service['Name'] ?? null,
        'Text' => $service['Text'] ?? null,
        'IsActive' => $service['IsActive'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
}

try {
    $skus = readSkusFromTxt($input);

    $serviceValue = requestJson('GET', "$baseUrl/api/catalog/pvt/skuservicevalue/$serviceValueId", $appKey, $appToken);
    if (!$serviceValue['ok'] || !is_array($serviceValue['json'])) {
        throw new RuntimeException("No se pudo validar SkuServiceValueId $serviceValueId");
    }

    if ((int) ($serviceValue['json']['SkuServiceTypeId'] ?? 0) !== $serviceTypeId) {
        throw new RuntimeException("SkuServiceValueId $serviceValueId no pertenece a SkuServiceTypeId $serviceTypeId");
    }

    echo "SKUs leidos: " . count($skus) . "\n";
    echo "Listando skuservice actuales...\n";
    $allServices = getAllSkuServices($baseUrl, $appKey, $appToken);
    $serviceMap = buildServiceMap($allServices, $skus);

    $results = [];
    $processed = 0;

    foreach ($skus as $sku) {
        $processed++;
        $existingServices = $serviceMap[$sku] ?? [];
        $sameTypeServices = array_values(array_filter($existingServices, fn (array $service): bool => isSameServiceType($service, $serviceTypeId)));
        $targetServices = array_values(array_filter($sameTypeServices, fn (array $service): bool => isTargetService($service, $serviceTypeId, $serviceValueId)));

        $row = [
            'configuracion' => $configName,
            'sku' => $sku,
            'target_service_type_id' => (string) $serviceTypeId,
            'target_service_value_id' => (string) $serviceValueId,
            'action' => '',
            'status' => '',
            'previous_services' => json_encode(array_map('compactService', $sameTypeServices), JSON_UNESCAPED_UNICODE),
            'final_sku_service_id' => '',
            'message' => '',
        ];

        if ($targetServices !== []) {
            $target = $targetServices[0];
            $targetId = (int) ($target['Id'] ?? 0);
            $payload = payloadForSku($sku, $serviceTypeId, $serviceValueId, $serviceName, $serviceText, $targetId);
            $put = requestJson('PUT', "$baseUrl/api/catalog/pvt/skuservice/$targetId", $appKey, $appToken, $payload);

            $row['action'] = 'already_exists_updated_text';
            $row['status'] = $put['ok'] ? 'ok' : 'error';
            $row['final_sku_service_id'] = (string) $targetId;
            $row['message'] = $put['ok'] ? 'Ya existia target; se aseguro name/text/active' : "PUT fallo HTTP {$put['status']} {$put['body']}";
            $results[] = $row;
        } else {
            foreach ($sameTypeServices as $legacyService) {
                $legacyId = (int) ($legacyService['Id'] ?? 0);
                if ($legacyId <= 0) {
                    continue;
                }

                $delete = requestJson('DELETE', "$baseUrl/api/catalog/pvt/skuservice/$legacyId", $appKey, $appToken);
                if (!$delete['ok']) {
                    $row['action'] = 'delete_legacy';
                    $row['status'] = 'error';
                    $row['message'] = "No se pudo borrar legacy $legacyId HTTP {$delete['status']} {$delete['body']}";
                    $results[] = $row;
                    continue 2;
                }
            }

            $payload = payloadForSku($sku, $serviceTypeId, $serviceValueId, $serviceName, $serviceText);
            $post = requestJson('POST', "$baseUrl/api/catalog/pvt/skuservice", $appKey, $appToken, $payload);

            $row['action'] = $sameTypeServices === [] ? 'created' : 'deleted_legacy_and_created';
            $row['status'] = $post['ok'] && is_array($post['json']) ? 'ok' : 'error';
            $row['final_sku_service_id'] = is_array($post['json']) ? (string) ($post['json']['Id'] ?? '') : '';
            $row['message'] = $row['status'] === 'ok' ? 'Configuracion aplicada' : "POST fallo HTTP {$post['status']} {$post['body']}";
            $results[] = $row;
        }

        if ($processed % 25 === 0 || $processed === count($skus)) {
            echo "Procesados: $processed/" . count($skus) . "\n";
        }
    }

    file_put_contents($outputJson, json_encode([
        'input' => basename($input),
        'configuracion' => $configName,
        'service_value_validado' => $serviceValue['json'],
        'resultados' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $csv = fopen($outputCsv, 'w');
    fputcsv($csv, array_keys($results[0] ?? [
        'configuracion' => '', 'sku' => '', 'target_service_type_id' => '', 'target_service_value_id' => '', 'action' => '', 'status' => '', 'previous_services' => '', 'final_sku_service_id' => '', 'message' => '',
    ]), ',', '"', '');

    foreach ($results as $result) {
        fputcsv($csv, $result, ',', '"', '');
    }
    fclose($csv);

    $summary = [
        'input' => basename($input),
        'total_skus' => count($results),
        'ok' => count(array_filter($results, fn (array $r): bool => $r['status'] === 'ok')),
        'errores' => count(array_filter($results, fn (array $r): bool => $r['status'] !== 'ok')),
        'acciones' => array_count_values(array_map(fn (array $r): string => $r['action'], $results)),
        'reporte_json' => basename($outputJson),
        'reporte_csv' => basename($outputCsv),
    ];

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    exit(1);
}
