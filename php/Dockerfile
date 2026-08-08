# PHP 8.2: 7.4 снят с поддержки, и composer.lock резолвится под 8.2 (см. config.platform)
FROM php:8.2-cli AS php

# Зависимости
RUN apt-get update \
    && apt-get install -y libzip-dev unzip git \
    && docker-php-ext-install zip pdo pdo_mysql

# Указываем рабочую директорию
WORKDIR /var/www/html

# Доверяем каталогу проекта в Git
RUN git config --global --add safe.directory /var/www/html

# Копируем файлы проекта в контейнер
COPY . /var/www/html

# Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader \
    && composer dump-autoload

CMD ["php", "-S", "0.0.0.0:80", "-t", "/var/www/html"]