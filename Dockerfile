# Alpine
FROM alpine:3.21.3

# Set the working directory
WORKDIR /var/www/html

ARG WWWGROUP="sail"
ARG WWWUSER="sail"

# Install basic packages
RUN apk update && apk --no-cache add \
    coreutils \
    supervisor \
    envsubst \
    nano \
    wget \
    curl \
    curl-dev \
    sqlite \
    ca-certificates \
    nodejs \
    npm \
    ffmpeg \
    redis \
    git \
    bash \
    tzdata \
    # nginx + php-fpm
    nginx \
    php84-cli \
    php84-fpm \
    php84-posix \
    php84-dev

# If running via Swoole, uncomment below line
# RUN apk --no-cache add php84-posix php84-pecl-swoole

# Install CRON
RUN touch crontab.tmp \
    && echo '* * * * * cd /var/www/html && /usr/bin/php artisan schedule:run >> /dev/null 2>&1' > crontab.tmp \
    && crontab crontab.tmp \
    && rm -rf crontab.tmp

# Install Redis config
COPY ./docker/8.4/redis.conf /etc/redis/redis.conf
RUN chmod 0644 /etc/redis/redis.conf

# Install and configure PHP extensions (adjust as needed)
RUN apk --no-cache add \
    php84-sqlite3 php84-gd php84-curl \
    php84-intl php84-imap php84-mbstring \
    php84-xml php84-zip php84-bcmath php84-soap \
    php84-xmlreader php84-xmlwriter \
    php84-iconv \
    php84-ldap \
    php84-tokenizer \
    php84-msgpack \
    php84-opcache \
    php84-pdo_mysql \
    php84-pdo_sqlite \
    php84-phar \
    php84-fileinfo \
    php84-pecl-igbinary \
    php84-pecl-pcov php84-pecl-imagick \
    php84-pecl-redis \
    php84-pcntl \
    && ln -s /usr/bin/php84 /usr/bin/php

COPY ./docker/8.4/php.ini /etc/php84/conf.d/99-sail.ini

# Configure supervisord
RUN touch /var/run/supervisord.pid \
    && mkdir -p /etc/supervisor.d/conf.d \
    && mkdir -p /var/log/supervisor \
    && touch /var/log/supervisor/supervisord.log

COPY ./docker/8.4/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Install composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH=$PATH:/root/.composer/vendor/bin
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy or create an nginx.conf if needed
COPY ./docker/8.4/nginx/nginx.conf /etc/nginx/nginx.tmpl
COPY ./docker/8.4/nginx/laravel.conf /etc/nginx/conf.d/laravel.tmpl

# Configure PHP-FPM
COPY ./docker/8.4/www.conf /etc/php84/php-fpm.d/www.tmpl

# Configure container startup script
COPY start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

# Pull app code
RUN git clone https://github.com/sparkison/m3u-editor.git /tmp/m3u-editor \
    && mv /tmp/m3u-editor/* /var/www/html \
    && mv /tmp/m3u-editor/.git /var/www/html/.git \
    && mv /tmp/m3u-editor/.env.example /var/www/html/.env.example \
    && rm -rf /tmp/m3u-editor

# Install composer dependencies
RUN composer install --no-dev --no-interaction --no-progress

# Final entrypoint
ENTRYPOINT ["start-container"]