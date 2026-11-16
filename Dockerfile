# Use the official PHP image with Apache
FROM php:8.4.27-apache

WORKDIR /var/www/html

# PHP extensions not built into the base image: gd + zip (images and spreadsheet exports), pdo_mysql,
# ftp (digital hatch) and imagick (converts TIFF attachments from the hatch to PNG).
# The installer pulls only the runtime libraries and removes the build dependencies afterwards.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions gd zip pdo_mysql ftp imagick

# Production php.ini + app limits
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    printf 'memory_limit=256M\nupload_max_filesize=128M\npost_max_size=128M\n' > "$PHP_INI_DIR/conf.d/app.ini"

# Apache on port 8080, document root on /public
RUN sed -i 's/80/8080/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && \
    sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf && \
    a2enmod rewrite

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Dependencies first, so this layer is cached until composer.json / composer.lock change
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist && \
    composer clear-cache

# Copy Laravel project (owned by www-data at copy time - no extra chown layer)
COPY --chown=www-data:www-data . .

# The servers log in through ADFS: its AuthController and session config replace the local ones, then the folder goes.
# A missing file fails the build on purpose - the local AuthController logs in by personal number alone.
RUN mv ADFS/AuthController.php app/Http/Controllers/AuthController.php && \
    mv ADFS/session.php config/session.php && \
    rm -rf ADFS

# Autoloader + package discovery, writable dirs, Passport key permissions, storage link
RUN composer dump-autoload --optimize --no-dev && \
    mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache && \
    for key in storage/oauth-private.key storage/oauth-public.key; do \
    if [ -f "$key" ]; then chmod 600 "$key"; fi; \
    done && \
    (php artisan storage:link || true)

# Startup script: Apache, scheduler and queue worker
RUN printf '%s\n' \
    '#!/bin/sh' \
    'for key in /var/www/html/storage/oauth-private.key /var/www/html/storage/oauth-public.key; do' \
    '    [ -f "$key" ] && chmod 600 "$key" 2>/dev/null || true' \
    'done' \
    'apache2-foreground &' \
    '(while true; do php /var/www/html/artisan schedule:run --verbose --no-interaction; sleep 60; done) &' \
    'php /var/www/html/artisan queue:work --verbose &' \
    'wait' > /usr/local/bin/start-scheduler && \
    chmod +x /usr/local/bin/start-scheduler

EXPOSE 8080

USER www-data

CMD ["/usr/local/bin/start-scheduler"]
