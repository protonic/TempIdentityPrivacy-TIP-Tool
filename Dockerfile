# TIP Tool - PHP 8.3 + Apache image.
# PHP 8.4 moved the imap extension out of core, so this stays on 8.3.
FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libc-client-dev libkrb5-dev \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install -j"$(nproc)" imap pdo_mysql \
    && apt-get purge -y --auto-remove libkrb5-dev \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers expires deflate \
    && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && echo "ServerTokens Prod\nServerSignature Off" > /etc/apache2/conf-available/tip-hardening.conf \
    && a2enconf tip-hardening \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "expose_php = Off" > "$PHP_INI_DIR/conf.d/tip.ini"

# Layout mirrors a normal install: web root with tip-config and tip-cron as siblings.
COPY --chown=www-data:www-data . /var/www/html/
COPY docker/tip-config/ /var/www/tip-config/
COPY docker/tip-cron/ /var/www/tip-cron/
COPY docker/bootstrap.php /usr/local/bin/tip-bootstrap.php
COPY docker/entrypoint.sh /usr/local/bin/tip-entrypoint

# The setup wizard is replaced by tip-bootstrap.php in the image.
RUN rm -rf /var/www/html/setup.php /var/www/html/assets/js/setup.js /var/www/html/docker \
        /var/www/html/Dockerfile /var/www/html/docker-compose.yml \
    && mkdir -p /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html/logs \
    && chmod +x /usr/local/bin/tip-entrypoint

VOLUME /var/www/html/logs
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/robots.txt") === false ? 1 : 0);'

ENTRYPOINT ["tip-entrypoint"]
CMD ["apache2-foreground"]
