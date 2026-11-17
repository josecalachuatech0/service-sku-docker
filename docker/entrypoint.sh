#!/bin/sh
# Punto de entrada del contenedor. Prepara carpetas y logs, y luego arranca Apache (o el comando dado).
#
# Logs: lib/log.php escribe en APP_LOG_FILE (por defecto $APP_DIR/logs/app.log). Este script sigue ese
# archivo con «tail -F» y lo copia a la salida del contenedor, que Azure recoge. No se usa /proc/1/fd/1
# directo porque Apache corre PHP como www-data y ese usuario no puede abrir la salida del proceso 1: si
# llega ese valor (o cualquier /proc/... o /dev/...), se avisa y se usa el archivo por defecto.
#
# DATOS_PERSISTENTES (opcional): carpeta que sobrevive a reinicios, por ejemplo /home/datos en App Service.
# Si se define, dashboard/cache y dashboard/runs se guardan ahí. Sin ella, se pierden al reiniciar.
set -eu

APP_DIR="${APP_DIR:-/var/www/app}"
LOG_DEFECTO="$APP_DIR/logs/app.log"
LOG="${APP_LOG_FILE:-$LOG_DEFECTO}"

avisar() { echo "entrypoint: $*" >&2; }

case "$LOG" in
    /proc/*|/dev/*|php://*)
        avisar "APP_LOG_FILE=$LOG no sirve con Apache (www-data no puede abrirlo); se usa $LOG_DEFECTO."
        LOG="$LOG_DEFECTO"
        ;;
esac
export APP_LOG_FILE="$LOG"

if [ -n "${DATOS_PERSISTENTES:-}" ]; then
    for d in cache runs; do
        mkdir -p "$DATOS_PERSISTENTES/$d"
        if [ ! -L "$APP_DIR/dashboard/$d" ]; then
            rm -rf "$APP_DIR/dashboard/$d"
            ln -s "$DATOS_PERSISTENTES/$d" "$APP_DIR/dashboard/$d"
        fi
    done
fi

mkdir -p "$(dirname "$LOG")" "$APP_DIR/dashboard/cache" "$APP_DIR/dashboard/runs"
touch "$LOG"
# Un log de más de 50 MB ya llegó a Azure: se vacía al arrancar para que no crezca sin fin.
if [ "$(wc -c < "$LOG")" -gt 52428800 ]; then : > "$LOG"; fi

# PHP corre como www-data: debe poder escribir el log, cache/ y runs/. En /home de App Service chown
# puede no estar permitido; entonces se abren los permisos y se avisa, sin impedir el arranque.
if [ "$(id -u)" = 0 ]; then
    for p in "$(dirname "$LOG")" "$LOG" "$APP_DIR/dashboard/cache/" "$APP_DIR/dashboard/runs/"; do
        if ! chown -R www-data:www-data "$p" 2>/dev/null; then
            chmod -R a+rwX "$p" 2>/dev/null || avisar "no se pudo dar permiso de escritura a www-data en $p."
        fi
    done
fi

# Copia el log a la salida del contenedor. Si tail muere, se vuelve a lanzar.
(
    while :; do
        tail -n 0 -F "$LOG" 2>/dev/null || true
        sleep 1
    done
) &

exec "$@"
