<?php
declare(strict_types=1);

/*
 * Actualiza desde VTEX lo que muestra Configurar servicios: la copia de types y los values nuevos.
 *
 * Uso:
 * php bin/update-services.php
 * php bin/update-services.php --types=dashboard/cache/service_types.csv --values=dashboard/cache/service_values.json
 * php bin/update-services.php --hasta-terminar
 *
 * Contexto para otra IA:
 * - Es lo que lanzan el boton «Actualizar Servicios» y la actualizacion automatica del dashboard.
 * - Corre list-service-types.php y luego find-new-service-values.php. Si fallan los types no busca values. Si
 *   fallan los values, los types ya quedaron actualizados y sale con error.
 * - --max (300) acota la busqueda de values para que el boton no pase de los 230 s de App Service. Con
 *   --hasta-terminar sigue por tramos hasta completarla: asi la lanza la actualizacion automatica.
 * - Solo lectura: no hace POST/PUT/DELETE.
 */

require_once __DIR__ . '/../lib/bootstrap.php';

$options = getopt('', ['types:', 'values:', 'max:', 'hasta-terminar']);
$types = optionValue($options, 'types') ?? 'dashboard/cache/service_types.csv';
$values = optionValue($options, 'values') ?? 'dashboard/cache/service_values.json';
$max = optionValue($options, 'max') ?? '300';

// passthru y no proc_open con STDOUT: al heredar el registro de job.php, cada script escribia encima del anterior.
$correr = static function (string $script, array $args): void {
    passthru(implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY, __DIR__ . "/$script"], $args))), $codigo);
    if ($codigo !== 0) {
        exit(1);
    }
};

$correr('list-service-types.php', ['--no-skus', "--output=$types"]);
$ruta = str_starts_with($values, '/') ? $values : __DIR__ . "/../$values";
$tramos = 0;
do {
    $correr('find-new-service-values.php', ["--max=$max", "--output=$values"]);
} while (isset($options['hasta-terminar']) && !savedServiceValues($ruta)['completo'] && ++$tramos < 20);
