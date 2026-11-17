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
 *   dashboard trae el SKU de servicio y el type sale de aqui; el value sigue siendo SERVICE_VALUES.
 * - serviceDefaultName(): Name/Text canonico sugerido por grupo; los brackets 15/16 tienen el
 *   suyo. Visita no tiene canonico definido: se escribe a mano.
 * - officialServiceTypes() / isOfficialServiceType(): lista oficial de types del equipo de
 *   instalaciones (lista_oficial_types_instalaciones.csv, 49 types, recibida el 2026-11-13). Todo el
 *   dashboard (Configurar servicios, Consultar en vivo y Cobertura) ignora los types que no estan en ella.
 */

const PROTECTED_SKU_SERVICE_IDS = [297864, 297865];
const PROTECTED_SKUS = ['983865'];

function isProtectedSkuServiceId(int|string $id): bool
{
    return in_array((int) $id, PROTECTED_SKU_SERVICE_IDS, true);
}

const OFFICIAL_TYPES_CSV = __DIR__ . '/../lista_oficial_types_instalaciones.csv';

// Ids de los types de la lista oficial (columna type_vtex). Sin el archivo no hay types validos: se
// detiene en lugar de mostrar todos.
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
    67 => 84872, 68 => 84874, 69 => 84875, 70 => 87489,
];

// Ultimo SkuServiceValue conocido: el 94524 (type 71), encontrado el 2026-11-09. bin/find-new-service-values.php
// busca values nuevos desde el siguiente id. Actualizarlo despues de validar un value nuevo.
const LAST_KNOWN_SERVICE_VALUE_ID = 94524;

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
    69 => '1172509', 70 => '1120298',
];

function typeFromServiceSku(string $serviceSku): ?int
{
    $type = array_search($serviceSku, SERVICE_SKUS, true);
    return $type === false ? null : (int) $type;
}

function serviceDefaultName(int $type, ?string $group): string
{
    return match (true) {
        $type === 15 => 'Servicio de Instalación tabla yeso',
        $type === 16 => 'Servicio de Instalación pared de concreto',
        $group === 'armado' => 'Servicio de armado',
        $group === 'instalacion' => 'Servicio de Instalación',
        default => '',
    };
}
