FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    icu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    linux-headers \
    $PHPIZE_DEPS

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        intl \
        zip \
        gd \
        bcmath \
        pcntl \
        posix \
        opcache

RUN pecl channel-update pecl.php.net && pecl install redis && docker-php-ext-enable redis

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/api
COPY api/ .

RUN composer install --optimize-autoloader --no-interaction --no-scripts

# The host's public/storage is excluded from the build context (it is an
# absolute symlink that dangles outside a container, and buildkit rejects the
# whole context over it), so the link is made here instead. Relative, so it
# survives a change of WORKDIR.
RUN ln -sfn ../storage/app/public public/storage

RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
