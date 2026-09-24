# Multi-stage build: frontend assets + PHP runtime

# Stage 1: Build frontend assets with Node
FROM node:22-alpine AS frontend-builder

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js tsconfig.json ./
COPY resources/ ./resources/
COPY public/ ./public/

RUN npm run build

# Stage 2: PHP runtime with FrankenPHP + Octane
FROM dunglas/frankenphp:1-php8.4

WORKDIR /app

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    sqlite3 \
    postgresql-client \
    && rm -rf /var/lib/apt/lists/* && \
    docker-php-ext-install pdo_mysql pcntl

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . .

# Remove existing node_modules and vendor (will rebuild)
RUN rm -rf node_modules vendor

# Create bootstrap/cache and storage directories early
RUN mkdir -p bootstrap/cache storage/logs storage/framework/{cache,sessions,views} && \
    chmod -R 775 bootstrap/cache storage

# Install PHP dependencies (no-scripts to avoid .env requirement during build)
RUN composer install --no-scripts --optimize-autoloader && \
    composer dump-autoload --no-scripts --optimize

# Copy built frontend assets from builder stage
COPY --from=frontend-builder /app/public/build ./public/build

# Create necessary storage directories
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views \
    && chmod -R 775 storage bootstrap/cache public \
    && chown -R www-data:www-data storage bootstrap/cache public

# Copy .env.example as .env
RUN cp .env.example .env

# Copy entrypoint script and make it executable
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set environment defaults (can be overridden by docker-compose environment)
ENV APP_ENV=local
ENV OCTANE_SERVER=frankenphp
ENV OCTANE_HOST=0.0.0.0
ENV OCTANE_PORT=8000

# Health check (checks the /up route defined in bootstrap/app.php)
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8000/up || exit 1

# Run as non-root user
USER www-data

# Use entrypoint script to generate APP_KEY and start server
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD []
