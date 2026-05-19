# syntax=docker/dockerfile:1.6
#
# Markdown → PDF converter — single-stage Docker image.
#
# Base: official php:8.4-apache (Debian bookworm).
# Adds: pandoc, chromium (headless), fontconfig, a starter font pack.
# Application files are baked in; .env / GOOGLE_FONTS_API_KEY / FONT_SOURCE
# are supplied at runtime via -e or docker-compose `environment:`.

FROM php:8.4-apache

# Avoid interactive apt prompts during build.
ENV DEBIAN_FRONTEND=noninteractive

# Install the conversion toolchain and a baseline of legible fonts so
# FONT_SOURCE=system has reasonable previews out of the box.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        pandoc \
        chromium \
        fontconfig \
        # Latin starter fonts (Debian Trixie repo names)
        fonts-liberation \
        fonts-dejavu \
        fonts-noto-core \
        fonts-firacode \
        fonts-jetbrains-mono \
        fonts-cascadia-code \
    && rm -rf /var/lib/apt/lists/*

# The app's includes/config.php expects /usr/bin/google-chrome. Chromium is
# the same Blink engine — symlink so the existing path works.
RUN ln -sf /usr/bin/chromium /usr/bin/google-chrome

# Enable common Apache modules (headers used by api/font.php's cache control)
RUN a2enmod headers rewrite

# Bump upload limits to match the app's MAX_UPLOAD_MB (16 MB + headroom)
RUN { \
        echo "upload_max_filesize = 20M"; \
        echo "post_max_size = 22M"; \
        echo "memory_limit = 256M"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# App
WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html/

# Per-job working dir must be writable by www-data; .env (if any) is mounted
# at runtime, not copied.
RUN mkdir -p /var/www/html/tmp \
    && chown -R www-data:www-data /var/www/html/tmp \
    && chmod 0775 /var/www/html/tmp \
    && rm -f /var/www/html/.env

# fc-list needs the calling process to have a HOME that fontconfig can read
# the cache from. www-data's home in Debian's php:apache image is /var/www;
# make sure it exists and is writable so fontconfig can write its cache.
RUN mkdir -p /var/www/.cache/fontconfig \
    && chown -R www-data:www-data /var/www

EXPOSE 80

# php:apache's default CMD already starts Apache in the foreground; no need
# to override unless you want a different entrypoint.

# Basic healthcheck — pings the form endpoint
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD curl -fsS http://localhost/ > /dev/null || exit 1
