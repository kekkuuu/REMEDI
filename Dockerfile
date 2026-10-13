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
        libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libzip-dev \
        unzip git \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip bcmath \
    && rm -rf /var/lib/apt/lists/*

# ── Python dependencies ────────────────────────────────────────────────────
# Its own virtualenv, on PATH as `python` / `python3`. The INTERPRETER is pinned
# too (2026-10-13), not taken from the base image's Debian: uv installs exactly
# Python 3.14.4 and the exact versions in requirements.txt, the same ones the
# terminal measurement runs on, so the live forecast and the measured one are
# computed identically. Copied before the app so editing a Blade file does not
# reinstall numpy.
COPY --from=ghcr.io/astral-sh/uv:latest /uv /bin/uv
ENV UV_PYTHON_INSTALL_DIR=/opt/python
ENV VIRTUAL_ENV=/opt/forecast-venv
ENV PATH="$VIRTUAL_ENV/bin:$PATH"
COPY resources/python/requirements.txt /tmp/requirements.txt
RUN uv venv --python 3.14.4 "$VIRTUAL_ENV" \
    && uv pip install --python "$VIRTUAL_ENV/bin/python" --no-cache -r /tmp/requirements.txt \
    && "$VIRTUAL_ENV/bin/python3" -c "import numpy, scipy, pandas, statsmodels, pymysql"

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

# The layout's shared CSS/JS (public/assets/, linked with a content hash by
# App\Support\StaticAsset) may be kept by the browser for a year: a changed
# file gets a new URL. Spliced into the site block of FrankenPHP's stock
# Caddyfile; validated with `frankenphp validate` in the running container.
ENV CADDY_SERVER_EXTRA_DIRECTIVES='header /assets/* Cache-Control "public, max-age=31536000, immutable"'
EXPOSE 8080

ENTRYPOINT ["docker/entrypoint.sh"]
