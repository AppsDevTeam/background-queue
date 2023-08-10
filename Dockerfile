FROM php:7.4-cli

RUN docker-php-ext-install pdo_mysql sockets bcmath

RUN apt-get update && apt-get install -y git unzip && curl --fail -sSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer