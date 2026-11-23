<?php
declare(strict_types=1);

/*
 * Reglas de negocio compartidas: lo que ningun script debe tocar y las configuraciones conocidas.
 *
 * Contexto para otra IA:
 * - PROTECTED_SKU_SERVICE_IDS: excepcion aprobada del SKU 983865 (brackets de TV). Las relaciones
 *   297864 (tabla yeso Q450) y 297865 (pared de concreto Q150) llevan nombres descriptivos a
 *   proposito: es lo unico que le permite al cliente distinguir dos servicios que cuestan el
 *   triple uno del otro. Ningun barrido de nombres ni aplicacion debe modificarlas.
 * - SERVICE_GROUPS / serviceGroup(): segmentan los SkuServiceType de VTEX por su nombre para el
 *   dashboard (demo). Solo entran Instalacion, Armado, Visita y Reemplazo; el resto (empaque, GEX,
 *   pruebas) pertenece a otros proyectos. Visita se evalua primero porque "Visita ... para
 *   Instalacion" contiene las dos palabras. Reemplazo (types 8, 10 y 12, desde el 2026-11-13) va
 *   antes que Instalacion por la misma razon.
 * - SERVICE_VALUES: value verificado de cada type (documentacion_armado_e_instalaciones.md,
 *   tabla maestra validada el 2026-10-15). Solo rellena el formulario; el value se vuelve a
 *   validar contra el type en apply-instalacion-bracket.php antes de escribir.
 * - SERVICE_SKUS / typeFromServiceSku(): SKU de servicio de cada type, 1 a 1. La planilla del
 *   dashboard trae el SKU de servicio y el type sale de aqui o, si el type se habilito solo, de newServiceSkus().
 * - serviceDefaultName(): Name/Text canonico sugerido por grupo; los brackets 15/16 tienen el
 *   suyo. Visita y Reemplazo tienen el suyo desde el 2026-11-23 (opcion A). PENDIENTE: pasar a la opcion B
 *   («Servicio de visita de verificación», «… de evaluación» para el 46 y «Servicio de reemplazo de calentador»).
 * - officialServiceTypes() / isOfficialServiceType(): lista oficial de types del equipo de
 *   instalaciones (lista_oficial_types_instalaciones.csv: 49 types recibidos el 2026-11-13 y el 71, sanitario,
 *   agregado el 2026-11-23). Todo el dashboard (Configurar servicios, Consultar en vivo y Cobertura) ignora
 *   los types que no estan en ella.
 * - newServiceTypes(): types que VTEX creo despues del ultimo conocido y que caen en un grupo. Se suman solos
 *   a la lista oficial; el CSV no se reescribe. Sin value (serviceValue) todavia no se pueden configurar.
 * - newServiceValues() / serviceValue(): values que VTEX creo despues del ultimo conocido. Se habilitan solos
 *   si cumplen las dos comprobaciones de la decision del 2026-11-13; SERVICE_VALUES siempre manda.
 */

const PROTECTED_SKU_SERVICE_IDS = [297864, 297865];
const PROTECTED_SKUS = ['983865'];

function isProtectedSkuServiceId(int|string $id): bool
{
    return in_array((int) $id, PROTECTED_SKU_SERVICE_IDS, true);
}

const OFFICIAL_TYPES_CSV = __DIR__ . '/../lista_oficial_types_instalaciones.csv';

// Ultimo type de VTEX ya revisado contra la lista oficial. Los de id menor o igual que no estan en el CSV
// quedaron fuera a proposito (26, 27, 30, 31 y 47, por ejemplo). Subirlo solo despues de decidir cada type nuevo.
const LAST_KNOWN_SERVICE_TYPE_ID = 71;
// Copia de types de VTEX que guardan «Actualizar Servicios» y la actualizacion automatica.
const TYPES_COPY_CSV = __DIR__ . '/../dashboard/cache/service_types.csv';

