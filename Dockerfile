FROM php:8.4-apache

ENV APP_ENV=production
ENV PORT=3000

# Configure Apache to listen on port 3000 (Deplexo Container Requirement)
RUN sed -i 's/80/3000/g' /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf

# Enable Apache rewrite module & AllowOverride
RUN a2enmod rewrite
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf /etc/apache2/sites-available/*.conf

# Install required system dependencies & PHP extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    sqlite3 \
    libonig-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_sqlite mbstring curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy application source code (excluded databases via .dockerignore)
COPY . /var/www/html/

# Create runtime directories & set permissions for SQLite data persistence
RUN mkdir -p /data /var/www/html/data /var/www/html/data/backups \
    && chown -R www-data:www-data /data /var/www/html \
    && chmod -R 777 /data /var/www/html/data

EXPOSE 3000
