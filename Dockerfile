FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libzip-dev libfreetype6-dev unzip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && docker-php-ext-install pdo_mysql gd zip \
 && a2enmod rewrite headers && apt-get clean
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && printf "expose_php=Off\nupload_max_filesize=16M\npost_max_size=80M\nmax_file_uploads=20\n" > /usr/local/etc/php/conf.d/security.ini
WORKDIR /var/www/html
