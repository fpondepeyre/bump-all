<?php

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Gitlab\Client;

#[AsCommand(
    name: 'composer:update',
    description: 'Update Composer dependencies across all projects in a GitLab group and open a MR for each',
)]
class BumpCommand extends Command
{
    /**
     * Symfony ecosystem packages that have their own independent versioning
     * and must NOT be updated to match the Symfony framework version.
     * See: https://symfony.com/doc/current/setup/upgrade_major.html
     */
    private const SYMFONY_INDEPENDENT_PACKAGES = [
        'symfony/flex',            // 2.x — manages recipes, not the framework
        'symfony/monolog-bundle',  // 3.x/4.x — independent versioning
        'symfony/maker-bundle',    // 1.x — independent versioning
        'symfony/webpack-encore-bundle', // 1.x/2.x — independent versioning
        'symfony/ux-chartjs',      // independent versioning
        'symfony/ux-twig-component', // independent versioning
    ];

    protected function configure(): void
    {
        $this->addArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages to update, format: vendor/name:version (e.g. symfony/http-client:7.4.* symfony/console:7.4.*)');
        $this->addOption('token', 't', InputOption::VALUE_OPTIONAL, 'GitLab private token (or GITLAB_TOKEN env var)');
        $this->addOption('group', 'g', InputOption::VALUE_OPTIONAL, 'GitLab group path or ID (or GITLAB_GROUP env var)');
        $this->addOption('gitlab-url', null, InputOption::VALUE_OPTIONAL, 'GitLab instance URL (or GITLAB_URL env var)');
        $this->addOption('base-branch', null, InputOption::VALUE_OPTIONAL, 'Base branch to update and target for the MR (or GITLAB_BASE_BRANCH env var, default: master)');
        $this->addOption('project', null, InputOption::VALUE_OPTIONAL, 'Restrict to a single project name or path (useful for testing)');
        $this->addOption('php-version', null, InputOption::VALUE_OPTIONAL, 'PHP version to use for dependency resolution — should match your CI (or COMPOSER_PHP_VERSION env var)');
        $this->addOption('add-missing', null, InputOption::VALUE_NONE, 'Also add packages that are not yet present in composer.json (upsert mode). Default: only update existing packages.');
        $this->addOption('with-all-dependencies', 'W', InputOption::VALUE_NONE, 'Pass --with-all-dependencies to composer update, allowing upgrades of transitive dependencies.');
        $this->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Exclude a package from being updated (can be repeated, e.g. --exclude=symfony/flex --exclude=symfony/monolog-bundle).');
        $this->addOption('symfony', null, InputOption::VALUE_REQUIRED, 'Shortcut for Symfony major migration: updates all symfony/* packages to the given version (e.g. --symfony=7.4). Automatically excludes packages with independent versioning and enables --with-all-dependencies.');
        $this->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Interactive mode: pick projects, packages and versions step by step.');
        $this->addOption('no-ssl-verify', null, InputOption::VALUE_NONE, 'Disable SSL certificate verification (useful for self-signed or internal CA certificates). Can also be set via NO_SSL_VERIFY=true in .env.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token      = $input->getOption('token')       ?: ($_ENV['GITLAB_TOKEN']          ?? null);
        $group      = $input->getOption('group')       ?: ($_ENV['GITLAB_GROUP']          ?? null);
        $gitlabUrl  = $input->getOption('gitlab-url')  ?: ($_ENV['GITLAB_URL']            ?? null);
        $baseBranch = $input->getOption('base-branch') ?: ($_ENV['GITLAB_BASE_BRANCH']    ?? 'master');
        $filterProject   = $input->getOption('project');
        $phpVersion      = $input->getOption('php-version') ?: ($_ENV['COMPOSER_PHP_VERSION'] ?? null);
        $addMissing      = $input->getOption('add-missing');
        $withAllDeps     = $input->getOption('with-all-dependencies');
        $excludes        = $input->getOption('exclude');
        $symfonyShortcut = $input->getOption('symfony');
        $interactive     = $input->getOption('interactive');
        $noSslVerify     = $input->getOption('no-ssl-verify') || filter_var($_ENV['NO_SSL_VERIFY'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        // --symfony=7.4 shortcut: replaces manual symfony/* packages + exclusions
        if ($symfonyShortcut !== null) {
            $version     = rtrim($symfonyShortcut, '.*') . '.*';
            $packages    = ['symfony/*' => $version];
            $excludes    = array_unique(array_merge($excludes, self::SYMFONY_INDEPENDENT_PACKAGES));
            $withAllDeps = true;
            $output->writeln(sprintf(
                '<info>Symfony migration mode: symfony/* → %s</info>  (excluding: %s)',
                $version,
                implode(', ', $excludes)
            ));
        } else {
            // Parse packages: each arg is "vendor/name:version"
            $packages = [];
            foreach ($input->getArgument('packages') as $arg) {
                if (!str_contains($arg, ':')) {
                    $output->writeln("<error>Invalid package format '$arg'. Expected vendor/name:version (e.g. symfony/http-client:7.4.*)</error>");
                    return Command::FAILURE;
                }
                [$name, $version] = explode(':', $arg, 2);
                $packages[$name] = $version;
            }

            if (empty($packages) && !$interactive) {
                $output->writeln('<error>No packages specified. Pass vendor/name:version arguments or use --symfony=X.Y or -i for interactive mode.</error>');
                return Command::FAILURE;
            }
        }

        if (!$token) {
            $output->writeln('<error>GitLab token is required. Use --token or set GITLAB_TOKEN in .env</error>');
            return Command::FAILURE;
        }
        if (!$group) {
            $output->writeln('<error>GitLab group is required. Use --group or set GITLAB_GROUP in .env</error>');
            return Command::FAILURE;
        }
        if (!$gitlabUrl) {
            $output->writeln('<error>GitLab URL is required. Use --gitlab-url or set GITLAB_URL in .env</error>');
            return Command::FAILURE;
        }

        if ($noSslVerify) {
            $httpClient = new \GuzzleHttp\Client([
                'verify' => false,
                'curl'   => [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0],
            ]);
            $builder = new \Gitlab\HttpClient\Builder(new \Http\Adapter\Guzzle7\Client($httpClient));
            $client  = new Client($builder);
        } else {
            $client = new Client();
        }
        $client->setUrl($gitlabUrl);
        $client->authenticate($token, Client::AUTH_HTTP_TOKEN);

        // Interactive mode: pick projects, packages and versions interactively
        $selectedProjectIds = null;
        if ($interactive) {
            [$packages, $selectedProjectIds, $addMissing, $withAllDeps] = $this->runInteractive(
                $input, $output, $client, $group, $baseBranch, $phpVersion
            );
            if ($packages === null) {
                return Command::FAILURE;
            }
        }

        $output->writeln("Scanning group <info>$group</info> on <info>$gitlabUrl</info>");
        $packageSummary = implode(', ', array_map(fn($n, $v) => "$n:$v", array_keys($packages), $packages));
        $output->writeln("Packages: <info>$packageSummary</info>  (branch: <info>$baseBranch</info>)");
        if ($filterProject) {
            $output->writeln("Filtering on project: <info>$filterProject</info>");
        }
        $output->writeln('');

        $page          = 1;
        $totalScanned  = 0;
        $totalUpdated  = 0;

        do {
            try {
                $projects = $client->groups()->projects($group, [
                    'per_page'          => 100,
                    'page'              => $page,
                    'include_subgroups' => true,
                ]);
            } catch (\Exception $e) {
                $output->writeln('<error>Cannot fetch group projects: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }

            if ($output->isVerbose()) {
                $output->writeln(sprintf('Page %d: %d project(s)', $page, count($projects)));
                foreach ($projects as $p) {
                    $output->writeln('  - ' . $p['path_with_namespace']);
                }
            }

            foreach ($projects as $project) {
                $projectId   = $project['id'];
                $projectName = $project['name'];
                $projectPath = $project['path'];

                if ($filterProject !== null && $projectName !== $filterProject && $projectPath !== $filterProject) {
                    continue;
                }
                if ($selectedProjectIds !== null && !in_array($projectId, $selectedProjectIds, true)) {
                    continue;
                }

                $totalScanned++;
                $output->write("[$projectPath] ");

                // Fetch composer.json
                try {
                    $composerFile = $client->repositoryFiles()->getFile($projectId, 'composer.json', $baseBranch);
                } catch (\Exception $e) {
                    $output->writeln('no composer.json on branch ' . $baseBranch . ', skipping.');
                    continue;
                }

                $composer     = json_decode(base64_decode($composerFile['content']), true);

                $matchedPackages = [];
                $addedPackages   = [];

                // Collect all existing packages from composer.json
                $allExisting = array_merge(
                    array_keys($composer['require']     ?? []),
                    array_keys($composer['require-dev'] ?? [])
                );

                foreach ($packages as $pattern => $version) {
                    $isWildcard = str_contains($pattern, '*');

                    if ($isWildcard) {
                        // Match all existing packages against the wildcard pattern
                        foreach ($allExisting as $existing) {
                            if (!fnmatch($pattern, $existing)) {
                                continue;
                            }
                            if (in_array($existing, $excludes, true)) {
                                continue;
                            }
                            if (isset($composer['require'][$existing])) {
                                $composer['require'][$existing] = $version;
                            } else {
                                $composer['require-dev'][$existing] = $version;
                            }
                            $matchedPackages[$existing] = $version;
                        }
                    } else {
                        if (isset($composer['require'][$pattern])) {
                            $composer['require'][$pattern] = $version;
                            $matchedPackages[$pattern] = $version;
                        } elseif (isset($composer['require-dev'][$pattern])) {
                            $composer['require-dev'][$pattern] = $version;
                            $matchedPackages[$pattern] = $version;
                        } elseif ($addMissing) {
                            $composer['require'][$pattern] = $version;
                            $matchedPackages[$pattern] = $version;
                            $addedPackages[$pattern]   = $version;
                        }
                    }
                }

                if (empty($matchedPackages)) {
                    $output->writeln('no matching packages found, skipping.');
                    continue;
                }

                // Update extra.symfony.require if symfony/* packages were bumped
                // This field controls Symfony Flex and must match the framework version
                foreach ($packages as $pattern => $version) {
                    if (fnmatch('symfony/*', $pattern) && isset($composer['extra']['symfony']['require'])) {
                        $old = $composer['extra']['symfony']['require'];
                        $composer['extra']['symfony']['require'] = $version;
                        $output->writeln("  extra.symfony.require: $old → $version");
                        break;
                    }
                }

                $updatedOnly = array_diff_key($matchedPackages, $addedPackages);
                $parts = [];
                if (!empty($updatedOnly)) {
                    $parts[] = 'updated: ' . implode(', ', array_map(fn($n, $v) => "$n:$v", array_keys($updatedOnly), $updatedOnly));
                }
                if (!empty($addedPackages)) {
                    $parts[] = 'added: ' . implode(', ', array_map(fn($n, $v) => "$n:$v", array_keys($addedPackages), $addedPackages));
                }
                $matchedSummary = implode(', ', array_map(fn($n, $v) => "$n:$v", array_keys($matchedPackages), $matchedPackages));
                $output->writeln(implode(' | ', $parts) . ' ...');

                // Fetch composer.lock
                $composerLockSha     = null;
                $composerLockContent = null;
                try {
                    $lockFile            = $client->repositoryFiles()->getFile($projectId, 'composer.lock', $baseBranch);
                    $composerLockSha     = $lockFile['blob_id'];
                    $composerLockContent = base64_decode($lockFile['content']);
                } catch (\Exception $e) {
                    // No composer.lock
                }

                // Get base branch SHA
                try {
                    $branchInfo = $client->repositories()->branch($projectId, $baseBranch);
                    $baseSha    = $branchInfo['commit']['id'];
                } catch (\Exception $e) {
                    $output->writeln("  <error>Cannot get branch '$baseBranch': " . $e->getMessage() . '</error>');
                    continue;
                }

                // Clone and run composer update
                $tmpDir       = '/tmp/bump-' . $projectPath;
                $gitHost      = parse_url($gitlabUrl, PHP_URL_HOST);
                $authFile     = $tmpDir . '-auth.json';

                exec('rm -rf ' . escapeshellarg($tmpDir));

                $cloneUrl = preg_replace('#^(https?://)#', '$1oauth2:' . $token . '@', $project['http_url_to_repo']);
                exec(
                    'git clone -b ' . escapeshellarg($baseBranch) . ' ' . escapeshellarg($cloneUrl) . ' ' . escapeshellarg($tmpDir) . ' 2>&1',
                    $cloneOut,
                    $cloneRet
                );
                if ($cloneRet !== 0) {
                    $output->writeln('  <error>Clone failed: ' . implode(' ', $cloneOut) . '</error>');
                    continue;
                }

                // Write COMPOSER_AUTH to a temp file to avoid token exposure in /proc
                file_put_contents($authFile, json_encode(['gitlab-token' => [$gitHost => $token]]));
                chmod($authFile, 0600);

                file_put_contents(
                    $tmpDir . '/composer.json',
                    json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
                );

                // Pin PHP platform version if specified — only for local resolution, NOT committed
                if ($phpVersion) {
                    exec('cd ' . escapeshellarg($tmpDir) . ' && composer config platform.php ' . escapeshellarg($phpVersion) . ' 2>&1');
                }

                $composerFlags = '--no-interaction --no-scripts' . ($withAllDeps ? ' --with-all-dependencies' : '');

                $composerOut = [];
                exec(
                    'cd ' . escapeshellarg($tmpDir) . ' && COMPOSER_AUTH=' . escapeshellarg(file_get_contents($authFile)) . ' composer update ' . $composerFlags . ' 2>&1',
                    $composerOut,
                    $composerRet
                );

                // On failure, retry ignoring only missing extensions (preserves PHP version constraint)
                if ($composerRet !== 0) {
                    $missingExts = [];
                    foreach ($composerOut as $line) {
                        if (preg_match('/requires? (ext-[\w]+)/', $line, $m)) {
                            $missingExts[$m[1]] = true;
                        }
                    }

                    if (!empty($missingExts)) {
                        $ignoreFlags = implode(' ', array_map(
                            fn($ext) => '--ignore-platform-req=' . escapeshellarg($ext),
                            array_keys($missingExts)
                        ));
                        $output->writeln('  Ignoring missing extensions: ' . implode(', ', array_keys($missingExts)));
                        $composerOut = [];
                        exec(
                            'cd ' . escapeshellarg($tmpDir) . ' && COMPOSER_AUTH=' . escapeshellarg(file_get_contents($authFile)) . ' composer update ' . $composerFlags . ' ' . $ignoreFlags . ' 2>&1',
                            $composerOut,
                            $composerRet
                        );
                    }
                }

                // Remove platform.php from composer.json — it was only needed for local resolution
                if ($phpVersion) {
                    exec('cd ' . escapeshellarg($tmpDir) . ' && composer config --unset platform 2>&1');
                }

                if ($composerRet !== 0) {
                    $output->writeln('  <error>composer update failed:</error>');
                    $output->writeln(implode("\n", $composerOut));
                    exec('rm -rf ' . escapeshellarg($tmpDir));
                    @unlink($authFile);
                    continue;
                }

                $newComposerJson = file_get_contents($tmpDir . '/composer.json');
                $newComposerLock = file_exists($tmpDir . '/composer.lock')
                    ? file_get_contents($tmpDir . '/composer.lock')
                    : null;

                exec('rm -rf ' . escapeshellarg($tmpDir));
                @unlink($authFile);

                $lockChanged = $newComposerLock !== null && $newComposerLock !== $composerLockContent;

                // Create branch name from packages
                if (count($matchedPackages) === 1) {
                    $pkgName = array_key_first($matchedPackages);
                    $pkgVer  = $matchedPackages[$pkgName];
                    $branch  = 'bump-' . str_replace('/', '-', $pkgName) . '-' . str_replace('*', 'last', $pkgVer);
                } else {
                    // Use vendor of first package + count
                    $firstPkg = array_key_first($matchedPackages);
                    $vendor   = explode('/', $firstPkg)[0];
                    $versions = array_unique(array_values($matchedPackages));
                    $verSlug  = str_replace('*', 'last', implode('-', $versions));
                    $branch   = 'bump-' . $vendor . '-' . count($matchedPackages) . 'pkgs-' . $verSlug;
                }
                try {
                    $client->repositories()->deleteBranch($projectId, $branch);
                } catch (\Exception $e) {
                    // Branch did not exist
                }
                try {
                    $client->repositories()->createBranch($projectId, $branch, $baseSha);
                } catch (\Exception $e) {
                    $output->writeln('  <error>Cannot create branch: ' . $e->getMessage() . '</error>');
                    continue;
                }

                // Commit composer.json
                try {
                    $client->repositoryFiles()->updateFile($projectId, [
                        'file_path'      => 'composer.json',
                        'branch'         => $branch,
                        'content'        => $newComposerJson,
                        'commit_message' => "bump: update $matchedSummary (composer.json)",
                        'encoding'       => 'text',
                    ]);
                } catch (\Exception $e) {
                    $output->writeln('  <error>Cannot commit composer.json: ' . $e->getMessage() . '</error>');
                    continue;
                }

                // Commit composer.lock if changed
                if ($lockChanged) {
                    try {
                        $action = $composerLockSha !== null ? 'updateFile' : 'createFile';
                        $client->repositoryFiles()->$action($projectId, [
                            'file_path'      => 'composer.lock',
                            'branch'         => $branch,
                            'content'        => $newComposerLock,
                            'commit_message' => "bump: update $matchedSummary (composer.lock)",
                            'encoding'       => 'text',
                        ]);
                    } catch (\Exception $e) {
                        $output->writeln('  <error>Cannot commit composer.lock: ' . $e->getMessage() . '</error>');
                    }
                }

                // Build MR title and description
                $mrTitle       = count($matchedPackages) === 1
                    ? 'Bump: ' . array_key_first($matchedPackages) . ' to ' . reset($matchedPackages)
                    : 'Bump: ' . count($matchedPackages) . ' packages (' . implode(', ', array_keys($matchedPackages)) . ')';
                $pkgList       = implode("\n", array_map(fn($n, $v) => "- `$n` → `$v`", array_keys($matchedPackages), $matchedPackages));
                $mrDescription = "## 🤖 Automated dependency update\n\nThis MR was automatically created by [bump-all](https://github.com/fpondepeyre/bump-all).\n\n---\n\n### What changed\n\n$pkgList\n\nUpdated in `composer.json`" . ($lockChanged ? " and `composer.lock`" : "") . ".\n\n### Why\n\nThis is a routine dependency bump. Please review the diff and make sure the CI passes before merging.";

                // Create MR
                try {
                    $client->mergeRequests()->create($projectId, $branch, $baseBranch, $mrTitle, [
                        'description'          => $mrDescription,
                        'remove_source_branch' => true,
                    ]);
                    $output->writeln('  MR created successfully.');
                    $totalUpdated++;
                } catch (\Exception $e) {
                    $msg = $e->getMessage();
                    if (str_contains($msg, 'already exists') || str_contains($msg, 'duplicate')) {
                        $output->writeln('  MR already exists.');
                        $totalUpdated++;
                    } else {
                        $output->writeln('  <error>Cannot create MR: ' . $msg . '</error>');
                    }
                }
            }

            $page++;
        } while (count($projects) === 100);

        $output->writeln('');
        $output->writeln("Done. Scanned: $totalScanned project(s), updated: $totalUpdated.");

        return Command::SUCCESS;
    }

    /**
     * Interactive wizard: select projects → packages → versions → mode.
     * Returns [packages, selectedProjectIds, addMissing, withAllDeps] or [null, ...] on abort.
     */
    private function runInteractive(InputInterface $input, OutputInterface $output, Client $client, string $group, string $baseBranch, ?string $phpVersion): array
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('bump-all — Interactive Wizard');
        $output->writeln([
            '  <comment>①</comment> Select projects   <comment>②</comment> Select packages   <comment>③</comment> Set versions   <comment>④</comment> Configure options',
            '',
        ]);

        // ── Step 1: Fetch & select projects ──────────────────────────────────
        $io->section('① Projects');

        $allProjects = [];
        $page = 1;
        $io->text('Fetching projects from GitLab...');
        do {
            try {
                $batch = $client->groups()->projects($group, [
                    'per_page' => 100, 'page' => $page, 'include_subgroups' => true,
                ]);
            } catch (\Exception $e) {
                $io->error('Cannot fetch projects: ' . $e->getMessage());
                return [null, null, false, false];
            }
            $allProjects = array_merge($allProjects, $batch);
            $page++;
        } while (count($batch) === 100);

        if (empty($allProjects)) {
            $io->error('No projects found in group.');
            return [null, null, false, false];
        }

        $io->success(sprintf('%d project(s) found in group.', count($allProjects)));

        // Show projects table for reference (with default branch)
        $io->table(
            ['#', 'Project', 'Default branch'],
            array_map(fn($i, $p) => [
                $i + 1,
                $p['path_with_namespace'],
                $p['default_branch'] ?? '?',
            ], array_keys($allProjects), $allProjects)
        );

        // Project selection: type numbers or "all"
        $selection = $io->ask(
            'Select projects by number (e.g. <comment>1,3,5</comment> or <comment>all</comment>)',
            null,
            function ($v) use ($allProjects) {
                if (strtolower(trim($v)) === 'all') return $v;
                foreach (array_map('intval', explode(',', $v)) as $idx) {
                    if ($idx < 1 || $idx > count($allProjects)) {
                        throw new \RuntimeException("Invalid index: $idx");
                    }
                }
                return $v;
            }
        );

        if (strtolower(trim($selection)) === 'all') {
            $selectedProjects = $allProjects;
        } else {
            $selectedProjects = array_map(fn($idx) => $allProjects[(int)$idx - 1], explode(',', $selection));
        }

        $io->success(sprintf('%d project(s) selected: %s', count($selectedProjects), implode(', ', array_column($selectedProjects, 'path'))));

        // ── Step 2: Fetch packages with current versions ──────────────────────
        $io->section('② Packages');
        $io->text(sprintf('Fetching <info>composer.json</info> from <info>%d</info> project(s) on branch <comment>%s</comment>...', count($selectedProjects), $baseBranch));
        $io->newLine();

        // progressIterate() handles the progress bar automatically
        $allPackages = [];
        foreach ($io->progressIterate($selectedProjects) as $project) {
            try {
                $file     = $client->repositoryFiles()->getFile($project['id'], 'composer.json', $baseBranch);
                $composer = json_decode(base64_decode($file['content']), true);
                foreach (['require', 'require-dev'] as $section) {
                    foreach ($composer[$section] ?? [] as $pkg => $ver) {
                        if ($pkg === 'php' || str_starts_with($pkg, 'ext-')) continue;
                        if (!isset($allPackages[$pkg])) {
                            $allPackages[$pkg] = ['current' => $ver, 'count' => 0];
                        }
                        $allPackages[$pkg]['count']++;
                    }
                }
            } catch (\Exception $e) {
                // no composer.json on this branch — silently skip
            }
        }

        if (empty($allPackages)) {
            $io->error('No packages found in the selected projects on branch ' . $baseBranch);
            return [null, null, false, false];
        }

        ksort($allPackages);
        $packageList = array_keys($allPackages);

        // Show package table with extra context (current version + how many projects use it)
        $io->table(
            ['#', 'Package', 'Version (first seen)', '# projects'],
            array_map(fn($i, $pkg) => [
                $i + 1,
                $pkg,
                $allPackages[$pkg]['current'],
                $allPackages[$pkg]['count'],
            ], array_keys($packageList), $packageList)
        );

        // Package selection: autocomplete with Tab, one at a time, empty = done
        $selectedPackageNames = $this->autocompleteMultiSelect(
            $input,
            $output,
            $io,
            $packageList,
        );

        if (empty($selectedPackageNames)) {
            $io->warning('No packages selected. Aborted.');
            return [null, null, false, false];
        }

        // ── Step 3: Version per selected package ──────────────────────────────
        $io->section('③ Versions');

        $packages = [];
        foreach ($selectedPackageNames as $pkg) {
            $current = $allPackages[$pkg]['current'] ?? 'not present';
            // Plain-text prompt — ANSI markup in readline prompts corrupts input
            $io->write(sprintf('  %s (currently %s): ', $pkg, $current));
            $q = new Question('');
            $q->setValidator(fn($v) => (trim($v) !== '') ? trim($v) : throw new \RuntimeException('Version cannot be empty.'));
            $version = $this->getHelper('question')->ask($input, $output, $q);
            $packages[$pkg] = $version;
        }

        // ── Step 4: Options ───────────────────────────────────────────────────
        $io->section('④ Options');

        $mode = $io->choice(
            'Update mode',
            [
                'update-only — skip projects where the package is absent',
                'upsert — also add the package if missing',
            ],
            'update-only — skip projects where the package is absent',
        );
        $addMissing  = str_starts_with($mode, 'upsert');
        $withAllDeps = $io->confirm('Use --with-all-dependencies? (recommended for major migrations)', false);

        // ── Summary + confirm ─────────────────────────────────────────────────
        $io->section('✅  Summary');

        $summaryRows = [
            ['Branch'                  => $baseBranch],
            ['Projects'                => implode(', ', array_column($selectedProjects, 'path'))],
            ['Mode'                    => $addMissing ? 'upsert (add if missing)' : 'update-only'],
            ['--with-all-dependencies' => $withAllDeps ? '✓ yes' : '✗ no'],
        ];
        $io->definitionList(...$summaryRows);

        $io->table(
            ['Package', 'Current', '→', 'Target'],
            array_map(fn($pkg, $ver) => [
                $pkg,
                $allPackages[$pkg]['current'],
                '→',
                "<info>$ver</info>",
            ], array_keys($packages), $packages)
        );

        if (!$io->confirm('🚀  Proceed and create MRs?', false)) {
            $io->warning('Aborted by user.');
            return [null, null, false, false];
        }

        return [$packages, array_column($selectedProjects, 'id'), $addMissing, $withAllDeps];
    }

    /**
     * Multi-select with Tab autocomplete.
     * User types one item at a time (Tab to complete), empty input finishes.
     *
     * @param string[] $choices
     * @return string[]
     */
    private function autocompleteMultiSelect(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        array $choices,
    ): array {
        $helper   = $this->getHelper('question');
        $selected = [];

        $io->text([
            'Type a package name and press <comment>Tab</comment> to autocomplete.',
            'You can also type any <comment>vendor/package</comment> name not yet in your projects.',
            'Press <comment>Enter</comment> with an empty line to finish.',
        ]);
        $io->newLine();

        while (true) {
            $remaining = array_values(array_diff($choices, $selected));

            $prompt = count($selected) === 0
                ? 'Package: '
                : sprintf('Package (%d selected, empty to finish): ', count($selected));

            $q = new Question($prompt);
            $q->setAutocompleterValues($remaining);
            $q->setValidator(function (?string $v) use ($selected) {
                $v = trim($v ?? '');
                if ($v === '') return null; // done
                if (!preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#i', $v)) {
                    throw new \RuntimeException("Invalid package name: $v (expected vendor/package)");
                }
                if (in_array($v, $selected, true)) {
                    throw new \RuntimeException("Already selected: $v");
                }
                return $v;
            });

            $pkg = $helper->ask($input, $output, $q);

            if ($pkg === null) break;

            $selected[] = $pkg;
            $output->writeln(sprintf('  [OK] %s', $pkg));
        }

        return $selected;
    }
}
