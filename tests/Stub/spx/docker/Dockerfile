# PHP with ext-spx to record the reports of ../real, see ../real/README.md.
# With ext-sockets and netbase (/etc/protocols), it runs Trap too, to try a report end to end.
FROM php:8.3-cli

ARG SPX_VERSION=v0.4.22

RUN apt-get update \
    && apt-get install -y --no-install-recommends git netbase zlib1g-dev \
    && docker-php-ext-install sockets \
    && git clone --depth 1 --branch "$SPX_VERSION" https://github.com/NoiseByNorthwest/php-spx.git /usr/src/php-spx \
    && cd /usr/src/php-spx \
    && phpize && ./configure && make -j"$(nproc)" && make install \
    && docker-php-ext-enable spx \
    && printf 'spx.data_dir=/spx\n' > /usr/local/etc/php/conf.d/spx.ini \
    && rm -rf /var/lib/apt/lists/*
