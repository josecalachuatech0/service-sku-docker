<?php
declare(strict_types=1);

/*
 * Dashboard local para consultar y configurar servicios (SkuService) por SKU.
 *
 * Uso:
 * php -S 127.0.0.1:8080 -t dashboard
 *
 * Contexto para otra IA:
 * - No tiene logica propia de VTEX: ejecuta los scripts de bin/ y muestra lo que escriben.
 *   - Consultar: bin/check-skus-services-live.php (solo GET).
 *   - Previo y aplicar: bin/apply-instalacion-bracket.php, que es generico por
 *     --servicetype/--servicevalue/--name, valida value contra type, es dry-run por defecto
 *     y nunca borra (marca 'revisar_manual').
 * - Aplicar solo se puede desde un previo ya generado: reutiliza los mismos skus_<type>.txt y
 *   los mismos parametros guardados en runs/<id>/params.json. No se re-teclea nada.
 * - Los SKUs entran pegados (un solo type, el del select) o en la planilla del proyecto
 *   (.xlsx/.csv, lib/planilla.php), que lleva un solo SKU de servicio, repetido o no: con dos distintos se rechaza al subirla
 *   (regla del 2026-11-23). La planilla original queda en runs/<id>/archivo.<ext>.
 * - Las corridas quedan en dashboard/runs/<id>/, no en input/.
 * - Cobertura (?m=cobertura): bin/coverage-by-category.php (solo GET) en segundo plano; el
 *   resultado queda en dashboard/cache/coverage.json y la pagina solo lo dibuja.
 */

const ROOT = __DIR__ . '/..';
date_default_timezone_set('America/Guatemala');
const RUNS = __DIR__ . '/runs';
const MAX_SKUS = 300;

// Los types salen de VTEX via bin/list-service-types.php y se guardan en TYPES_CACHE. Si todavia
// no se ha actualizado, se usa la copia de la raiz (service_types.csv, desactualizada).
// Segmentacion, values y names sugeridos viven en lib/reglas.php; el value se vuelve a validar
// contra el type en el script antes de hacer nada.
require_once ROOT . '/lib/reglas.php';
require_once ROOT . '/lib/planilla.php';
// Log para rastrear fallos (lib/log.php). El .env se carga para que el log pueda quitar las claves de
// lo que escriben los scripts; las variables de entorno reales tienen prioridad.
require_once ROOT . '/config/env.php';
loadEnv(ROOT . '/.env');
require_once ROOT . '/lib/log.php';
logErroresFatales();
const TYPES_CACHE = __DIR__ . '/cache/service_types.csv';
const TYPES_FALLBACK = ROOT . '/service_types.csv';

// Plantilla de la planilla (?plantilla=csv | xlsx): solo los dos encabezados. Se elige el formato en el
// modal del boton «Descargar plantilla». El CSV lleva BOM para que Excel lo abra en UTF-8.
function plantillaXlsx(): string
{
    $ns = 'http://schemas.openxmlformats.org/';
    $tmp = tempnam(sys_get_temp_dir(), 'plantilla_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="' . $ns . 'package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="' . $ns . 'package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . $ns . 'officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="' . $ns . 'spreadsheetml/2006/main" xmlns:r="' . $ns . 'officeDocument/2006/relationships">'
        . '<sheets><sheet name="Servicios" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="' . $ns . 'package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . $ns . 'officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $ns . 'spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="inlineStr"><is><t>SKU</t></is></c><c r="B1" t="inlineStr"><is><t>SKU de servicio</t></is></c></row></sheetData></worksheet>');
    $zip->close();
    $bytes = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}

if (isset($_GET['plantilla'])) {
    $xlsx = $_GET['plantilla'] === 'xlsx';
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: ' . ($xlsx ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8'));
        header('Content-Disposition: attachment; filename="plantilla_servicios.' . ($xlsx ? 'xlsx' : 'csv') . '"');
    }
    echo $xlsx ? plantillaXlsx() : "\xEF\xBB\xBFSKU,SKU de servicio\r\n";
    exit;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function parseSkus(string $raw): array
{
    preg_match_all('/\d+/', $raw, $m);
    return array_values(array_unique($m[0]));
}

// Resumen bajo el cuadro de SKUs. Mismo criterio que parseSkus: cada número es un SKU.
// - texto: «Pegaste N SKUs: U únicos y R repetidos.», donde N son todos los pegados, U los distintos
//   (los que se procesan) y R las copias de más, así U + R = N. Sin repetidos: «Pegaste N SKUs, sin
//   repetidos.». Vacío si no hay SKUs.
// - limite: aviso si los únicos pasan de MAX_SKUS por corrida.
// - repetidos: R, para mostrar el botón «Quitar repetidos».
// El script de la página repite este cálculo para actualizarlo mientras se escribe.
// «Pegaste N SKUs: U únicos y R repetidos.» o, con planilla, «Subiste N filas: U únicas y R repetidas.».
// R son las copias de más (N - U). Sin repetidos: «Pegaste N SKUs, sin repetidos.».
function textoPegados(int $n, int $u, string $fuente = 'texto'): string
{
    $r = $n - $u;
    [$cosa, $cosas, $unico, $unicos, $rep, $reps, $verbo] = $fuente === 'planilla'
        ? ['fila', 'filas', 'única', 'únicas', 'repetida', 'repetidas', 'Subiste']
        : ['SKU', 'SKUs', 'único', 'únicos', 'repetido', 'repetidos', 'Pegaste'];
    $total = $verbo . ' ' . $n . ' ' . ($n === 1 ? $cosa : $cosas);
    return $r === 0 ? "$total, sin $reps."
        : "$total: $u " . ($u === 1 ? $unico : $unicos) . " y $r " . ($r === 1 ? $rep : $reps) . '.';
}

function resumenPegados(string $raw): array
{
    preg_match_all('/\d+/', $raw, $m);   // sin quitar repetidos
    $n = count($m[0]);
    $u = count(array_unique($m[0]));
    $r = $n - $u;
    return ['texto' => $n === 0 ? '' : textoPegados($n, $u), 'limite' => $u > MAX_SKUS ? 'Máximo ' . MAX_SKUS . " por corrida: tienes $u." : '', 'repetidos' => $r];
}

// Resumen de lo que se pegó o subió. Cada clave es un SKU (texto pegado) o "type:sku" (planilla): así,
// el mismo SKU con dos types distintos no cuenta como repetido. Devuelve los SKUs distintos, los SKUs que
// se repiten (en el orden en que aparecen), el total pegado, las claves únicas y la fuente. Se guarda en
// los parámetros de la corrida.
function resumenCopia(array $claves, string $fuente = 'texto'): array
{
    $sku = fn(string $k): string => (string) substr($k, (int) strrpos(':' . $k, ':'));
    $veces = array_count_values($claves);
    $repetidos = [];
    foreach ($veces as $k => $n) {
        if ($n > 1) {
            $repetidos[$sku((string) $k)] = true;
        }
    }
    return ['skus_copiados' => count(array_unique(array_map($sku, $claves))),
        'repetidos' => array_map('strval', array_keys($repetidos)),
        'pegados' => count($claves), 'unicos' => count($veces), 'fuente' => $fuente];
}

// Líneas bajo el título del previo, de la aplicación y de Consulta SKU: el mismo texto del cuadro
// («Pegaste…» o «Subiste N filas…») y la lista de los que se repiten. Las corridas guardadas antes no
// tienen el total pegado: solo muestran los SKUs únicos (skus_copiados, o total_skus en las más viejas).
function lineasCopia(array $p): array
{
    if (isset($p['pegados'], $p['unicos'])) {
        $lineas = [textoPegados((int) $p['pegados'], (int) $p['unicos'], (string) ($p['fuente'] ?? 'texto'))];
    } else {
        $n = (int) ($p['skus_copiados'] ?? $p['total_skus'] ?? 0);
        $lineas = [$n . ($n === 1 ? ' SKU único' : ' SKUs únicos')];
    }
    if (($p['repetidos'] ?? []) !== []) {
        $lineas[] = 'Se repiten: ' . implode(', ', $p['repetidos']);
    }
    return $lineas;
}

// VTEX intermitente: despues de los reintentos de lib/vtex.php quedo un HTTP 0 (sin conexion), 429 o 5xx
// en la salida del script o en el mensaje de alguna fila.
function vtexNoDisponible(string $texto): bool
{
    return preg_match('/HTTP (0|429|5\d\d)\b/', $texto) === 1;
}

// Toast de cada accion: [tipo, texto] o null. $tipo: consulta | previo | aplicar | tipos.
// Prioridad: VTEX no disponible > funcion del dashboard caida > resultado de aplicar > archivo subido.
function alertaResultado(string $tipo, array $result, bool $conArchivo): ?array
{
    $run = $result['run'] ?? ['code' => 0, 'out' => '', 'err' => ''];
    $rows = $result['rows'] ?? [];
    $texto = $run['out'] . "\n" . $run['err'] . "\n" . implode("\n", array_column($rows, 'mensaje'));
    $luego = ' no está disponible temporalmente. Intenta de nuevo en unos minutos.';
    if (vtexNoDisponible($texto)) {
        return ['tipo' => 'warn', 'texto' => 'VTEX' . $luego];
    }
    if ((int) $run['code'] !== 0) {
        $nombre = ['consulta' => 'Consulta SKU', 'previo' => 'Configuración del Servicio', 'aplicar' => 'Aplicar',
            'tipos' => 'Actualizar Servicios'][$tipo] ?? 'El servicio';
        return ['tipo' => 'warn', 'texto' => $nombre . $luego];
    }
    if ($tipo === 'aplicar') {
        return in_array('error', array_column($rows, 'status'), true)
            ? ['tipo' => 'err', 'texto' => 'No se pudieron configurar los SKUs']
            : ['tipo' => 'ok', 'texto' => 'Se configuraron exitosamente los SKUs'];
    }
    return $conArchivo ? ['tipo' => 'ok', 'texto' => 'Archivo subido exitosamente'] : null;
}

// PHP de consola para lanzar los scripts de bin/. Con «php -S» es PHP_BINARY; con Apache (mod_php)
// PHP_BINARY viene vacío y con php-fpm apunta a php-fpm, así que se usa PHP_CLI o «php» del PATH.
function phpCli(string $binario = PHP_BINARY): string
{
    if ($binario !== '' && !str_contains(basename($binario), 'fpm')) {
        return $binario;
    }
    $cli = getenv('PHP_CLI');
    return $cli === false || $cli === '' ? 'php' : $cli;
}

function runScript(array $args): array
{
    $cmd = array_merge([phpCli(), ROOT . '/bin/' . array_shift($args)], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT);
    if (!is_resource($proc)) {
        appLog('error', 'script.no_arranca', ['script' => basename($cmd[1])]);
        return ['code' => -1, 'out' => '', 'err' => 'No se pudo ejecutar el script'];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($proc), 'out' => (string) $out, 'err' => (string) $err];
}

