#!/bin/sh
set -eu

# PECL's downloader can return a truncated archive as a successful download.
# Verify the official, pinned source before handing it to the compiler.
redis_archive=/tmp/phpredis-6.3.0.tgz
redis_sha256=0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5
for redis_attempt in 1 2 3; do
    if curl --fail --silent --show-error --location --retry 3 --retry-all-errors \
        --retry-delay 2 --connect-timeout 15 --max-time 120 \
        https://pecl.php.net/get/redis-6.3.0.tgz --output "$redis_archive" \
        && printf '%s  %s\n' "$redis_sha256" "$redis_archive" | sha256sum --check --status; then
        pecl install "$redis_archive"
        docker-php-ext-enable redis
        rm -f "$redis_archive"
        exit 0
    fi
done
printf '%s\n' 'Could not obtain the checksum-verified Redis extension archive.' >&2
exit 1
