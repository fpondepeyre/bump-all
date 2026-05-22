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

- **Docker** (recommended) — no local PHP/Composer needed
- A GitLab personal access token with **`api`** scope

### Creating a GitLab token

1. Go to **`https://your-gitlab.com/-/user_settings/personal_access_tokens`**
2. Click **"Add new token"**, give it a name (e.g. `bump-all`)
3. Check the **`api`** scope and set an expiration date
4. Click **"Create personal access token"** and copy it immediately

> For self-hosted GitLab, replace `your-gitlab.com` with your instance URL.

---

## Setup

```bash
git clone https://github.com/fpondepeyre/bump-all.git
cd bump-all
docker build -t bump-all .
cp .env.example .env
```

Fill in your `.env`:

```env
GITLAB_TOKEN=glpat-xxxxxxxxxxxxxxxxxxxx   # your GitLab personal access token
GITLAB_GROUP=my-company/my-team           # group path in GitLab (can be nested: org/team/sub)
GITLAB_URL=https://gitlab.com             # your GitLab instance URL
GITLAB_BASE_BRANCH=main                   # branch to update and target for MRs
# COMPOSER_PHP_VERSION=8.3.27             # uncomment only if your CI PHP differs from the Docker image
# NO_SSL_VERIFY=true                      # uncomment for self-signed / internal CA certificates
```

> `.env` is git-ignored. Never commit it.

> **Self-hosted GitLab behind a private network?** Use `--network=host` when running Docker so the container reaches your GitLab instance through the host's network stack (see examples below).

---

## Usage

Two modes are available: **interactive wizard** (`-i`) and **command-line flags**.

---

## Interactive mode (`-i`)

The wizard guides you through 4 steps: select projects → select packages → set target versions → configure options.

```bash
docker run --rm -it --network=host -v $(pwd)/.env:/app/.env bump-all -i
```

### What you'll see

```
bump-all — Interactive Wizard
==============================

  ① Select projects   ② Select packages   ③ Set versions   ④ Configure options

① Projects
----------
 Fetching projects from GitLab...
 [OK] 25 project(s) found in group.

 ----  Project                                        Default branch
  1    my-org/backends/service-search                 release/2026.7.4
  2    my-org/backends/service-order                  release/2026.7.4
  ...

 Select projects by number (e.g. 1,3,5 or all):
 > 1,2
```

```
② Packages
----------
 Type a package name and press Tab to autocomplete.
 You can also type any vendor/package name not yet in your projects.
 Press Enter with an empty line to finish.

Package: symfony/http-client
  [OK] symfony/http-client
Package (1 selected, empty to finish):
```

```
③ Versions
----------
  symfony/http-client (currently 6.4.*): 7.4.*
```

```
④ Options
---------
 Update mode:
  [0] update-only — skip projects where the package is absent
  [1] upsert — also add the package if missing
 > 0

 Use --with-all-dependencies? (recommended for major migrations) (yes/no) [no]:
 > no
```

Then a summary is displayed and you confirm before any MR is created.

### Tips for interactive mode

- **Select all projects**: type `all` at the project selection prompt
- **Multiple projects**: type comma-separated numbers like `1,3,5`
- **Tab autocomplete**: start typing a package name and press Tab — suggestions come from the `composer.json` files of the selected projects
- **New package**: type any `vendor/package` name even if it doesn't exist in any project yet — then choose `upsert` mode so it gets added
- **Multiple packages**: keep entering packages one by one; press Enter on an empty line when done

---

## Command-line mode

```bash
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  "vendor/name:version" ["vendor/name:version" ...] [options]
```

Each package argument uses the format `vendor/name:version`.

### Examples

#### Update a single package across all projects

```bash
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  "symfony/http-client:7.4.*"
```

#### Update multiple packages at once

```bash
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  "symfony/http-client:7.4.*" \
  "symfony/messenger:7.4.*" \
  "symfony/console:7.4.*"
```

#### Target a specific branch (e.g. a release branch)

```bash
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  "symfony/http-client:7.4.*" \
  --base-branch="release/2026.7.4"
```

#### Test on a single project before rolling out to all

```bash
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  "symfony/http-client:7.4.*" \
  --project="my-org/backends/service-search" \
  --base-branch="release/2026.7.4"
```

#### Add a package that doesn't exist yet in the projects (upsert)

```bash
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  "symfony/http-client:7.4.*" \
  --add-missing
```

#### Allow composer to upgrade transitive dependencies

```bash
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  "symfony/http-client:7.4.*" \
  --with-all-dependencies
```

#### Override the GitLab group without changing `.env`

```bash
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  "vendor/package:^3.0" \
  --group="other-org/other-team"
```

#### Verbose output (shows every project scanned)

```bash
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  "vendor/package:^3.0" -v
```

---

## Symfony major version migration

Migrating from Symfony 6.4 → 7.4 across all your projects? Use the `--symfony` shortcut instead of listing every package manually.

#### Test on one project first

```bash
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  --symfony=7.4 \
  --base-branch="release/2026.7.4" \
  --project="my-org/backends/service-search"
```