// Log de cada acción del dashboard. Aplicar se anota siempre, porque escribe en VTEX. Consulta SKU,
// Configuración del Servicio (previo) y Actualizar Servicios solo cuando fallan: el script salió con
// error, VTEX no respondió (HTTP 0, 429 o 5xx), una fila quedó en error o un SKU no se pudo verificar.
// $accion: consulta | previo | aplicar | tipos.
function registrarAccion(string $accion, array $result, int $ms): void
{
    $run = $result['run'] ?? ['code' => 0, 'out' => '', 'err' => ''];
    $rows = $result['rows'] ?? [];
    $filas = ['ok' => 0, 'error' => 0, 'omitido' => 0];
    foreach (array_column($rows, 'status') as $st) {
        if (isset($filas[$st])) {
            $filas[$st]++;
        }
    }
    $texto = $run['out'] . "\n" . $run['err'] . "\n" . implode("\n", array_column($rows, 'mensaje'));
    $fallo = (int) $run['code'] !== 0 || vtexNoDisponible($texto) || $filas['error'] > 0
        || in_array('sin_verificar', array_column($rows, 'accion'), true)
        || in_array('error de consulta', array_column($rows, 'estado'), true);
    if (!$fallo && $accion !== 'aplicar') {
        return;
    }
    $datos = ['accion' => $accion, 'corrida' => $result['id'] ?? null, 'codigo' => (int) $run['code'], 'ms' => $ms];
    if ($accion === 'aplicar') {
        $datos['grupos'] = array_map(fn(array $g): array => ['type' => $g['type'], 'skus' => $g['skus']], $result['params']['grupos'] ?? []);
        $datos['filas'] = $filas;
    }
    if ($fallo) {
        $datos['salida'] = trim($run['err']) !== '' ? trim($run['err']) : trim($run['out']);
    }
    appLog($fallo ? 'error' : 'info', 'accion', $datos);
}

// Excepciones del dashboard. Las RuntimeException son los mensajes para quien usa el formulario (falta
// elegir el servicio, archivo mal llenado, más de MAX_SKUS): ya se ven en pantalla y no van al log.
function registrarExcepcion(string $accion, Throwable $e): void
{
    if ($e instanceof RuntimeException) {
        return;
    }
    appLog('error', 'dashboard.excepcion', ['accion' => $accion, 'tipo' => $e::class, 'mensaje' => $e->getMessage(),
        'archivo' => basename($e->getFile()), 'linea' => $e->getLine()]);
}

function readCsv(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $fh = fopen($path, 'r');
    $header = fgetcsv($fh, null, ',', '"', '');
    $rows = [];
    while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        if (count($r) === count($header)) {
            $rows[] = array_combine($header, $r);
        }
    }
    fclose($fh);
    return $rows;
}

