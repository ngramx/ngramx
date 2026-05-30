<?php

declare(strict_types=1);

namespace Ngramx\Command\N8n;

use GuzzleHttp\Client;
use Ngramx\Config\ConfigLoader;
use Ngramx\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Dotenv\Dotenv;

abstract class AbstractCommand extends Command
{
    protected const REQUIRED_ENV_KEYS = [
        'NGRAMX_N8N_HOST',
        'NGRAMX_N8N_PORT',
        'NGRAMX_N8N_API_KEY',
    ];

    public function __construct(
        protected readonly ConfigLoader $configLoader,
        protected readonly Client $httpClient,
    ) {
        parent::__construct();
    }

    /**
     * @return array<string, string>
     */
    protected function loadEnv(string $path): array
    {
        if (!file_exists($path)) {
            // Create empty .env
            file_put_contents($path, '');
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read .env file: {$path}");
        }

        $dotenv = new Dotenv();
        return $dotenv->parse($content);
    }

    /**
     * @param array<string, string> $env
     * @return array<string, string>
     */
    protected function promptForMissingEnvValues(
        array $env,
        InputInterface $input,
        OutputInterface $output
    ): array {
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            throw new \RuntimeException('Question helper is not available');
        }

        foreach (static::REQUIRED_ENV_KEYS as $key) {
            if (!isset($env[$key]) || trim((string) $env[$key]) === '') {
                $question = new Question(
                    sprintf('Enter value for %s: ', $key)
                );

                // Hide API key input
                if ($key === 'NGRAMX_N8N_API_KEY') {
                    $question->setHidden(true);
                    $question->setHiddenFallback(false);
                }

                $value = $helper->ask($input, $output, $question);

                if ($value === null || trim($value) === '') {
                    throw new \RuntimeException("{$key} is required");
                }

                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * @param array<string, string> $env
     */
    protected function writeEnv(string $path, array $env): void
    {
        ksort($env);

        $lines = [];
        foreach ($env as $key => $value) {
            $lines[] = $key . '=' . $this->escapeEnvValue((string) $value);
        }

        file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    protected function escapeEnvValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (preg_match('/\s|["\'$`\\\\]/', $value)) {
            return '"' . addcslashes($value, '"\\') . '"';
        }

        return $value;
    }

    /**
     * @param array<string, string> $env
     * @return array<string, array<string, string>>
     */
    protected function buildApiOptions(array $env, bool $includeContentType = false): array
    {
        $headers = [
            'X-N8N-API-KEY' => $env['NGRAMX_N8N_API_KEY'],
            'Accept' => 'application/json',
        ];

        if ($includeContentType) {
            $headers['Content-Type'] = 'application/json';
        }

        return ['headers' => $headers];
    }

    /**
     * @param array<string, string> $env
     */
    protected function buildBaseUri(array $env): string
    {
        return "{$env['NGRAMX_N8N_HOST']}:{$env['NGRAMX_N8N_PORT']}";
    }

    protected function buildWorkflowsUri(string $baseUri): string
    {
        return rtrim($baseUri, '/') . '/api/v1/workflows';
    }

    protected function buildWorkflowUri(string $baseUri, string $workflowId): string
    {
        return rtrim($baseUri, '/') . '/api/v1/workflows/' . $workflowId;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    protected function fetchWorkflowsList(string $workflowsUri, array $options): array
    {
        $response = $this->httpClient->request('GET', $workflowsUri, $options);
        $data = json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);

        if (!isset($data['data']) || !is_array($data['data'])) {
            throw new \RuntimeException('Invalid workflows response: missing data array');
        }

        return $data['data'];
    }

    protected function getEnvPath(): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException('Failed to get current working directory');
        }
        return $cwd . '/.env';
    }

    /**
     * @return array<string, string>
     */
    protected function setupEnvironment(
        InputInterface $input,
        OutputInterface $output,
        OutputFormatter $formatter
    ): array {
        $envPath = $this->getEnvPath();
        $env = $this->loadEnv($envPath);
        $env = $this->promptForMissingEnvValues($env, $input, $output);
        $this->writeEnv($envPath, $env);

        $formatter->success('<info>✓ .env is configured correctly</info>');

        return $env;
    }

    /**
     * @return array{0: \Ngramx\Config\Schema\NgramxConfig, 1: string}
     */
    protected function loadConfig(OutputFormatter $formatter): array
    {
        $configPath = $this->configLoader->findConfigFile();
        $config = $this->configLoader->load($configPath);
        $formatter->info("Loaded configuration from: $configPath");

        return [$config, $configPath];
    }
}
