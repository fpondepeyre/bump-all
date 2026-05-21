# bump-all

Update a Composer dependency across every project in a GitLab group and open a **Merge Request** for each one — in a single command.

---

## How it works

For each project in your GitLab group, `bump-all`:

1. Checks if the target package exists in `composer.json`
2. Updates its version and runs `composer update` locally
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
php app/console composer:update <package> <version> [options]
```

### Basic examples

```bash
# Update a package to a specific version
php app/console composer:update "vendor/package" "^3.0"

# Update on a release branch instead of master
php app/console composer:update "symfony/http-client" "7.4.*" \
  --base-branch="release/2025.1.0"

# Test on a single project before running on the whole group
php app/console composer:update "symfony/http-client" "7.4.*" \
  --base-branch="release/2025.1.0" \
  --project="my-service"

# Show every project scanned (verbose)
php app/console composer:update "vendor/package" "^3.0" -v
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

> All options can be set in `.env` — CLI flags override env vars.

---

## Tips

**Private GitLab packages** — authentication is handled automatically via `GITLAB_TOKEN`.

**Missing PHP extensions** (e.g. `ext-rdkafka`, `ext-grpc`) — if `composer update` fails because of extensions not installed locally, the tool retries automatically ignoring only those extensions. The PHP version constraint is always preserved.

**PHP version mismatch** — always set `COMPOSER_PHP_VERSION` to match your CI. Otherwise the lock file may include packages incompatible with your CI's PHP version.

**Re-running** — safe to run multiple times. If the bump branch already exists, it is recreated from scratch.

---

## Docker

```bash
docker build -t bump-all .

# Pass your .env into the container
docker run --rm -v $(pwd)/.env:/app/.env bump-all "vendor/package" "^3.0"
```
