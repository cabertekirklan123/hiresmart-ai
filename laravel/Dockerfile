FROM serversideup/php:8.3-fpm-nginx

USER root

RUN install-php-extensions pdo_pgsql pgsql

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY --chown=www-data:www-data . .

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
