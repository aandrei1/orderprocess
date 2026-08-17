FROM php:8.4-cli-alpine

WORKDIR /app

# System dependencies
RUN apk add --no-cache \
    git \
    unzip \
    curl \
    postgresql-dev \
    linux-headers

# PHP extensions for PostgreSQL + AMQP
RUN docker-php-ext-install pdo_pgsql

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy project files
COPY . .

# Install PHP dependencies
RUN composer install --no-interaction --optimize-autoloader --no-dev

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public/"]
