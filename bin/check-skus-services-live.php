<?php
declare(strict_types=1);

/*
 * Muestra en vivo las relaciones de servicio de una lista puntual de SKUs.
 *
 * Uso:
 * php bin/check-skus-services-live.php --skus=1086254,1044449
 * php bin/check-skus-services-live.php --input=input/algunos_skus.txt
 * php bin/check-skus-services-live.php --skus=1086254 --output=consulta.csv
 *
 * Con --output escribe ademas un CSV con una fila por servicio (sku, producto, estado,
 * skuservice_id, type, sku_servicio, precio, name). Un SKU sin servicios o con error de
 * consulta deja una fila con esas columnas vacias. La salida de texto no cambia.
 *
 * SOLO LECTURA: solo hace GET.
 *
 * Contexto para otra IA:
 * - Lee GET /api/catalog_system/pvt/sku/stockkeepingunitbyid/{sku} y desglosa el bloque
 *   Services[]: SkuService.Id, ServiceTypeId, Name, y del primer Option el PriceName
 *   (que es el SKU del servicio) y el Price.
 * - Sirve para verificar puntualmente lo que un reporte CSV afirma. Ojo: ese endpoint a
 *   veces devuelve el bloque Services vacio sin marcar error (ver incidencia A1 de la
 *   bitacora); si un SKU sale sin servicios y esperabas lo contrario, reconsultalo antes
 *   de concluir que no los tiene.
 */

require_once __DIR__ . '/../lib/bootstrap.php';

$options = getopt('', ['skus:', 'input:', 'output:']);
$skusOption = optionValue($options, 'skus');
$inputOption = optionValue($options, 'input');
$outputOption = optionValue($options, 'output');

$skus = [];

if ($skusOption !== null) {
    foreach (explode(',', $skusOption) as $sku) {
        $sku = trim($sku);
        if ($sku !== '') {
            $skus[] = $sku;
        }
    }
}

if ($inputOption !== null) {
    if (!is_readable($inputOption)) {
        fwrite(STDERR, "No se puede leer $inputOption\n");
        exit(1);
    }
    foreach (file($inputOption, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $skus[] = $line;
        }
    }
}

$skus = array_values(array_unique($skus));

if ($skus === []) {
    fwrite(STDERR, "Uso: php bin/check-skus-services-live.php --skus=1086254,1044449 [--input=archivo.txt] [--output=archivo.csv]\n");
    exit(1);
}

$appKey = envString('API_KEY');
$appToken = envString('API_TOKEN');
$baseUrl = vtexBaseUrl();

if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}

$sinServicios = [];
$filas = [];

foreach ($skus as $sku) {
    $r = vtexCall('GET', "$baseUrl/api/catalog_system/pvt/sku/stockkeepingunitbyid/$sku", $appKey, $appToken);
    $detail = $r['ok'] ? $r['json'] : null;

    // 404: el SKU no existe (mal escrito, por ejemplo). Otro error: no se pudo consultar.
    if ($r['status'] === 404) {
        echo "SKU $sku -> NO EXISTE en VTEX\n\n";
        $filas[] = [$sku, '', 'no existe', '', '', '', '', ''];
        continue;
    }
    if (!is_array($detail)) {
        fwrite(STDERR, "  [HTTP {$r['status']}] stockkeepingunitbyid/$sku\n");
        echo "SKU $sku -> ERROR de consulta\n\n";
        $filas[] = [$sku, '', 'error de consulta', '', '', '', '', ''];
        continue;
    }

    $productName = (string) ($detail['ProductName'] ?? $detail['SkuName'] ?? '');
    $active = ($detail['IsActive'] ?? false) ? 'activo' : 'INACTIVO';
    printf("SKU %s | %s | %s\n", $sku, $active, mb_substr($productName, 0, 55));

    $services = $detail['Services'] ?? [];

    if (!is_array($services) || $services === []) {
        echo "   (sin Services)\n\n";
        $sinServicios[] = $sku;
        $filas[] = [$sku, $productName, $active, '', '', '', '', ''];
        continue;
    }

    foreach ($services as $service) {
        if (!is_array($service)) {
            continue;
        }
        $option = $service['Options'][0] ?? [];
        printf(
            "   SkuService.Id=%-8s TypeId=%-5s SkuServicio=%-12s Q%-8s Name=%s\n",
            (string) ($service['Id'] ?? '?'),
            (string) ($service['ServiceTypeId'] ?? '?'),
            (string) ($option['PriceName'] ?? '?'),
            (string) ($option['Price'] ?? '?'),
            json_encode((string) ($service['Name'] ?? ''), JSON_UNESCAPED_UNICODE)
        );
        $filas[] = [$sku, $productName, $active, (string) ($service['Id'] ?? ''), (string) ($service['ServiceTypeId'] ?? ''),
            (string) ($option['PriceName'] ?? ''), (string) ($option['Price'] ?? ''), (string) ($service['Name'] ?? '')];
    }

    echo "\n";
}

if ($sinServicios !== []) {
    echo "Aviso: " . count($sinServicios) . " SKU(s) sin bloque Services: " . implode(', ', $sinServicios) . "\n";
    echo "Si esperabas que tuvieran servicios, reconsultalos: este endpoint devuelve el bloque\n";
    echo "vacio de forma intermitente (incidencia A1 de la bitacora).\n";
}

if ($outputOption !== null) {
    $fh = fopen($outputOption, 'w');
    if ($fh === false) {
        fwrite(STDERR, "No se puede escribir $outputOption\n");
        exit(1);
    }
    fputcsv($fh, ['sku', 'producto', 'estado', 'skuservice_id', 'type', 'sku_servicio', 'precio', 'name'], ',', '"', '');
    foreach ($filas as $fila) {
        fputcsv($fh, $fila, ',', '"', '');
    }
    fclose($fh);
}