// Una corrida guarda sus SKUs por type: un skus_<type>.txt por type y params.json con la lista.
function newRun(array $grupos, array $params): string
{
    $id = date('Ymd_His') . '_' . bin2hex(random_bytes(2));
    mkdir(RUNS . "/$id", 0775, true);
    foreach ($grupos as $type => $skus) {
        file_put_contents(RUNS . "/$id/skus_$type.txt", implode("\n", $skus) . "\n");
    }
    file_put_contents(RUNS . "/$id/params.json", json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $id;
}

function loadRun(string $id): ?array
{
    if (!preg_match('/^\d{8}_\d{6}_[0-9a-f]{4}$/', $id) || !is_file(RUNS . "/$id/params.json")) {
        return null;
    }
    $params = json_decode((string) file_get_contents(RUNS . "/$id/params.json"), true);
    return isset($params['grupos']) ? $params : null;
}

// Corre el script una vez por type. Se detiene en el primer error del script (credenciales, value
// que no corresponde al type, etc.) para no aplicar a medias sin avisar.
function runGroups(string $id, array $params, bool $aplicar): array
{
    $prefijo = $aplicar ? 'aplicacion' : 'previo';
    $res = ['code' => 0, 'out' => '', 'err' => '', 'rows' => []];
    foreach ($params['grupos'] as $g) {
        $args = [
            'apply-instalacion-bracket.php',
            '--input=' . RUNS . "/$id/skus_{$g['type']}.txt",
            '--output=' . RUNS . "/$id/{$prefijo}_{$g['type']}.csv",
            '--servicetype=' . $g['type'],
            '--servicevalue=' . $g['value'],
            '--name=' . $g['name'],
        ];
        if ($aplicar) {
            $args[] = '--aplicar';
        }
        $run = runScript($args);
        $res['out'] .= "== type {$g['type']}\n" . $run['out'];
        $res['err'] .= $run['err'] === '' ? '' : "== type {$g['type']}\n" . $run['err'];
        $res['rows'] = array_merge($res['rows'], readCsv(RUNS . "/$id/{$prefijo}_{$g['type']}.csv"));
        // Precio del value, tal como VTEX lo devolvio al validarlo; la tabla lo muestra en vez del id.
        $json = json_decode((string) @file_get_contents(RUNS . "/$id/{$prefijo}_{$g['type']}.json"), true);
        if (isset($json['service_value_validado']['Value'])) {
            $res['precios'][$g['type']] = $json['service_value_validado']['Value'];
        }
        if ($run['code'] !== 0) {
            $res['code'] = $run['code'];
            $res['err'] = "Falló en el type {$g['type']}; los types siguientes no se procesaron.\n" . $res['err'];
            break;
        }
    }
    return $res;
}

// Tabla del previo y de la aplicacion: el type se lee por su nombre en VTEX y el value por su
// precio. Solo cambia lo que se ve; los CSV de la corrida conservan los ids. Sin dato, queda el id.
function encabezadoPrevio(string $col): string
{
    return ['nombre_producto' => 'Nombre del producto', 'target_service_type_id' => 'servicio',
        'target_service_value_id' => 'precio'][$col] ?? $col;
}

function celdaPrevio(string $col, array $row, array $types, array $precios): string
{
    $type = (int) ($row['target_service_type_id'] ?? 0);
    if ($col === 'target_service_type_id') {
        return (string) ($types[$type]['name'] ?? $row[$col]);
    }
    if ($col === 'target_service_value_id') {
        return isset($precios[$type]) ? 'Q' . $precios[$type] : (string) $row[$col];
    }
    if ($col === 'accion') {
        return accionVisible((string) $row[$col]);
    }
    // Mensaje en lenguaje simple, segun accion y status; el CSV conserva el original del script.
    if ($col === 'mensaje') {
        $simple = [
            'crear|dry-run' => 'agregar servicio',                 // «POST pendiente»
            'sin_cambios|ok' => 'tiene el servicio aplicado',      // «Ya estaba con el name/text/active correctos»
        ][($row['accion'] ?? '') . '|' . ($row['status'] ?? '')] ?? null;
        if ($simple !== null) {
            return $simple;
        }
    }
    return (string) ($row[$col] ?? '');
}

// Consultar en vivo: la columna Name muestra el nombre del service type (de la copia de types,
// sin filtrar por grupo). Sin nombre, queda el Name de VTEX.
function nombresDeTypes(string $path): array
{
    $nombres = [];
    foreach (readCsv($path) as $r) {
        $nombres[(int) $r['Id']] = $r['Name'];
    }
    return $nombres;
}

// La tabla de Consultar en vivo solo muestra servicios de types de la lista oficial (isOfficialServiceType,
// desde el 2026-11-13). Eso ya deja fuera Empaque de regalo (1) y GEX (56-60), pedido del 2026-11-09.
// Quita las demas filas. Un SKU que se queda sin servicios conserva una fila vacia («Sin servicios»).
function filasConsultaValidas(array $rows): array
{
    $out = [];
    $vistos = [];
    foreach ($rows as $r) {
        $vistos[$r['sku']] ??= $r;
        if ($r['skuservice_id'] !== '' && !isOfficialServiceType((int) $r['type'])) {
            continue;
        }
        $out[] = $r;
    }
    // Las claves numericas de PHP vuelven enteros: se compara como texto.
    $conFila = array_map('strval', array_column($out, 'sku'));
    foreach ($vistos as $sku => $r) {
        if (!in_array((string) $sku, $conFila, true)) {
            $out[] = ['skuservice_id' => '', 'type' => '', 'sku_servicio' => '', 'precio' => '', 'name' => ''] + $r;
        }
    }
    // Se respeta el orden en que el script devolvio los SKUs.
    $orden = array_flip(array_map('strval', array_keys($vistos)));
    usort($out, fn($a, $b) => $orden[(string) $a['sku']] <=> $orden[(string) $b['sku']]);
    return $out;
}

function nombreConsulta(array $row, array $nombres): string
{
    return (string) ($nombres[(int) ($row['type'] ?? 0)] ?? $row['name'] ?? '');
}

// Avisos sobre la tabla del previo, la aplicación y Consulta SKU: SKUs que no existen en VTEX (404) y
// SKUs que no se pudieron verificar (otro error). Las filas del previo traen «accion» (no_existe,
// sin_verificar); las de Consulta SKU, «estado» («no existe», «error de consulta»). $tipo: previo | consulta.
function avisosSkus(array $rows, string $tipo): array
{
    $grupos = ['no_existe' => [], 'sin_verificar' => []];
    foreach ($rows as $r) {
        $k = $r['accion'] ?? (['no existe' => 'no_existe', 'error de consulta' => 'sin_verificar'][$r['estado'] ?? ''] ?? '');
        if (isset($grupos[$k])) {
            $grupos[$k][(string) $r['sku']] = true;
        }
    }
    $avisos = [];
    if ($n = count($grupos['no_existe'])) {
        $lista = implode(', ', array_keys($grupos['no_existe']));
        $avisos[] = ($n === 1 ? "1 SKU no existe en VTEX: $lista." : "$n SKUs no existen en VTEX: $lista.")
            . ($tipo === 'previo'
                ? ($n === 1 ? ' No se va a configurar; revisa si está mal escrito.' : ' No se van a configurar; revisa si están mal escritos.')
                : ($n === 1 ? ' Revisa si está mal escrito.' : ' Revisa si están mal escritos.'));
    }
    if ($n = count($grupos['sin_verificar'])) {
        $lista = implode(', ', array_keys($grupos['sin_verificar']));
        $avisos[] = ($n === 1 ? "1 SKU no se pudo verificar en VTEX: $lista." : "$n SKUs no se pudieron verificar en VTEX: $lista.")
            . ($tipo === 'previo' ? ' Vuelve a generar el previo.' : ' Vuelve a consultar.');
    }
    return $avisos;
}

// Las acciones se leen con espacios («sin cambios»); el CSV y el codigo usan guion bajo.
function accionVisible(string $accion): string
{
    return str_replace('_', ' ', $accion);
}

// Planilla subida (input "archivo"), o null si no se subio nada.
function uploadedSheet(): ?array
{
    $f = $_FILES['archivo'] ?? null;
    if ($f === null || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir el archivo (código ' . $f['error'] . ').');
    }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    $sheet = serviceRowsFromSheet(readSheetRows($f['tmp_name'], $ext));
    return $sheet + ['tmp' => $f['tmp_name'], 'name' => basename((string) $f['name']), 'ext' => $ext];
}

// Una planilla lleva un solo SKU de servicio, que se puede repetir en todas las filas. Devuelve el error si trae
// mas de uno distinto, con las primeras filas de cada uno. Cada SKU de servicio es de un solo type.
function errorVariosServicios(array $items): ?string
{
    $filas = [];
    foreach ($items as $it) {
        $filas[$it['type']][] = $it['fila'];
    }
    if (count($filas) < 2) {
        return null;
    }
    $skus = SERVICE_SKUS + newServiceSkus();
    $detalle = array_map(fn(int $type, array $f): string => "{$skus[$type]} (filas " . implode(', ', array_slice($f, 0, 5))
        . (count($f) > 5 ? '…' : '') . ')', array_keys($filas), $filas);
    return 'La planilla debe traer un solo SKU de servicio (puede repetirse en todas las filas) y trae ' . count($filas) . ': '
        . implode('; ', $detalle) . '. Sube un archivo por SKU de servicio.';
}

// Types activos de VTEX que caen en Instalacion, Armado, Visita o Reemplazo y estan en la lista
// oficial (isOfficialServiceType), indexados por id.
function loadServiceTypes(string $path): array
{
    $types = [];
    foreach (readCsv($path) as $r) {
        $group = serviceGroup($r['Name']);
        if ($group === null || $r['IsActive'] !== 'si' || !isOfficialServiceType((int) $r['Id'])) {
            continue;
        }
        $id = (int) $r['Id'];
        $types[$id] = ['id' => $id, 'name' => $r['Name'], 'group' => $group,
            'value' => serviceValue($id), 'defaultName' => serviceDefaultName($id, $group)];
    }
    return $types;
}

// Aviso por cada type activo con mas de un value en VTEX: no se habilita hasta que quede uno.
function avisosValuesRepetidos(array $repetidos, array $types): array
{
    $avisos = [];
    foreach (array_intersect_key($repetidos, $types) as $id => $values) {
        $avisos[] = "El type $id «{$types[$id]['name']}» tiene " . count($values) . ' values en VTEX (' . implode(', ', $values)
            . '). No se puede configurar hasta que quede uno solo.';
    }
    return $avisos;
}

// Arranque: si falta una credencial o un archivo de referencia, o no se puede escribir en cache/ o runs/,
// se anota una vez. Se vuelve a anotar solo si la lista de problemas cambia (marca en cache/arranque.hash).
function problemasArranque(array $variables, array $archivos, array $carpetas): array
{
    $p = [];
    foreach ($variables as $v) {
        $valor = getenv($v);
        if (($valor === false || $valor === '') && (string) ($_ENV[$v] ?? '') === '') {
            $p[] = "Falta la variable de entorno $v.";
        }
    }
    foreach ($archivos as $a) {
        if (!is_readable($a) || (int) @filesize($a) === 0) {
            $p[] = 'No se puede leer el archivo ' . basename($a) . '.';
        }
    }
    foreach ($carpetas as $c) {
        if (!is_dir($c) ? !@mkdir($c, 0775, true) : !is_writable($c)) {
            $p[] = 'No se puede escribir en la carpeta ' . basename($c) . '/.';
        }
    }
    return $p;
}

function registrarArranque(array $problemas, string $marca): void
{
    $hash = md5(json_encode($problemas));
    if ($hash === @file_get_contents($marca)) {
        return;
    }
    if ($problemas !== []) {
        appLog('error', 'arranque.problema', ['problemas' => $problemas]);
    }
    @file_put_contents($marca, $hash);
}

// Solo desde el servidor web: los tests incluyen esta página por CLI.
if (PHP_SAPI !== 'cli') {
    registrarArranque(problemasArranque(['API_KEY', 'API_TOKEN'], [OFFICIAL_TYPES_CSV, is_file(TYPES_CACHE) ? TYPES_CACHE : TYPES_FALLBACK],
        [__DIR__ . '/cache', RUNS]), __DIR__ . '/cache/arranque.hash');
}

$action = $_POST['action'] ?? '';
$rawSkus = (string) ($_POST['skus'] ?? '');
$error = null;
$result = null;   // ['tipo' => 'consulta'|'previo'|'aplicar', ...]
$notice = null;
$inicioAccion = microtime(true);

try {
    if ($action === 'tipos') {
        @mkdir(dirname(TYPES_CACHE), 0775, true);
        $run = runScript(['update-services.php', '--types=' . TYPES_CACHE, '--values=' . VALUES_COPY_JSON]);
        if ($run['code'] !== 0) {
            $tiposFallo = $run;   // el toast distingue VTEX no disponible de otro fallo
            throw new RuntimeException('No se pudo completar la actualización desde VTEX: ' . trim($run['err']));
        }
        $notice = 'Types actualizados desde VTEX.'
            . (preg_match('/^Values nuevos habilitados: (type .+)$/m', $run['out'], $m) ? ' Values nuevos habilitados: ' . $m[1] : '')
            . (savedServiceValues()['completo'] ? '' : ' La búsqueda de values sigue en segundo plano.');
    }

    if ($action === 'consultar' || $action === 'previo') {
        $upload = uploadedSheet();
        if ($upload !== null && trim($rawSkus) !== '') {
            throw new RuntimeException('Usa los SKUs pegados o el archivo, no los dos.');
        }
        if ($upload !== null) {
            if ($upload['errores'] !== []) {
                throw new RuntimeException("El archivo tiene filas inválidas. Corrígelas y vuelve a subirlo:\n"
                    . implode("\n", array_slice($upload['errores'], 0, 10))
                    . (count($upload['errores']) > 10 ? "\n… y " . (count($upload['errores']) - 10) . ' más.' : ''));
            }
            if ($upload['items'] === []) {
                throw new RuntimeException('El archivo no tiene filas activas para procesar.');
            }
            $varios = errorVariosServicios($upload['items']);
            if ($varios !== null) {
                throw new RuntimeException($varios);
            }
            // type => SKUs únicos, en el orden del archivo.
            $grupos = [];
            foreach ($upload['items'] as $it) {
                $grupos[$it['type']][$it['sku']] = $it['sku'];
            }
            $grupos = array_map('array_values', $grupos);
            $skus = array_values(array_unique(array_column($upload['items'], 'sku')));
            $copia = resumenCopia(array_map(fn(array $it): string => $it['type'] . ':' . $it['sku'], $upload['items']), 'planilla');
        } else {
            $skus = parseSkus($rawSkus);
            if ($skus === []) {
                throw new RuntimeException('No hay SKUs válidos.');
            }
            $type = (int) ($_POST['type'] ?? 0);
            if ($type === 0 && $action === 'previo') {   // Consulta SKU no usa el servicio
                throw new RuntimeException('Elige el servicio a configurar.');
            }
            $grupos = [$type => $skus];
            preg_match_all('/\d+/', $rawSkus, $m);   // mismo criterio que parseSkus, sin quitar repetidos
            $copia = resumenCopia($m[0]);
        }
        $pares = array_sum(array_map('count', $grupos));
        if ($pares > MAX_SKUS) {
            throw new RuntimeException('Máximo ' . MAX_SKUS . " SKUs por corrida (este lote trae $pares); para lotes más grandes usa la terminal o divide el archivo.");
        }
    }

    if ($action === 'consultar') {
        // El script escribe una fila por servicio en un CSV temporal; con eso se arma la tabla.
        $csv = tempnam(sys_get_temp_dir(), 'consulta_');
        $run = runScript(['check-skus-services-live.php', '--skus=' . implode(',', $skus), '--output=' . $csv]);
        $result = ['tipo' => 'consulta', 'run' => $run, 'rows' => filasConsultaValidas(readCsv($csv)), 'copia' => $copia];
        @unlink($csv);
    }

    if ($action === 'previo') {
        // Value y Name no vienen del formulario ni del archivo: salen del type (lib/reglas.php).
        $allowed = loadServiceTypes(is_file(TYPES_CACHE) ? TYPES_CACHE : TYPES_FALLBACK);
        $problemas = [];
        $params = ['origen' => $upload === null ? 'formulario' : $upload['name'], 'grupos' => [], 'total_skus' => $pares] + $copia;
        foreach ($grupos as $type => $lista) {
            $donde = $upload === null ? '' : ' (filas ' . implode(', ', array_slice(array_column(
                array_filter($upload['items'], fn($it) => $it['type'] === $type), 'fila'), 0, 5)) . ')';
            $t = $allowed[$type] ?? null;
            $grupo = SERVICE_GROUPS[$t['group'] ?? ''] ?? '';
            if ($t === null) {
                $problemas[] = $upload === null ? 'Elige un servicio de la lista.'
                    : "El type $type$donde no es un servicio activo de Instalación, Armado, Visita o Reemplazo.";
            } elseif ($t['value'] === null) {
                $problemas[] = "El type $type$donde no tiene value conocido. No se puede configurar todavía.";
            } elseif ($t['defaultName'] === '') {
                $problemas[] = "El type $type$donde no tiene Name canónico definido ({$grupo}). No se puede configurar todavía.";
            } else {
                $params['grupos'][] = ['type' => $type, 'value' => $t['value'], 'name' => $t['defaultName'], 'skus' => count($lista)];
            }
        }
        if ($problemas !== []) {
            throw new RuntimeException(implode("\n", $problemas));
        }
        if ($upload !== null) {
            $params['filas_inactivas'] = $upload['inactivas'];
        }
        $id = newRun($grupos, $params);
        if ($upload !== null) {
            move_uploaded_file($upload['tmp'], RUNS . "/$id/archivo.{$upload['ext']}");
        }
        $result = ['tipo' => 'previo', 'id' => $id, 'params' => $params] + runGroups($id, $params, false);
        $result['run'] = ['code' => $result['code'], 'out' => $result['out'], 'err' => $result['err']];
    }

    if ($action === 'aplicar') {
        $id = (string) ($_POST['run_id'] ?? '');
        $params = loadRun($id);
        if ($params === null || glob(RUNS . "/$id/previo_*.csv") === []) {
            throw new RuntimeException('Esa corrida no tiene previo. Genera el previo primero.');
        }
        if (glob(RUNS . "/$id/aplicacion_*.csv") !== []) {
            throw new RuntimeException("La corrida $id ya se aplicó. Genera un previo nuevo.");
        }
        if (($_POST['confirmo'] ?? '') !== 'si') {
            throw new RuntimeException('Marca la casilla de confirmación para aplicar.');
        }
        $result = ['tipo' => 'aplicar', 'id' => $id, 'params' => $params] + runGroups($id, $params, true);
        $result['run'] = ['code' => $result['code'], 'out' => $result['out'], 'err' => $result['err']];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    registrarExcepcion($action, $e);
}
$msAccion = (int) round((microtime(true) - $inicioAccion) * 1000);
if ($result !== null) {
    registrarAccion($result['tipo'], $result, $msAccion);
} elseif (isset($tiposFallo)) {
    registrarAccion('tipos', ['run' => $tiposFallo], $msAccion);
}

// Columnas de la tabla del previo y de la aplicacion. La clasificacion por pulgadas solo tiene
// sentido para brackets de TV.
function columnasVisibles(array $rows, array $types): array
{
    if ($rows === []) {
        return [];
    }
    // Los ids de relacion son para el perfil tecnico de VTEX: quedan en el CSV de la corrida, no en la tabla.
    $cols = array_values(array_diff(array_keys($rows[0]), ['sku_service_id_previo', 'final_sku_service_id']));
    if (array_intersect($types, [15, 16]) === []) {
        $cols = array_values(array_diff($cols, ['clasificacion', 'fuera_de_rango']));
    }
    return $cols;
}

// Modulos del menu lateral: ?m=cobertura (graficos, pagina de inicio) o ?m=config (configurar servicios).
$module = ($_GET['m'] ?? '') === 'config' ? 'config' : 'cobertura';

// Cobertura: bin/coverage-by-category.php tarda varios minutos (baja ~164k relaciones y pagina
// todo el catalogo), asi que corre en segundo plano. La pagina se recarga sola mientras el log
// no tenga la linea EXIT:<codigo>. Un log sin EXIT de mas de 20 minutos se da por fallido.
const COVERAGE_CACHE = __DIR__ . '/cache/coverage.json';
const COVERAGE_LOG = __DIR__ . '/cache/coverage.log';

// Tareas en segundo plano (Cobertura y actualización automática de types). Se lanzan con bin/job.php,
// que deja la línea EXIT:<código> en su registro y anota «tarea.fin» en el log. Aquí se anota el inicio
// y, una sola vez, la tarea que pasó 20 minutos sin terminar (marca <registro>.trabada).
function lanzarTarea(string $nombre, string $registro, string $script, array $args): void
{
    @mkdir(dirname($registro), 0775, true);
    @unlink("$registro.trabada");
    // Se vacía aquí, antes de lanzar: la siguiente carga de la página no debe leer el EXIT de la tarea anterior.
    @file_put_contents($registro, '');
    $cmd = array_map('escapeshellarg', array_merge([phpCli(), ROOT . '/bin/job.php', $nombre, $registro, $script], $args));
    exec(implode(' ', $cmd) . ' > /dev/null 2>&1 &');
    appLog('info', 'tarea.inicio', ['tarea' => $nombre]);
}

function avisarTrabada(string $nombre, string $registro): void
{
    if (is_file("$registro.trabada")) {
        return;
    }
    appLog('error', 'tarea.trabada', ['tarea' => $nombre, 'minutos_sin_terminar' => (int) floor((time() - (int) @filemtime($registro)) / 60)]);
    @touch("$registro.trabada");
}

function coverageJob(): array
{
    if (!is_file(COVERAGE_LOG)) {
        return ['running' => false, 'exit' => null, 'progress' => '', 'last' => ''];
    }
    $log = (string) file_get_contents(COVERAGE_LOG);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $log))));
    preg_match_all('/^\[\d+\/\d+\] .*$/m', $log, $m);
    $exit = preg_match('/^EXIT:(\d+)$/m', $log, $e) ? (int) $e[1] : null;
    $stale = $exit === null && filemtime(COVERAGE_LOG) < time() - 1200;
    if ($stale) {
        avisarTrabada('cobertura', COVERAGE_LOG);
    }
    $messages = array_filter($lines, fn($l) => !str_starts_with($l, 'EXIT:'));
    return [
        'running' => $exit === null && !$stale,
        'exit' => $stale ? -1 : $exit,
        'progress' => end($m[0]) ?: 'Descargando relaciones de servicio…',
        'last' => (string) end($messages),
    ];
}