#### Roll out to all projects

```bash
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  --symfony=7.4 \
  --base-branch="release/2026.7.4"
```

#### Add custom exclusions (on top of the built-in ones)

```bash
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  --symfony=7.4 \
  --base-branch="release/2026.7.4" \
  --exclude="symfony/my-custom-bundle"
```

What `--symfony=7.4` does automatically:
- Updates **all `symfony/*` packages** present in each `composer.json` to `7.4.*`
- Updates **`extra.symfony.require`** to `7.4.*` (required by Symfony Flex)
- Enables **`--with-all-dependencies`** (recommended for major upgrades)
- **Auto-excludes** packages that have independent versioning and must not follow the framework version:
  - `symfony/flex`, `symfony/monolog-bundle`, `symfony/maker-bundle`, `symfony/webpack-encore-bundle`, `symfony/ux-*`

See the [Symfony upgrade guide](https://symfony.com/doc/current/setup/upgrade_major.html) for background.

---

## All options

| Option                    | Env var                | Default    | Description                                                                       |
|---------------------------|------------------------|------------|-----------------------------------------------------------------------------------|
| `--interactive`, `-i`     | —                      | —          | Launch the interactive wizard                                                     |
| `--token`, `-t`           | `GITLAB_TOKEN`         | —          | GitLab personal access token                                                      |
| `--group`, `-g`           | `GITLAB_GROUP`         | —          | GitLab group path or numeric ID                                                   |
| `--gitlab-url`            | `GITLAB_URL`           | —          | GitLab instance URL                                                               |
| `--base-branch`           | `GITLAB_BASE_BRANCH`   | `master`   | Branch to update and target for MRs                                               |
| `--project`               | —                      | —          | Restrict to a single project by name or path                                      |
| `--php-version`           | `COMPOSER_PHP_VERSION` | *(Docker PHP)* | PHP version for `composer update` resolution — only if CI differs from Docker |
| `--symfony=X.Y`           | —                      | —          | Symfony major migration shortcut (see above)                                      |
| `--add-missing`           | —                      | off        | Add packages not yet in `composer.json` (upsert mode)                             |
| `--with-all-dependencies` | —                      | off        | Pass `-W` to composer, allowing transitive upgrades/downgrades                    |
| `--exclude`               | —                      | —          | Exclude a package from being updated (repeatable)                                 |
| `--no-ssl-verify`         | `NO_SSL_VERIFY`        | off        | Disable SSL certificate verification (self-signed / internal CA)                  |

> CLI flags always override `.env` values.

---

## Update modes

| Mode           | Flag            | Behaviour                                                    |
|----------------|-----------------|--------------------------------------------------------------|
| `update-only`  | *(default)*     | Only updates packages already present in `composer.json`     |
| `upsert`       | `--add-missing` | Also **adds** packages that are absent from `composer.json`  |

```bash
# update-only (default): skips projects where symfony/http-client is not declared
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all "symfony/http-client:7.4.*"

# upsert: adds symfony/http-client even to projects that don't have it yet
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all "symfony/http-client:7.4.*" --add-missing
```

---

## Tips

**Private GitLab packages** — authentication is handled automatically via `GITLAB_TOKEN`. Private package repositories declared in `composer.json` are passed through.

**Missing PHP extensions** — the Docker image ships with `ext-rdkafka`, `ext-amqp`, and `ext-grpc` pre-installed. If `composer update` fails due to a missing extension, the tool retries automatically ignoring only those extensions while always preserving the PHP version constraint.

**PHP version** — always set `COMPOSER_PHP_VERSION` to match your CI. Otherwise the lock file may resolve packages incompatible with your CI's PHP.

**Re-running** — safe to run multiple times. If the bump branch already exists it is recreated from scratch.

**Self-signed certificates** — set `NO_SSL_VERIFY=true` in `.env` or pass `--no-ssl-verify` to skip TLS verification (useful for internal GitLab instances with a private CA).

**Private network / Docker** — if your GitLab is only reachable from the host machine (e.g. VPN or internal DNS), always add `--network=host` to the `docker run` command.

---

## Docker reference

```bash
# Build the image (once)
docker build -t bump-all .

# Interactive wizard
docker run --rm -it --network=host -v $(pwd)/.env:/app/.env bump-all -i

# Single package, all projects
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all "vendor/package:^3.0"

# Symfony migration on one project (dry-run style test)
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  --symfony=7.4 \
  --base-branch="release/2026.7.4" \
  --project="my-org/backends/service-search"

# Multiple packages, specific branch
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  "symfony/http-client:7.4.*" \
  "symfony/messenger:7.4.*" \
  --base-branch="release/2026.7.4"

# Wildcard: all symfony/* except those with independent versioning
docker run --rm --network=host -v $(pwd)/.env:/app/.env bump-all \
  "symfony/*:7.4.*" \
  --exclude="symfony/flex" \
  --exclude="symfony/monolog-bundle" \
  --exclude="symfony/maker-bundle" \
  --base-branch="release/2026.7.4"
```
