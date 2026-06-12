<?php

declare(strict_types=1);

namespace Ngramx\Config\Validator;

use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\Schema\AgentsConfig;

class ConfigValidator
{
    /**
     * @param array<string, mixed> $config
     * @throws ConfigException
     */
    public function validate(array $config): void
    {
        $this->validateRequiredFields($config);
        $this->validateDockerSection($config['docker']);

        if (isset($config['setup'])) {
            $this->validateSetupSection($config['setup']);
        }

        if (isset($config['commands'])) {
            $this->validateCommandsSection($config['commands']);
        }

        if (isset($config['secrets'])) {
            $this->validateSecretsSection($config['secrets']);
        }

        if (isset($config['agents'])) {
            $this->validateAgentsSection($config['agents']);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @throws ConfigException
     */
    private function validateRequiredFields(array $config): void
    {
        if (!isset($config['version'])) {
            throw new ConfigException('Missing required field: version');
        }

        if (!isset($config['docker'])) {
            throw new ConfigException('Missing required field: docker');
        }
    }

    /**
     * @param array<string, mixed> $docker
     * @throws ConfigException
     */
    public function validateDockerSection(array $docker): void
    {
        if (!isset($docker['compose_file'])) {
            throw new ConfigException('Missing required field: docker.compose_file');
        }

        if (!isset($docker['primary_service'])) {
            throw new ConfigException('Missing required field: docker.primary_service');
        }

        if (!isset($docker['app_url'])) {
            throw new ConfigException('Missing required field: docker.app_url');
        }

        if (isset($docker['verify_timeout'])) {
            if (!is_int($docker['verify_timeout']) || $docker['verify_timeout'] <= 0) {
                throw new ConfigException('docker.verify_timeout must be a positive integer (seconds)');
            }
        }

        if (isset($docker['wait_for']) && !is_array($docker['wait_for'])) {
            throw new ConfigException('docker.wait_for must be an array');
        }

        if (isset($docker['wait_for'])) {
            foreach ($docker['wait_for'] as $index => $waitConfig) {
                if (!isset($waitConfig['service'])) {
                    throw new ConfigException("docker.wait_for[$index] missing required field: service");
                }
                if (!isset($waitConfig['timeout'])) {
                    throw new ConfigException("docker.wait_for[$index] missing required field: timeout");
                }
                if (!is_int($waitConfig['timeout']) || $waitConfig['timeout'] <= 0) {
                    throw new ConfigException("docker.wait_for[$index].timeout must be a positive integer");
                }

                if (isset($waitConfig['healthcheck']) && !is_bool($waitConfig['healthcheck'])) {
                    throw new ConfigException("docker.wait_for[$index].healthcheck must be a boolean");
                }

                if (isset($waitConfig['ready_command'])) {
                    if (!is_string($waitConfig['ready_command']) || trim($waitConfig['ready_command']) === '') {
                        throw new ConfigException("docker.wait_for[$index].ready_command must be a non-empty string");
                    }
                }

                if (isset($waitConfig['ready_log'])) {
                    if (!is_string($waitConfig['ready_log']) || $waitConfig['ready_log'] === '') {
                        throw new ConfigException("docker.wait_for[$index].ready_log must be a non-empty string");
                    }
                    if (@preg_match('~' . str_replace('~', '\~', $waitConfig['ready_log']) . '~', '') === false) {
                        throw new ConfigException("docker.wait_for[$index].ready_log is not a valid regular expression");
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $setup
     * @throws ConfigException
     */
    public function validateSetupSection(array $setup): void
    {
        if (isset($setup['pre_start'])) {
            $this->validateCommandList($setup['pre_start'], 'setup.pre_start');
        }

        if (isset($setup['initialize'])) {
            $this->validateCommandList($setup['initialize'], 'setup.initialize');
        }
    }

    /**
     * @param array<string, mixed> $commands
     * @throws ConfigException
     */
    private function validateCommandsSection(array $commands): void
    {
        foreach ($commands as $name => $command) {
            $this->validateCommandDefinition($command, "commands.$name", allowParallel: true);
        }
    }

    /**
     * @param array<int, mixed> $commands
     * @throws ConfigException
     */
    private function validateCommandList(array $commands, string $path): void
    {
        foreach ($commands as $index => $command) {
            $this->validateCommandDefinition($command, "$path[$index]", allowParallel: false);
        }
    }

    /**
     * @param mixed $command
     * @throws ConfigException
     */
    private function validateCommandDefinition(mixed $command, string $path, bool $allowParallel): void
    {
        if (!is_array($command)) {
            throw new ConfigException("$path must be an array");
        }

        if (!isset($command['command'])) {
            throw new ConfigException("$path missing required field: command");
        }

        if (is_array($command['command'])) {
            if (!$allowParallel) {
                throw new ConfigException("$path.command must be a string (parallel list form is only supported under the `commands:` section)");
            }

            if ($command['command'] === []) {
                throw new ConfigException("$path.command list must contain at least one command");
            }

            if (array_keys($command['command']) !== range(0, count($command['command']) - 1)) {
                throw new ConfigException("$path.command must be a list, not an associative map");
            }

            foreach ($command['command'] as $index => $item) {
                if (!is_string($item)) {
                    throw new ConfigException("$path.command[$index] must be a string");
                }
                if (trim($item) === '') {
                    throw new ConfigException("$path.command[$index] must be a non-empty string");
                }
            }
        } elseif (!is_string($command['command'])) {
            throw new ConfigException("$path.command must be a string" . ($allowParallel ? ' or a list of strings' : ''));
        }

        if (!isset($command['description'])) {
            throw new ConfigException("$path missing required field: description");
        }

        if (isset($command['timeout']) && (!is_int($command['timeout']) || $command['timeout'] <= 0)) {
            throw new ConfigException("$path.timeout must be a positive integer");
        }

        if (isset($command['retry']) && (!is_int($command['retry']) || $command['retry'] < 0)) {
            throw new ConfigException("$path.retry must be a non-negative integer");
        }

        if (isset($command['ignore_failure']) && !is_bool($command['ignore_failure'])) {
            throw new ConfigException("$path.ignore_failure must be a boolean");
        }

        if (isset($command['parallel'])) {
            if (!is_bool($command['parallel'])) {
                throw new ConfigException("$path.parallel must be a boolean");
            }
            if (!is_array($command['command'])) {
                throw new ConfigException("$path.parallel only applies to a list of commands");
            }
        }
    }

    /**
     * @param mixed $secrets
     * @throws ConfigException
     */
    private function validateSecretsSection(mixed $secrets): void
    {
        if (!is_array($secrets)) {
            throw new ConfigException('secrets must be an array');
        }

        if (isset($secrets['provider']) && !is_string($secrets['provider'])) {
            throw new ConfigException('secrets.provider must be a string');
        }

        if (isset($secrets['provider']) && $secrets['provider'] !== 'env') {
            throw new ConfigException("Unsupported secrets provider: {$secrets['provider']}. Supported providers: env");
        }

        if (isset($secrets['required'])) {
            if (!is_array($secrets['required'])) {
                throw new ConfigException('secrets.required must be an array');
            }

            foreach ($secrets['required'] as $index => $name) {
                if (!is_string($name) || $name === '') {
                    throw new ConfigException("secrets.required[$index] must be a non-empty string");
                }
            }
        }
    }

    /**
     * @param mixed $agents
     * @throws ConfigException
     */
    private function validateAgentsSection(mixed $agents): void
    {
        if (!is_array($agents)) {
            throw new ConfigException('agents must be an array');
        }

        if (isset($agents['targets'])) {
            if (!is_array($agents['targets'])) {
                throw new ConfigException('agents.targets must be an array');
            }

            foreach ($agents['targets'] as $index => $target) {
                if (!is_string($target) || $target === '') {
                    throw new ConfigException("agents.targets[$index] must be a non-empty string");
                }
                if (!in_array($target, AgentsConfig::VALID_TARGETS, true)) {
                    throw new ConfigException(
                        "agents.targets[$index]: unknown target '$target'. Valid targets: " . implode(', ', AgentsConfig::VALID_TARGETS)
                    );
                }
            }
        }

        if (isset($agents['skills'])) {
            if (!is_array($agents['skills'])) {
                throw new ConfigException('agents.skills must be an array');
            }

            foreach ($agents['skills'] as $index => $skill) {
                if (!is_string($skill) || $skill === '') {
                    throw new ConfigException("agents.skills[$index] must be a non-empty string");
                }
                if (!in_array($skill, AgentsConfig::VALID_SKILLS, true)) {
                    throw new ConfigException(
                        "agents.skills[$index]: unknown skill target '$skill'. Valid targets: " . implode(', ', AgentsConfig::VALID_SKILLS)
                    );
                }
            }
        }
    }
}
