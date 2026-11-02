FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev libonig-dev libpng-dev libxml2-dev \
 && docker-php-ext-install pdo_mysql mbstring bcmath intl zip pcntl \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
EXPOSE 8000
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8000"]

