# Alpine
FROM alpine:3.21.3

# Set the working directory
WORKDIR /var/www/html

ENV WWWGROUP="m3ue"
ENV WWWUSER="m3ue"

# Install basic packages
RUN apk update && apk --no-cache add \
    coreutils \
    supervisor \
    envsubst \
    su-exec \
    nano \
    wget \
    curl \
    curl-dev \
    sqlite \
    ca-certificates \
    nodejs \
    npm \
    ffmpeg \
    jellyfin-ffmpeg \
    redis \
    git \
    bash \
    tzdata \
    # HW accelerated video encoding
    libva \
    libva-utils \
    mesa-dri-gallium \
    mesa-va-gallium \
    # nginx + php-fpm
    nginx \
    php84-cli \
    php84-fpm \
    php84-posix \
    php84-openssl \
    php84-dev

# Add architecture-specific packages conditionally
RUN if [ "$(uname -m)" = "x86_64" ]; then \
        apk add --no-cache intel-media-driver libva-intel-driver; \
    else \
        echo "Skipping Intel-specific packages on $(uname -m) architecture"; \
    fi

# Install PostgreSQL server & client
RUN apk update && apk add --no-cache \
    postgresql \
    postgresql-client

# Install CRON
RUN touch crontab.tmp \
    && echo '* * * * * cd /var/www/html && /usr/bin/php artisan schedule:run >> /dev/null 2>&1' > crontab.tmp \
    && crontab crontab.tmp \
    && rm -rf crontab.tmp

# Install Redis config
COPY ./docker/8.4/redis.conf /etc/redis/redis.tmpl
RUN chmod 0644 /etc/redis/redis.tmpl

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
    php84-pdo_sqlite \
    php84-pdo_pgsql \
    php84-phar \
    php84-fileinfo \
    php84-pecl-igbinary \
    php84-pecl-pcov php84-pecl-imagick \
    php84-pecl-redis \
    php84-pcntl \
    && ln -s /usr/bin/php84 /usr/bin/php

COPY ./docker/8.4/php.ini /etc/php84/conf.d/99-m3ue.ini

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
COPY . /var/www/html

# Configure git
RUN git config --global --add safe.directory /var/www/html

# Install composer dependencies
RUN composer install --no-dev --no-interaction --no-progress -o

# Symlink jellyfin-ffmpeg to usr/bin
RUN ln -s /usr/lib/jellyfin-ffmpeg/ffmpeg /usr/bin/jellyfin-ffmpeg

# Setup user, group and permissions
RUN addgroup $WWWGROUP \
    && adduser -h /var/www/html -s /bin/bash -G $WWWGROUP -D $WWWUSER

# Create alias for `php artisan` command
RUN echo -e '#!/bin/bash\n php artisan app:"$@"' > /usr/bin/m3ue && \
    chmod +x /usr/bin/m3ue

RUN chown -R $WWWUSER:$WWWGROUP /var/www/html
RUN chown -R $WWWUSER:$WWWGROUP /var/lib/nginx

RUN mkdir -p /var/lib/postgresql && chown -R $WWWUSER:$WWWGROUP /var/lib/postgresql
RUN mkdir -p /run/postgresql && chown -R $WWWUSER:$WWWGROUP /run/postgresql

# Final entrypoint
ENTRYPOINT ["start-container"]