// Actualización automática de types: si la copia de TYPES_CACHE tiene más de 3 días, al abrir el
// dashboard se lanza en segundo plano el mismo comando del botón «Actualizar Servicios» y la página
// carga sin esperar. Tras una falla se espera 1 hora antes de reintentar. Solo lee de VTEX (GET).
const TYPES_AUTO_LOG = __DIR__ . '/cache/types_auto.log';
const TYPES_VIGENCIA = 3 * 86400;
const TYPES_REINTENTO = 3600;

function typesAutoJob(): array
{
    if (!is_file(TYPES_AUTO_LOG)) {
        return ['running' => false, 'exit' => null, 'fecha' => null];
    }
    $fecha = (int) filemtime(TYPES_AUTO_LOG);
    $exit = preg_match('/^EXIT:(\d+)$/m', (string) file_get_contents(TYPES_AUTO_LOG), $e) ? (int) $e[1] : null;
    $stale = $exit === null && $fecha < time() - 1200;
    if ($stale) {
        avisarTrabada('types_auto', TYPES_AUTO_LOG);
    }
    return ['running' => $exit === null && !$stale, 'exit' => $stale ? -1 : $exit, 'fecha' => $fecha];
}

// ¿Toca lanzar la actualización automática? $copia es la fecha de TYPES_CACHE (null si no existe).
function typesVencidos(?int $copia, array $auto, int $ahora, bool $valuesIncompletos = false): bool
{
    if ($auto['running']) {
        return false;
    }
    // Una falla posterior a la copia se reintenta aunque la copia sea reciente: pudieron fallar solo los values.
    $fallo = $auto['exit'] !== null && $auto['exit'] !== 0 && ($copia === null || (int) $auto['fecha'] >= $copia);
    if ($fallo) {
        return $ahora - (int) $auto['fecha'] >= TYPES_REINTENTO;
    }
    return $valuesIncompletos || $copia === null || $ahora - $copia >= TYPES_VIGENCIA;
}

$typesAuto = typesAutoJob();
// Solo desde el servidor web: los tests incluyen esta página por CLI y no deben leer VTEX.
if (PHP_SAPI !== 'cli' && typesVencidos(is_file(TYPES_CACHE) ? (int) filemtime(TYPES_CACHE) : null, $typesAuto, time(), !savedServiceValues()['completo'])) {
    lanzarTarea('types_auto', TYPES_AUTO_LOG, 'update-services.php',
        ['--types=' . TYPES_CACHE, '--values=' . VALUES_COPY_JSON, '--hasta-terminar']);
    $typesAuto = ['running' => true, 'exit' => null, 'fecha' => time()];
}

$job = coverageJob();
if ($action === 'cobertura' && !$job['running']) {
    lanzarTarea('cobertura', COVERAGE_LOG, 'coverage-by-category.php', ['--output=' . COVERAGE_CACHE]);
}
if ($action === 'cobertura') {
    header('Location: ?m=cobertura&esperando=1', true, 303);
    exit;
}

$coverage = is_file(COVERAGE_CACHE) ? json_decode((string) file_get_contents(COVERAGE_CACHE), true) : null;
$toast = null;   // ['tipo' => 'ok'|'warn'|'err', 'texto' => ...]
if ($result !== null) {
    $toast = alertaResultado($result['tipo'], $result, ($upload ?? null) !== null);
} elseif (isset($tiposFallo)) {
    $toast = alertaResultado('tipos', ['run' => $tiposFallo], false);
}

function pct(int $configured, int $eligible): float
{
    return $eligible > 0 ? $configured / $eligible * 100 : 0.0;
}

function num(int|float $n): string
{
    return number_format($n, 0, '.', ',');
}

// Cuantos SKUs faltan configurar para llegar a la meta.
function faltan(array $c, int $target): int
{
    return max(0, (int) ceil($c['eligible'] * $target / 100) - $c['configured']);
}

// Nodos de un nivel del arbol (1 departamento, 2 categoria, 3 subcategoria), sin los vacios.
function level(array $departments, int $n): array
{
    $nodes = $departments;
    for ($i = 1; $i < $n; $i++) {
        $nodes = array_merge([], ...array_map(fn($c) => $c['children'] ?? [], $nodes));
    }
    return array_values(array_filter($nodes, fn($c) => $c['eligible'] > 0));
}

function bajoMeta(array $nodes, int $target): int
{
    return count(array_filter($nodes, fn($c) => pct($c['configured'], $c['eligible']) < $target));
}

// Filas para un grafico: sin categorias vacias, de mayor a menor cobertura.
function chartRows(array $items): array
{
    $rows = array_values(array_filter($items, fn($c) => $c['eligible'] > 0));
    usort($rows, fn($a, $b) => [pct($b['configured'], $b['eligible']), $b['eligible']]
        <=> [pct($a['configured'], $a['eligible']), $a['eligible']]);
    return $rows;
}

// Barras horizontales de % configurado (eje 0-100) con la linea de la meta. Cada fila lleva en
// data-* lo que muestra el tooltip; con $link ('dept' o 'cat') la fila abre el detalle del nivel de abajo.
function renderChart(array $rows, int $target, ?string $link): string
{
    $html = '<div class="chart">';
    foreach ($rows as $c) {
        $p = pct($c['configured'], $c['eligible']);
        $html .= sprintf(
            '<div class="bar-row%s" data-id="%d" data-name="%s" data-tip="%s de %s SKUs configurados" data-falta="%s">'
            . '<span class="bar-name">%s</span><span class="bar-track"><span class="bar" style="width:%.2f%%"></span></span>'
            . '<span class="bar-val">%s%%</span></div>',
            $link ? " link\" data-link=\"$link" : '', $c['id'], h($c['name']), num($c['configured']), num($c['eligible']),
            faltan($c, $target) > 0 ? 'Faltan ' . num(faltan($c, $target)) . " para el $target%" : 'Cumple la meta',
            h($c['name']), $p, number_format($p, 1)
        );
    }
    $html .= '<div class="chart-axis"><span></span><span class="ticks"><span style="left:0">0%</span><span style="left:25%">25%</span><span style="left:50%">50%</span><span style="left:75%">75%</span><span style="left:100%">100%</span></span><span></span></div>';
    $html .= "<div class=\"chart-target\"><span>Meta $target%</span></div></div>";
    return $html;
}

// Departamentos con más SKUs configurados, de mayor a menor cantidad. La barra es el % configurado de
// los elegibles del departamento (0-100%), igual que los demás gráficos. Sin departamentos con 0 configurados.
function renderTopConfigurados(array $departments, int $n): string
{
    $rows = array_values(array_filter($departments, fn($c) => $c['configured'] > 0));
    usort($rows, fn($a, $b) => $b['configured'] <=> $a['configured']);
    $rows = array_slice($rows, 0, $n);
    $html = '<div class="chart chart-top">';
    foreach ($rows as $c) {
        $html .= sprintf(
            '<div class="bar-row" data-name="%s" data-tip="%s de %s SKUs elegibles">'
            . '<span class="bar-name">%s</span><span class="bar-track"><span class="bar" style="width:%.2f%%"></span></span>'
            . '<span class="bar-val">%s · %s%%</span></div>',
            h($c['name']), num($c['configured']), num($c['eligible']), h($c['name']),
            pct($c['configured'], $c['eligible']), num($c['configured']), number_format(pct($c['configured'], $c['eligible']), 1)
        );
    }
    return $html . '</div>';
}

function fechaLarga(string $iso): string
{
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $t = strtotime($iso);
    return date('j', $t) . ' de ' . $meses[(int) date('n', $t) - 1];
}

function renderTable(array $rows, int $target): string
{
    $html = '<div class="tablewrap"><table><tr><th>Categoría</th><th>Elegibles</th><th>Configurados</th><th>% configurado</th><th>Faltan para la meta</th></tr>';
    foreach ($rows as $c) {
        $html .= sprintf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s%%</td><td>%s</td></tr>', h($c['name']),
            num($c['eligible']), num($c['configured']), number_format(pct($c['configured'], $c['eligible']), 1),
            num(faltan($c, $target)));
    }
    return $html . '</table></div>';
}

if ($module === 'cobertura' && isset($_GET['esperando']) && !$job['running'] && $job['exit'] !== null) {
    if ($job['exit'] !== 0) {
        $toast = ['tipo' => 'err', 'texto' => 'No se pudo actualizar la cobertura: ' . $job['last']];
    } elseif ($coverage !== null) {
        $t = (int) $coverage['target'];
        $bajo = array_map(fn($n) => bajoMeta(level($coverage['departments'], $n), $t), [1, 2, 3]);
        $g = $coverage['global'];
        $toast = array_sum($bajo) > 0
            ? ['tipo' => 'warn', 'texto' => sprintf('Cobertura actualizada: %s%% global. Bajo la meta del %d%%: %d departamentos, %d categorías y %d subcategorías.',
                number_format(pct($g['configured'], $g['eligible']), 1), $t, ...$bajo)]
            : ['tipo' => 'ok', 'texto' => 'Cobertura actualizada: todos los departamentos, categorías y subcategorías cumplen la meta.'];
    }
}

