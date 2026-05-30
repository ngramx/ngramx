<?php

declare(strict_types=1);

namespace Ngramx\Command\N8n;

use GuzzleHttp\Exception\GuzzleException;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class NormaliseCommand extends AbstractCommand
{
    /**
     * @var array<string, array{type: string, name: string, nodes: array<string>}>
     */
    private array $requiredCredentials = [];

    /**
     * @var array<string, array<int, array{id: string, type: string, name: string}>>
     */
    private array $targetCredentials = [];

    /**
     * @var array<string, string>
     */
    private array $credentialMap = [];

    protected function configure(): void
    {
        $this
            ->setName('n8n:normalise')
            ->setDescription('Normalise workflow credentials by mapping them to target n8n instance')
            ->addArgument('workflow', InputArgument::REQUIRED, 'Path to workflow JSON file')
            ->addOption('no-strict', null, InputOption::VALUE_NONE, 'Do not exit on missing/duplicate credentials (strict mode is on by default)')
            ->addOption('map', 'm', InputOption::VALUE_REQUIRED, 'Path to credential mapping JSON file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not write output; just report what would change')
            ->addOption('report', 'r', InputOption::VALUE_REQUIRED, 'Report format: json or text (default: text)')
            ->addOption('no-patch', null, InputOption::VALUE_NONE, 'Only validate (preflight-only mode)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (default: stdout)');
    }

    /**
     * Extract credentials from workflow JSON
     * @param array<string, mixed> $workflowData
     * @return array<string, array{type: string, name: string, nodes: array<string>}>
     */
    private function extractCredentials(array $workflowData): array
    {
        $credentials = [];

        if (!isset($workflowData['nodes']) || !is_array($workflowData['nodes'])) {
            return $credentials;
        }

        foreach ($workflowData['nodes'] as $node) {
            if (!is_array($node)) {
                continue;
            }

            $nodeName = $node['name'] ?? 'unnamed';

            if (!isset($node['credentials']) || !is_array($node['credentials'])) {
                continue;
            }

            foreach ($node['credentials'] as $credentialType => $credentialRef) {
                if (!is_array($credentialRef)) {
                    continue;
                }

                $credentialName = $credentialRef['name'] ?? null;

                if ($credentialName === null) {
                    continue;
                }

                $key = $credentialType . ':' . $credentialName;

                if (!isset($credentials[$key])) {
                    $credentials[$key] = [
                        'type' => $credentialType,
                        'name' => $credentialName,
                        'nodes' => [],
                    ];
                }

                if (!in_array($nodeName, $credentials[$key]['nodes'], true)) {
                    $credentials[$key]['nodes'][] = $nodeName;
                }
            }
        }

        return $credentials;
    }

    /**
     * Build credentials URI
     */
    private function buildCredentialsUri(string $baseUri): string
    {
        return rtrim($baseUri, '/') . '/api/v1/credentials';
    }

    /**
     * Fetch credentials from target n8n instance
     * @param array<string, string> $env
     * @return array<string, array<int, array{id: string, type: string, name: string}>>
     */
    private function fetchTargetCredentials(array $env): array
    {
        $baseUri = $this->buildBaseUri($env);
        $credentialsUri = $this->buildCredentialsUri($baseUri);
        $options = $this->buildApiOptions($env, false);

        try {
            $response = $this->httpClient->request('GET', $credentialsUri, $options);
            $data = json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);

            if (!isset($data['data']) || !is_array($data['data'])) {
                throw new \RuntimeException('Invalid credentials response: missing data array');
            }

            $credentials = [];
            foreach ($data['data'] as $credential) {
                if (!is_array($credential)) {
                    continue;
                }

                $id = $credential['id'] ?? null;
                $type = $credential['type'] ?? null;
                $name = $credential['name'] ?? null;

                if ($id === null || $type === null || $name === null) {
                    continue;
                }

                $key = $type . ':' . $name;

                if (!isset($credentials[$key])) {
                    $credentials[$key] = [];
                }

                $credentials[$key][] = [
                    'id' => (string) $id,
                    'type' => (string) $type,
                    'name' => (string) $name,
                ];
            }

            return $credentials;
        } catch (GuzzleException | \JsonException $e) {
            throw new \RuntimeException(
                sprintf('Failed to fetch credentials from target n8n: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Load credential mapping from file
     * @return array<string, string>
     */
    private function loadCredentialMap(string $mapPath): array
    {
        if (!file_exists($mapPath)) {
            throw new \RuntimeException("Credential map file not found: {$mapPath}");
        }

        $content = file_get_contents($mapPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read credential map file: {$mapPath}");
        }

        $map = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($map)) {
            throw new \RuntimeException('Invalid credential map: expected object, got ' . gettype($map));
        }

        // Reject JSON arrays (indexed arrays) - only accept objects (associative arrays)
        // Check if array keys are sequential integers starting from 0 (indexed array)
        if ($map !== [] && array_keys($map) === range(0, count($map) - 1)) {
            throw new \RuntimeException('Invalid credential map: expected object, got array');
        }

        $result = [];
        foreach ($map as $sourceKey => $targetKey) {
            if (!is_string($sourceKey) || !is_string($targetKey)) {
                continue;
            }
            $result[$sourceKey] = $targetKey;
        }

        return $result;
    }

    /**
     * Validate credentials and return validation result
     * @return array{missing: array<string>, duplicates: array<string, array<int, array{id: string, type: string, name: string}>>}
     */
    private function validateCredentials(): array
    {
        $missing = [];
        $duplicates = [];

        foreach ($this->requiredCredentials as $key => $credential) {
            $targetKey = $this->credentialMap[$key] ?? $key;

            if (!isset($this->targetCredentials[$targetKey])) {
                $missing[] = $key;
                continue;
            }

            $targetCreds = $this->targetCredentials[$targetKey];
            $targetCredsCount = count($targetCreds);
            if ($targetCredsCount > 1) {
                $duplicates[$key] = $targetCreds;
            }
        }

        return [
            'missing' => $missing,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Patch credential IDs into workflow JSON
     * @param array<string, mixed> $workflowData
     * @return array<string, mixed>
     */
    private function patchCredentialIds(array $workflowData): array
    {
        if (!isset($workflowData['nodes']) || !is_array($workflowData['nodes'])) {
            return $workflowData;
        }

        foreach ($workflowData['nodes'] as &$node) {
            if (!is_array($node) || !isset($node['credentials']) || !is_array($node['credentials'])) {
                continue;
            }

            foreach ($node['credentials'] as $credentialType => &$credentialRef) {
                if (!is_array($credentialRef)) {
                    continue;
                }

                $credentialName = $credentialRef['name'] ?? null;
                if ($credentialName === null) {
                    continue;
                }

                $sourceKey = $credentialType . ':' . $credentialName;
                $targetKey = $this->credentialMap[$sourceKey] ?? $sourceKey;

                if (!isset($this->targetCredentials[$targetKey])) {
                    continue;
                }

                $targetCreds = $this->targetCredentials[$targetKey];
                if (count($targetCreds) > 0) {
                    $targetCred = $targetCreds[0]; // Use first match (or handle duplicates separately)

                    // Update ID but keep name
                    $credentialRef['id'] = $targetCred['id'];
                }
            }
        }

        return $workflowData;
    }

    /**
     * Format workflow JSON for output
     * @param array<string, mixed> $workflowData
     */
    private function formatWorkflowJson(array $workflowData): string
    {
        return json_encode(
            $workflowData,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Generate text report
     * @param array{missing: array<string>, duplicates: array<string, array<int, array{id: string, type: string, name: string}>>} $validation
     * @param array<string, string> $env
     */
    private function generateTextReport(array $validation, OutputFormatter $formatter, array $env): void
    {
        $baseUri = $this->buildBaseUri($env);
        $formatter->info("normalise: target {$baseUri}");
        $formatter->info(sprintf('found %d credential refs in workflow', count($this->requiredCredentials)));

        foreach ($this->requiredCredentials as $key => $credential) {
            $targetKey = $this->credentialMap[$key] ?? $key;

            $targetCreds = $this->targetCredentials[$targetKey] ?? null;
            if ($targetCreds !== null && count($targetCreds) === 1) {
                $targetCred = $targetCreds[0];
                $nodeList = implode(', ', $credential['nodes']);
                $formatter->success(sprintf(
                    'OK   %s:%s (id %s) used by: %s',
                    $credential['type'],
                    $credential['name'],
                    $targetCred['id'],
                    $nodeList
                ));
            }
        }

        foreach ($validation['missing'] as $key) {
            $credential = $this->requiredCredentials[$key];
            $nodeList = implode(', ', $credential['nodes']);
            $formatter->error(sprintf(
                'MISS %s:%s used by: %s',
                $credential['type'],
                $credential['name'],
                $nodeList
            ));
        }

        foreach ($validation['duplicates'] as $key => $dups) {
            $credential = $this->requiredCredentials[$key];
            $formatter->error(sprintf(
                'DUPLICATE %s:%s - %d matches found on target',
                $credential['type'],
                $credential['name'],
                count($dups)
            ));
        }

        if (count($validation['missing']) > 0) {
            $formatter->error(sprintf('error: %d missing credential(s)', count($validation['missing'])));
            $formatter->info('hint: create missing credentials in target n8n, or provide --map');
        }

        if (count($validation['duplicates']) > 0) {
            $formatter->error(sprintf('error: %d duplicate credential(s)', count($validation['duplicates'])));
            $formatter->info('hint: use --map to disambiguate duplicate credentials');
        }
    }

    /**
     * Generate JSON report
     * @param array{missing: array<string>, duplicates: array<string, array<int, array{id: string, type: string, name: string}>>} $validation
     * @return array<string, mixed>
     */
    private function generateJsonReport(array $validation): array
    {
        $report = [
            'summary' => [
                'total' => count($this->requiredCredentials),
                'ok' => 0,
                'missing' => count($validation['missing']),
                'duplicates' => count($validation['duplicates']),
            ],
            'credentials' => [],
        ];

        foreach ($this->requiredCredentials as $key => $credential) {
            $targetKey = $this->credentialMap[$key] ?? $key;
            $status = 'ok';

            if (in_array($key, $validation['missing'], true)) {
                $status = 'missing';
            } elseif (isset($validation['duplicates'][$key])) {
                $status = 'duplicate';
            } else {
                $report['summary']['ok']++;
            }

            $entry = [
                'key' => $key,
                'type' => $credential['type'],
                'name' => $credential['name'],
                'status' => $status,
                'nodes' => $credential['nodes'],
            ];

            if ($status === 'ok' && isset($this->targetCredentials[$targetKey])) {
                $targetCreds = $this->targetCredentials[$targetKey];
                if (count($targetCreds) > 0) {
                    $entry['target_id'] = $targetCreds[0]['id'];
                }
            }

            if ($status === 'duplicate' && isset($validation['duplicates'][$key])) {
                $entry['duplicates'] = $validation['duplicates'][$key];
            }

            $report['credentials'][] = $entry;
        }

        return $report;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);
        $workflowPath = $input->getArgument('workflow');
        // --strict is default on, unless --no-strict is explicitly provided
        $strict = !$input->getOption('no-strict');
        $dryRun = $input->getOption('dry-run');
        $noPatch = $input->getOption('no-patch');
        $reportFormat = $input->getOption('report') ?? 'text';
        $outputPath = $input->getOption('output');

        try {
            // Load workflow JSON
            if (!file_exists($workflowPath)) {
                throw new \RuntimeException("Workflow file not found: {$workflowPath}");
            }

            $workflowContent = file_get_contents($workflowPath);
            if ($workflowContent === false) {
                throw new \RuntimeException("Failed to read workflow file: {$workflowPath}");
            }

            $workflowData = json_decode($workflowContent, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($workflowData)) {
                throw new \RuntimeException('Invalid workflow JSON: expected object');
            }

            // Extract credentials
            $this->requiredCredentials = $this->extractCredentials($workflowData);

            // Setup environment and fetch target credentials
            $env = $this->setupEnvironment($input, $output, $formatter);
            $this->targetCredentials = $this->fetchTargetCredentials($env);

            // Load credential map if provided
            $mapPath = $input->getOption('map');
            if ($mapPath !== null) {
                $this->credentialMap = $this->loadCredentialMap($mapPath);
            }

            // Validate
            $validation = $this->validateCredentials();

            // Check if we should exit on errors
            $hasErrors = count($validation['missing']) > 0 || count($validation['duplicates']) > 0;
            if ($strict && $hasErrors) {
                // Generate report before exiting on error
                if ($reportFormat === 'json') {
                    $report = $this->generateJsonReport($validation);
                    $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                } else {
                    $this->generateTextReport($validation, $formatter, $env);
                }
                return Command::FAILURE;
            }

            // Generate report (only if not outputting workflow JSON to stdout)
            // If outputting to stdout, suppress report to avoid mixing with JSON
            $shouldOutputReport = $noPatch || $outputPath !== null || $dryRun;

            if ($shouldOutputReport) {
                if ($reportFormat === 'json') {
                    $report = $this->generateJsonReport($validation);
                    $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                } else {
                    $this->generateTextReport($validation, $formatter, $env);
                }
            }

            // Patch and output if not in no-patch mode
            if (!$noPatch) {
                $patchedWorkflow = $this->patchCredentialIds($workflowData);
                $jsonOutput = $this->formatWorkflowJson($patchedWorkflow);

                if ($dryRun) {
                    $formatter->info('Dry run: would write patched workflow (use --output to see it)');
                } else {
                    if ($outputPath !== null) {
                        file_put_contents($outputPath, $jsonOutput);
                        $formatter->success(sprintf('✓ Patched workflow written to: %s', $outputPath));
                    } else {
                        // Output workflow JSON to stdout (report was suppressed above)
                        $output->writeln($jsonOutput);
                    }
                }
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $formatter->error('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
