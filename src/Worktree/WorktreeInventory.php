<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

use Ngramx\Config\LockFile;
use Ngramx\Config\LockFileData;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Docker\DockerCompose;
use Ngramx\Git\GitRepositoryService;
use Ngramx\Http\EnvironmentUrl;

/**
 * Builds the repository-wide picture of every environment Ngramx knows about:
 * the main checkout plus each worktree under `.ngramx/worktrees/`.
 *
 * Shared by `ngramx status` and `ngramx worktree --list` so both answer the
 * same question the same way.
 */
class WorktreeInventory
{
    public const WORKTREE_DIR = '.ngramx/worktrees';

    private const WORKTREE_PATH_MARKER = '/.ngramx/worktrees/';

    private readonly DockerCompose $dockerCompose;
    private readonly GitRepositoryService $gitRepositoryService;
    private readonly EnvironmentUrl $environmentUrl;
    private readonly WorktreeUrlResolver $worktreeUrlResolver;
    private readonly AgentRunReader $agentRunReader;

    public function __construct(
        ?DockerCompose $dockerCompose = null,
        ?GitRepositoryService $gitRepositoryService = null,
        ?EnvironmentUrl $environmentUrl = null,
        ?WorktreeUrlResolver $worktreeUrlResolver = null,
        ?AgentRunReader $agentRunReader = null,
    ) {
        $this->dockerCompose = $dockerCompose ?? new DockerCompose();
        $this->gitRepositoryService = $gitRepositoryService ?? new GitRepositoryService();
        $this->environmentUrl = $environmentUrl ?? new EnvironmentUrl();
        $this->agentRunReader = $agentRunReader ?? new AgentRunReader();
        // One baseline attempt: an environment we have already confirmed is
        // running either answers immediately or is not host-agnostic.
        $this->worktreeUrlResolver = $worktreeUrlResolver ?? new WorktreeUrlResolver(baselineAttempts: 1);
    }

    /**
     * Given any directory inside a project, return the main checkout's path.
     *
     * A worktree lives at `<repo>/.ngramx/worktrees/<folder>`, so `status` run
     * from inside one (or from a subfolder of one) still reports on the whole
     * repository rather than on that single environment.
     */
    public static function repositoryRootFor(string $projectPath): string
    {
        $normalized = str_replace('\\', '/', rtrim($projectPath, '/\\'));

        $marker = strpos($normalized, self::WORKTREE_PATH_MARKER);
        if ($marker === false) {
            return $projectPath;
        }

        return substr($normalized, 0, $marker);
    }

    /**
     * The worktree folder name when $projectPath is (or sits inside) a worktree,
     * or null for the main checkout.
     */
    public static function worktreeFolderFor(string $projectPath): ?string
    {
        $normalized = str_replace('\\', '/', rtrim($projectPath, '/\\'));

        $marker = strpos($normalized, self::WORKTREE_PATH_MARKER);
        if ($marker === false) {
            return null;
        }

        $rest = substr($normalized, $marker + strlen(self::WORKTREE_PATH_MARKER));
        $folder = explode('/', $rest)[0];

        return $folder !== '' ? $folder : null;
    }

    /**
     * Snapshot the main checkout and every worktree.
     *
     * @param string $repositoryPath The main checkout (see repositoryRootFor()).
     * @param ?string $currentPath Where the command was run from, used to mark
     *        the environment the developer is standing in.
     * @return array{root: EnvironmentSnapshot, worktrees: list<EnvironmentSnapshot>}
     */
    public function collect(string $repositoryPath, NgramxConfig $config, ?string $currentPath = null): array
    {
        $currentWorktree = $currentPath === null ? null : self::worktreeFolderFor($currentPath);

        return [
            'root' => $this->snapshotRoot($repositoryPath, $config, $currentWorktree === null),
            'worktrees' => $this->snapshotWorktrees($repositoryPath, $config, $currentWorktree),
        ];
    }

    /**
     * List every worktree directory under `.ngramx/worktrees/`, sorted so the
     * numbering shown to the user is stable across runs.
     *
     * @return list<string> Absolute paths
     */
    public function listWorktreeDirectories(string $repositoryPath): array
    {
        $worktreesDir = $repositoryPath . '/' . self::WORKTREE_DIR;

        if (!is_dir($worktreesDir)) {
            return [];
        }

        $entries = scandir($worktreesDir);
        if ($entries === false) {
            return [];
        }

        $paths = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $worktreesDir . '/' . $entry;
            if (is_dir($path)) {
                $paths[] = $path;
            }
        }

