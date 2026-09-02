FROM php:8.4-cli

WORKDIR /app/app

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip default-mysql-client libicu-dev libzip-dev \
    && docker-php-ext-install intl pdo_mysql zip \
    && pecl install apcu redis \
    && docker-php-ext-enable apcu redis \
    && echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/app/start.sh /usr/local/bin/app-dev-start

RUN chmod +x /usr/local/bin/app-dev-start

EXPOSE 8000
