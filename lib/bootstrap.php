<?php
declare(strict_types=1);

/*
 * Punto de entrada comun para los scripts de bin/.
 *
 * Uso, al inicio de cada script:
 * require_once __DIR__ . '/../lib/bootstrap.php';
 *
 * Contexto para otra IA:
 * - Carga config/env.php y el .env de la raiz, y luego las librerias compartidas.
 * - Toda la logica que antes estaba copiada en cada script (opciones de CLI, cliente HTTP de
 *   VTEX con reintentos, reglas protegidas) vive aqui. Corregir aqui, no en los scripts.
 * - Los scripts de bin/legacy/ NO usan esta libreria: se archivaron tal cual estaban.
 */

require_once __DIR__ . '/../config/env.php';

loadEnv(__DIR__ . '/../.env');

require_once __DIR__ . '/log.php';
logErroresFatales();

require_once __DIR__ . '/cli.php';
require_once __DIR__ . '/vtex.php';
require_once __DIR__ . '/reglas.php';
