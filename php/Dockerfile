# PHP 8.2: 7.4 снят с поддержки, и composer.lock резолвится под 8.2 (см. config.platform)
#
# pcntl в require composer.json намеренно нет: расширения не существует на Windows,
# и `composer install` на хосте упал бы на нём. Воркер обходится без него
# (проверяет function_exists), теряя только корректное завершение по SIGTERM.
FROM php:8.2-cli AS php

# Зависимости
RUN apt-get update \
    && apt-get install -y libzip-dev unzip git \
    # sockets — обязательная зависимость php-amqplib, в образе её нет;
    # pcntl — корректное завершение воркера по SIGTERM.
    && docker-php-ext-install zip pdo pdo_mysql sockets pcntl

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