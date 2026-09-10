FROM php:8.4-cli-alpine

WORKDIR /app

# System dependencies
RUN apk add --no-cache \
    git \
    unzip \
    curl \
    postgresql-dev \
    linux-headers

# PHP extensions for PostgreSQL + AMQP (php-amqplib needs ext-sockets)
RUN docker-php-ext-install pdo_pgsql sockets

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

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public/"]
