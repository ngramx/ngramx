FROM php:8.3-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash \
    git \
    zip \
    unzip \
    curl

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure git to allow mounted volumes
RUN git config --global --add safe.directory /app

# Set working directory
WORKDIR /app

# Keep container running
CMD ["tail", "-f", "/dev/null"]

