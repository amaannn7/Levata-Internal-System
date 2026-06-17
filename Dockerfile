# Macktiles Sales Intelligence — PHP + Apache container for Railway/Render
FROM php:8.2-apache

# Install curl extension (used by the LLM provider calls)
RUN apt-get update \
    && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules the .htaccess relies on
RUN a2enmod rewrite headers

# Allow .htaccess overrides in the web root
RUN sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copy the application
COPY . /var/www/html/

# Entrypoint configures the runtime port ($PORT) and data permissions
RUN chmod +x /var/www/html/docker-entrypoint.sh \
    && mkdir -p /var/www/html/data/uploads \
    && chown -R www-data:www-data /var/www/html/data \
    && chmod -R 775 /var/www/html/data

ENV PORT=80
EXPOSE 80

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
