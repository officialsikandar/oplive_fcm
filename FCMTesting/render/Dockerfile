FROM php:8.2-apache

RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && a2enmod rewrite

COPY send.php /var/www/html/send.php
COPY index.html /var/www/html/index.html
COPY style.css /var/www/html/style.css
COPY app.js /var/www/html/app.js
COPY config.js /var/www/html/config.js

RUN sed -i "s|window.FCM_API = 'send.php'|window.FCM_API = 'send.php'|" /var/www/html/config.js
