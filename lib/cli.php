<?php
declare(strict_types=1);

/*
 * Helpers de linea de comandos compartidos por los scripts de bin/.
 *
 * Contexto para otra IA:
 * - optionValue acepta un nombre corto opcional (query-skus-*). Sin $short se comporta igual
 *   que la version que tenian los demas scripts.
 * - Las cadenas vacias o con solo espacios cuentan como "no pasada" y devuelven null.
 */

function envString(string $key, string $default = ''): string
{
    $value = env($key, $default);
    return is_string($value) ? trim($value) : $default;
}

function optionValue(array $options, string $name, ?string $short = null): ?string
{
    $value = $options[$name] ?? ($short !== null ? ($options[$short] ?? null) : null);
    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}
