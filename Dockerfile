# Use PHP 8.1 with Apache
FROM php:8.1-apache

# Install MySQL extensions for PHP
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy frontend files to Apache root
COPY frontend/ /var/www/html/

# Copy images folder to Apache root
COPY images/ /var/www/html/images/

# Expose port 80
EXPOSE 80