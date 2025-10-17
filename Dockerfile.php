FROM php:8.1-apache

# Устанавливаем системные зависимости
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    default-mysql-client

# Очищаем кэш
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Устанавливаем PHP расширения
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Получаем Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Настраиваем Apache
RUN a2enmod rewrite
RUN a2enmod headers

# Настраиваем виртуальный хост
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    ServerName localhost\n\
    <Directory "/var/www/html">\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Устанавливаем рабочую директорию
WORKDIR /var/www/html

# Копируем файлы проекта
COPY ./api /var/www/html

# Устанавливаем права доступа
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

# Экспонируем порт
EXPOSE 80

# Запускаем Apache
CMD ["apache2-foreground"] 