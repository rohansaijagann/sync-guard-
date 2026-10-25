FROM php:8.2-apache

# Install required system packages, extensions, and utilities
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Install official Composer binary
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory to project root
WORKDIR /var/www/html

# Configure Apache DocumentRoot to point to /var/www/html/public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Ensure Apache allows directory overrides for clean URLs
RUN sed -i '/<Directory ${APACHE_DOCUMENT_ROOT}>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set system and PHP default timezone to UTC
RUN echo "date.timezone=UTC" > /usr/local/etc/php/conf.d/timezone.ini
ENV TZ=UTC

EXPOSE 80
