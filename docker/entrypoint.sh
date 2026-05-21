#!/usr/bin/env bash
set -e

# Load .env if present
if [ -f /app/.env ]; then
    while IFS= read -r line; do
        [[ -z "$line" || "$line" =~ ^# ]] && continue
        export "$line"
    done < /app/.env
fi

exec php /app/app/console composer:update "$@"
