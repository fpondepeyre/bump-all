# bump-all

Update one or more Composer dependencies across every project in a GitLab group and open a **Merge Request** for each — in a single command.

---

## How it works

For each project in your GitLab group, `bump-all`:

1. Checks if any of the target packages exist in `composer.json`
2. Updates their versions and runs `composer update` locally
3. Pushes a new branch with the updated `composer.json` + `composer.lock`
4. Opens a Merge Request targeting your base branch

---

## Requirements

- PHP >= 7.4 + Composer + Git installed locally
- A GitLab private token with `api` scope

---

## Setup

```bash
git clone https://github.com/your-user/bump-all.git
cd bump-all
composer install
cp .env.example .env
```

Fill in your `.env`:

```env
GITLAB_TOKEN=glpat-xxxxxxxxxxxxxxxxxxxx   # your GitLab token
GITLAB_GROUP=my-company/my-team           # group path in GitLab
GITLAB_URL=https://gitlab.com             # your GitLab instance
GITLAB_BASE_BRANCH=master                 # branch to update and target for MRs
COMPOSER_PHP_VERSION=8.3.0               # PHP version used in your CI
```

> `.env` is git-ignored. Never commit it.

---

## Usage

```bash
php app/console composer:update <vendor/name:version> [<vendor/name:version> ...] [options]
```

Each package is passed as `vendor/name:version`. You can pass as many as you need.

### Examples

```bash
# Update a single package
php app/console composer:update "vendor/package:^3.0"

# Update multiple packages at once (e.g. migrate from Symfony 6.4 → 7.4)
php app/console composer:update \
  "symfony/http-client:7.4.*" \
  "symfony/console:7.4.*" \
  "symfony/framework-bundle:7.4.*" \
  "symfony/amqp-messenger:7.4.*"

# Target a specific branch
php app/console composer:update "symfony/http-client:7.4.*" \
  --base-branch="release/2025.1.0"

# Test on a single project before running on the whole group
php app/console composer:update "symfony/http-client:7.4.*" \
  --base-branch="release/2025.1.0" \
  --project="my-service"

# Show every project scanned (verbose)
php app/console composer:update "vendor/package:^3.0" -v
```

### All options

| Option          | Env var                | Description                                              |
|-----------------|------------------------|----------------------------------------------------------|
| `--token`, `-t` | `GITLAB_TOKEN`         | GitLab private token                                     |
| `--group`, `-g` | `GITLAB_GROUP`         | GitLab group path or numeric ID                          |
| `--gitlab-url`  | `GITLAB_URL`           | GitLab instance URL                                      |
| `--base-branch` | `GITLAB_BASE_BRANCH`   | Branch to update and open MR against (default: `master`) |
| `--project`     | —                      | Restrict to one project by name or path                  |
| `--php-version` | `COMPOSER_PHP_VERSION` | Pin PHP version for `composer update` resolution         |
| `--add-missing` | —                      | Add packages not yet present in `composer.json` (upsert mode). Default: skip missing. |

> All options can be set in `.env` — CLI flags override env vars.

# Update modes

By default, `bump-all` only **updates packages that already exist** in `composer.json` — it never adds new ones.

Use `--add-missing` to also **add packages** that are not yet present:

```bash
# Only updates projects that already have symfony/http-client
php app/console composer:update "symfony/http-client:7.4.*"

# Adds symfony/http-client to every project, even those that don't have it yet
php app/console composer:update "symfony/http-client:7.4.*" --add-missing
```

---



**Private GitLab packages** — authentication is handled automatically via `GITLAB_TOKEN`.

**Missing PHP extensions** (e.g. `ext-rdkafka`, `ext-grpc`) — if `composer update` fails because of extensions not installed locally, the tool retries automatically ignoring only those extensions. The PHP version constraint is always preserved.

**PHP version mismatch** — always set `COMPOSER_PHP_VERSION` to match your CI. Otherwise the lock file may include packages incompatible with your CI's PHP version.

**Re-running** — safe to run multiple times. If the bump branch already exists, it is recreated from scratch.

---

## Docker

```bash
docker build -t bump-all .

# Single package
docker run --rm -v $(pwd)/.env:/app/.env bump-all "vendor/package:^3.0"

# Multiple packages
docker run --rm -v $(pwd)/.env:/app/.env bump-all \
  "symfony/http-client:7.4.*" \
  "symfony/console:7.4.*" \
  "symfony/framework-bundle:7.4.*"
```
