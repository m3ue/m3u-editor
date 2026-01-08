########################################
# Build Arguments - Configurable at build time
########################################
# Allow customization of m3u-proxy repository and branch
# NOTE: GitHub Actions workflow automatically overrides these with dynamic values
# based on the repository owner (e.g., hektyc/m3u-proxy for hektyc/m3u-editor)
# Default: upstream sparkison/m3u-proxy (main branch) - used for manual builds
# Override: --build-arg M3U_PROXY_REPO=https://github.com/yourusername/m3u-proxy.git
#           --build-arg M3U_PROXY_BRANCH=dev
ARG M3U_PROXY_REPO=https://github.com/sparkison/m3u-proxy.git
ARG M3U_PROXY_BRANCH=main

########################################
# Stage 1: Composer builder - installs PHP dependencies
########################################
FROM composer:2 AS composer_builder
WORKDIR /app

# Copy composer metadata first for better layer caching
COPY composer.json composer.lock ./

# Install dependencies first (cached if composer files unchanged)
# Some platform requirements (ext-intl, ext-pcntl) are provided by the runtime image
RUN composer install --no-dev --no-interaction --no-progress -o --prefer-dist --ignore-platform-reqs --no-scripts --no-autoloader

# Copy application code for autoload generation
COPY app/ ./app/
COPY bootstrap/ ./bootstrap/
COPY config/ ./config/
COPY database/ ./database/
COPY routes/ ./routes/
COPY artisan ./

# Generate optimized autoloader
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

########################################
# Stage 2: Node builder - builds frontend assets
########################################
FROM node:18-alpine AS node_builder
WORKDIR /app

# Set production environment for optimized builds
ENV NODE_ENV=production

# Copy package files first for better layer caching
COPY package.json package-lock.json ./
RUN npm ci --silent --omit=dev

# Copy only files needed for the build
COPY vite.config.js postcss.config.js ./
COPY resources/ ./resources/
COPY public/ ./public/

# Copy vendor built by composer stage for Vite to resolve vendor CSS
COPY --from=composer_builder /app/vendor ./vendor

# Run the frontend build (Vite)
RUN npm run build && \
    # Clean up node_modules after build - not needed in final image
    rm -rf node_modules

########################################
# Stage 3: m3u-proxy builder - prepares Python proxy service
########################################
FROM python:3.12-alpine AS proxy_builder

# Re-declare ARGs for this stage
ARG M3U_PROXY_REPO=https://github.com/sparkison/m3u-proxy.git
ARG M3U_PROXY_BRANCH=main

WORKDIR /opt/m3u-proxy

# Install git for cloning
RUN apk add --no-cache git

# Clone and setup m3u-proxy
RUN echo "Cloning m3u-proxy from: ${M3U_PROXY_REPO} (branch: ${M3U_PROXY_BRANCH})" && \
    git clone -b ${M3U_PROXY_BRANCH} ${M3U_PROXY_REPO} . && \
    # Remove .git to reduce image size
    rm -rf .git

# Create virtual environment and install dependencies
RUN python3 -m venv .venv && \
    .venv/bin/pip install --no-cache-dir --upgrade pip && \
    .venv/bin/pip install --no-cache-dir -r requirements.txt

########################################
# Stage 4: Runtime image
########################################
FROM alpine:3.21.3 AS runtime

# Labels for image metadata
LABEL org.opencontainers.image.title="m3u-editor" \
      org.opencontainers.image.description="M3U Editor - IPTV playlist management" \
      org.opencontainers.image.vendor="sparkison" \
      org.opencontainers.image.licenses="MIT"

WORKDIR /var/www/html

# Re-declare ARGs in this stage so they're available
ARG GIT_BRANCH
ARG GIT_COMMIT
ARG GIT_TAG

# Set environment variables
ENV GIT_BRANCH=${GIT_BRANCH} \
    GIT_COMMIT=${GIT_COMMIT} \
    GIT_TAG=${GIT_TAG} \
    WWWGROUP="m3ue" \
    WWWUSER="m3ue" \
    # PHP/Laravel production settings
    APP_ENV=production \
    LOG_CHANNEL=stderr

