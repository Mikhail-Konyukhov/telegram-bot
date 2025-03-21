# Используем образ с PHP
FROM php:7.4-cli AS php

# Устанавливаем ZIP-расширение и Git
RUN apt-get update && apt-get install -y libzip-dev unzip git \
    && docker-php-ext-install zip

# Устанавливаем PDO и MySQL драйвер
RUN docker-php-ext-install pdo pdo_mysql

# Указываем рабочую директорию
WORKDIR /var/www/html

# Доверяем каталогу проекта в Git
RUN git config --global --add safe.directory /var/www/html

# Копируем файлы проекта в контейнер
COPY . /var/www/html

# Устанавливаем зависимости через Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader && composer dump-autoload

# Запускаем встроенный PHP-сервер на порту 80
CMD ["php", "-S", "0.0.0.0:80", "/var/www/html/index.php"]