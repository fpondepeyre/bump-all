#!/usr/bin/env bash
set -e

# Load .env if present
if [ -f /app/.env ]; then
    export $(grep -v '^#' /app/.env | xargs)
fi

exec php /app/app/console composer:update "$@"
