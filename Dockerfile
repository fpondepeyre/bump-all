FROM php:8.3-cli

# Add confluent repo for latest librdkafka (same approach as project dockerfiles)
RUN apt-get update -q \
    && apt-get install -qy gpg \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /etc/apt/keyrings \
    && curl -s https://packages.confluent.io/deb/7.9/archive.key | gpg --dearmor | tee /etc/apt/keyrings/confluent.gpg > /dev/null \
    && printf "Types: deb\nURIs: https://packages.confluent.io/deb/7.9\nSuites: stable\nComponents: main\nArchitectures: %s\nSigned-by: /etc/apt/keyrings/confluent.gpg\n\nTypes: deb\nURIs: https://packages.confluent.io/clients/deb/\nSuites: bookworm\nComponents: main\nArchitectures: %s\nSigned-By: /etc/apt/keyrings/confluent.gpg\n" "$(dpkg --print-architecture)" "$(dpkg --print-architecture)" > /etc/apt/sources.list.d/confluent-platform.sources

RUN apt-get update -q && apt-get install -qy \
    git \
    unzip \
    librdkafka-dev \
    librabbitmq-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions using install-php-extensions (pre-built binaries, much faster than pecl)
RUN curl -L https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions \
    --output /usr/local/bin/install-php-extensions \
    && chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions rdkafka amqp grpc \
    && rm -f /usr/local/bin/install-php-extensions

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

COPY . /app/

RUN composer install --no-dev --no-interaction

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
