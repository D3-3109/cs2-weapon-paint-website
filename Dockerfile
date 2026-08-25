FROM node:22-bookworm-slim AS frontend-build

WORKDIR /src

COPY package.json package-lock.json ./
COPY frontend/package.json frontend/package-lock.json ./frontend/
RUN npm ci
RUN npm --prefix frontend install

COPY . .
# Mirror remote skin/sticker images locally (China-friendly, self-contained dist).
# Set WP_SKIP_IMAGE_MIRROR=1 to build without outbound network.
ARG WP_SKIP_IMAGE_MIRROR=0
RUN if [ "$WP_SKIP_IMAGE_MIRROR" != "1" ]; then node scripts/mirror-images.mjs; fi
RUN cd frontend && npm exec -- vite build --emptyOutDir
RUN node copy-backend.js

FROM php:8.3-apache-bookworm

RUN apt-get update \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && printf 'SetEnv WP_SKIP_HTTPS_REDIRECT 1\n' > /etc/apache2/conf-available/weapon-paints.conf \
    && printf '%s\n' \
        'SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1' \
        > /etc/apache2/conf-available/weapon-paints-auth.conf \
    && a2enconf weapon-paints \
    && a2enconf weapon-paints-auth \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=frontend-build /src/dist/ ./
COPY docker/entrypoint.sh /usr/local/bin/wp-entrypoint

RUN chmod +x /usr/local/bin/wp-entrypoint \
    && mkdir -p api/storage/sessions api/storage/cache api/storage/ratelimit api/storage/images_qr \
    && chown -R www-data:www-data api/storage

VOLUME ["/var/www/html/api/storage"]
EXPOSE 80
ENTRYPOINT ["wp-entrypoint"]
CMD ["apache2-foreground"]
