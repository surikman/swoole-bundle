ARG PHP_TAG="7.3-cli-alpine3.10"
ARG COMPOSER_TAG="1.9.1"

FROM php:$PHP_TAG as ext-builder
RUN docker-php-source extract && \
    apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS

FROM ext-builder as ext-pdo_mysql
RUN docker-php-ext-install pdo_mysql

FROM ext-builder as ext-apcu
RUN pecl install apcu-5.1.12 && \
    docker-php-ext-enable apcu

FROM ext-builder as ext-inotify
RUN pecl install inotify && \
    docker-php-ext-enable inotify

FROM ext-builder as ext-pcntl
RUN docker-php-ext-install pcntl

FROM ext-builder as ext-intl
RUN apk add --no-cache icu-dev && \
    docker-php-ext-install intl

FROM ext-builder as ext-xdebug
RUN pecl install xdebug && \
    docker-php-ext-enable xdebug

FROM ext-builder as ext-blackfire
# If you use Alpine, you need to set this value to "alpine"
ENV current_os=alpine
RUN version=$(php -r "echo PHP_MAJOR_VERSION.PHP_MINOR_VERSION;") \
    && curl -A "Docker" -o /tmp/blackfire-probe.tar.gz -D - -L -s https://blackfire.io/api/v1/releases/probe/php/$current_os/amd64/$version \
    && mkdir -p /tmp/blackfire \
    && tar zxpf /tmp/blackfire-probe.tar.gz -C /tmp/blackfire \
    && mv /tmp/blackfire/blackfire-*.so $(php -r "echo ini_get('extension_dir');")/blackfire.so \
    && printf "extension=blackfire.so\nblackfire.agent_socket=tcp://blackfire:8707\n" > $PHP_INI_DIR/conf.d/blackfire.ini \
    && rm -rf /tmp/blackfire /tmp/blackfire-probe.tar.gz

FROM ext-builder as ext-swoole
RUN apk add --no-cache git
ARG SWOOLE_VERSION="4.4.12"
RUN git clone https://github.com/swoole/swoole-src.git --branch "v$SWOOLE_VERSION" --depth 1 && \
    cd swoole-src && \
    phpize && \
    ./configure && \
    make && \
    make install && \
    docker-php-ext-enable swoole

FROM ext-builder as ext-pcov
RUN pecl install pcov && \
    docker-php-ext-enable pcov
RUN echo "pcov.enabled=1" >> /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini && \
    echo "pcov.directory=/usr/src/app/src" >> /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini

FROM ext-builder as ext-sdebug
RUN apk add --no-cache git
WORKDIR /root
RUN git clone https://github.com/mabu233/sdebug.git && \
    cd sdebug && \
    git checkout sdebug_2_7 && \
    phpize && \
    ./configure && \
    make clean && \
    make all && \
    make install

FROM php:$PHP_TAG as base
WORKDIR /usr/src/app
RUN addgroup -g 1000 -S runner && \
    adduser -u 1000 -S app -G runner && \
    chown app:runner /usr/src/app
RUN apk add --no-cache libstdc++ icu
# php -i | grep 'PHP API' | sed -e 's/PHP API => //'
ARG PHP_API_VERSION="20180731"
COPY --from=ext-swoole /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/swoole.so /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/swoole.so
COPY --from=ext-swoole /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini
COPY --from=ext-inotify /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/inotify.so /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/inotify.so
COPY --from=ext-inotify /usr/local/etc/php/conf.d/docker-php-ext-inotify.ini /usr/local/etc/php/conf.d/docker-php-ext-inotify.ini
COPY --from=ext-pcntl /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/pcntl.so /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/pcntl.so
COPY --from=ext-pcntl /usr/local/etc/php/conf.d/docker-php-ext-pcntl.ini /usr/local/etc/php/conf.d/docker-php-ext-pcntl.ini
COPY --from=ext-pdo_mysql /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/pdo_mysql.so /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/pdo_mysql.so
COPY --from=ext-pdo_mysql /usr/local/etc/php/conf.d/docker-php-ext-pdo_mysql.ini /usr/local/etc/php/conf.d/docker-php-ext-pdo_mysql.ini
COPY --from=ext-apcu /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/apcu.so /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/apcu.so
COPY --from=ext-apcu /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini
COPY --from=ext-blackfire /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/blackfire.so /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/blackfire.so
COPY --from=ext-blackfire /usr/local/etc/php/conf.d/blackfire.ini /usr/local/etc/php/conf.d/blackfire.ini
COPY --from=ext-intl /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/intl.so /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/intl.so
COPY --from=ext-intl /usr/local/etc/php/conf.d/docker-php-ext-intl.ini /usr/local/etc/php/conf.d/docker-php-ext-intl.ini

