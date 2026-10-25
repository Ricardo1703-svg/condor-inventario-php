# App 1 - Stack clásico (PaaS Cóndor Cloud): PHP 8.3 nativo + Apache + PDO MySQL
FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
 && a2enmod headers \
 && echo 'ServerTokens Prod\nServerSignature Off' > /etc/apache2/conf-enabled/zz-hardening.conf \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY src/ /var/www/html/
RUN chown -R root:root /var/www/html && chmod -R a+rX /var/www/html
EXPOSE 80
