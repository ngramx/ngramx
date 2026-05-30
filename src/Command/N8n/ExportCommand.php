<?php

declare(strict_types=1);

namespace Ngramx\Command\N8n;

use GuzzleHttp\Exception\GuzzleException;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ExportCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this
            ->setName('n8n:export')
            ->setDescription('Export n8n workflows')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files');
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function fetchWorkflowDetails(string $workflowUri, array $options): array
    {
        $response = $this->httpClient->request('GET', $workflowUri, $options);
        $rawJson = (string) $response->getBody();
        return json_decode($rawJson, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatWorkflowJson(array $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function saveWorkflow(string $destFile, string $jsonContent): void
    {
        file_put_contents($destFile, $jsonContent);
    }

    private function shouldSkipFile(string $destFile, bool $force): bool
    {
        return file_exists($destFile) && !$force;
    }

    /**
     * @param array<string, string> $env
     */
    private function performExport(array $env, string $dest, bool $force, OutputFormatter $formatter): bool
    {
        $skipped = false;
        $baseUri = $this->buildBaseUri($env);
        $options = $this->buildApiOptions($env, false);

        try {
            $workflowsUri = $this->buildWorkflowsUri($baseUri);
            $workflows = $this->fetchWorkflowsList($workflowsUri, $options);

            foreach ($workflows as $workflow) {
                if (!isset($workflow['id']) || !isset($workflow['name'])) {
                    continue; // Skip invalid workflow entries
                }

                $destFile = $dest . '/' . $workflow['name'] . '.json';

                if ($this->shouldSkipFile($destFile, $force)) {
                    $formatter->info(sprintf('File "%s" already exists', $destFile));
                    $skipped = true;
                    continue;
                }

                $workflowUri = $this->buildWorkflowUri($baseUri, $workflow['id']);
                $workflowData = $this->fetchWorkflowDetails($workflowUri, $options);
                $prettyJson = $this->formatWorkflowJson($workflowData);
                $this->saveWorkflow($destFile, $prettyJson);
            }
        } catch (GuzzleException | \JsonException $e) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to export n8n workflow: %s',
                    $e->getMessage()
                ),
                0,
                $e
            );
        }

        return $skipped;
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);
        $force = (bool) $input->getOption('force');

        try {
            $env = $this->setupEnvironment($input, $output, $formatter);
            [$config] = $this->loadConfig($formatter);

            $dest = $config->n8n->workflowsDir;
            $this->ensureDirectoryExists($dest);

            $skipped = $this->performExport($env, $dest, $force, $formatter);
            $skippedMessage = $skipped ? 'Some exports were skipped. Use -f (force)' : '';
            $formatter->success(sprintf(
                '<info>✓ Workflow export complete to %s. %s</info>',
                $dest,
                $skippedMessage
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $formatter->error('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
