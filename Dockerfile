# ============================================================
# Minimal PHP 8.2-FPM image untuk benchmark LMS
# Hanya install extension yang benar-benar dibutuhkan.
# ============================================================
FROM php:8.2-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git curl zip unzip \
    libpng-dev libonig-dev libxml2-dev libzip-dev \
    libcurl4-openssl-dev libssl-dev libicu-dev \
    && docker-php-ext-install \
        pdo pdo_mysql \
        mbstring xml zip bcmath intl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get autoremove -y && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Opcache tuning untuk performa
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.revalidate_freq=0'; \
    echo 'opcache.validate_timestamps=0'; \
} > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html
