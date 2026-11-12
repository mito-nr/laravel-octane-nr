FROM php:8.2-cli-bookworm

ARG SWOOLE_VERSION=5.1.1
ARG OCTANE_VERSION=1.5.6
ARG NEW_RELIC_AGENT_VERSION=12.12.0.41

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    # Octane 1.5.6 only supports Laravel <=10, whose releases all carry security advisories.
    # Demo/verification image only - do not ship this to production.
    COMPOSER_NO_SECURITY_BLOCKING=1

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip curl ca-certificates default-mysql-client \
 && rm -rf /var/lib/apt/lists/*

# PHP extensions: Swoole 5.1.1 / phpredis / PDO MySQL / OpenTelemetry ext / protobuf (OTLP) / Composer
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions swoole-${SWOOLE_VERSION} redis pdo_mysql pcntl sockets opentelemetry protobuf @composer

# New Relic PHP agent (x64 / arm64 are both contained in the tarball)
RUN curl -fsSL "https://download.newrelic.com/php_agent/release/newrelic-php5-${NEW_RELIC_AGENT_VERSION}-linux.tar.gz" | tar -C /tmp -zx \
 && NR_INSTALL_USE_CP_NOT_LN=1 NR_INSTALL_SILENT=1 "/tmp/newrelic-php5-${NEW_RELIC_AGENT_VERSION}-linux/newrelic-install" install \
 && rm -rf /tmp/newrelic-php5-* /tmp/nrinstall*

# Laravel 10 + Octane 1.5.6 + OpenTelemetry (SDK / OTLP exporter / auto-instrumentation)
RUN rm -rf /var/www && mkdir -p /var/www
WORKDIR /var/www
RUN composer create-project laravel/laravel:^10.0 . --no-interaction --prefer-dist \
 && composer config --no-plugins allow-plugins.php-http/discovery true \
 && composer require --no-interaction -W \
      laravel/octane:${OCTANE_VERSION} \
      open-telemetry/sdk \
      open-telemetry/exporter-otlp \
      open-telemetry/opentelemetry-auto-pdo \
      php-http/guzzle7-adapter \
      nyholm/psr7

# Application code (controllers, routes, migration ...)
COPY app-overlay/ /var/www/
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
 && chmod -R 777 storage bootstrap/cache

EXPOSE 8000
ENTRYPOINT ["entrypoint.sh"]
