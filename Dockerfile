FROM php:8.3-cli

# Install git and composer
RUN apt-get update && apt-get install -y git unzip && rm -rf /var/lib/apt/lists/*
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

COPY . /app/

RUN composer install --no-dev --no-interaction

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
