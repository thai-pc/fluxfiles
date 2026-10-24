# Core + every paid module, with the real engines the modules shell out to:
# ClamAV (virus), Tesseract (ocr), c2patool (c2pa), Ghostscript. Built from a
# staged copy of the repo (.stage/app — never the live tree, never the dev .env).
FROM php:8.2-cli-trixie

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev libzip-dev zip unzip \
        curl ca-certificates jq tesseract-ocr tesseract-ocr-eng ghostscript \
        clamav clamav-daemon clamdscan \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd zip \
    && rm -rf /var/lib/apt/lists/* \
    && printf 'display_errors=stderr\nmemory_limit=512M\nupload_max_filesize=64M\npost_max_size=64M\n' > /usr/local/etc/php/conf.d/zz-live.ini

# Virus definitions at build time, so clamd starts in seconds.
RUN freshclam --stdout --foreground || freshclam --stdout --foreground \
    && mkdir -p /run/clamav && chown clamav:clamav /run/clamav \
    && sed -i 's|^LocalSocket .*|LocalSocket /run/clamav/clamd.ctl|' /etc/clamav/clamd.conf

# c2patool + the c2pa-rs ES256 test signing pair (test-only certificate).
ARG C2PATOOL=c2patool-v0.27.22
RUN curl -fsSL "https://github.com/contentauth/c2pa-rs/releases/download/${C2PATOOL}/${C2PATOOL}-x86_64-unknown-linux-gnu.tar.gz" -o /tmp/c2pa.tgz \
    && mkdir /tmp/c2pa && tar -xzf /tmp/c2pa.tgz -C /tmp/c2pa \
    && find /tmp/c2pa -type f -name c2patool -exec install -m 0755 {} /usr/local/bin/c2patool \; \
    && mkdir -p /etc/c2pa \
    && curl -fsSL https://raw.githubusercontent.com/contentauth/c2pa-rs/main/sdk/tests/fixtures/certs/es256.pem -o /etc/c2pa/es256.pem \
    && curl -fsSL https://raw.githubusercontent.com/contentauth/c2pa-rs/main/sdk/tests/fixtures/certs/es256.pub -o /etc/c2pa/es256.pub \
    && rm -rf /tmp/c2pa /tmp/c2pa.tgz && c2patool --version

COPY fixtures/ /fixtures/
COPY .stage/app/ /app/
WORKDIR /app/core
EXPOSE 8190
CMD ["bash", "/fixtures/core-entrypoint.sh"]
