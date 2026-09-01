FROM php:8.1-apache

# Install system dependencies, Python 3, and pip
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    python3-venv \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN a2enmod rewrite

# Copy entire project directory structure
COPY . /var/www/html/

# Install Python requirements for your forecasting models
RUN pip3 install --no-cache-dir -r /var/www/html/backend/requirements.txt

# Copy images folder directly into the frontend directory so Apache can serve them
COPY images/ /var/www/html/frontend/images/

# Update Apache DocumentRoot to point to the frontend folder
ENV APACHE_DOCUMENT_ROOT /var/www/html/frontend
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 80