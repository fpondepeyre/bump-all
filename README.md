# bump-all

A CLI tool that updates a Composer dependency across **all projects in a GitLab group** and opens a **Merge Request** for each one.

## How it works

1. Lists all projects in the given GitLab group (including subgroups)
2. For each project that has the target package in `composer.json` (require or require-dev)
3. Updates the version, runs `composer update` locally
4. Creates a branch, commits `composer.json` + `composer.lock`, opens a MR

## Requirements

- PHP >= 7.4
- Composer
- Git

## Installation

```bash
git clone https://github.com/your-user/bump-all.git
cd bump-all
composer install
cp .env.example .env
# Edit .env with your values
```

## Configuration

All options can be set in `.env` (never committed) or passed as CLI flags.

```env
GITLAB_TOKEN=glpat-xxxxxxxxxxxxxxxxxxxx
GITLAB_GROUP=my-company/my-group
GITLAB_URL=https://gitlab.example.com
GITLAB_BASE_BRANCH=master
COMPOSER_PHP_VERSION=8.3.0
```

## Usage

```bash
php app/console composer:update <package> <version> [options]
```

### Arguments

| Argument  | Description                              |
|-----------|------------------------------------------|
| `package` | Composer package name (e.g. `vendor/lib`)|
| `version` | Version constraint (e.g. `^2.0`, `7.4.*`)|

### Options

| Option             | Env var                  | Description                                      |
|--------------------|--------------------------|--------------------------------------------------|
| `--token`, `-t`    | `GITLAB_TOKEN`           | GitLab private token (scope: `api`)              |
| `--group`, `-g`    | `GITLAB_GROUP`           | GitLab group path or ID                          |
| `--gitlab-url`     | `GITLAB_URL`             | GitLab instance URL                              |
| `--base-branch`    | `GITLAB_BASE_BRANCH`     | Branch to update and target for MR (default: `master`) |
| `--project`        | —                        | Restrict to a single project (for testing)       |
| `--php-version`    | `COMPOSER_PHP_VERSION`   | PHP version for resolution (should match CI)     |

### Examples

```bash
# Update symfony/http-client to 7.4.* on a release branch (single project for testing)
php app/console composer:update "symfony/http-client" "7.4.*" \
  --base-branch="release/2025.1.0" \
  --project="my-service"

# Run on all projects in the group
php app/console composer:update "symfony/http-client" "7.4.*" \
  --base-branch="release/2025.1.0"

# Verbose output (shows all scanned projects)
php app/console composer:update "vendor/package" "^3.0" -v
```

## Docker

```bash
docker build -t bump-all .

# Mount your .env
docker run --rm -v $(pwd)/.env:/app/.env bump-all "vendor/package" "^3.0"
```

## Notes

- If `composer update` fails due to **missing PHP extensions** locally (e.g. `ext-rdkafka`), the tool automatically retries ignoring only those extensions — the PHP version constraint is always preserved.
- Use `--php-version` to match your CI PHP version and avoid lock file incompatibilities.
- The bump branch is automatically recreated if it already exists (safe to re-run).
