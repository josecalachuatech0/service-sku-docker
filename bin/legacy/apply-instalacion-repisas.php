<?php
declare(strict_types=1);

/*
 * Script pequeno de prueba para aplicar instalacion de repisas a una lista fija de SKUs.
 *
 * Contexto para otra IA:
 * - Fue usado para validar el flujo con pocos SKUs antes de correr el batch completo.
 * - Para lotes grandes usar bin/apply-instalacion-repisas-from-batch.php.
 */

// Archivado en bin/legacy/ el 2026-10-23. Escribe en VTEX al ejecutarse (sin dry-run) y borra
// relaciones. No corre sin --ejecutar-legacy. Para configurar, usar bin/apply-instalacion-bracket.php.
if (!in_array('--ejecutar-legacy', $argv, true)) {
    fwrite(STDERR, "Script archivado: escribe en VTEX sin dry-run. Usa bin/apply-instalacion-bracket.php.\n"
        . "Si de verdad necesitas correrlo, agrega --ejecutar-legacy.\n");
    exit(1);
}

require_once __DIR__ . '/../../config/env.php';

loadEnv(__DIR__ . '/../../.env');

$appKey = trim((string) env('API_KEY', ''));
$appToken = trim((string) env('API_TOKEN', ''));
$baseUrl = 'https://cemacogt.myvtex.com';
$configName = 'instalacion repisas';
$serviceTypeId = 37;
$serviceValueId = 84864;
$serviceName = 'Servicio de armado';
$serviceText = 'Servicio de armado';
$skus = ['993261', '188206', '188207', '1174473', '993260'];
$outputJson = __DIR__ . '/../../reporte_instalacion_repisas_5_skus.json';
$outputCsv = __DIR__ . '/../../reporte_instalacion_repisas_5_skus.csv';

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

