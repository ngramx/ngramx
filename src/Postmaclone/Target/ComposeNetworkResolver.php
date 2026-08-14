<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Target;

use Ngramx\Docker\ComposeFiles;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Finds the Docker network used by a project's compose stack so an ephemeral
 * Postmaclone DB can join it (no compose "external: true" network required).
 */
class ComposeNetworkResolver
{
    public function resolve(?string $composeFile, ?string $primaryService, ?string $projectName = null): ?string
    {
        if ($composeFile === null || $composeFile === '' || !is_file($composeFile)) {
            return null;
        }

        $fromRunning = $this->fromRunningService($composeFile, $primaryService, $projectName);
        if ($fromRunning !== null) {
            return $fromRunning;
        }

        return $this->fromComposeFileAndDocker($composeFile, $projectName);
    }

    /**
     * `docker compose ps` argv for the running primary service, including `-p`
     * when the stack was started with a namespace.
     *
     * @return list<string>
     */
    public function runningServicePsCommand(string $composeFile, string $primaryService, ?string $projectName): array
    {
        $command = array_merge(['docker', 'compose'], ComposeFiles::fileArgs($composeFile));
        if ($projectName !== null && $projectName !== '') {
            $command[] = '-p';
            $command[] = $projectName;
        }

        return array_merge($command, ['ps', '-q', $primaryService]);
    }

    /**
     * Pick a docker network for a compose stack. Namespaced projects must match
     * `{project}_{logical}` before a suffix search, or a default-mode stack on
     * the same machine can steal the alias.
     *
     * @param list<string> $declared
     * @param list<string> $existing
     */
    public function matchExistingNetwork(array $declared, array $existing, ?string $projectName = null): ?string
    {
        if ($projectName !== null && $projectName !== '') {
            foreach ($declared as $logical) {
                $preferred = $projectName . '_' . $logical;
                foreach ($existing as $network) {
                    if ($network === $preferred) {
                        return $network;
                    }
                }
            }
        }

        foreach ($declared as $logical) {
            foreach ($existing as $network) {
                if ($network === $logical || str_ends_with($network, '_' . $logical)) {
                    return $network;
                }
            }
        }

        return null;
    }

    private function fromRunningService(string $composeFile, ?string $primaryService, ?string $projectName): ?string
    {
        if ($primaryService === null || $primaryService === '') {
            return null;
        }

        $ps = new Process($this->runningServicePsCommand($composeFile, $primaryService, $projectName));
        $ps->setTimeout(30);
        $ps->run();
        $id = trim($ps->getOutput());
        if ($id === '' || !$ps->isSuccessful()) {
            return null;
        }

        $inspect = new Process([
            'docker', 'inspect',
            '-f', '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}',
            $id,
        ]);
        $inspect->run();
        if (!$inspect->isSuccessful()) {
            return null;
        }

        foreach (preg_split('/\s+/', trim($inspect->getOutput())) ?: [] as $network) {
            if ($network !== '' && !in_array($network, ['bridge', 'host', 'none'], true)) {
                return $network;
            }
        }

        return null;
    }

    private function fromComposeFileAndDocker(string $composeFile, ?string $projectName): ?string
    {
        try {
            $parsed = Yaml::parseFile($composeFile);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($parsed)) {
            return null;
        }

        $declared = [];
        if (isset($parsed['networks']) && is_array($parsed['networks'])) {
            foreach (array_keys($parsed['networks']) as $name) {
                if (is_string($name) && $name !== '') {
                    $declared[] = $name;
                }
            }
        }
        if ($declared === []) {
            $declared[] = 'default';
        }

        $list = new Process(['docker', 'network', 'ls', '--format', '{{.Name}}']);
        $list->run();
        if (!$list->isSuccessful()) {
            return null;
        }
        $existing = array_values(array_filter(preg_split('/\R/', trim($list->getOutput())) ?: []));

        return $this->matchExistingNetwork($declared, $existing, $projectName);
    }
}
