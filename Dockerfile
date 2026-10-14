# CodeVault — application image.
#
# One image serves every role (web, cron, queue worker): the app is plain PHP,
# and the only difference between the roles is the command that runs. That keeps
# docker-compose.yml to a single build and guarantees the worker is running
# exactly the code the web request does.
#
# Build:  docker build -t codevault .
# Run:    docker compose up -d          (see docker-compose.yml)

FROM php:8.3-apache

# --- PHP extensions the application uses -------------------------------------
# Mirrors core/Install/RequirementsChecker.php (pdo_mysql, openssl, curl,
# mbstring, zip, json) plus the optional ones the Docker image can afford:
# gd for WebP derivatives, intl for locale-aware formatting, bcmath for money
# maths, opcache for speed, and redis so sessions/queue/cache use Redis
# instead of the file/sync fallbacks.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev libfreetype6-dev libicu-dev libjpeg62-turbo-dev \
        libonig-dev libpng-dev libzip-dev unzip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" bcmath gd intl mbstring opcache pdo_mysql zip; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove libcurl4-openssl-dev libfreetype6-dev libicu-dev \
        libjpeg62-turbo-dev libonig-dev libpng-dev libzip-dev; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

# --- Web server ---------------------------------------------------------------
# Document root is public/ — the same layout the README recommends for shared
# hosting (the repo-root .htaccess stays as the fallback for hosts that cannot
# point the document root at a subdirectory).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN set -eux; \
    a2enmod rewrite headers expires; \
    sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf; \
    printf '<Directory /var/www/html/>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/codevault.conf; \
    a2enconf codevault

# --- PHP configuration --------------------------------------------------------
RUN { \
        echo 'expose_php = Off'; \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_log = /var/www/html/storage/cache/php-error.log'; \
        echo 'memory_limit = 256M'; \
        echo 'upload_max_filesize = 32M'; \
        echo 'post_max_size = 32M'; \
        echo 'session.cookie_httponly = 1'; \
        echo 'opcache.validate_timestamps = 1'; \
        echo 'opcache.revalidate_freq = 0'; \
    } > /usr/local/etc/php/conf.d/codevault.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

# Runtime dependencies only: the platform itself has no runtime Composer
# packages, so this generates the optimized autoloader and nothing else.
RUN set -eux; \
    composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader; \
    mkdir -p storage/cache storage/sessions storage/system storage/backups \
        storage/ticket_attachments storage/logs; \
    chown -R www-data:www-data storage; \
    chmod +x docker/entrypoint.sh

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://localhost/health > /dev/null || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
