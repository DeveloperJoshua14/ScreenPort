FROM composer:2 AS dependencies
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --ignore-platform-reqs

FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install curl mbstring pdo_sqlite \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod headers rewrite ssl
WORKDIR /var/www/screenport
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data bin ./bin
COPY --chown=www-data:www-data resources ./resources
COPY --from=dependencies --chown=www-data:www-data /build/vendor ./vendor
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/screenport.ini
RUN mkdir -p /var/lib/screenport && chown www-data:www-data /var/lib/screenport
ENV STORAGE_PATH=/var/lib/screenport
EXPOSE 80
CMD ["apache2-foreground"]
