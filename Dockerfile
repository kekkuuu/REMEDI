# REMEDI — one image carrying BOTH runtimes.
#
# The app is Laravel, but the forecasting layer is Python: GenerateDemandForecast
# shells out to resources/python/generate_forecasts.py through Symfony Process
# and waits up to thirty minutes for pandas and statsmodels to fit ~1,250
# products. A PHP-only image would deploy cleanly and then fail every night at
# 02:00 -- silently, on a schedule, with the forecast pages quietly serving
# whatever they last imported.
#
# FrankenPHP rather than nginx + php-fpm + supervisor: one process to start and
# keep alive, serving public/ directly. `php artisan serve` is NOT an option --
# it is single-threaded, and REMEDI.md records the dashboard occupying it for
# 5-12 seconds while nothing else, static files included, is served.
FROM dunglas/frankenphp:1-php8.3

WORKDIR /app

# ── System packages ────────────────────────────────────────────────────────
# python3 for the forecasting layer; libpng/libjpeg/libwebp for GD, which
# `php artisan logo:mark` needs to regenerate the inlined logo derivatives.
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-pip python3-venv \
        libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libzip-dev \
        unzip git \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip bcmath \
    && rm -rf /var/lib/apt/lists/*

# ── Python dependencies ────────────────────────────────────────────────────
# Its own virtualenv, on PATH as `python`. Debian marks the system interpreter
# externally-managed, and pandas/statsmodels wheels do not belong in it.
# Copied before the app so editing a Blade file does not reinstall numpy.
ENV VIRTUAL_ENV=/opt/forecast-venv
ENV PATH="$VIRTUAL_ENV/bin:$PATH"
COPY resources/python/requirements.txt /tmp/requirements.txt
RUN python3 -m venv "$VIRTUAL_ENV" \
    && pip install --no-cache-dir -r /tmp/requirements.txt

# ── PHP dependencies ───────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ── The app ────────────────────────────────────────────────────────────────
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chmod +x docker/entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache

# Railway hands the port in $PORT; FrankenPHP reads $SERVER_NAME. The
# entrypoint binds them together.
ENV SERVER_NAME=:8080
EXPOSE 8080

ENTRYPOINT ["docker/entrypoint.sh"]
