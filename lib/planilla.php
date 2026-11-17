<?php
declare(strict_types=1);

/*
 * Lectura de la planilla del proyecto en .xlsx o .csv, para configurar servicios por lote desde el
 * dashboard. Desde el 2026-11-09 tiene solo dos columnas: «SKU» (el SKU del producto) y «SKU de
 * servicio». El dashboard la ofrece como plantilla descargable.
 *
 * Contexto para otra IA:
 * - Sin librerias: el .xlsx se lee con ZipArchive + SimpleXML (solo la primera hoja).
 * - Las columnas se buscan por nombre, no por posicion; otras columnas se ignoran.
 * - El type sale del SKU de servicio (SERVICE_SKUS, lib/reglas.php). El value y el Name canonico
 *   salen siempre del type, como en el formulario.
 * - Ya no se acepta la planilla nativa de VTEX (Id Sku, Id Serviço Tipo, Ativo).
 * - Los .csv guardados desde Excel pueden venir en ISO-8859-1 y con ';'. Se detectan los dos.
 */

/** Filas de la planilla como listas de strings (la primera es el encabezado). */
function readSheetRows(string $path, string $ext): array
{
    return match (strtolower($ext)) {
        'xlsx' => readXlsxRows($path),
        'csv' => readCsvRows($path),
        default => throw new RuntimeException('El archivo debe ser .xlsx o .csv.'),
    };
}

function readXlsxRows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('No se pudo abrir el .xlsx.');
    }

    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        foreach (simplexml_load_string($xml)->si as $si) {
            $text = isset($si->t) ? (string) $si->t : '';
            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }
            $shared[] = $text;
        }
    }

    // Primera hoja segun workbook.xml; si no se puede resolver, sheet1.xml.
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $first = simplexml_load_string($wb)->sheets->sheet[0] ?? null;
        $rid = $first === null ? '' : (string) $first->attributes(
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach (simplexml_load_string($rels)->Relationship as $r) {
            if ((string) $r['Id'] === $rid) {
                $target = (string) $r['Target'];
                $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            }
        }
    }
    $xml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($xml === false) {
        throw new RuntimeException('El .xlsx no tiene hojas.');
    }

    $rows = [];
    foreach (simplexml_load_string($xml)->sheetData->row as $row) {
        $cells = [];
        $next = 0;
        foreach ($row->c as $c) {
            $col = preg_match('/^([A-Z]+)/', (string) $c['r'], $m) ? columnIndex($m[1]) : $next;
            $type = (string) $c['t'];
            $v = match ($type) {
                's' => $shared[(int) $c->v] ?? '',
                'inlineStr' => (string) $c->is->t,
                default => (string) $c->v,
            };
            // Numeros grandes que Excel guarda en notacion cientifica (1.88206E5).
            if ($type === '' && preg_match('/^-?\d+(\.\d+)?E[+-]?\d+$/i', $v)) {
                $v = sprintf('%.0f', (float) $v);
            }
            $cells[$col] = $v;
            $next = $col + 1;
        }
        $rows[] = $cells === [] ? [] : array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells);
    }
    return $rows;
}

function columnIndex(string $letters): int
{
    $n = 0;
    foreach (str_split($letters) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

function readCsvRows(string $path): array
{
    $raw = (string) file_get_contents($path);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }
    $firstLine = strtok($raw, "\n");
    $sep = substr_count((string) $firstLine, ';') > substr_count((string) $firstLine, ',') ? ';' : ',';

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, null, $sep, '"', '')) !== false) {
        $rows[] = $r === [null] ? [] : array_map(fn($v) => (string) $v, $r);
    }
    fclose($fh);
    return $rows;
}

/**
 * Pares SKU + type de la planilla del proyecto: columnas «SKU» (producto) y «SKU de servicio».
 * El type sale del SKU de servicio (typeFromServiceSku, lib/reglas.php).
 * Devuelve ['items' => [['fila', 'sku', 'type']], 'inactivas' => [], 'errores' => [texto...]].
 * 'inactivas' queda vacio: el formato no tiene columna Ativo; se mantiene por compatibilidad.
 */
function serviceRowsFromSheet(array $rows): array
{
    if ($rows === []) {
        throw new RuntimeException('El archivo está vacío.');
    }
    // Encabezados comparados sin mayusculas, espacios ni acentos: «SKU de servicio» -> "skudeservicio".
    $norm = fn($h) => preg_replace('/[^a-z]/', '', strtolower((string) $h));
    $skuCol = $serviceCol = null;
    foreach (array_shift($rows) as $i => $h) {
        $n = $norm($h);
        if ($skuCol === null && $n === 'sku') {
            $skuCol = $i;
        } elseif ($serviceCol === null && $n === 'skudeservicio') {
            $serviceCol = $i;
        }
    }
    if ($skuCol === null || $serviceCol === null) {
        throw new RuntimeException('No encontré las columnas «SKU» y «SKU de servicio». Descarga la plantilla y usa sus encabezados.');
    }

    $out = ['items' => [], 'inactivas' => [], 'errores' => []];
    foreach ($rows as $k => $r) {
        $fila = $k + 2;
        $sku = trim($r[$skuCol] ?? '');
        $serviceSku = trim($r[$serviceCol] ?? '');
        if ($sku === '' && $serviceSku === '') {
            continue;
        }
        if (!ctype_digit($sku) || !ctype_digit($serviceSku)) {
            $out['errores'][] = "Fila $fila: SKU y SKU de servicio deben ser números («{$sku}», «{$serviceSku}»).";
            continue;
        }
        $type = typeFromServiceSku($serviceSku);
        if ($type === null) {
            $out['errores'][] = "Fila $fila: el SKU de servicio «{$serviceSku}» no corresponde a ningún servicio conocido.";
            continue;
        }
        $out['items'][] = ['fila' => $fila, 'sku' => $sku, 'type' => $type];
    }
    return $out;
}
