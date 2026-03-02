FROM php:8.4-apache

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf \
\
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        locales apt-utils git libicu-dev g++ libpng-dev libxml2-dev libzip-dev libonig-dev libxslt-dev unzip \
        libfreetype6-dev libjpeg-dev libjpeg62-turbo libjpeg62-turbo-dev nodejs npm \
\
    && echo "en_US.UTF-8 UTF-8" > /etc/locale.gen \
    && echo "fr_FR.UTF-8 UTF-8" >> /etc/locale.gen \
    && locale-gen \
\
    && curl -sS https://getcomposer.org/installer | php -- \
    && mv composer.phar /usr/local/bin/composer \
\
    && curl -sS https://get.symfony.com/cli/installer \
#    && mv /root/.symfony/bin/symfony /usr/local/bin \
\
    && docker-php-ext-configure gd --enable-gd --with-freetype --with-jpeg \
\
    && docker-php-ext-configure intl \
    && docker-php-ext-configure soap \
\
    && docker-php-ext-install \
            pdo pdo_mysql mysqli opcache intl zip calendar dom mbstring gd xsl soap \
\
    && pecl install apcu && docker-php-ext-enable apcu

RUN a2enmod rewrite

WORKDIR /var/www/