FROM composer:${COMPOSER_TAG} AS composer-bin
FROM base as app-installer
ENV COMPOSER_ALLOW_SUPERUSER="1"
COPY --chown=app:runner --from=composer-bin /usr/bin/composer /usr/local/bin/composer
RUN composer global require "hirak/prestissimo:^0.3" --prefer-dist --no-progress --no-suggest --classmap-authoritative --ansi
COPY composer.json composer.lock ./
RUN composer validate
ARG COMPOSER_ARGS="install"
RUN composer ${COMPOSER_ARGS} --prefer-dist --no-progress --no-suggest --no-autoloader --ansi
COPY . ./
RUN composer dump-autoload --classmap-authoritative --ansi

FROM base as base-coverage-xdebug
RUN apk add --no-cache bash lsof
ARG PHP_API_VERSION="20180731"
COPY --from=ext-xdebug /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/xdebug.so /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/xdebug.so
COPY --from=ext-xdebug /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
USER app:runner
ENV COVERAGE="1" \
    COMPOSER_ALLOW_SUPERUSER="1"
COPY --chown=app:runner --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY --chown=app:runner --from=app-installer /usr/src/app ./

FROM base as base-coverage-pcov
ARG PHP_API_VERSION="20180731"
COPY --from=ext-pcov /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/pcov.so /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/pcov.so
COPY --from=ext-pcov /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini
USER app:runner
ENV COVERAGE="1" \
    COMPOSER_ALLOW_SUPERUSER="1"
COPY --chown=app:runner --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY --chown=app:runner --from=app-installer /usr/src/app ./

FROM base as Cli
USER app:runner
COPY --chown=app:runner --from=app-installer /usr/src/app ./
ENTRYPOINT ["./tests/Fixtures/Symfony/app/console"]
CMD ["swoole:server:run"]

FROM base as CliDev
ENV COMPOSER_ALLOW_SUPERUSER="1"
USER app:runner
COPY --chown=app:runner --from=composer-bin /usr/bin/composer /usr/local/bin/composer
ENTRYPOINT ["./tests/Fixtures/Symfony/app/console"]
CMD ["swoole:server:run"]

FROM base as CliDevSdebug
RUN apk add --no-cache git
ARG PHP_API_VERSION="20180731"
COPY --from=ext-sdebug /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/xdebug.so /usr/local/lib/php/extensions/no-debug-non-zts-${PHP_API_VERSION}/xdebug.so
RUN echo "zend_extension=$(find /usr/local/lib/php/extensions/ -name xdebug.so)" > /usr/local/etc/php/conf.d/xdebug.ini && \
        echo "xdebug.remote_host=docker.for.mac.host.internal" >> /usr/local/etc/php/conf.d/xdebug.ini && \
        echo 'xdebug.file_link_format="phpstorm://open?url=file://%%f&line=%%l"' >> /usr/local/etc/php/conf.d/xdebug.ini && \
        echo "xdebug.remote_enable=1" >> /usr/local/etc/php/conf.d/xdebug.ini && \
        echo "xdebug.remote_autostart=1" >> /usr/local/etc/php/conf.d/xdebug.ini && \
        echo "xdebug.remote_connect_back=0" >> /usr/local/etc/php/conf.d/xdebug.ini && \
        echo "xdebug.idekey=PHPSTORM" >> /usr/local/etc/php/conf.d/xdebug.ini && \
        echo "xdebug.remote_port=9090" >> /usr/local/etc/php/conf.d/xdebug.ini && \
        echo "xdebug.max_nesting_level=10000" >> /usr/local/etc/php/conf.d/xdebug.ini && \
        echo "xdebug.remote_handler=dbgp" >> /usr/local/etc/php/conf.d/xdebug.ini && \
        echo "xdebug.remote_mode=req" >> /usr/local/etc/php/conf.d/xdebug.ini
ENV COMPOSER_ALLOW_SUPERUSER="1"
USER app:runner
COPY --chown=app:runner --from=composer-bin /usr/bin/composer /usr/local/bin/composer
WORKDIR /usr/src/app/tests/Fixtures/Symfony/app
ENTRYPOINT ["./console"]
CMD ["swoole:server:run"]

FROM Cli as Composer
ENV COMPOSER_ALLOW_SUPERUSER="1"
COPY --chown=app:runner --from=composer-bin /usr/bin/composer /usr/local/bin/composer
ENTRYPOINT ["composer"]
CMD ["test"]

FROM base-coverage-xdebug as CoverageXdebug
ENTRYPOINT ["composer"]
CMD ["unit-code-coverage"]

FROM base-coverage-pcov as CoveragePcov
ENTRYPOINT ["composer"]
CMD ["unit-code-coverage"]

FROM base-coverage-xdebug as CoverageXdebugWithRetry
ENTRYPOINT ["/bin/bash"]
CMD ["tests/run-feature-tests-code-coverage.sh"]
