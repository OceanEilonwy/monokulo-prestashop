# PrestaShop on Apache, for this module's tests and for trying it out:
# the official image, with Apache moved to SHOP_PORT, pcov for coverage and
# Composer for the tests' own dependencies. PrestaShop installs itself into
# the database when the tests start it (docker/ps-setup).
#
# PS_VERSION and PHP_VERSION pick the official image's tag
# (prestashop/prestashop:<PS_VERSION>-<PHP_VERSION>): CI builds 9.2.0 on PHP
# 8.2 and 8.5, and 8.2.8 on PHP 8.1.
ARG PS_VERSION=9.2.0
ARG PHP_VERSION=8.5
FROM prestashop/prestashop:${PS_VERSION}-${PHP_VERSION}

RUN apt-get update \
 && apt-get install -y --no-install-recommends $PHPIZE_DEPS unzip \
 && (pecl install pcov && docker-php-ext-enable pcov || echo "pcov is not available for this PHP: no coverage") \
 && apt-get purge -y --auto-remove $PHPIZE_DEPS \
 && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache on SHOP_PORT (the compose file runs on the host's network, so the
# shop's address is the same from the browser, from Monokulo and from the
# tests).
ARG SHOP_PORT=8093
RUN sed -i "s/^Listen 80$/Listen ${SHOP_PORT}/" /etc/apache2/ports.conf \
 && sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${SHOP_PORT}>/" /etc/apache2/sites-available/000-default.conf \
 && printf 'display_errors = Off\nlog_errors = On\nerror_reporting = E_ALL\nmemory_limit = 512M\npcov.directory = /repo/src\n' > /usr/local/etc/php/conf.d/monokulo-tests.ini

# Files Apache makes (caches, logs, sessions) stay writable by the tests,
# which boot PrestaShop in-process as another user.
RUN echo 'umask 000' >> /etc/apache2/envvars

COPY docker/ps-setup /usr/local/bin/ps-setup
RUN chmod +x /usr/local/bin/ps-setup
