FROM php:8.2-apache

RUN apt-get update && apt-get install -y libzip-dev && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo pdo_mysql zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# dompdf wird beim Bau des Images installiert, nicht mehr bei jedem
# Containerstart – kein Internetzugriff und keine Wartezeit mehr beim
# Hochfahren nötig.
RUN composer require dompdf/dompdf --no-interaction --no-dev --optimize-autoloader

RUN mkdir -p uploads/abrechnungen uploads/rechnungen uploads/dokumente \
        uploads/rechnungen/einreichungen uploads/eigentuemerkosten \
        uploads/uebergabeprotokolle backups \
    && chown -R www-data:www-data uploads backups \
    && chmod -R 775 uploads backups

EXPOSE 80
