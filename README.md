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
- A GitLab personal access token with **`api`** scope

### Creating a GitLab token

1. Go to **`https://your-gitlab.com/-/user_settings/personal_access_tokens`**
2. Click **"Add new token"**
3. Give it a name (e.g. `bump-all`)
4. Set an expiration date
5. Check the **`api`** scope
6. Click **"Create personal access token"**
7. Copy the token immediately — it won't be shown again

> For self-hosted GitLab, replace `your-gitlab.com` with your instance URL.

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
docker run --rm -v $(pwd)/.env:/app/.env bump-all "vendor/package:^3.0"

# Symfony major migration — the easy way (see details below)
docker run --rm -v $(pwd)/.env:/app/.env bump-all \
  --symfony=7.4 \
  --base-branch="release/2026.7.4" \
  --project="my-service"

# Update multiple specific packages
docker run --rm -v $(pwd)/.env:/app/.env bump-all \
  "symfony/http-client:7.4.*" \
  "symfony/messenger:7.4.*" \
  --base-branch="release/2026.7.4"

# Show every project scanned (verbose)
docker run --rm -v $(pwd)/.env:/app/.env bump-all "vendor/package:^3.0" -v
```

### All options

| Option                    | Env var                | Description                                              |
|---------------------------|------------------------|----------------------------------------------------------|
| `--token`, `-t`           | `GITLAB_TOKEN`         | GitLab private token                                     |
| `--group`, `-g`           | `GITLAB_GROUP`         | GitLab group path or numeric ID                          |
| `--gitlab-url`            | `GITLAB_URL`           | GitLab instance URL                                      |
| `--base-branch`           | `GITLAB_BASE_BRANCH`   | Branch to update and open MR against (default: `master`) |
| `--project`               | —                      | Restrict to one project by name or path                  |
| `--php-version`           | `COMPOSER_PHP_VERSION` | Pin PHP version for `composer update` resolution         |
| `--symfony=X.Y`           | —                      | Symfony major migration shortcut (see below)             |
| `--add-missing`           | —                      | Add packages not yet present in `composer.json` (upsert mode) |
| `--exclude`               | —                      | Exclude a package from wildcard matching (repeatable)    |
| `--with-all-dependencies` | —                      | Allow composer to upgrade/downgrade transitive dependencies (`-W`) |

> All options can be set in `.env` — CLI flags override env vars.

---

## Symfony major version migration

Migrating from Symfony 6.4 → 7.4 across all your projects? Use the `--symfony` shortcut:

```bash
docker run --rm -v $(pwd)/.env:/app/.env bump-all \
  --symfony=7.4 \
  --base-branch="release/2026.7.4" \
  --project="my-service"   # test on one project first
```

This single option automatically:
- Updates **all `symfony/*` packages** present in each `composer.json` to `7.4.*`
- **Auto-excludes** packages with independent versioning (not following symfony framework version):
  - `symfony/flex` → 2.x
  - `symfony/monolog-bundle` → 3.x/4.x
  - `symfony/maker-bundle` → 1.x
  - `symfony/webpack-encore-bundle` → 1.x/2.x
- Updates **`extra.symfony.require`** to `7.4.*` (required by Symfony Flex, [as per docs](https://symfony.com/doc/current/setup/upgrade_major.html))
- Enables **`--with-all-dependencies`** automatically ([recommended by Symfony upgrade guide](https://symfony.com/doc/current/setup/upgrade_major.html#dependency-errors))

Once validated on one project, run on all:

```bash
docker run --rm -v $(pwd)/.env:/app/.env bump-all \
  --symfony=7.4 \
  --base-branch="release/2026.7.4"
```

To add extra exclusions beyond the defaults:

```bash
docker run --rm -v $(pwd)/.env:/app/.env bump-all \
  --symfony=7.4 \
  --exclude="symfony/my-custom-bundle" \
  --base-branch="release/2026.7.4"
```

# Update modes

By default, `bump-all` only **updates packages that already exist** in `composer.json` — it never adds new ones.

Use `--add-missing` to also **add packages** that are not yet present:

```bash
# Only updates projects that already have symfony/http-client
docker run --rm -v $(pwd)/.env:/app/.env bump-all "symfony/http-client:7.4.*"

# Adds symfony/http-client to every project, even those that don't have it yet
docker run --rm -v $(pwd)/.env:/app/.env bump-all "symfony/http-client:7.4.*" --add-missing
```

---

## Tips

**Private GitLab packages** — authentication is handled automatically via `GITLAB_TOKEN`.

**Missing PHP extensions** — the Docker image ships with `ext-rdkafka`, `ext-amqp`, and `ext-grpc` pre-installed. If running outside Docker and `composer update` fails due to a missing extension, the tool retries automatically ignoring only those extensions. The PHP version constraint is always preserved.

**PHP version mismatch** — always set `COMPOSER_PHP_VERSION` to match your CI. Otherwise the lock file may include packages incompatible with your CI's PHP version.

**Re-running** — safe to run multiple times. If the bump branch already exists, it is recreated from scratch.

---

## Docker

The Docker image includes `ext-rdkafka`, `ext-amqp`, and `ext-grpc` so that `composer update` resolves dependencies correctly without needing to ignore platform requirements.

```bash
docker build -t bump-all .

# Single package
docker run --rm -v $(pwd)/.env:/app/.env bump-all "vendor/package:^3.0"

# All symfony/* packages (wildcard)
docker run --rm -v $(pwd)/.env:/app/.env bump-all \
  "symfony/*:7.4.*" \
  --exclude="symfony/flex" \
  --exclude="symfony/monolog-bundle" \
  --exclude="symfony/maker-bundle" \
  --base-branch="release/2026.7.4"
```