        sort($paths);

        return $paths;
    }

    private function snapshotRoot(string $repositoryPath, NgramxConfig $config, bool $isCurrent): EnvironmentSnapshot
    {
        $lock = new LockFile($repositoryPath);
        $lockData = $lock->exists() ? $lock->read() : null;

        // The main checkout usually runs under Compose's own default project
        // name, so a null namespace here is normal rather than "unknown".
        $namespace = $lockData?->namespace;
        $composeFile = $this->composeFileFor($repositoryPath, $config);

        $running = $this->dockerCompose->isServiceRunning(
            $composeFile,
            $config->docker->primaryService,
            $namespace,
        );

        return new EnvironmentSnapshot(
            name: basename($repositoryPath),
            path: $repositoryPath,
            branch: $this->gitRepositoryService->getCurrentBranch($repositoryPath),
            running: $running,
            url: $running
                ? $this->environmentUrl->resolve(
                    $config->docker->appUrl,
                    $composeFile,
                    $config->docker->primaryService,
                    $lockData,
                )
                : null,
            namespace: $namespace,
            isCurrent: $isCurrent,
            portOffset: $lockData?->portOffset,
            agent: $this->agentRunReader->read($repositoryPath),
        );
    }

    /**
     * @return list<EnvironmentSnapshot>
     */
    private function snapshotWorktrees(
        string $repositoryPath,
        NgramxConfig $config,
        ?string $currentWorktree
    ): array {
        $worktrees = $this->listWorktreeDirectories($repositoryPath);
        if ($worktrees === []) {
            return [];
        }

        $branchMap = $this->gitRepositoryService->listWorktreeBranches($repositoryPath);

        $snapshots = [];
        foreach ($worktrees as $worktreePath) {
            $folder = basename($worktreePath);
            $namespace = WorktreeIdentity::namespaceFor($folder);
            $composeFile = $this->composeFileFor($worktreePath, $config);

            // Probe the containers rather than trusting the lock file: a lock
            // left behind by a reboot or a manual `docker compose down` would
            // otherwise report a long-dead environment as running.
            $running = file_exists($worktreePath . '/.ngramx.lock')
                && $this->dockerCompose->isServiceRunning(
                    $composeFile,
                    $config->docker->primaryService,
                    $namespace,
                );

            $lock = new LockFile($worktreePath);
            $lockData = $lock->exists() ? $lock->read() : null;

            $snapshots[] = new EnvironmentSnapshot(
                name: $folder,
                path: $worktreePath,
                branch: $branchMap[$worktreePath] ?? null,
                running: $running,
                url: $running ? $this->worktreeUrl($config, $composeFile, $folder, $lockData) : null,
                namespace: $namespace,
                isCurrent: $currentWorktree !== null && $currentWorktree === $folder,
                portOffset: $lockData?->portOffset,
                // Read regardless of whether the stack is up: a finished run in
                // a stopped environment is exactly what someone scanning the
                // overview for "what happened here?" is looking for.
                agent: $this->agentRunReader->read($worktreePath),
            );
        }

        return $snapshots;
    }

    /**
     * The URL a running worktree is reachable on.
     *
     * `review` records the decision in the lock file, hostname included. For
     * environments started before that field existed, re-run the same
     * subdomain-vs-canonical-host probe rather than advertising the shared
     * canonical host, which points at the main checkout's stack.
     */
    private function worktreeUrl(
        NgramxConfig $config,
        string $composeFile,
        string $folder,
        ?LockFileData $lockData
    ): string {
        $url = $this->environmentUrl->resolve(
            $config->docker->appUrl,
            $composeFile,
            $config->docker->primaryService,
            $lockData,
        );

        if ($lockData !== null && $lockData->url !== null && $lockData->url !== '') {
            return $url;
        }

        return $this->worktreeUrlResolver->resolve($url, $folder, 0);
    }

    /**
     * Resolve the compose file for a checkout. `docker.compose_file` is
     * normally relative, and a relative path would otherwise resolve against
     * the caller's cwd — reading the main checkout's compose file while
     * reporting on a worktree.
     */
    private function composeFileFor(string $checkoutPath, NgramxConfig $config): string
    {
        $composeFile = $config->docker->composeFile;

        if (str_starts_with($composeFile, '/')) {
            return $composeFile;
        }

        $resolved = $checkoutPath . '/' . $composeFile;

        return file_exists($resolved) ? $resolved : $composeFile;
    }
}
