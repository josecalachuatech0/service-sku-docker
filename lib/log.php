<?php
declare(strict_types=1);

/*
 * Log para rastrear fallos del dashboard y de los scripts de bin/.
 *
 * Contexto para otra IA:
 * - Una línea JSON por evento: fecha (hora de Guatemala), nivel (info | aviso | error), evento, origen y los datos del evento.
 * - Destino: la variable de entorno APP_LOG_FILE. Sin ella, logs/app.log en la raíz del repo (ignorado
 *   por git). En el contenedor no hace falta definirla: docker/entrypoint.sh sigue logs/app.log y lo
 *   copia a la salida que recoge Azure (Apache corre como www-data y no puede abrir /proc/1/fd/1). No se usa
 *   php://stderr en los scripts: el dashboard lee su stderr y buscaría ahí «HTTP 503».
 * - Nunca guarda API_KEY ni API_TOKEN: logRedact los quita enteros y recortados, porque las trazas de
 *   PHP muestran los argumentos de vtexCall cortados a 15 caracteres.
 * - Escribir el log nunca rompe lo que se está haciendo: si falla, se ignora.
 * - Qué se anota y por qué: ver «Logs» en la bitácora (2026-11-17).
 */

const LOG_MAX_TEXTO = 2000;   // caracteres por campo de texto: se guarda el final, que es donde está el error
const LOG_SECRETOS = ['API_KEY', 'API_TOKEN'];

function logDestino(): string
{
    $destino = getenv('APP_LOG_FILE');
    return $destino === false || $destino === '' ? dirname(__DIR__) . '/logs/app.log' : $destino;
}

// Quita del texto los valores de API_KEY y API_TOKEN: enteros y cualquier recorte de 8 caracteres o más.
function logRedact(string $texto): string
{
    foreach (LOG_SECRETOS as $clave) {
        $valor = getenv($clave);
        if ($valor === false || strlen($valor) < 8) {
            $valor = (string) ($_ENV[$clave] ?? '');
        }
        // También la forma con «/» escapada, como queda dentro de un JSON.
        foreach (array_unique([$valor, str_replace('/', '\\/', $valor)]) as $forma) {
            for ($n = strlen($forma); $n >= 8; $n--) {
                $texto = str_replace(substr($forma, 0, $n), '***', $texto);
            }
        }
    }
    return preg_replace('/(X-VTEX-API-App(?:Key|Token):\s*)\S+/i', '$1***', $texto) ?? $texto;
}

function logLimpiar(mixed $valor): mixed
{
    if (is_array($valor)) {
        return array_map('logLimpiar', $valor);
    }
    if (!is_string($valor)) {
        return $valor;
    }
    $valor = logRedact($valor);
    return strlen($valor) > LOG_MAX_TEXTO ? '…' . substr($valor, -LOG_MAX_TEXTO) : $valor;
}

/**
 * @param 'info'|'aviso'|'error' $nivel
 */
function appLog(string $nivel, string $evento, array $datos = []): void
{
    try {
        $linea = json_encode(['fecha' => (new DateTimeImmutable('now', new DateTimeZone('America/Guatemala')))->format('c'), 'nivel' => $nivel, 'evento' => $evento,
            'origen' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'php'))] + logLimpiar($datos),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $destino = logDestino();
        $esArchivo = !str_starts_with($destino, 'php://') && !str_starts_with($destino, '/proc/') && !str_starts_with($destino, '/dev/');
        if ($esArchivo && !is_dir(dirname($destino))) {
            @mkdir(dirname($destino), 0775, true);
        }
        @file_put_contents($destino, $linea . "\n", FILE_APPEND | ($esArchivo ? LOCK_EX : 0));
    } catch (Throwable) {
        // El log no debe tumbar la acción que se está registrando.
    }
}

// Anota los errores fatales de PHP (falta de memoria, excepciones sin atrapar) al terminar el proceso.
function logErroresFatales(): void
{
    register_shutdown_function(static function (): void {
        $e = error_get_last();
        if ($e !== null && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR], true)) {
            appLog('error', 'php.fatal', ['mensaje' => $e['message'], 'archivo' => basename($e['file']), 'linea' => $e['line']]);
        }
    });
}