$typesFromVtex = is_file(TYPES_CACHE);
$typesPath = $typesFromVtex ? TYPES_CACHE : TYPES_FALLBACK;
$serviceTypes = loadServiceTypes($typesPath);
// Configurar servicios solo muestra los types que se pueden configurar: los que tienen value (desde el
// 2026-11-13). Cobertura sigue mostrando todos. El previo vuelve a rechazar un type sin value que llegue igual.
$configTypes = array_filter($serviceTypes, fn($t) => $t['value'] !== null);
$configGroups = array_intersect_key(SERVICE_GROUPS, array_flip(array_column($configTypes, 'group')));
$typesDate = is_file($typesPath) ? date('Y-m-d H:i', (int) filemtime($typesPath)) : null;
// Por defecto ningún grupo ni servicio elegido. Consulta SKU no usa el servicio: tras consultar, los
// selects vuelven a «Elige un grupo» y «Elige un servicio».
$selGroup = $action === 'consultar' ? '' : (string) ($_POST['grupo'] ?? '');
$selType = $action === 'consultar' ? 0 : (int) ($_POST['type'] ?? 0);

$accionClase = [
    'crear' => 'crear', 'actualizar_existente' => 'actualizar', 'sin_cambios' => 'neutro',
    'revisar_manual' => 'alerta', 'omitido' => 'neutro',
    'no_existe' => 'error', 'sin_verificar' => 'error',   // SKU que no existe o no se pudo verificar: no se configura
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Servicios VTEX</title>
<?php if ($module === 'cobertura' && $job['running']): ?>
<meta http-equiv="refresh" content="5;url=?m=cobertura&amp;esperando=1">
<?php endif; ?>
<style>
/* Sistema de diseño de cemaco.com, medido en vivo el 2026-11-03 (metodo-sistema-diseno.md).
   Origen de cada token en design/cemaco-tokens.json. Solo modo claro: la tienda no tiene oscuro.
   Ningún componente usa valores sueltos: todo sale de estas variables. */
:root {
    --font: HelveticaNeueLTW05, sans-serif;
    --fs-10: 10px; --lh-10: 11px; --fs-12: 12px; --lh-12: 14px; --fs-14: 14px; --lh-14: 16px;
    --fs-16: 16px; --lh-16: 18px; --fs-20: 20px; --lh-20: 23px; --fs-24: 24px; --lh-24: 28px;
    --fs-display: 40px; --lh-display: 44px; --w-regular: 400; --w-bold: 700;

    --c-primary: #101e8d; --c-primary-hover: #072c75; --c-focus: #94d500;
    --c-text: #000000; --c-text-2: #333333; --c-muted: #707070; --c-subtle: #b5b5b5;
    --c-border: #707070; --c-border-2: #c7c7c7; --c-line: #e1e1e1; --c-border-disabled: #e3e4e6;
    --c-bg: #ffffff; --c-bg-2: #f6f6f6; --c-tint: #f1f2ff; --c-muted-5: #f2f4f5; --c-disabled: #979899;
    --c-link: #0f3e99; --c-link-hover: #0c389f; --c-on-primary: #ffffff;
    --c-danger: #ff4c4c; --c-danger-faded: #ffe6e6; --c-success: #8bc34a; --c-success-faded: #eafce3;
    --c-warning: #ffb100; --c-warning-faded: #fff6e0; --c-on-warning-faded: #1a1a1a;

    --r-xs: 4px; --r-sm: 5px; --r-md: 8px; --r-lg: 10px; --r-input: 20px; --r-pill: 25px;
    --shadow-1: 0 3px 6px rgba(0, 0, 0, .16); --shadow-2: 4px 7px 4px rgba(0, 0, 0, .23);
    --t-link: color .15s ease-in;

    --topbar-h: 72px; --logo-h: 30px; --btn-h: 32px; --input-h: 40px;
    --pad-panel: 16px 24px; --pad-msg: 9px 16px 9px 12px; --pad-pill: 5px 10px;
    --pad-btn: 0 16px; --pad-btn-2: 8px 16px; --pad-input: 1px 16px; --pad-textarea: 8px 16px;
    --gap: 16px; --gap-sm: 8px;
}
* { box-sizing: border-box; }
body {
    margin: 0; background: var(--c-bg); color: var(--c-text-2);
    font-family: var(--font); font-size: var(--fs-14); line-height: var(--lh-14); font-weight: var(--w-regular);
}
a { color: var(--c-link); transition: var(--t-link); }
a:hover { color: var(--c-link-hover); }
main { max-width: 1700px; margin: 0 auto; padding: 24px 16px 64px 72px; }
/* Header: barra azul de la tienda (72px, logo de 30px). El interior usa el mismo ancho y
   relleno lateral que main, para que el logo quede alineado con el contenido. */
.topbar { position: sticky; top: 0; z-index: 20; height: var(--topbar-h); background: var(--c-primary); }
.topbar-inner {
    max-width: 1700px; height: 100%; margin: 0 auto; padding: 0 16px 0 72px;
    display: flex; align-items: center;
}
.topbar a { display: block; }
.topbar img { display: block; height: var(--logo-h); width: auto; }
/* Menu lateral (derivado: la tienda no tiene riel). Se expande al pasar el mouse o con teclado. */
.drawer {
    position: fixed; inset: var(--topbar-h) auto 0 0; z-index: 10; width: 56px; overflow: hidden;
    background: var(--c-bg); border-right: 1px solid var(--c-line);
    transition: width .18s ease, box-shadow .18s ease;
}
.drawer:hover, .drawer:focus-within { width: 240px; box-shadow: var(--shadow-1); }
.drawer nav { display: flex; flex-direction: column; gap: 4px; padding: 12px 8px; }
.drawer a {
    display: flex; align-items: center; gap: 12px; padding: 10px; border-radius: var(--r-sm);
    color: var(--c-text-2); text-decoration: none; white-space: nowrap;
}
.drawer a:hover { background: var(--c-bg-2); color: var(--c-text-2); }
.drawer a.active { background: var(--c-tint); color: var(--c-primary); font-weight: var(--w-bold); }
.drawer svg { flex: none; width: 20px; height: 20px; }
.drawer span { opacity: 0; transition: opacity .12s ease; }
.drawer:hover span, .drawer:focus-within span { opacity: 1; }
h1 { font-size: var(--fs-20); line-height: var(--lh-20); font-weight: var(--w-bold); color: var(--c-text-2); margin: 0 0 4px; }
.sub { color: var(--c-muted); margin: 0 0 20px; }
.panel {
    background: var(--c-bg); border-radius: var(--r-lg); box-shadow: var(--shadow-1);
    padding: var(--pad-panel); margin-bottom: var(--gap);
}
.panel h2 { font-size: var(--fs-16); line-height: var(--lh-16); font-weight: var(--w-bold); color: var(--c-text-2); margin: 0 0 4px; }
label { display: block; font-weight: var(--w-bold); margin-bottom: 6px; }
textarea, select, input[type=text], input[type=number] {
    width: 100%; min-height: var(--input-h); padding: var(--pad-input); border: 1px solid var(--c-border);
    border-radius: var(--r-input); background: var(--c-bg); color: var(--c-text);
    font-family: var(--font); font-size: var(--fs-16); line-height: var(--lh-16);
}
textarea { min-height: 110px; padding: var(--pad-textarea); border-radius: var(--r-md); }
/* «Elige un grupo» y «Elige un servicio» se ven como placeholder gris; las opciones, en color normal. */
select:has(option[value=""]:checked) { color: var(--c-muted); }
select option { color: var(--c-text); }
textarea:focus, select:focus, input:focus { outline: none; border-color: var(--c-focus); }
input[type=checkbox] { accent-color: var(--c-primary); }
input[type=file] { font-family: var(--font); font-size: var(--fs-14); color: var(--c-muted); }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--gap); }
.selects { display: grid; grid-template-columns: 1fr 2fr; gap: var(--gap-sm); }
.hint { color: var(--c-muted); font-size: var(--fs-12); line-height: var(--lh-12); margin: 6px 0 0; }
@media (max-width: 720px) { .grid, .selects { grid-template-columns: 1fr; } }
.actions { display: flex; gap: var(--gap-sm); flex-wrap: wrap; align-items: center; margin-top: 14px; }
/* Botones: secundario = «Filtrar», primario = «Entrar», disabled = styleguide de la tienda. */
.archivo { display: flex; gap: var(--gap-sm); align-items: center; flex-wrap: wrap; }
a.boton { display: inline-block; text-decoration: none; }
/* Modal de la plantilla: mismo radio y sombra que el toast. */
dialog.modal { max-width: 420px; padding: 20px; border: 1px solid var(--c-border-2); border-radius: var(--r-md);
    background: var(--c-bg); color: var(--c-text); }
