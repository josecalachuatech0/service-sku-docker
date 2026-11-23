<?php
declare(strict_types=1);

/*
 * Busca SkuServiceValue nuevos en VTEX, para saber si algun type sin value ya tiene uno.
 *
 * Uso:
 * php bin/find-new-service-values.php
 * php bin/find-new-service-values.php --desde=94952 --stop-after=50 --max=2000
 * php bin/find-new-service-values.php --output=dashboard/cache/service_values.json
 *
 * Contexto para otra IA:
 * - VTEX no lista los values ni los filtra por type (GET skuservicevalue sin id responde 405); solo
 *   permite leer un value por su id, y el value dice a que type pertenece.
 * - Los ids de los values son correlativos: uno nuevo se crea al final. Por eso se lee desde el ultimo
 *   value conocido (LAST_KNOWN_SERVICE_VALUE_ID en lib/reglas.php) hacia adelante y se corta tras
 *   --stop-after ids seguidos que no existen (404). Un value creado en un hueco de ids antiguos no se ve.
 * - Un error que no sea 404 corta la busqueda con error: no se puede afirmar que no haya values nuevos.
 * - Se lee el nombre de cada type encontrado (una vez por type) y se clasifica con serviceGroup(): solo
 *   se detallan los values de Instalacion, Armado, Visita y Reemplazo. Los de otros proyectos (GEX,
 *   empaque) se resumen en una linea: VTEX crea muchos values de GEX y taparian el resultado.
 * - No cambia lib/reglas.php. Sin --output solo informa. Con --output guarda los values de instalaciones y hasta
 *   donde leyo: la corrida siguiente continua desde ahi, y newServiceValues() decide cuales se habilitan solos.
 *   Si llega a --max sin el corte, la busqueda queda incompleta: siguen habilitados los de la ultima completa.
 *   Con --output tambien relee los values que se pueden habilitar. No consulta su SKU de servicio en el catalogo:
 *   no siempre existe y no se exige (regla del 2026-11-23).
 *   Si falla a medias, lo guardado no cambia.
 * - Script de solo lectura: no hace POST/PUT/DELETE.
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/reglas.php';

$options = getopt('', ['desde:', 'stop-after:', 'max:', 'output:']);
$outputPath = optionValue($options, 'output');
if ($outputPath !== null && !str_starts_with($outputPath, '/')) {
    $outputPath = __DIR__ . '/../' . $outputPath;
}
$guardado = $outputPath === null ? ['ultimo' => 0, 'verificado' => 0, 'values' => []] : savedServiceValues($outputPath);
$desde = (int) (optionValue($options, 'desde') ?? (string) (max(LAST_KNOWN_SERVICE_VALUE_ID, $guardado['ultimo'], ...array_values(SERVICE_VALUES)) + 1));
$stopAfter = (int) (optionValue($options, 'stop-after') ?? '50');
$max = (int) (optionValue($options, 'max') ?? '2000');

if ($desde <= 0 || $stopAfter <= 0 || $max <= 0) {
    fwrite(STDERR, "--desde, --stop-after y --max deben ser mayores a 0\n");
    exit(1);
}

$appKey = envString('API_KEY');
$appToken = envString('API_TOKEN');
if ($appKey === '' || $appToken === '') {
    fwrite(STDERR, "Configura API_KEY y API_TOKEN en .env\n");
    exit(1);
}
$baseUrl = rtrim(vtexBaseUrl(), '/');

$encontrados = [];
$seguidos = 0;
$id = $desde;
for (; $id < $desde + $max; $id++) {
    $r = vtexCall('GET', "$baseUrl/api/catalog/pvt/skuservicevalue/$id", $appKey, $appToken);
    if ($r['status'] === 404) {
        if (++$seguidos >= $stopAfter) {
            break;
        }
        continue;
    }
    if (!$r['ok'] || !is_array($r['json'])) {
        fwrite(STDERR, "No se pudo leer el value $id (HTTP {$r['status']}). La búsqueda quedó incompleta: vuelve a correrla.\n");
        exit(1);
    }
    $seguidos = 0;
    $encontrados[] = $r['json'];
}
$hasta = min($id, $desde + $max - 1);

echo "Revisados los values $desde a $hasta.";
echo $seguidos >= $stopAfter ? " Corte: $stopAfter ids seguidos sin value.\n" : " Se llegó al máximo de --max=$max sin el corte: puede haber más.\n";
echo 'Values encontrados: ' . count($encontrados) . "\n";

$nombres = [];
$otros = [];
$sinValue = [];
$porId = array_column($guardado['values'], null, 'id');
foreach ($encontrados as $v) {
    $type = (int) ($v['SkuServiceTypeId'] ?? 0);
    if (!array_key_exists($type, $nombres)) {
        $t = vtexCall('GET', "$baseUrl/api/catalog/pvt/skuservicetype/$type", $appKey, $appToken);
        if (!$t['ok'] || !is_array($t['json'])) {
            fwrite(STDERR, "No se pudo leer el type $type (HTTP {$t['status']}). La búsqueda quedó incompleta: vuelve a correrla.\n");
            exit(1);
        }
        $nombres[$type] = (string) ($t['json']['Name'] ?? '');
    }
    $grupo = serviceGroup($nombres[$type]);
    if ($grupo === null) {
        $otros[$type] = ($otros[$type] ?? 0) + 1;
        continue;
    }
    $porId[(int) $v['Id']] = ['id' => (int) $v['Id'], 'type' => $type, 'grupo' => $grupo,
        'sku' => trim((string) ($v['Name'] ?? ''))];
    $conocido = SERVICE_VALUES[$type] ?? null;
    $estado = match (true) {
        $conocido === null => 'TYPE SIN VALUE en lib/reglas.php',
        $conocido === (int) $v['Id'] => 'ya está en lib/reglas.php',
        default => "otro value del type (en lib/reglas.php: $conocido)",
    };
    if ($conocido === null) {
        $sinValue[$type] = true;
    }
    printf("  value %d · type %d (%s) · SKU de servicio %s · Q%s · %s\n", (int) $v['Id'], $type, $nombres[$type],
        (string) ($v['Name'] ?? ''), number_format((float) ($v['Value'] ?? 0), 2, '.', ','), $estado);
}
if ($otros !== []) {
    ksort($otros);
    echo '  De otros proyectos (no son de instalaciones): ' . implode(', ', array_map(
        fn($t, $n) => "type $t «{$nombres[$t]}» ($n)", array_keys($otros), $otros)) . "\n";
}

echo "\n== Resultado ==\n";
echo $sinValue === []
    ? "Ningún type sin value tiene un value nuevo.\n"
    : 'Types sin value que ya tienen value en VTEX: ' . implode(', ', array_keys($sinValue)) . ". Valídalos y agrégalos a lib/reglas.php.\n";
if ($encontrados !== []) {
    echo 'Último value encontrado: ' . (int) end($encontrados)['Id'] . '. Después de validar, actualiza LAST_KNOWN_SERVICE_VALUE_ID en lib/reglas.php.' . "\n";
}

if ($outputPath !== null) {
    $leer = static function (string $ruta, string $que) use ($baseUrl, $appKey, $appToken): ?array {
        $r = vtexCall('GET', "$baseUrl$ruta", $appKey, $appToken);
        if ($r['status'] !== 404 && (!$r['ok'] || !is_array($r['json']))) {
            fwrite(STDERR, "No se pudo leer $que (HTTP {$r['status']}). La búsqueda quedó incompleta: vuelve a correrla.\n");
            exit(1);
        }
        return $r['status'] === 404 ? null : $r['json'];
    };
    // Los que se pueden habilitar se releen: VTEX pudo borrarlos o cambiarlos.
    $guardados = array_column($guardado['values'], 'id');
    foreach ($porId as $vid => $v) {
        if (!takesNewServiceValue($v['type'])) {
            continue;
        }
        if (in_array($vid, $guardados, true)) {
            $actual = $leer("/api/catalog/pvt/skuservicevalue/$vid", "el value $vid");
            if ($actual === null) {
                unset($porId[$vid]);
                continue;
            }
            $v['sku'] = trim((string) ($actual['Name'] ?? ''));
            $type = (int) ($actual['SkuServiceTypeId'] ?? 0);
            if ($type !== $v['type']) {
                $grupo = serviceGroup((string) ($leer("/api/catalog/pvt/skuservicetype/$type", "el type $type")['Name'] ?? ''));
                if ($grupo === null) {
                    unset($porId[$vid]);
                    continue;
                }
                $v = ['type' => $type, 'grupo' => $grupo] + $v;
            }
        }
        $porId[$vid] = ['id' => $vid, 'type' => $v['type'], 'grupo' => $v['grupo'], 'sku' => $v['sku']];
    }
    $completo = $seguidos >= $stopAfter;
    ksort($porId);
    $ultimo = max($guardado['ultimo'], $encontrados === [] ? 0 : (int) end($encontrados)['Id']);
    // En un temporal que se renombra al final: el dashboard nunca lee una lista a medias.
    $tmp = $outputPath . '.tmp.' . getmypid();
    $json = json_encode(['ultimo' => $ultimo, 'verificado' => $completo ? $ultimo : $guardado['verificado'],
        'completo' => $completo, 'values' => array_values($porId)]);
    if (file_put_contents($tmp, $json) === false || !rename($tmp, $outputPath)) {
        @unlink($tmp);
        fwrite(STDERR, "No se pudo escribir $outputPath\n");
        exit(1);
    }
    foreach (ambiguousServiceValues($outputPath) as $type => $ids) {
        echo "Type $type con más de un value, no se habilita: " . implode(', ', $ids) . ".\n";
    }
    $nuevos = newServiceValues($outputPath);
    echo 'Values nuevos habilitados: ' . ($nuevos === [] ? 'ninguno' : implode(', ', array_map(
        fn($t, $n) => "type $t (value {$n['value']})", array_keys($nuevos), $nuevos))) . '.'
        . ($completo ? '' : ' La búsqueda quedó incompleta: la próxima corrida continúa.') . "\n";
}
