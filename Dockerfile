# PHP 8.2 sürümünü kullanan resmi PHP Apache imajını kullandım
FROM php:8.2-apache

# Sistem paketlerini güncelle ve gerekli paketleri yükledim
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite

# Apache mod_rewrite'ı etkinleştirdim
RUN a2enmod rewrite

# PHP ini ayarlarını güncelledim
RUN echo "upload_max_filesize = 64M" > /usr/local/etc/php/conf.d/upload-limit.ini \
    && echo "post_max_size = 64M" >> /usr/local/etc/php/conf.d/upload-limit.ini

# Apache DocumentRoot'u /var/www/html olarak ayarladım
ENV APACHE_DOCUMENT_ROOT /var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Uygulama dosyalarını kopyaladım
COPY . /var/www/html/

# SQLite veritabanı dizini oluştur ve izinlerini ayarladım
RUN mkdir -p /var/www/html/db && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Apache kullanıcısına SQLite dizini için yazma izni verdim
RUN chown -R www-data:www-data /var/www/html/db

# Portu expose ettim
EXPOSE 80

# Apache'yi başlattım
CMD ["apache2-foreground"]
