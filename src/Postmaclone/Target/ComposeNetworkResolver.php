<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Target;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Finds the Docker network used by a project's compose stack so an ephemeral
 * Postmaclone DB can join it (no compose "external: true" network required).
 */
final class ComposeNetworkResolver
{
    public function resolve(?string $composeFile, ?string $primaryService): ?string
    {
        if ($composeFile === null || $composeFile === '' || !is_file($composeFile)) {
            return null;
        }

        $fromRunning = $this->fromRunningService($composeFile, $primaryService);
        if ($fromRunning !== null) {
            return $fromRunning;
        }

        return $this->fromComposeFileAndDocker($composeFile);
    }

    private function fromRunningService(string $composeFile, ?string $primaryService): ?string
    {
        if ($primaryService === null || $primaryService === '') {
            return null;
        }

        $ps = new Process(['docker', 'compose', '-f', $composeFile, 'ps', '-q', $primaryService]);
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

    private function fromComposeFileAndDocker(string $composeFile): ?string
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
        $existing = array_filter(preg_split('/\R/', trim($list->getOutput())) ?: []);

        // Prefer exact / suffix matches: project_earl_kendrick_network, earl_kendrick_network
        foreach ($declared as $logical) {
            foreach ($existing as $network) {
                if ($network === $logical || str_ends_with($network, '_' . $logical)) {
                    return $network;
                }
            }
        }

        return null;
    }
}
