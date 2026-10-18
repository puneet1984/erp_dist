FROM php:8.2-apache

RUN docker-php-ext-install mysqli \
    && a2enmod rewrite headers \
    && printf 'RedirectMatch ^/$ /erp/\n' > /etc/apache2/conf-available/erp-root.conf \
    && a2enconf erp-root

# The app expects to be served from /erp (BASE_PATH in config/config.php)
ENV APP_DIR=/var/www/html/erp
WORKDIR /var/www/html/erp
COPY . /var/www/html/erp

# Pristine copies of writable folders; the entrypoint seeds the mounted volumes from these
RUN mkdir -p /opt/erp-seed \
    && for d in config uploads logs assets/json; do \
         mkdir -p "/opt/erp-seed/$d" "/var/www/html/erp/$d" \
         && cp -a "/var/www/html/erp/$d/." "/opt/erp-seed/$d/"; \
       done

COPY docker/entrypoint.sh /usr/local/bin/erp-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/erp-entrypoint && chmod +x /usr/local/bin/erp-entrypoint

ENTRYPOINT ["erp-entrypoint"]
CMD ["apache2-foreground"]