function requestJson(string $method, string $url, string $appKey, string $appToken, ?array $body = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
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

function findServiceByType(?array $skuDetail, int $serviceTypeId): ?array
{
    if (!is_array($skuDetail) || !is_array($skuDetail['Services'] ?? null)) {
        return null;
    }

    foreach ($skuDetail['Services'] as $service) {
        if (!is_array($service)) {
            continue;
        }

        if ((int) ($service['ServiceTypeId'] ?? 0) === $serviceTypeId) {
            return $service;
        }
    }

    return null;
}

function payloadForSku(string $sku, int $serviceTypeId, int $serviceValueId, string $name, string $text, ?int $id = null): array
{
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

function serviceMatches(?array $service, int $sku, int $serviceTypeId, int $serviceValueId): bool
{
    return is_array($service)
        && (int) ($service['SkuId'] ?? 0) === $sku
        && (int) ($service['SkuServiceTypeId'] ?? 0) === $serviceTypeId
        && (int) ($service['SkuServiceValueId'] ?? 0) === $serviceValueId
        && ($service['IsActive'] ?? false) === true;
}

$serviceValue = requestJson('GET', "$baseUrl/api/catalog/pvt/skuservicevalue/$serviceValueId", $appKey, $appToken);
if (!$serviceValue['ok'] || !is_array($serviceValue['json'])) {
    fwrite(STDERR, "No se pudo validar SkuServiceValueId $serviceValueId\n");
    exit(1);
}

if ((int) ($serviceValue['json']['SkuServiceTypeId'] ?? 0) !== $serviceTypeId) {
    fwrite(STDERR, "SkuServiceValueId $serviceValueId no pertenece al SkuServiceTypeId $serviceTypeId\n");
    exit(1);
}

$results = [];
foreach ($skus as $sku) {
    $row = [
        'configuracion' => $configName,
        'sku' => $sku,
        'target_service_type_id' => $serviceTypeId,
        'target_service_value_id' => $serviceValueId,
        'action' => '',
        'status' => '',
        'previous_sku_service_id' => '',
        'final_sku_service_id' => '',
        'message' => '',
    ];

    $skuDetail = requestJson('GET', "$baseUrl/api/catalog_system/pvt/sku/stockkeepingunitbyid/$sku", $appKey, $appToken);
    if (!$skuDetail['ok'] || !is_array($skuDetail['json'])) {
        $row['action'] = 'validate_sku';
        $row['status'] = 'error';
        $row['message'] = "No se pudo consultar SKU: HTTP {$skuDetail['status']}";
        $results[] = $row;
        continue;
    }

    $existing = findServiceByType($skuDetail['json'], $serviceTypeId);
    $existingId = is_array($existing) && isset($existing['Id']) ? (int) $existing['Id'] : null;
    $row['previous_sku_service_id'] = $existingId !== null ? (string) $existingId : '';

    if ($existingId !== null) {
        $payload = payloadForSku($sku, $serviceTypeId, $serviceValueId, $serviceName, $serviceText, $existingId);
        $put = requestJson('PUT', "$baseUrl/api/catalog/pvt/skuservice/$existingId", $appKey, $appToken, $payload);
        $direct = requestJson('GET', "$baseUrl/api/catalog/pvt/skuservice/$existingId", $appKey, $appToken);

        if ($put['ok'] && serviceMatches($direct['json'] ?? null, (int) $sku, $serviceTypeId, $serviceValueId)) {
            $row['action'] = 'updated';
            $row['status'] = 'ok';
            $row['final_sku_service_id'] = (string) $existingId;
            $row['message'] = 'Actualizado por PUT';
            $results[] = $row;
            continue;
        }

        // Algunos registros legacy no permiten cambiar el value por PUT; se recrea solo el service type objetivo.
        $delete = requestJson('DELETE', "$baseUrl/api/catalog/pvt/skuservice/$existingId", $appKey, $appToken);
        if (!$delete['ok']) {
            $row['action'] = 'delete_recreate';
            $row['status'] = 'error';
            $row['message'] = "No se pudo borrar service legacy $existingId: HTTP {$delete['status']}";
            $results[] = $row;
            continue;
        }

        $postPayload = payloadForSku($sku, $serviceTypeId, $serviceValueId, $serviceName, $serviceText);
        $post = requestJson('POST', "$baseUrl/api/catalog/pvt/skuservice", $appKey, $appToken, $postPayload);
        if (!$post['ok'] || !is_array($post['json'])) {
            $row['action'] = 'delete_recreate';
            $row['status'] = 'error';
            $row['message'] = "Se borró $existingId pero falló POST: HTTP {$post['status']} {$post['body']}";
            $results[] = $row;
            continue;
        }

        $row['action'] = 'deleted_and_recreated';
        $row['status'] = 'ok';
        $row['final_sku_service_id'] = (string) ($post['json']['Id'] ?? '');
        $row['message'] = 'Recreado porque PUT no cambió SkuServiceValueId';
        $results[] = $row;
        continue;
    }

    $payload = payloadForSku($sku, $serviceTypeId, $serviceValueId, $serviceName, $serviceText);
    $post = requestJson('POST', "$baseUrl/api/catalog/pvt/skuservice", $appKey, $appToken, $payload);
    if (!$post['ok'] || !is_array($post['json'])) {
        $row['action'] = 'created';
        $row['status'] = 'error';
        $row['message'] = "Falló POST: HTTP {$post['status']} {$post['body']}";
        $results[] = $row;
        continue;
    }

    $row['action'] = 'created';
    $row['status'] = 'ok';
    $row['final_sku_service_id'] = (string) ($post['json']['Id'] ?? '');
    $row['message'] = 'Creado por POST';
    $results[] = $row;
}

file_put_contents($outputJson, json_encode([
    'configuracion' => $configName,
    'service_value_validado' => $serviceValue['json'],
    'resultados' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$csv = fopen($outputCsv, 'w');
fputcsv($csv, array_keys($results[0] ?? [
    'configuracion' => '', 'sku' => '', 'target_service_type_id' => '', 'target_service_value_id' => '', 'action' => '', 'status' => '', 'previous_sku_service_id' => '', 'final_sku_service_id' => '', 'message' => '',
]), ',', '"', '');
foreach ($results as $result) {
    fputcsv($csv, $result, ',', '"', '');
}
fclose($csv);

$summary = [
    'total' => count($results),
    'ok' => count(array_filter($results, fn (array $r): bool => $r['status'] === 'ok')),
    'errores' => count(array_filter($results, fn (array $r): bool => $r['status'] !== 'ok')),
    'acciones' => array_count_values(array_map(fn (array $r): string => $r['action'], $results)),
    'reporte_json' => basename($outputJson),
    'reporte_csv' => basename($outputCsv),
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
