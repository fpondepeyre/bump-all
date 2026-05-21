<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitlab\Client;

class BumpCommand extends Command
{
    protected function configure()
    {
        $this->setName('composer:update');
        $this->setDescription('Composer update new dependency and create GitLab MR');
        $this->addArgument('package', InputArgument::REQUIRED, 'Composer package name');
        $this->addArgument('version', InputArgument::REQUIRED, 'New version');
        $this->addOption('token', 't', InputOption::VALUE_OPTIONAL, 'GitLab private token (or GITLAB_TOKEN env var)');
        $this->addOption('group', 'g', InputOption::VALUE_OPTIONAL, 'GitLab group name or ID (or GITLAB_GROUP env var)');
        $this->addOption('gitlab-url', null, InputOption::VALUE_OPTIONAL, 'GitLab instance URL (or GITLAB_URL env var)');
        $this->addOption('base-branch', null, InputOption::VALUE_OPTIONAL, 'Base branch name (or GITLAB_BASE_BRANCH env var)');
        $this->addOption('project', null, InputOption::VALUE_OPTIONAL, 'Filter on a single project name (useful for testing)');
        $this->addOption('php-version', null, InputOption::VALUE_OPTIONAL, 'PHP version for composer resolution (or COMPOSER_PHP_VERSION env var)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token      = $input->getOption('token')       ?: ($_ENV['GITLAB_TOKEN']        ?? null);
        $group      = $input->getOption('group')       ?: ($_ENV['GITLAB_GROUP']        ?? null);
        $package    = $input->getArgument('package');
        $version    = $input->getArgument('version');
        $gitlabUrl  = $input->getOption('gitlab-url')  ?: ($_ENV['GITLAB_URL']          ?? 'https://your-gitlab.com');
        $baseBranch = $input->getOption('base-branch') ?: ($_ENV['GITLAB_BASE_BRANCH']  ?? 'master');
        $filterProject = $input->getOption('project');
        $phpVersion    = $input->getOption('php-version') ?: ($_ENV['COMPOSER_PHP_VERSION'] ?? null);

        if (!$token) {
            throw new \RuntimeException('GitLab token is required. Pass it as argument or set GITLAB_TOKEN in .env');
        }
        if (!$group) {
            throw new \RuntimeException('GitLab group is required. Pass it as argument or set GITLAB_GROUP in .env');
        }

        $client = new Client();
        $client->setUrl($gitlabUrl);
        $client->authenticate($token, Client::AUTH_HTTP_TOKEN);

        $output->writeln("🔍 Connecting to $gitlabUrl");
        $output->writeln("🔍 Group: $group | Package: $package | Version: $version | Branch: $baseBranch");
        if ($filterProject) {
            $output->writeln("🔍 Filtering on project: $filterProject");
        }

        $page = 1;
        $totalProjects = 0;
        do {
            try {
                $projects = $client->groups()->projects($group, [
                    'per_page'          => 100,
                    'page'              => $page,
                    'include_subgroups' => true,
                ]);
            } catch (\Exception $e) {
                $output->writeln("❌ Cannot fetch group projects: " . $e->getMessage());
                return Command::FAILURE;
            }

            $output->writeln("📦 Page $page: " . count($projects) . " project(s) found.");
            foreach ($projects as $p) {
                $output->writeln("   - " . $p['path_with_namespace']);
            }
            $totalProjects += count($projects);

            foreach ($projects as $project) {
                $projectId   = $project['id'];
                $projectName = $project['name'];
                $projectPath = $project['path'];

                if ($filterProject !== null && $projectName !== $filterProject && $projectPath !== $filterProject) {
                    continue;
                }

                $output->writeln("  → [$projectName] Checking composer.json on branch '$baseBranch'...");
                try {
                    $composerFile = $client->repositoryFiles()->getFile($projectId, 'composer.json', $baseBranch);
                } catch (\Exception $e) {
                    $output->writeln("  → [$projectName] No composer.json on branch '$baseBranch', skipping. ({$e->getMessage()})");
                    continue;
                }

                $output->writeln("  → [$projectName] composer.json found, checking for '$package'...");

                $composer = json_decode(base64_decode($composerFile['content']), true);

                $inRequire    = isset($composer['require'][$package]);
                $inRequireDev = isset($composer['require-dev'][$package]);

                if (!$inRequire && !$inRequireDev) {
                    $output->writeln("  → [$projectName] Package '$package' not found in require/require-dev, skipping.");
                    continue;
                }

                $output->writeln("[$projectName] Found package $package, updating to $version...");

                if ($inRequire) {
                    $composer['require'][$package] = $version;
                }
                if ($inRequireDev) {
                    $composer['require-dev'][$package] = $version;
                }

                // Fetch composer.lock if exists
                $composerLockSha     = null;
                $composerLockContent = null;
                try {
                    $lockFile            = $client->repositoryFiles()->getFile($projectId, 'composer.lock', $baseBranch);
                    $composerLockSha     = $lockFile['blob_id'];
                    $composerLockContent = base64_decode($lockFile['content']);
                } catch (\Exception $e) {
                    // No composer.lock, that's fine
                }

                // Get base branch SHA
                try {
                    $branchInfo = $client->repositories()->branch($projectId, $baseBranch);
                    $baseSha    = $branchInfo['commit']['id'];
                } catch (\Exception $e) {
                    $output->writeln("[$projectName] Error getting branch: " . $e->getMessage());
                    continue;
                }

                // Clone and run composer update
                $tmpDir  = '/tmp/bump-' . $projectPath;
                $gitHost = parse_url($gitlabUrl, PHP_URL_HOST);
                $composerAuth = json_encode([
                    'gitlab-token' => [$gitHost => $token],
                ]);
                exec('rm -rf ' . escapeshellarg($tmpDir));

                $cloneUrl = preg_replace(
                    '#^(https?://)#',
                    '$1oauth2:' . $token . '@',
                    $project['http_url_to_repo']
                );
                exec('git clone -b ' . escapeshellarg($baseBranch) . ' ' . escapeshellarg($cloneUrl) . ' ' . escapeshellarg($tmpDir), $cloneOut, $cloneRet);
                if ($cloneRet !== 0) {
                    $output->writeln("  → [$projectName] Clone failed:\n" . implode("\n", $cloneOut));
                    continue;
                }

                file_put_contents(
                    $tmpDir . '/composer.json',
                    json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
                );

                $output->writeln("  → [$projectName] Running composer update...");

                // Set target PHP version for dependency resolution if specified
                if ($phpVersion) {
                    exec('cd ' . escapeshellarg($tmpDir) . ' && composer config platform.php ' . escapeshellarg($phpVersion) . ' 2>&1');
                    $output->writeln("  → [$projectName] Targeting PHP $phpVersion for resolution.");
                }

                $composerOut = [];
                exec('cd ' . escapeshellarg($tmpDir) . ' && COMPOSER_AUTH=' . escapeshellarg($composerAuth) . ' composer update --no-interaction --no-scripts 2>&1', $composerOut, $composerRet);

                // If it failed because of missing extensions (ext-*), retry ignoring only those
                if ($composerRet !== 0) {
                    $missingExts = [];
                    foreach ($composerOut as $line) {
                        // Matches both "requires ext-foo" and "require ext-foo"
                        if (preg_match('/requires? (ext-[\w]+)/', $line, $m)) {
                            $missingExts[$m[1]] = true;
                        }
                    }

                    if (!empty($missingExts)) {
                        $ignoreFlags = implode(' ', array_map(
                            fn($ext) => '--ignore-platform-req=' . escapeshellarg($ext),
                            array_keys($missingExts)
                        ));
                        $output->writeln("  → [$projectName] Retrying ignoring missing extensions: " . implode(', ', array_keys($missingExts)));
                        $composerOut = [];
                        exec('cd ' . escapeshellarg($tmpDir) . ' && COMPOSER_AUTH=' . escapeshellarg($composerAuth) . ' composer update --no-interaction --no-scripts ' . $ignoreFlags . ' 2>&1', $composerOut, $composerRet);
                    }
                }
                if ($composerRet !== 0) {
                    $output->writeln("[$projectName] composer update failed:\n" . implode("\n", $composerOut));
                    exec('rm -rf ' . escapeshellarg($tmpDir));
                    continue;
                }

                $newComposerJson = file_get_contents($tmpDir . '/composer.json');
                $newComposerLock = file_exists($tmpDir . '/composer.lock')
                    ? file_get_contents($tmpDir . '/composer.lock')
                    : null;

                exec('rm -rf ' . escapeshellarg($tmpDir));

                $lockChanged = $newComposerLock !== null && $newComposerLock !== $composerLockContent;

                // Create branch (delete first if it already exists)
                $branch = 'bump-' . str_replace('/', '-', $package) . '-' . str_replace('*', 'last', $version);
                try {
                    $client->repositories()->deleteBranch($projectId, $branch);
                    $output->writeln("  → [$projectName] Existing branch '$branch' deleted, recreating...");
                } catch (\Exception $e) {
                    // Branch didn't exist, that's fine
                }
                try {
                    $client->repositories()->createBranch($projectId, $branch, $baseSha);
                } catch (\Exception $e) {
                    $output->writeln("[$projectName] Error creating branch: " . $e->getMessage());
                    continue;
                }

                // Commit composer.json
                try {
                    $client->repositoryFiles()->updateFile($projectId, [
                        'file_path'      => 'composer.json',
                        'branch'         => $branch,
                        'content'        => $newComposerJson,
                        'commit_message' => "bump: update $package to $version (composer.json)",
                        'encoding'       => 'text',
                    ]);
                } catch (\Exception $e) {
                    $output->writeln("[$projectName] Error committing composer.json: " . $e->getMessage());
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
                            'commit_message' => "bump: update $package to $version (composer.lock)",
                            'encoding'       => 'text',
                        ]);
                    } catch (\Exception $e) {
                        $output->writeln("[$projectName] Error committing composer.lock: " . $e->getMessage());
                    }
                }

                // Create MR (ignore if already exists)
                try {
                    $client->mergeRequests()->create($projectId, $branch, $baseBranch, "Bump: $package to $version", [
                        'description'          => "# Description\nMise à jour de `$package` en `$version`",
                        'remove_source_branch' => true,
                    ]);
                    $output->writeln("[$projectName] MR created ✓");
                } catch (\Exception $e) {
                    $msg = $e->getMessage();
                    if (str_contains($msg, 'already exists') || str_contains($msg, 'duplicate')) {
                        $output->writeln("[$projectName] MR already exists, skipping.");
                    } else {
                        $output->writeln("[$projectName] Error creating MR: $msg");
                    }
                }
            }

            $page++;
        } while (count($projects) === 100);

        $output->writeln("✅ Done. $totalProjects project(s) scanned.");

        return Command::SUCCESS;
    }
}
