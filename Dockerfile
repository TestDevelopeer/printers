FROM php:8.4-fpm-bookworm

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    git \
    iputils-ping \
    libicu-dev \
    libpq-dev \
    libsnmp-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        intl \
        pcntl \
        pdo_pgsql \
        snmp \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY . .

# Override php-fpm pool config: higher memory_limit for Filament pages
RUN mkdir -p /usr/local/etc/php-fpm.d && \
    cp docker/app/php-fpm-overrides.conf /usr/local/etc/php-fpm.d/zz-overrides.conf

RUN rm -f bootstrap/cache/*.php \
    && composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader \
    && chmod +x docker/app/start-container.sh \
    && mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["docker/app/start-container.sh"]
CMD ["app"]
