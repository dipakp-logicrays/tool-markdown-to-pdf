#!/usr/bin/env bash
#
# Container entrypoint.
#
# Adjusts Apache to listen on the port specified by the PORT environment
# variable, then hands off to apache2-foreground (the base image's
# normal command).
#
# Why: Render.com (and most managed PaaS providers) inject PORT at
# runtime and expect the app to listen on that port — typically 10000.
# Locally with docker compose, PORT is unset and we default to 80,
# matching the bind-mount and existing port mapping in docker-compose.yml.

set -e

PORT="${PORT:-80}"

if [ "$PORT" != "80" ]; then
    # Apache's Listen directive lives in ports.conf; the VirtualHost in
    # the default site. Both must agree on the port.
    sed -i "s/^Listen 80\$/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/" \
        /etc/apache2/sites-enabled/000-default.conf
fi

exec apache2-foreground
