<?php
declare(strict_types=1);

/*
 * Corre una tarea del dashboard en segundo plano y anota su fin en el log.
 *
 * Uso:
 * php bin/job.php <nombre> <registro> <script de bin/> [argumentos del script...]
 *
 * Ejemplo:
 * php bin/job.php cobertura dashboard/cache/coverage.log coverage-by-category.php --output=dashboard/cache/coverage.json
 *
 * Contexto para otra IA:
 * - Reemplaza el «( script > registro 2>&1; echo EXIT:$? >> registro ) &» que usaba el dashboard. El
 *   registro queda igual: la salida del script y al final la línea EXIT:<código>, que el dashboard lee
 *   para saber si la tarea terminó (coverageJob, typesAutoJob).
 * - Además anota «tarea.fin» en el log (lib/log.php) con el código, la duración y la última línea.
 * - El registro siempre termina en EXIT, también si el script no existe (EXIT:1) o si job.php se cae
 *   antes de lanzarlo (EXIT:255).
 * - Solo lanza scripts de bin/. Lo que escriban en VTEX depende del script; los que usa el dashboard
 *   aquí (coverage-by-category, update-services) solo leen.
 */

// El registro se abre antes de cargar la librería y de validar el script: cualquier salida temprana o
// caída de este proceso deja igual la línea EXIT:<código>, como el wrapper de shell anterior. Sin ella
// el dashboard vería la tarea «en curso» hasta darla por trabada a los 20 minutos.
[$nombre, $registro, $script] = [$argv[1] ?? '', $argv[2] ?? '', basename($argv[3] ?? '')];
$fh = $registro === '' ? false : @fopen($registro, 'w');
$terminado = false;
register_shutdown_function(static function () use (&$fh, &$terminado): void {
    if (!$terminado && is_resource($fh)) {
        fwrite($fh, "EXIT:255\n");   // job.php se cayó (error fatal) antes de terminar
        fclose($fh);
    }
});
$cerrar = static function (int $codigo, string $ultima) use (&$fh, &$terminado, $nombre, $registro): void {
    if (is_resource($fh)) {
        fwrite($fh, "EXIT:$codigo\n");
        fclose($fh);
    }
    $terminado = true;
    if (function_exists('appLog')) {
        appLog($codigo === 0 ? 'info' : 'error', 'tarea.fin', ['tarea' => $nombre === '' ? '(sin nombre)' : $nombre, 'codigo' => $codigo,
            'ms' => (int) round((microtime(true) - $GLOBALS['inicio']) * 1000), 'ultima_linea' => $ultima]);
    }
};
$inicio = microtime(true);

require_once __DIR__ . '/../lib/bootstrap.php';

if ($nombre === '' || $registro === '' || $script === '' || !is_file(__DIR__ . "/$script")) {
    $uso = 'Uso: php bin/job.php <nombre> <registro> <script de bin/> [argumentos...]';
    if (is_resource($fh)) {
        fwrite($fh, "$uso\n");
    }
    $cerrar(1, $uso);
    fwrite(STDERR, "$uso\n");
    exit(1);
}
if (!is_resource($fh)) {
    $cerrar(1, "No se pudo escribir el registro $registro.");
    fwrite(STDERR, "No se pudo escribir el registro $registro.\n");
    exit(1);
}

$proc = proc_open(array_merge([PHP_BINARY, __DIR__ . "/$script"], array_slice($argv, 4)), [1 => $fh, 2 => $fh], $pipes, dirname(__DIR__));
$codigo = is_resource($proc) ? proc_close($proc) : -1;
$codigo = $codigo < 0 ? 255 : $codigo;   // el dashboard lee EXIT:<dígitos>; -1 lo dejaría «en curso»

$lineas = array_filter(array_map('trim', file($registro, FILE_IGNORE_NEW_LINES) ?: []), fn($l) => $l !== '' && !str_starts_with($l, 'EXIT:'));
$cerrar($codigo, (string) end($lineas));
exit($codigo === 0 ? 0 : 1);
