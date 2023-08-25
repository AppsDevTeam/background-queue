FROM php:8.0-cli

RUN apt-get update

RUN apt-get install -y git unzip && curl --fail -sSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN docker-php-ext-install pdo_mysql sockets bcmath \
&& pecl install xdebug && docker-php-ext-enable xdebug && echo "xdebug.mode=coverage" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini