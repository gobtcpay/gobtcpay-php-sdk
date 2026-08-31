FROM php:8.2-cli

RUN apt-get update -yqq && apt-get install -yqq \
        git unzip libzip-dev \
    && docker-php-ext-install zip \
    && pecl install pcov && docker-php-ext-enable pcov \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY composer.json ./
RUN composer install --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize
