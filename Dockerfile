FROM php:8.3-apache

# ส่วนขยาย PHP ที่ระบบใช้: pdo_mysql (ต่อ MariaDB), gd (รูปภาพ), intl/mbstring (ภาษาไทย), zip
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libfreetype6-dev libicu-dev libzip-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd intl mbstring zip \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
COPY app ./app
COPY public ./public

RUN chown -R www-data:www-data /var/www/html