dialog.modal::backdrop { background: rgb(0 0 0 / .35); }
dialog.modal h2 { margin-top: 0; }
button, input[type=file]::file-selector-button, a.boton {
    padding: var(--pad-btn-2); border: 1px solid var(--c-border-2); border-radius: var(--r-md);
    background: var(--c-bg); color: var(--c-muted); cursor: pointer;
    font-family: var(--font); font-size: var(--fs-14); line-height: var(--lh-14); font-weight: var(--w-regular);
}
button.primary, button.danger {
    min-height: var(--btn-h); padding: var(--pad-btn); border: 2px solid var(--c-primary); border-radius: var(--r-pill);
    background: var(--c-primary); color: var(--c-on-primary);
}
button.primary:hover { background: var(--c-primary-hover); border-color: var(--c-primary-hover); }
button.danger { background: var(--c-danger); border-color: var(--c-danger); }
select:disabled { background: var(--c-bg-2); color: var(--c-disabled); border-color: var(--c-border-disabled); cursor: not-allowed; }
button:disabled, button:disabled:hover {
    background: var(--c-muted-5); border-color: var(--c-border-disabled); color: var(--c-disabled); cursor: not-allowed;
}
.msg { padding: var(--pad-msg); border-radius: var(--r-lg); margin-bottom: var(--gap); white-space: pre-line; color: var(--c-text); }
.msg.err { background: var(--c-danger-faded); }
.msg.info { background: var(--c-tint); }
.msg.warn { background: var(--c-warning-faded); color: var(--c-on-warning-faded); }
.chips { display: flex; gap: var(--gap-sm); flex-wrap: wrap; margin: 8px 0 12px; }
.chip { padding: var(--pad-pill); border-radius: var(--r-sm); background: var(--c-muted-5); color: var(--c-text-2); }
.tablewrap { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; font-size: var(--fs-14); line-height: var(--lh-14); }
th, td { text-align: left; padding: 8px; border-bottom: 1px solid var(--c-line); vertical-align: top; }
th { color: var(--c-muted); font-weight: var(--w-bold); white-space: nowrap; }
/* Acciones del previo: fondos --faded del styleguide (el color pleno como texto no se lee sobre blanco). */
td.crear { background: var(--c-success-faded); font-weight: var(--w-bold); }
td.actualizar { background: var(--c-tint); color: var(--c-primary); font-weight: var(--w-bold); }
td.alerta { background: var(--c-warning-faded); color: var(--c-on-warning-faded); font-weight: var(--w-bold); }
td.error { background: var(--c-danger-faded); color: var(--c-text); font-weight: var(--w-bold); }
pre {
    background: var(--c-bg-2); border: 1px solid var(--c-line); border-radius: var(--r-md); padding: 10px;
    overflow-x: auto; font-size: var(--fs-12); line-height: var(--lh-12); white-space: pre-wrap;
}
details summary { cursor: pointer; color: var(--c-muted); }
/* Cobertura (derivado). Una sola serie (% configurado) en el azul de la marca; la meta es una línea de tinta. */
:root { --series: var(--c-primary); --grid: var(--c-line); --axis: var(--c-border-2); --ink-muted: var(--c-muted); }
.toolbar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: var(--gap); }
.toolbar form { margin: 0; }
.toolbar .hint { margin: 0; }
#skus-total { display: inline-block; font-size: var(--fs-16); line-height: var(--lh-16); font-weight: 600; color: var(--c-primary); background: var(--c-tint); padding: 6px 12px; border-radius: var(--r-md); margin: 0; }
#skus-total:empty { display: none; }
/* «Quitar repetidos» usa el botón secundario medido, como «Descargar plantilla». */
.skus-resumen { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin-top: 8px; }
#skus-limite { margin: 6px 0 0; color: var(--c-danger); font-weight: 600; font-size: var(--fs-14); }
#skus-limite:empty { display: none; }
#fuente-doble { margin: 8px 0 0; }
.fuente-doble-botones { display: inline-flex; gap: 8px; margin-left: 4px; vertical-align: middle; }
.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--gap); margin-bottom: var(--gap); }
/* Cada tarjeta ocupa todo su alto: etiqueta arriba, cifra repartida en el resto. */
.stat { display: flex; flex-direction: column; justify-content: space-between; gap: 4px; margin-bottom: 0; }
.stat .label { color: var(--c-muted); font-size: var(--fs-14); }
.stat .value { font-size: var(--fs-display); line-height: var(--lh-display); font-weight: var(--w-bold); color: var(--c-text); font-variant-numeric: tabular-nums; }
.stat .value .of { font-size: var(--fs-16); font-weight: var(--w-regular); color: var(--c-muted); }
.stat .level { font-size: var(--fs-14); color: var(--c-muted); margin-top: 2px; }
.stat .level b { font-size: var(--fs-16); color: var(--c-text); font-variant-numeric: tabular-nums; }
@media (max-width: 720px) { .stats { grid-template-columns: 1fr 1fr; } }
/* Departamentos, categorias y subcategorias apilados a la izquierda; servicios a la derecha. */
.coverage-cols { display: grid; grid-template-columns: 1fr 1fr; gap: var(--gap); align-items: start; }
.coverage-cols > * { min-width: 0; }
@media (max-width: 960px) { .coverage-cols { grid-template-columns: 1fr; } }
.chart { --nw: 220px; --vw: 56px; --g: 12px; position: relative; margin-top: 28px; }
@media (max-width: 720px) { .chart { --nw: 110px; } }
.bar-row, .chart-axis { display: grid; grid-template-columns: var(--nw) 1fr var(--vw); gap: var(--g); align-items: center; }
.bar-row { padding: 3px 0; border-radius: var(--r-xs); cursor: default; }
.bar-row:hover { background: var(--c-bg-2); }
.bar-row.link { cursor: pointer; }
.chart-top { --vw: 96px; margin-top: 12px; }
.chart-top .bar-val { white-space: nowrap; }
.bar-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: right; color: var(--c-muted); }
.bar-row:hover .bar-name { color: var(--c-text); }
.bar-track {
    position: relative; height: 18px;
    background: linear-gradient(to right, var(--axis) 1px, transparent 1px),
        repeating-linear-gradient(to right, transparent 0 calc(25% - 1px), var(--grid) calc(25% - 1px) 25%);
}
.bar { position: absolute; left: 0; top: 2px; bottom: 2px; background: var(--series); border-radius: 0 var(--r-xs) var(--r-xs) 0; }
.bar-val { font-variant-numeric: tabular-nums; font-size: var(--fs-14); }
.chart-axis { color: var(--ink-muted); font-size: var(--fs-12); font-variant-numeric: tabular-nums; margin-top: 4px; }
.chart-axis .ticks { position: relative; height: 16px; }
.chart-axis .ticks span { position: absolute; transform: translateX(-50%); }
.chart-target {
    position: absolute; top: 0; bottom: 20px; width: 0; border-left: 2px solid var(--c-text); pointer-events: none;
    left: calc(var(--nw) + var(--g) + (100% - var(--nw) - var(--vw) - 2 * var(--g)) * .95);
}
.chart-target span {
    position: absolute; top: -18px; left: -4px; transform: translateX(-100%);
    font-size: var(--fs-12); white-space: nowrap; color: var(--c-text);
}
/* Tooltip y toast: popup «Inicia sesión para» de la tienda (radio 10px, sombra 2). */
.tip {
    position: fixed; z-index: 30; pointer-events: none; display: none; max-width: 280px;
    background: var(--c-bg); color: var(--c-text-2); border-radius: var(--r-lg);
    padding: 8px 12px; font-size: var(--fs-14); box-shadow: var(--shadow-2);
}
.tip b { display: block; margin-bottom: 2px; color: var(--c-text); }
.tip .muted, td.muted { color: var(--c-muted); }
.drill-head { display: flex; gap: 12px; align-items: end; flex-wrap: wrap; justify-content: space-between; }
.drill-head select { width: auto; min-width: 240px; }
.toast {
    position: fixed; right: 16px; bottom: 16px; z-index: 40; max-width: 380px; padding: var(--pad-panel);
    border-radius: var(--r-lg); background: var(--c-bg); color: var(--c-text-2);
    box-shadow: var(--shadow-2); display: flex; gap: 10px; align-items: start;
    transition: opacity .3s ease, transform .3s ease;
}
.toast.hide { opacity: 0; transform: translateY(8px); }
.toast .icon { font-weight: var(--w-bold); flex: none; }
.toast.ok .icon { color: var(--c-success); }
.toast.warn .icon { color: var(--c-warning); }
.toast.err .icon { color: var(--c-danger); }
.toast button { padding: 0 4px; border: 0; background: none; color: var(--c-muted); }
/* Loader de Configurar servicios: aparece debajo de los botones mientras el servidor responde. */
.cargando { display: flex; gap: 10px; align-items: center; margin-top: 12px; color: var(--c-text-2); }
.cargando[hidden] { display: none; }
.spinner {
    flex: none; width: 18px; height: 18px; border-radius: 50%;
    border: 2px solid var(--c-line); border-top-color: var(--c-primary);
    animation: girar .8s linear infinite;
}
@keyframes girar { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spinner { animation-duration: 2.4s; } }
</style>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a href="?" title="Inicio"><img src="assets/logo-cemaco.png" alt="Cemaco" width="130" height="30"></a>
    </div>
</header>
<aside class="drawer" aria-label="Menú">
    <nav>
        <a href="?m=cobertura" class="<?= $module === 'cobertura' ? 'active' : '' ?>" title="Cobertura por categoría">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
            <span>Cobertura por categoría</span>
        </a>
        <a href="?m=config" class="<?= $module === 'config' ? 'active' : '' ?>" title="Configurar servicios">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/></svg>
            <span>Configurar servicios</span>
        </a>
    </nav>
</aside>
<main>
<?php if ($module === 'cobertura'): ?>
<h1>Cobertura por categoría</h1>
<p class="sub">Porcentaje de SKUs que ya tienen un servicio configurado (type con su value y Name canónico), frente a la meta del 95%. La meta se mide en cada nivel: departamento, categoría y subcategoría.</p>

<div class="toolbar">
    <form method="post">
        <button name="action" value="cobertura" class="primary" <?= $job['running'] ? 'disabled' : '' ?>>
            <?= $job['running'] ? 'Calculando…' : 'Actualizar' ?>
        </button>
    </form>
    <p class="hint">
        <?php if ($job['running']): ?>
            Leyendo VTEX, tarda unos 11 minutos. <?= h($job['progress']) ?>
        <?php elseif ($coverage !== null): ?>
            Datos del <?= h(date('Y-m-d H:i', strtotime($coverage['generatedAt']))) ?>.
        <?php endif; ?>
    </p>
</div>

<?php if ($coverage === null): ?>
    <section class="panel"><div class="msg info" style="margin:0">Todavía no hay datos. Usa «Actualizar» para calcular la cobertura (solo lee VTEX).</div></section>
