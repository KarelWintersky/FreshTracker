#!/bin/bash
set -e

echo "Building FreshTracker PHAR..."

# 1. Create temporary Dockerfile
TMP_DOCKERFILE="Dockerfile.builder.tmp"
if [ -f "box.phar" ]; then
    echo "Found local box.phar, will COPY it into image"
    BOX_COPY="COPY box.phar /usr/local/bin/box"
    BOX_CHMOD="RUN chmod +x /usr/local/bin/box"
else
    echo "box.phar not found locally, will download from GitHub"
    BOX_COPY=""
    BOX_CHMOD="RUN curl -LSs https://github.com/box-project/box/releases/download/4.5.0/box.phar -o /usr/local/bin/box && chmod +x /usr/local/bin/box"
fi

cat > "$TMP_DOCKERFILE" << DOCKERFILE
FROM php:8.3-cli

RUN apt-get update && apt-get install -y git unzip curl libzip-dev coreutils libicu-dev libsodium-dev && \
    docker-php-ext-install intl pcntl zip sodium && \
    pecl install redis && docker-php-ext-enable redis && \
    rm -rf /var/lib/apt/lists/*

RUN git config --global --add safe.directory '*'

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

${BOX_COPY}
${BOX_CHMOD}

WORKDIR /app
DOCKERFILE

# 2. Build the builder image
echo "Building builder image..."
docker build -f "$TMP_DOCKERFILE" -t freshtracker-builder .
rm -f "$TMP_DOCKERFILE"

# 3. Get current user UID/GID
CURRENT_UID=$(id -u)
CURRENT_GID=$(id -g)

# 4. Run build inside container
echo "Running build..."
docker run --rm \
    -v "$(pwd)":/app \
    -e HOST_UID=$CURRENT_UID \
    -e HOST_GID=$CURRENT_GID \
    freshtracker-builder sh -c "
        echo '   Installing dependencies...' && \
        composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction && \
        echo '   Generating version file...' && \
        git rev-parse --short HEAD > public/_version && \
        git log --oneline --format=%B -n 1 HEAD | head -n 1 >> public/_version && \
        git log --oneline --format='%at' -n 1 HEAD | xargs -I{} date -d @{} +%Y-%m-%d >> public/_version && \
        echo '   Compiling PHAR...' && \
        box compile && \
        echo '   Fixing permissions...' && \
        chown \${HOST_UID}:\${HOST_GID} /app/freshtracker.phar && \
        echo 'Done!'
    "

# 5. Verify
if [ -f "freshtracker.phar" ]; then
    echo "PHAR ready:"
    ls -lh freshtracker.phar
else
    echo "Error: freshtracker.phar was not created."
    exit 1
fi

# 6. Cleanup box.phar from public/
if [ -f "public/box.phar" ]; then
    echo "Removing box.phar from public/"
    rm -f public/box.phar
fi
