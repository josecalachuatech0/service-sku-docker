# Imagen del dashboard de servicios de SKU para Azure App Service.
#
# - Apache con PHP 8.5 (la misma versión con la que se prueba en local).
# - Las credenciales NO van en la imagen: App Service las entrega como variables de entorno
#   (API_KEY, API_TOKEN, VTEX_BASE_URL), cargadas desde Key Vault. El .env queda fuera (.dockerignore).
# - Los logs (lib/log.php) van a /var/www/app/logs/app.log y docker/entrypoint.sh los copia a la salida
#   del contenedor, que es lo que muestra Azure en Log stream y Log Analytics.
# - Solo entran los scripts y las tablas que usa el código; el historial queda fuera (.dockerignore).
FROM php:8.5-apache

# zip: leer y generar la planilla .xlsx. curl, mbstring, iconv y simplexml ya vienen en la imagen.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# PHP_CLI: con Apache PHP_BINARY viene vacío; el dashboard lanza los scripts de bin/ con este PHP.
ENV APP_DIR=/var/www/app \
    APACHE_DOCUMENT_ROOT=/var/www/app/dashboard \
    PHP_CLI=/usr/local/bin/php \
    TZ=America/Guatemala

# Apache sirve solo dashboard/. cache/ y runs/ quedan bloqueadas por URL (docker/apache.conf).
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
COPY docker/apache.conf /etc/apache2/conf-enabled/sku-service.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/sku-service.ini

WORKDIR /var/www/app
COPY . /var/www/app
RUN mkdir -p dashboard/cache dashboard/runs logs \
    && chown -R www-data:www-data dashboard/cache dashboard/runs logs \
    && chmod +x docker/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/var/www/app/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