# Add Alpine edge repositories and install ALL system packages in a single layer
# This maximizes layer caching and reduces image size
RUN echo "@edge https://dl-cdn.alpinelinux.org/alpine/edge/main" >> /etc/apk/repositories && \
    echo "@edge https://dl-cdn.alpinelinux.org/alpine/edge/community" >> /etc/apk/repositories && \
    apk update && apk add --no-cache \
    # Core utilities
    coreutils \
    openssl \
    supervisor \
    envsubst \
    su-exec \
    nano \
    wget \
    curl \
    curl-dev \
    sqlite \
    ca-certificates \
    bash \
    tzdata \
    # Node.js runtime (for Reverb/websockets if needed)
    nodejs \
    npm \
    # Redis server
    redis \
    # FFmpeg 8.0 from Alpine edge
    ffmpeg@edge \
    # Nginx web server
    nginx \
    # PostgreSQL server & client
    postgresql \
    postgresql-client \
    postgresql-contrib \
    # Python runtime (for m3u-proxy)
    python3 \
    # PHP 8.4 and all required extensions
    php84-cli \
    php84-fpm \
    php84-posix \
    php84-openssl \
    php84-sqlite3 \
    php84-gd \
    php84-curl \
    php84-intl \
    php84-imap \
    php84-mbstring \
    php84-xml \
    php84-zip \
    php84-bcmath \
    php84-soap \
    php84-xmlreader \
    php84-xmlwriter \
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
    php84-pecl-imagick \
    php84-pecl-redis \
    php84-pcntl && \
    # Create PHP symlink
    ln -s /usr/bin/php84 /usr/bin/php && \
    # Clean up apk cache
    rm -rf /var/cache/apk/*

# Create user and group early for proper file ownership
RUN addgroup ${WWWGROUP} && \
    adduser -h /var/www/html -s /bin/bash -G ${WWWGROUP} -D ${WWWUSER}

# Setup cron for Laravel scheduler
RUN echo '* * * * * cd /var/www/html && /usr/bin/php artisan schedule:run >> /dev/null 2>&1' | crontab -

# Create required directories with proper ownership
RUN mkdir -p \
    /etc/supervisor.d/conf.d \
    /var/log/supervisor \
    /var/lib/postgresql \
    /run/postgresql && \
    touch /var/run/supervisord.pid \
    /var/log/supervisor/supervisord.log && \
    chown -R ${WWWUSER}:${WWWGROUP} \
    /var/lib/nginx \
    /var/lib/postgresql \
    /run/postgresql

# Copy configuration files (these change less frequently)
COPY --chown=${WWWUSER}:${WWWGROUP} ./docker/8.4/redis.conf /etc/redis/redis.tmpl
COPY --chown=root:root ./docker/8.4/php.ini /etc/php84/conf.d/99-m3ue.ini
COPY --chown=root:root ./docker/8.4/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY --chown=root:root ./docker/8.4/nginx/nginx.conf /etc/nginx/nginx.tmpl
COPY --chown=root:root ./docker/8.4/nginx/laravel.conf /etc/nginx/conf.d/laravel.tmpl
COPY --chown=root:root ./docker/8.4/nginx/xtream.conf /etc/nginx/conf.d/xtream.tmpl
COPY --chown=root:root ./docker/8.4/www.conf /etc/php84/php-fpm.d/www.tmpl

# Copy container startup script
COPY --chmod=755 start-container /usr/local/bin/start-container

# Copy m3u-proxy from builder stage
COPY --from=proxy_builder --chown=${WWWUSER}:${WWWGROUP} /opt/m3u-proxy /opt/m3u-proxy

# Copy application code (changes more frequently)
COPY --chown=${WWWUSER}:${WWWGROUP} . /var/www/html

# Create git info file
RUN echo "GIT_BRANCH=${GIT_BRANCH}" > /var/www/html/.git-info && \
    echo "GIT_COMMIT=${GIT_COMMIT}" >> /var/www/html/.git-info && \
    echo "GIT_TAG=${GIT_TAG}" >> /var/www/html/.git-info && \
    echo "BUILD_DATE=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> /var/www/html/.git-info

# Copy build artifacts from builder stages (overwrite source files)
# Vendor directory from composer builder
COPY --from=composer_builder --chown=${WWWUSER}:${WWWGROUP} /app/vendor /var/www/html/vendor

# Built frontend assets from node builder
COPY --from=node_builder --chown=${WWWUSER}:${WWWGROUP} /app/public/build /var/www/html/public/build

# Create artisan command alias
RUN echo -e '#!/bin/bash\nphp artisan app:"$@"' > /usr/bin/m3ue && \
    chmod +x /usr/bin/m3ue

# Ensure proper permissions for storage and cache directories
RUN chown -R ${WWWUSER}:${WWWGROUP} /var/www/html && \
    chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Expose ports
EXPOSE 80 443 8080 6001

# Health check for the application
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/up || exit 1

# Final entrypoint
ENTRYPOINT ["start-container"]
