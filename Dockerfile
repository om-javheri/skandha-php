FROM php:8.3-apache
ENV APACHE_DOCUMENT_ROOT=/var/www/site/public DATA_DIR=/data
RUN a2enmod rewrite headers \
 && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && mkdir -p /data && chown www-data /data
COPY . /var/www/site/
VOLUME /data
