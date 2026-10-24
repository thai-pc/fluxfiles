# WordPress + the real FluxFiles plugin + all 13 paid modules, on the same PHP 8.2
# image the standalone live rig uses (ClamAV clamd, Tesseract, c2patool, Ghostscript
# are already in ff-modules-live:latest — build ../ first). Built from the staged
# copy in .stage/plugin (never the live tree, never the dev .env).
FROM ff-modules-live:latest

RUN docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql \
    && curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp \
    && chmod +x /usr/local/bin/wp \
    && mkdir -p /var/www/html && WP_CLI_ALLOW_ROOT=1 wp core download --path=/var/www/html --version=latest --quiet

COPY fixtures/ /wpfixtures/
COPY .stage/plugin/ /var/www/html/wp-content/plugins/fluxfiles/
WORKDIR /var/www/html
ENV WP_CLI_ALLOW_ROOT=1 PHP_CLI_SERVER_WORKERS=8
EXPOSE 8390
CMD ["bash", "/wpfixtures/wp-entrypoint.sh"]