// Types nuevos de la copia: id mayor que el ultimo conocido y nombre que cae en un grupo (decision del
// 2026-11-13). Sin copia, o con una copia ilegible, no hay types nuevos.
function newServiceTypes(string $copy = TYPES_COPY_CSV): array
{
    $fh = is_file($copy) ? fopen($copy, 'r') : false;
    if ($fh === false) {
        return [];
    }
    $header = fgetcsv($fh, null, ',', '"', '') ?: [];
    $id = array_search('Id', $header, true);
    $name = array_search('Name', $header, true);
    $types = [];
    while ($id !== false && $name !== false && ($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        if (ctype_digit((string) ($r[$id] ?? '')) && (int) $r[$id] > LAST_KNOWN_SERVICE_TYPE_ID
            && serviceGroup((string) ($r[$name] ?? '')) !== null) {
            $types[] = (int) $r[$id];
        }
    }
    fclose($fh);
    return $types;
}

// Ids de los types de la lista oficial (columna type_vtex) mas los nuevos (newServiceTypes). Sin el archivo
// no hay types validos: se detiene en lugar de mostrar todos.
function officialServiceTypes(): array
{
    static $types = null;
    if ($types !== null) {
        return $types;
    }
    $fh = is_file(OFFICIAL_TYPES_CSV) ? fopen(OFFICIAL_TYPES_CSV, 'r') : false;
    if ($fh === false) {
        throw new RuntimeException('No se encontro la lista oficial de types: ' . OFFICIAL_TYPES_CSV);
    }
    $header = fgetcsv($fh, null, ',', '"', '');
    $col = array_search('type_vtex', $header === false ? [] : $header, true);
    if ($col === false) {
        fclose($fh);
        throw new RuntimeException('La lista oficial de types no tiene la columna type_vtex.');
    }
    $types = [];
    while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        if (isset($r[$col]) && ctype_digit(trim($r[$col]))) {
            $types[] = (int) $r[$col];
        }
    }
    fclose($fh);
    $types = array_values(array_unique(array_merge($types, newServiceTypes())));
    return $types;
}

function isOfficialServiceType(int $type): bool
{
    return in_array($type, officialServiceTypes(), true);
}

const SERVICE_GROUPS = ['instalacion' => 'Instalación', 'armado' => 'Armado', 'visita' => 'Visita', 'reemplazo' => 'Reemplazo'];

