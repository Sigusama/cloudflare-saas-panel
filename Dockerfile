FROM php:8.4-apache
LABEL org.opencontainers.image.source="https://github.com/Sigusama/cloudflare-saas-panel" \
    org.opencontainers.image.title="Cloudflare SaaS Auto Onboarding Panel" \
    org.opencontainers.image.licenses="AGPL-3.0-only"
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev libicu-dev libonig-dev \
    && docker-php-ext-install pdo_mysql curl intl mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/panel
COPY . /var/www/panel
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/panel.ini
RUN mkdir -p storage/logs && chown -R www-data:www-data storage \
    && chmod +x docker/entrypoint.sh
ENTRYPOINT ["/var/www/panel/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
