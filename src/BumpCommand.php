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
        $this->setDescription('Update a Composer dependency across all projects in a GitLab group and open a MR for each');
        $this->addArgument('package', InputArgument::REQUIRED, 'Composer package name (e.g. vendor/package)');
        $this->addArgument('version', InputArgument::REQUIRED, 'New version constraint (e.g. ^2.0 or 7.4.*)');
        $this->addOption('token', 't', InputOption::VALUE_OPTIONAL, 'GitLab private token (or GITLAB_TOKEN env var)');
        $this->addOption('group', 'g', InputOption::VALUE_OPTIONAL, 'GitLab group path or ID (or GITLAB_GROUP env var)');
        $this->addOption('gitlab-url', null, InputOption::VALUE_OPTIONAL, 'GitLab instance URL (or GITLAB_URL env var)');
        $this->addOption('base-branch', null, InputOption::VALUE_OPTIONAL, 'Base branch to update and target for the MR (or GITLAB_BASE_BRANCH env var, default: master)');
        $this->addOption('project', null, InputOption::VALUE_OPTIONAL, 'Restrict to a single project name or path (useful for testing)');
        $this->addOption('php-version', null, InputOption::VALUE_OPTIONAL, 'PHP version to use for dependency resolution — should match your CI (or COMPOSER_PHP_VERSION env var)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token      = $input->getOption('token')       ?: ($_ENV['GITLAB_TOKEN']          ?? null);
        $group      = $input->getOption('group')       ?: ($_ENV['GITLAB_GROUP']          ?? null);
        $package    = $input->getArgument('package');
        $version    = $input->getArgument('version');
        $gitlabUrl  = $input->getOption('gitlab-url')  ?: ($_ENV['GITLAB_URL']            ?? null);
        $baseBranch = $input->getOption('base-branch') ?: ($_ENV['GITLAB_BASE_BRANCH']    ?? 'master');
        $filterProject = $input->getOption('project');
        $phpVersion    = $input->getOption('php-version') ?: ($_ENV['COMPOSER_PHP_VERSION'] ?? null);

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

        $client = new Client();
        $client->setUrl($gitlabUrl);
        $client->authenticate($token, Client::AUTH_HTTP_TOKEN);

        $output->writeln("Scanning group <info>$group</info> on <info>$gitlabUrl</info>");
        $output->writeln("Package: <info>$package</info>  →  <info>$version</info>  (branch: <info>$baseBranch</info>)");
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
                $inRequire    = isset($composer['require'][$package]);
                $inRequireDev = isset($composer['require-dev'][$package]);

                if (!$inRequire && !$inRequireDev) {
                    $output->writeln('package not found, skipping.');
                    continue;
                }

                $output->writeln('updating ' . $package . ' to ' . $version . ' ...');

                if ($inRequire) {
                    $composer['require'][$package] = $version;
                }
                if ($inRequireDev) {
                    $composer['require-dev'][$package] = $version;
                }

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
                $tmpDir  = '/tmp/bump-' . $projectPath;
                $gitHost = parse_url($gitlabUrl, PHP_URL_HOST);
                $composerAuth = json_encode(['gitlab-token' => [$gitHost => $token]]);

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

                file_put_contents(
                    $tmpDir . '/composer.json',
                    json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
                );

                // Pin PHP platform version if specified (avoids installing packages incompatible with CI PHP)
                if ($phpVersion) {
                    exec('cd ' . escapeshellarg($tmpDir) . ' && composer config platform.php ' . escapeshellarg($phpVersion) . ' 2>&1');
                }

                $composerOut = [];
                exec(
                    'cd ' . escapeshellarg($tmpDir) . ' && COMPOSER_AUTH=' . escapeshellarg($composerAuth) . ' composer update --no-interaction --no-scripts 2>&1',
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
                            'cd ' . escapeshellarg($tmpDir) . ' && COMPOSER_AUTH=' . escapeshellarg($composerAuth) . ' composer update --no-interaction --no-scripts ' . $ignoreFlags . ' 2>&1',
                            $composerOut,
                            $composerRet
                        );
                    }
                }

                if ($composerRet !== 0) {
                    $output->writeln('  <error>composer update failed:</error>');
                    $output->writeln(implode("\n", $composerOut));
                    exec('rm -rf ' . escapeshellarg($tmpDir));
                    continue;
                }

                $newComposerJson = file_get_contents($tmpDir . '/composer.json');
                $newComposerLock = file_exists($tmpDir . '/composer.lock')
                    ? file_get_contents($tmpDir . '/composer.lock')
                    : null;

                exec('rm -rf ' . escapeshellarg($tmpDir));

                $lockChanged = $newComposerLock !== null && $newComposerLock !== $composerLockContent;

                // Create branch (recreate if already exists)
                $branch = 'bump-' . str_replace('/', '-', $package) . '-' . str_replace('*', 'last', $version);
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
                        'commit_message' => "bump: update $package to $version (composer.json)",
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
                            'commit_message' => "bump: update $package to $version (composer.lock)",
                            'encoding'       => 'text',
                        ]);
                    } catch (\Exception $e) {
                        $output->writeln('  <error>Cannot commit composer.lock: ' . $e->getMessage() . '</error>');
                    }
                }

                // Create MR
                try {
                    $client->mergeRequests()->create($projectId, $branch, $baseBranch, "Bump: $package to $version", [
                        'description'          => "## Description\n\nUpdate `$package` to `$version`.",
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
}
