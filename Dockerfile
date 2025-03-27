# Laravel (Alpine Edge build)
FROM alpine:3.21.3

# Set environment variables
ENV WWWGROUP="sail"
ENV APP_PORT=36400
ENV REVERB_PORT=36800
ENV TZ=UTC
# ENV NODE_VERSION=22.12.0
ENV SUPERVISOR_PHP_USER="root"
#
# Supervisord command to run the app services
#

# Run via Artisan
# ENV SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=$APP_PORT"

# Run via Octane
ENV SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan octane:start --workers=4 --task-workers=6 --server=swoole --host=0.0.0.0 --port=$APP_PORT"

# Queue worker
# Laravel queue worker
#ENV QUEUE_PHP_COMMAND="/usr/bin/php /var/www/html/artisan queue:work --queue=default,import --sleep=3 --tries=3"

# Horizon queue worker
ENV QUEUE_PHP_COMMAND="/usr/bin/php /var/www/html/artisan horizon"

# Websockets
ENV WEBSOCKET_PHP_COMMAND="/usr/bin/php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=$REVERB_PORT --no-interaction --no-ansi"

# Set the working directory
WORKDIR /var/www/html

# Install basic packages
RUN apk update \
    && apk --no-cache add  \
        coreutils \
        supervisor \
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
        git

# Install PHP 8.4
RUN apk --no-cache add \
        php84-cli php84-dev

# Install PHP Swoole from prebuilt package
RUN apk --no-cache add \
    php84-posix php84-pecl-swoole

# ...or Build Swoole with pecl
# RUN apk --no-cache add \
#         php84-dev php84-pear php84-openssl php84-sockets \
#         gcc g++ musl-dev make \
#     && ln -s /usr/bin/pecl84 /usr/bin/pecl
# RUN pecl install \
#     --configureoptions 'enable-openssl="no" enable-sockets="yes" enable-mysqlnd="no" enable-swoole-curl="yes"' \
#     swoole

# # Install specific Node version
# FROM node:$NODE_VERSION-alpine AS node
# COPY --from=node /usr/lib /usr/lib
# COPY --from=node /usr/local/share /usr/local/share
# COPY --from=node /usr/local/lib /usr/local/lib
# COPY --from=node /usr/local/include /usr/local/include
# COPY --from=node /usr/local/bin /usr/local/bin

# https://wiki.alpinelinux.org/wiki/Setting_the_timezone
RUN apk --no-cache add tzdata \
    && cp /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone \
    && echo date

# Install and configure bash
RUN apk --no-cache add bash \
    && sed -i 's/bin\/ash/bin\/bash/g' /etc/passwd

# Install CRON
RUN touch crontab.tmp \
    && echo '* * * * * cd /var/www/html && /usr/bin/php artisan schedule:run >> /dev/null 2>&1' > crontab.tmp \
    && crontab crontab.tmp \
    && rm -rf crontab.tmp

# Install Redis config
COPY ./docker/8.4/redis.conf /etc/redis/redis.conf
RUN chmod 0644 /etc/redis/redis.conf

# Install and configure PHP extensions
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

# Configure container startup script
COPY start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

RUN git clone https://github.com/sparkison/m3u-editor.git /tmp/m3u-editor \
    && mv /tmp/m3u-editor/* /var/www/html \
    && mv /tmp/m3u-editor/.git /var/www/html/.git \
    && mv /tmp/m3u-editor/.env.example /var/www/html/.env.example \
    && rm -rf /tmp/m3u-editor

# Install composer dependencies
RUN composer install --no-dev --no-interaction --no-progress

# Configure user for sail
RUN addgroup $WWWGROUP \
    && adduser -h /var/www/html -s /bin/bash -G $WWWGROUP -D sail

RUN chown -R sail:$WWWGROUP /var/www/html

# Expose app port
EXPOSE $APP_PORT

ENTRYPOINT ["start-container"]