function serviceGroup(string $typeName): ?string
{
    $n = mb_strtolower(strtr($typeName, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u']));
    foreach (['visita' => 'visita', 'reemplazo' => 'reemplazo', 'armado' => 'armado', 'instalaci' => 'instalacion'] as $needle => $group) {
        if (str_contains($n, $needle)) {
            return $group;
        }
    }
    return null;
}

const SERVICE_VALUES = [
    3 => 3, 4 => 4, 5 => 5, 6 => 92477, 7 => 34, 9 => 84878, 11 => 84877, 13 => 13, 15 => 84873,
    16 => 84871, 17 => 92475, 20 => 84865, 26 => 14, 36 => 84870, 37 => 84864, 38 => 22, 39 => 25,
    40 => 27, 41 => 84876, 43 => 24, 44 => 23, 45 => 26, 46 => 92476, 47 => 15, 48 => 16, 49 => 17,
    50 => 18, 51 => 19, 52 => 20, 53 => 21, 63 => 84866, 64 => 84867, 65 => 84868, 66 => 84869,
    67 => 84872, 68 => 84874, 69 => 84875, 70 => 87489, 71 => 94524,
];

// Ultimo SkuServiceValue revisado: el 94951 (2026-11-23). Del 94525 al 94951 todos son de GEX. El ultimo de
// instalaciones es el 94524 (type 71). bin/find-new-service-values.php busca values nuevos desde el siguiente id. Actualizarlo despues de validar un value nuevo.
const LAST_KNOWN_SERVICE_VALUE_ID = 94951;

// Busqueda de values nuevos que guarda bin/find-new-service-values.php --output.
const VALUES_COPY_JSON = __DIR__ . '/../dashboard/cache/service_values.json';

// Lo guardado por la busqueda de values. ultimo: ultimo value encontrado; verificado: ultimo value de la mas
// reciente busqueda completa; values: los de instalaciones. Sin archivo, o con uno danado, no hay nada guardado.
function savedServiceValues(string $path = VALUES_COPY_JSON): array
{
    $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $d = is_array($d) ? $d : [];
    $values = [];
    foreach (is_array($d['values'] ?? null) ? $d['values'] : [] as $v) {
        if (is_array($v) && is_int($v['id'] ?? null) && is_int($v['type'] ?? null) && isset(SERVICE_GROUPS[(string) ($v['grupo'] ?? '')])) {
            $values[] = ['id' => $v['id'], 'type' => $v['type'], 'grupo' => $v['grupo'], 'sku' => (string) ($v['sku'] ?? '')];
        }
    }
    $entero = static fn(string $k): int => is_int($d[$k] ?? null) ? $d[$k] : 0;
    return ['ultimo' => $entero('ultimo'), 'verificado' => $entero('verificado'), 'completo' => ($d['completo'] ?? null) === true, 'values' => $values];
}

// ¿Puede este type tomar su value de la busqueda? Solo los oficiales que no lo tienen en SERVICE_VALUES.
function takesNewServiceValue(int $type): bool
{
    return !isset(SERVICE_VALUES[$type]) && isOfficialServiceType($type);
}

// Values encontrados de esos types: type => [id => value].
function candidateServiceValues(string $path): array
{
    $byType = [];
    foreach (savedServiceValues($path)['values'] as $v) {
        if (takesNewServiceValue($v['type'])) {
            $byType[$v['type']][$v['id']] = $v;
        }
    }
    return $byType;
}

// Values que se habilitan solos, por type: el type es oficial, no tiene value en SERVICE_VALUES y VTEX no le
// conoce otro value nuevo. Que el value sea de ese type lo dice VTEX al leerlo. Mientras una busqueda esta a
// medias solo valen los de la ultima completa, porque de los demas no se sabe si hay otro.
function newServiceValues(string $path = VALUES_COPY_JSON): array
{
    $verified = savedServiceValues($path)['verificado'];
    $new = [];
    foreach (candidateServiceValues($path) as $type => $values) {
        $v = reset($values);
        if (count($values) === 1 && $v['id'] <= $verified) {
            $new[$type] = ['value' => $v['id'], 'grupo' => $v['grupo']];
        }
    }
    return $new;
}

// Types con mas de un value nuevo: type => ids. No se habilitan; el dashboard avisa.
function ambiguousServiceValues(string $path = VALUES_COPY_JSON): array
{
    return array_map('array_keys', array_filter(candidateServiceValues($path), static fn(array $vs): bool => count($vs) > 1));
}

// SKU de servicio de cada type habilitado solo, para la planilla: el Name de su value, si no es
// de otro type. No se exige que exista en el catalogo (regla del 2026-11-23). Los demas se configuran pegando SKUs.
function newServiceSkus(string $path = VALUES_COPY_JSON): array
{
    $saved = savedServiceValues($path)['values'];
    $skus = [];
    foreach (newServiceValues($path) as $type => $new) {
        $sku = array_column($saved, 'sku', 'id')[$new['value']];
        $otros = array_filter($saved, static fn(array $v): bool => $v['sku'] === $sku && $v['type'] !== $type);
        if (ctype_digit($sku) && $otros === [] && !in_array($sku, SERVICE_SKUS, true)) {
            $skus[$type] = $sku;
        }
    }
    return $skus;
}

// Value con el que se configura un type, o null si todavia no tiene. El del codigo manda.
function serviceValue(int $type): ?int
{
    static $new = null;
    $new ??= newServiceValues();
    return SERVICE_VALUES[$type] ?? $new[$type]['value'] ?? null;
}

// SKU de servicio de cada type (tabla maestra de documentacion_armado_e_instalaciones.md, validada
// el 2026-10-15). Es 1 a 1: la planilla del dashboard trae el SKU de servicio y de aqui sale el type.
const SERVICE_SKUS = [
    3 => '1080156', 4 => '1079237', 5 => '1079238', 6 => '1119963', 7 => '1119961', 9 => '1119962',
    11 => '1119960', 13 => '1009726', 15 => '1119955', 16 => '1009725', 17 => '1120299',
    20 => '1009834', 26 => '1119943', 36 => '1096441', 37 => '1119956', 38 => '1087476',
    39 => '1087482', 40 => '1087473', 41 => '1087477', 43 => '1087474', 44 => '1119957',
    45 => '1087478', 46 => '1119958', 47 => '1175761', 48 => '1009799', 49 => '1119948',
    50 => '1119949', 51 => '1119950', 52 => '1183991', 53 => '1183992', 63 => '1176948',
    64 => '1171881', 65 => '1171883', 66 => '1171882', 67 => '1172507', 68 => '1172508',
    69 => '1172509', 70 => '1120298', 71 => '1192783',
];

function typeFromServiceSku(string $serviceSku): ?int
{
    static $new = null;
    $new ??= newServiceSkus();
    $type = array_search($serviceSku, SERVICE_SKUS + $new, true);
    return $type === false ? null : (int) $type;
}

function serviceDefaultName(int $type, ?string $group): string
{
    return match (true) {
        $type === 15 => 'Servicio de Instalación tabla yeso',
        $type === 16 => 'Servicio de Instalación pared de concreto',
        $group === 'armado' => 'Servicio de armado',
        $group === 'instalacion' => 'Servicio de Instalación',
        $group === 'visita' => 'Servicio de visita',
        $group === 'reemplazo' => 'Servicio de reemplazo',
        default => '',
    };
}
