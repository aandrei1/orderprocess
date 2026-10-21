FROM php:8.4-cli-alpine

WORKDIR /app

# System dependencies
RUN apk add --no-cache \
    git \
    unzip \
    curl \
    postgresql-dev \
    linux-headers \
    bash

# PHP extensions for PostgreSQL + AMQP (php-amqplib needs ext-sockets)
RUN docker-php-ext-install pdo_pgsql sockets

# Dev-only opcache/realpath tuning — see the file for why.
COPY docker/php/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

COPY composer.json composer.lock ./

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP dependencies (learning project: keep dev tools like PHPUnit)
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

# Copy project files
COPY . .

# Make the image self-contained: generate the autoloader and warm the prod
# cache. Docker Compose never notices (the bind mount in compose.override.yaml
# replaces /app and `make install` rebuilds vendor/), but Kubernetes has no
# bind mount and runs exactly what is baked in here.
RUN composer dump-autoload --optimize --no-dev \
    && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public/"]
