# Reproducible PHP env for running the FluxFiles core test suite on any PHP version.
#   docker build --build-arg PHP_VERSION=8.4 -f docker/Dockerfile -t fluxfiles-test .
#   docker run --rm fluxfiles-test                 # runs unit + integration suite
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev libzip-dev zip unzip git \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd zip \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . .
RUN composer update -d packages/core --prefer-dist --no-interaction --no-progress

# >=32-byte secret so jwt v7 + the token-generating tests work without a mounted .env
ENV FLUXFILES_SECRET=docker-test-secret-key-0123456789abcdef \
    FLUXFILES_RATE_LIMIT_READ=100000 \
    FLUXFILES_RATE_LIMIT_WRITE=100000

CMD ["bash", "-lc", "printf 'FLUXFILES_SECRET=%s\\n' \"$FLUXFILES_SECRET\" > .env; rc=0; for f in packages/core/tests/unit/*.php packages/core/tests/integration/*.php; do php \"$f\" || rc=1; done; exit $rc"]
