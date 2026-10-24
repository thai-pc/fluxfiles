# Laravel proxy host for the live paid-module rig. Built ON the base modules-live
# image (php 8.2 + ClamAV clamd + Tesseract + c2patool + Ghostscript) because the
# modules shell out to those engines from the PHP that runs them — here, Laravel's.
FROM ff-modules-live:latest
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_AUDIT=1
# Cached layer: a stock Laravel 12 skeleton, independent of the staged sources.
RUN composer create-project laravel/laravel /srv/laravel "^12.0" --prefer-dist --no-interaction --no-progress
COPY fixtures/ /fixtures-laravel/
COPY .stage/src/ /src/
RUN bash /fixtures-laravel/install-app.sh
WORKDIR /srv/laravel
EXPOSE 8290
CMD ["bash", "/fixtures-laravel/entrypoint.sh"]