<?php else: ?>
    <?php
    $target = (int) $coverage['target'];
    $depts = chartRows($coverage['departments']);
    $vacios = count($coverage['departments']) - count($depts);
    $niveles = ['departamentos' => $depts, 'categorías' => level($coverage['departments'], 2),
        'subcategorías' => level($coverage['departments'], 3)];
    $selDept = (int) ($_GET['d'] ?? ($depts[0]['id'] ?? 0));
    $selCat = (int) ($_GET['c'] ?? 0);
    ?>
    <div class="stats">
        <?php foreach ($niveles as $nombre => $nodos): ?>
            <div class="panel stat"><div class="label"><?= ucfirst($nombre) ?> bajo el <?= $target ?>%</div>
                <div class="value"><?= num(bajoMeta($nodos, $target)) ?> <span class="of">de <?= num(count($nodos)) ?></span></div>
            </div>
        <?php endforeach; ?>
        <div class="panel stat"><div class="label">Bajo el <?= $target ?>%, por nivel</div>
            <?php foreach ($niveles as $nombre => $nodos): ?>
                <div class="level"><b><?= num(bajoMeta($nodos, $target)) ?></b> de <?= num(count($nodos)) ?> <?= $nombre ?></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="coverage-cols">
    <div>
    <section class="panel">
        <h2>Departamentos</h2>
        <p class="hint">Clic en un departamento para ver sus categorías.<?= $vacios > 0 ? " $vacios departamentos sin SKUs no se muestran." : '' ?></p>
        <?= renderChart($depts, $target, 'dept') ?>
        <details style="margin-top:12px"><summary>Ver como tabla</summary><?= renderTable($depts, $target) ?></details>
    </section>

    <section class="panel" id="detalle">
        <div class="drill-head">
            <div>
                <h2>Categorías del departamento</h2>
                <p class="hint">Segundo nivel del árbol; cada una es la suma de sus subcategorías. Clic en una categoría para verlas.</p>
            </div>
            <select id="dept" aria-label="Departamento">
                <?php foreach ($depts as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $d['id'] === $selDept ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php foreach ($depts as $d): $hijas = chartRows($d['children'] ?: [$d]); ?>
            <div class="drill" data-dept="<?= $d['id'] ?>" <?= $d['id'] === $selDept ? '' : 'hidden' ?>>
                <?= renderChart($hijas, $target, 'cat') ?>
                <details style="margin-top:12px"><summary>Ver como tabla</summary><?= renderTable($hijas, $target) ?></details>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="panel" id="detalle-cat">
        <?php
        // Categorias con SKUs, agrupadas por departamento; sin ?c= se abre la primera del departamento elegido.
        $cats = [];
        foreach ($depts as $d) {
            $cats[$d['id']] = chartRows($d['children'] ?: [$d]);
        }
        if (!in_array($selCat, array_column(array_merge([], ...array_values($cats)), 'id'), true)) {
            $selCat = $cats[$selDept][0]['id'] ?? 0;
        }
        ?>
        <div class="drill-head">
            <div>
                <h2>Subcategorías de la categoría</h2>
                <p class="hint">Tercer nivel del árbol, la subcategoría final.</p>
            </div>
            <select id="cat" aria-label="Categoría">
                <?php foreach ($depts as $d): ?>
                    <optgroup label="<?= h($d['name']) ?>">
                        <?php foreach ($cats[$d['id']] as $c): ?>
                            <option value="<?= $c['id'] ?>" data-dept="<?= $d['id'] ?>" <?= $c['id'] === $selCat ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <?php foreach ($cats as $lista): foreach ($lista as $c): $hijas = chartRows(($c['children'] ?? []) ?: [$c]); ?>
            <div class="drill-cat" data-cat="<?= $c['id'] ?>" <?= $c['id'] === $selCat ? '' : 'hidden' ?>>
                <?= renderChart($hijas, $target, null) ?>
                <details style="margin-top:12px"><summary>Ver como tabla</summary><?= renderTable($hijas, $target) ?></details>
            </div>
        <?php endforeach; endforeach; ?>
    </section>

    <section class="panel" id="top-configurados">
        <h2>Departamentos con más SKUs configurados</h2>
        <p class="hint">SKUs con al menos un servicio, de los elegibles del departamento. Cálculo del <?= h(fechaLarga($coverage['generatedAt'])) ?>.</p>
        <?= renderTopConfigurados($coverage['departments'], 8) ?>
    </section>
    </div>

    <div>
    <section class="panel" id="servicios">
        <h2>Servicios de instalación, armado, visita y reemplazo</h2>
        <div class="tablewrap"><table>
            <tr><th>Grupo</th><th>Nombre</th></tr>
            <?php // Grupos en orden alfabético (pedido del 2026-11-13); dentro de cada grupo, el orden de la copia de types.
            $gruposAlfabeticos = SERVICE_GROUPS; asort($gruposAlfabeticos, SORT_STRING);
            foreach ($gruposAlfabeticos as $k => $label): foreach ($serviceTypes as $t): if ($t['group'] !== $k) continue; ?>
                <tr><td><?= h($label) ?></td><td><?= h($t['name']) ?></td></tr>
            <?php endforeach; endforeach; ?>
        </table></div>
    </section>
    </div>
    </div>
<?php endif; ?>
<div class="tip" id="tip" role="tooltip"></div>
<?php else: ?>
<h1>Servicios VTEX · Cemaco</h1>
<p class="sub">Interfaz sobre los scripts de <code>bin/</code>. Consultar y previo solo leen; únicamente «Aplicar» escribe en VTEX.</p>

<?php if ($error !== null): ?>
    <div class="msg err"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($notice !== null): ?>
    <div class="msg info"><?= h($notice) ?></div>
<?php endif; ?>
<?php if (!$typesFromVtex): ?>
    <div class="msg warn">Los types vienen de la copia guardada <code>service_types.csv</code>, que está desactualizada. Usa «Actualizar Servicios».</div>
<?php endif; ?>
<?php foreach (avisosValuesRepetidos(ambiguousServiceValues(), $serviceTypes) as $aviso): ?>
    <div class="msg warn" role="alert"><?= h($aviso) ?></div>
<?php endforeach; ?>
<?php if (!$typesAuto['running'] && $typesAuto['exit'] !== null && $typesAuto['exit'] !== 0 && ($typesDate === null || $typesAuto['fecha'] > filemtime($typesPath))): ?>
    <div class="msg warn">La actualización automática de types falló el <?= date('Y-m-d H:i', $typesAuto['fecha']) ?>. Se reintenta en 1 hora; también puedes usar «Actualizar Servicios».</div>
<?php endif; ?>

<form method="post" class="panel" enctype="multipart/form-data">
    <div class="grid">
        <div>
            <label for="skus">SKUs (uno por línea, o separados por coma)</label>
            <textarea id="skus" name="skus" placeholder="1086254&#10;1044449"><?= h($rawSkus) ?></textarea>
            <?php $pegados = resumenPegados($rawSkus); ?>
            <div class="skus-resumen">
                <p class="hint" id="skus-total" aria-live="polite"><?= h($pegados['texto']) ?></p>
                <button type="button" id="skus-limpiar"<?= $pegados['repetidos'] === 0 ? ' hidden' : '' ?>>Quitar repetidos</button>
            </div>
            <p id="skus-limite" role="alert"><?= h($pegados['limite']) ?></p>
            <label for="archivo" style="margin-top:12px">… o sube la planilla (.xlsx o .csv, un solo SKU de servicio por archivo)</label>
            <div class="archivo">
                <button type="button" onclick="document.getElementById('plantilla').showModal()">Descargar plantilla</button>
                <input type="file" id="archivo" name="archivo" accept=".xlsx,.csv">
            </div>
            <div id="fuente-doble" class="msg err" role="alert" hidden>Elegiste una planilla y también hay SKUs pegados. Usa solo uno:
                <span class="fuente-doble-botones"><button type="button" id="vaciar-cuadro">Vaciar el cuadro</button> <button type="button" id="quitar-archivo">Quitar el archivo</button></span>
            </div>
            <dialog id="plantilla" class="modal" aria-labelledby="plantilla-titulo">
                <h2 id="plantilla-titulo">Descargar plantilla</h2>
                <p class="hint">Elige el formato. Las dos traen los encabezados «SKU» y «SKU de servicio».</p>
                <div class="actions">
                    <a class="boton" href="?m=config&amp;plantilla=xlsx" download data-toast="Archivo descargado exitosamente">Excel (.xlsx)</a>
                    <a class="boton" href="?m=config&amp;plantilla=csv" download data-toast="Archivo descargado exitosamente">CSV (.csv)</a>
                    <button type="button" onclick="this.closest('dialog').close()">Cancelar</button>
                </div>
            </dialog>
            <p class="hint">Dos columnas: «SKU» (el SKU del producto) y «SKU de servicio». Todas las filas llevan el mismo SKU de servicio. El servicio, el value y el Name salen del SKU de servicio, no del archivo.</p>
        </div>
        <div>
            <label for="grupo">Servicio a configurar</label>
            <div class="selects">
                <select id="grupo" name="grupo">
                    <option value="" disabled hidden <?= $selGroup === '' ? 'selected' : '' ?>>Elige un grupo</option>
                    <?php foreach ($configGroups as $k => $label): ?>
                        <option value="<?= h($k) ?>" <?= $selGroup === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="type" name="type"></select>
            </div>
            <p class="hint"><?= count($configTypes) ?> types de la lista oficial de instalaciones con value, activos en VTEX (<?= $typesFromVtex ? 'leídos de VTEX' : 'copia guardada' ?><?= $typesDate ? ', ' . h($typesDate) : '' ?>). El value y el Name canónico salen del type elegido. Con planilla, el type sale de cada fila.
                <?= $typesAuto['running'] ? 'Actualizando types en segundo plano…' : ($typesFromVtex ? 'Próxima actualización automática: ' . date('Y-m-d H:i', (int) filemtime(TYPES_CACHE) + TYPES_VIGENCIA) . '.' : '') ?></p>
        </div>
    </div>
    <div class="actions">
        <button name="action" value="consultar" data-cargando="Consultando…">Consulta SKU</button>
        <button name="action" value="previo" class="primary" data-cargando="Generando previo…">Configuración del Servicio</button>
        <button name="action" value="tipos" data-cargando="Actualizando servicios…">Actualizar Servicios</button>
    </div>
</form>

<?php if ($result !== null): ?>
    <section class="panel">
        <?php if ($result['tipo'] === 'consulta'): ?>
            <h2>Consulta SKU</h2>
            <?php foreach (lineasCopia($result['copia']) as $linea): ?><p class="sub"><?= h($linea) ?></p><?php endforeach; ?>
            <p class="sub">Lee <code>stockkeepingunitbyid</code>, que es un caché: puede tardar en reflejar cambios recientes.</p>
            <?php
            $rows = $result['rows'];
            $nombresTypes = nombresDeTypes($typesPath);
            $sinServicios = array_values(array_unique(array_column(array_filter($rows, fn($r) => $r['skuservice_id'] === '' && !in_array($r['estado'], ['error de consulta', 'no existe'], true)), 'sku')));
            ?>
            <?php if ($result['run']['code'] !== 0): ?>
                <div class="msg err">El script terminó con error (código <?= (int) $result['run']['code'] ?>). <?= h(trim($result['run']['err'])) ?></div>
            <?php endif; ?>
            <?php foreach (avisosSkus($rows, 'consulta') as $aviso): ?><div class="msg err"><?= h($aviso) ?></div><?php endforeach; ?>
            <?php if ($sinServicios !== []): ?>
                <div class="msg info"><?= count($sinServicios) ?> SKU(s) sin servicios: <?= h(implode(', ', $sinServicios)) ?>.</div>
            <?php endif; ?>
            <?php if ($rows !== []): ?>
                <div class="chips">
                    <span class="chip">SKUs: <?= count(array_unique(array_column($rows, 'sku'))) ?></span>
                    <span class="chip">servicios: <?= count(array_filter($rows, fn($r) => $r['skuservice_id'] !== '')) ?></span>
                </div>
                <div class="tablewrap"><table>
                    <?php // SkuService.Id y Type no se muestran. ?>
                    <tr><th>SKU</th><th>Producto</th><th>Estado</th><th>SKU de servicio</th><th>Precio</th><th>Nombre del servicio</th></tr>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= h($r['sku']) ?></td>
                            <td><?= h($r['producto']) ?></td>
                            <td><?= h($r['estado']) ?></td>
                            <?php if ($r['skuservice_id'] === ''): ?>
                                <td colspan="3" class="muted"><?= ['error de consulta' => 'No se pudo consultar', 'no existe' => 'No existe en VTEX'][$r['estado']] ?? 'Sin servicios' ?></td>
                            <?php else: ?>
                                <td><?= h($r['sku_servicio']) ?></td>
                                <td><?= $r['precio'] === '' ? '' : 'Q' . h($r['precio']) ?></td>
                                <td><?= h(nombreConsulta($r, $nombresTypes)) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </table></div>
            <?php endif; ?>

        <?php else: ?>
            <?php
            $rows = $result['rows'];
            $p = $result['params'];
            $cols = columnasVisibles($rows, array_column($p['grupos'], 'type'));
            $acciones = array_count_values(array_column($rows, 'accion'));
            $status = array_count_values(array_column($rows, 'status'));
            ?>
            <h2><?= $result['tipo'] === 'previo' ? 'Configuración del servicio' : 'Resultado de la aplicación' ?></h2>
            <?php // Lo pegado o subido, con el mismo texto del cuadro, y los SKUs que se repiten. ?>
            <?php foreach (lineasCopia($p) as $linea): ?><p class="sub"><?= h($linea) ?></p><?php endforeach; ?>
            <ul class="sub">
                <?php foreach ($p['grupos'] as $g): ?>
                    <li>type <?= (int) $g['type'] ?> · value <?= (int) $g['value'] ?> · Name «<?= h($g['name']) ?>» · <?= (int) $g['skus'] ?> SKUs</li>
                <?php endforeach; ?>
            </ul>
            <?php if (($p['filas_inactivas'] ?? []) !== []): ?>
                <div class="msg info"><?= count($p['filas_inactivas']) ?> fila(s) con Ativo = 0 no se procesaron (filas <?= h(implode(', ', array_slice($p['filas_inactivas'], 0, 10))) ?><?= count($p['filas_inactivas']) > 10 ? ', …' : '' ?>). Este flujo nunca desactiva servicios.</div>
            <?php endif; ?>

            <?php if ($result['run']['code'] !== 0): ?>
                <div class="msg err">El script terminó con error (código <?= (int) $result['run']['code'] ?>). <?= h(trim($result['run']['err'])) ?></div>
            <?php endif; ?>

            <?php foreach (avisosSkus($rows, 'previo') as $aviso): ?><div class="msg err"><?= h($aviso) ?></div><?php endforeach; ?>
            <div class="chips">
                <?php foreach ($acciones as $a => $n): ?><span class="chip"><?= h(accionVisible($a)) ?>: <?= $n ?></span><?php endforeach; ?>
                <?php foreach ($status as $s => $n): ?><span class="chip">status <?= h($s) ?>: <?= $n ?></span><?php endforeach; ?>
            </div>

            <?php if (($acciones['revisar_manual'] ?? 0) > 0): ?>
                <div class="msg warn"><?= $acciones['revisar_manual'] ?> SKU(s) ya tienen este type con otro value. No se tocan: hay que decidir aparte.</div>
            <?php endif; ?>

            <?php if ($rows !== []): ?>
                <div class="tablewrap"><table>
                    <tr><?php foreach ($cols as $c): ?><th><?= h(encabezadoPrevio($c)) ?></th><?php endforeach; ?></tr>
                    <?php foreach ($rows as $r): ?>
                        <tr><?php foreach ($cols as $c):
                            $cls = $c === 'accion' ? ($accionClase[$r[$c]] ?? '') : ($c === 'status' && $r[$c] === 'error' ? 'error' : '');
                            ?><td class="<?= $cls ?>"><?= h(celdaPrevio($c, $r, $serviceTypes, $result['precios'] ?? [])) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </table></div>
            <?php endif; ?>

            <?php if ($result['tipo'] === 'previo' && $result['run']['code'] === 0 && $rows !== []): ?>
                <?php $escribe = ($acciones['crear'] ?? 0) + ($acciones['actualizar_existente'] ?? 0); ?>
                <form method="post" class="actions" style="align-items:center">
                    <input type="hidden" name="run_id" value="<?= h($result['id']) ?>">
                    <label style="font-weight:400;margin:0">
                        <input type="checkbox" name="confirmo" value="si">
                        Confirmo: escribir en VTEX (<?= $escribe ?> crear/actualizar)
                    </label>
                    <button name="action" value="aplicar" class="danger" data-cargando="Aplicando en VTEX…" <?= $escribe === 0 ? 'disabled' : '' ?>>Aplicar en VTEX</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php endif; ?>
</main>
<?php if ($module === 'config'): ?>
<script>
const TYPES = <?= json_encode(array_values($configTypes), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const grupo = document.getElementById('grupo');
const type = document.getElementById('type');
const listTypes = (keep) => {
    type.innerHTML = '';
    const vacio = new Option('Elige un servicio', '', false, !keep);
    vacio.disabled = vacio.hidden = true;   // placeholder: no se puede volver a elegir
    type.add(vacio);
    for (const t of TYPES.filter(x => x.group === grupo.value)) {
        type.add(new Option(t.name, t.id, false, t.id === keep));
    }
};
grupo.addEventListener('change', () => listTypes(0));
listTypes(<?= $selType ?>);

// Resumen bajo el cuadro de SKUs: mismo cálculo que resumenPegados() en PHP.
const MAX_SKUS = <?= MAX_SKUS ?>;
const skus = document.getElementById('skus');
const skusTotal = document.getElementById('skus-total');
const skusLimite = document.getElementById('skus-limite');
const skusLimpiar = document.getElementById('skus-limpiar');
const cuenta = (x, uno, varios) => x + (x === 1 ? uno : varios);
const resumirSkus = () => {
    const pegados = skus.value.match(/\d+/g) || [];
    const n = pegados.length;
    const u = new Set(pegados).size;
    const r = n - u;
    skusTotal.textContent = n === 0 ? '' : r === 0
        ? 'Pegaste ' + cuenta(n, ' SKU', ' SKUs') + ', sin repetidos.'
        : 'Pegaste ' + cuenta(n, ' SKU', ' SKUs') + ': ' + u + (u === 1 ? ' único y ' : ' únicos y ') + cuenta(r, ' repetido.', ' repetidos.');
    skusLimite.textContent = u > MAX_SKUS ? 'Máximo ' + MAX_SKUS + ' por corrida: tienes ' + u + '.' : '';
    skusLimpiar.hidden = r === 0;
};
skus.addEventListener('input', resumirSkus);
// SKUs pegados y planilla a la vez: el servidor rechaza las dos («Usa los SKUs pegados o el archivo, no
// los dos.») y el archivo se pierde al volver. Se avisa en el momento, con dos botones para quedarse con
// una sola fuente, y Consulta SKU y Configuración del Servicio se desactivan mientras sigan las dos.
const archivo = document.getElementById('archivo');
const fuenteDoble = document.getElementById('fuente-doble');
const revisarFuentes = () => {
    const doble = archivo.files.length > 0 && skus.value.trim() !== '';
    fuenteDoble.hidden = !doble;
    document.querySelectorAll('button[value="consultar"], button[value="previo"]').forEach(b => { b.disabled = doble; });
};
archivo.addEventListener('change', revisarFuentes);
skus.addEventListener('input', revisarFuentes);
document.getElementById('vaciar-cuadro').addEventListener('click', () => {
    skus.value = '';
    resumirSkus();
    revisarFuentes();
});
document.getElementById('quitar-archivo').addEventListener('click', () => {
    archivo.value = '';
    revisarFuentes();
});
revisarFuentes();

// Quitar repetidos: deja cada SKU una vez, uno por línea, en el orden en que aparecen.
skusLimpiar.addEventListener('click', () => {
    skus.value = [...new Set(skus.value.match(/\d+/g) || [])].join('\n');
    resumirSkus();
    skus.focus();
});

// Loader: al enviar, el boton pulsado muestra su texto de espera, todos los botones, selects y casillas
// de la página se desactivan, también los de otros formularios (evita doble clic y otra acción a mitad
// de la primera), y aparece un aviso con spinner. Un control desactivado no se envia, asi que la accion,
// los selects y las casillas marcadas del formulario enviado se copian antes a campos ocultos.
document.querySelectorAll('form[method="post"]').forEach(form => form.addEventListener('submit', e => {
    const boton = e.submitter;
    if (!boton || !boton.dataset.cargando) {
        return;
    }
    const oculto = document.createElement('input');
    oculto.type = 'hidden';
    oculto.name = 'action';
    oculto.value = boton.value;
    form.appendChild(oculto);
    form.querySelectorAll('select, input[type="checkbox"]').forEach(c => {
        if (c.name && !c.disabled && (c.type !== 'checkbox' || c.checked)) {
            const copia = document.createElement('input');
            copia.type = 'hidden';
            copia.name = c.name;
            copia.value = c.value;
            copia.dataset.copiaDe = c.name;
            form.appendChild(copia);
        }
    });
    document.querySelectorAll('button, select, input[type="checkbox"]').forEach(c => {
        if (c.tagName === 'BUTTON') {
            c.dataset.textoOriginal = c.textContent;
        }
        c.dataset.estabaDesactivado = c.disabled ? 'si' : 'no';
        c.disabled = true;
    });
    boton.textContent = boton.dataset.cargando;
    const aviso = document.createElement('div');
    aviso.className = 'cargando';
    aviso.setAttribute('role', 'status');
    aviso.innerHTML = '<span class="spinner" aria-hidden="true"></span><span></span>';
    aviso.lastChild.textContent = boton.value === 'aplicar'
        ? 'Escribiendo en VTEX. Puede tardar unos minutos: no cierres ni recargues la página.'
        : 'Leyendo VTEX. Puede tardar unos minutos: no cierres la página.';
    form.after(aviso);
}));
// Al volver con el boton Atras el navegador puede mostrar la pagina guardada: se deja como estaba.
window.addEventListener('pageshow', e => {
    if (!e.persisted) {
        return;
    }
    document.querySelectorAll('.cargando').forEach(a => a.remove());
    document.querySelectorAll('input[type="hidden"][name="action"]').forEach(i => i.remove());
    document.querySelectorAll('input[data-copia-de]').forEach(i => i.remove());
    document.querySelectorAll('[data-estaba-desactivado]').forEach(c => {
        c.disabled = c.dataset.estabaDesactivado === 'si';
    });
    document.querySelectorAll('button[data-texto-original]').forEach(b => {
        b.textContent = b.dataset.textoOriginal;
    });
});
</script>
<?php else: ?>
<script>
const tip = document.getElementById('tip');
const pctOf = (row) => row.querySelector('.bar-val').textContent;
document.addEventListener('mousemove', (e) => {
    const row = e.target.closest('.bar-row');
    if (!row) { tip.style.display = 'none'; return; }
    tip.innerHTML = '';
    const b = document.createElement('b');
    b.textContent = `${row.dataset.name} · ${pctOf(row)}`;
    const muted = document.createElement('div');
    muted.className = 'muted';
    muted.textContent = [row.dataset.tip, row.dataset.falta].filter(Boolean).map((t) => `${t}.`).join(' ');
    tip.append(b, muted);
    tip.style.display = 'block';
    const x = Math.min(e.clientX + 14, window.innerWidth - tip.offsetWidth - 8);
    tip.style.left = `${x}px`;
    tip.style.top = `${e.clientY + 14}px`;
});
const dept = document.getElementById('dept');
const cat = document.getElementById('cat');
const setParam = (key, id) => {
    const url = new URL(location.href);
    url.searchParams.set(key, id);
    url.searchParams.delete('esperando');
    history.replaceState(null, '', url);
};
const showCat = (id) => {
    cat.value = id;
    document.querySelectorAll('.drill-cat').forEach((el) => { el.hidden = el.dataset.cat !== id; });
    setParam('c', id);
};
// Al cambiar de departamento, el panel de subcategorias pasa a su primera categoria.
const showDept = (id) => {
    dept.value = id;
    document.querySelectorAll('.drill').forEach((el) => { el.hidden = el.dataset.dept !== id; });
    setParam('d', id);
    const first = cat.querySelector(`option[data-dept="${id}"]`);
    if (first) showCat(first.value);
};
if (dept) {
    dept.addEventListener('change', () => showDept(dept.value));
    cat.addEventListener('change', () => showCat(cat.value));
    document.querySelectorAll('.bar-row.link').forEach((row) => row.addEventListener('click', () => {
        const toCat = row.dataset.link === 'cat';
        (toCat ? showCat : showDept)(row.dataset.id);
        document.getElementById(toCat ? 'detalle-cat' : 'detalle').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));
}
</script>
<?php endif; ?>
<?php // Toast comun a los dos modulos: el del servidor ($toast) y los que arma el navegador. ?>
<?php if ($toast !== null): ?>
    <div class="toast <?= $toast['tipo'] ?>" role="status">
        <span class="icon" aria-hidden="true"><?= ['ok' => '✓', 'warn' => '!', 'err' => '×'][$toast['tipo']] ?></span>
        <span><?= h($toast['texto']) ?></span>
        <button type="button" aria-label="Cerrar">×</button>
    </div>
<?php endif; ?>
<script>
function prepararToast(toast) {
    const close = () => { toast.classList.add('hide'); setTimeout(() => toast.remove(), 300); };
    toast.querySelector('button').addEventListener('click', close);
    setTimeout(close, 8000);
}
function mostrarToast(tipo, texto) {
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = 'toast ' + tipo;
    toast.setAttribute('role', 'status');
    toast.innerHTML = '<span class="icon" aria-hidden="true"></span><span></span><button type="button" aria-label="Cerrar">×</button>';
    toast.querySelector('.icon').textContent = { ok: '✓', warn: '!', err: '×' }[tipo];
    toast.querySelector('span:not(.icon)').textContent = texto;
    document.body.appendChild(toast);
    prepararToast(toast);
}
const toastServidor = document.querySelector('.toast');
if (toastServidor) {
    prepararToast(toastServidor);
    const url = new URL(location.href);
    url.searchParams.delete('esperando');
    history.replaceState(null, '', url);
}
// La descarga la hace el navegador: el aviso se muestra al pulsar el boton.
document.querySelectorAll('a[data-toast]').forEach(a => a.addEventListener('click', () => {
    a.closest('dialog')?.close();
    mostrarToast('ok', a.dataset.toast);
}));
</script>
</body>
</html>
