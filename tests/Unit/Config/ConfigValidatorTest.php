<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\Validator\ConfigValidator;
use PHPUnit\Framework\TestCase;

class ConfigValidatorTest extends TestCase
{
    private ConfigValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigValidator();
    }

    public function test_it_validates_valid_config(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true); // If no exception, validation passed
    }

    public function test_it_throws_exception_for_missing_version(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Missing required field: version');

        $config = [
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_throws_exception_for_missing_docker_section(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Missing required field: docker');

        $config = [
            'version' => '1.0',
        ];

        $this->validator->validate($config);
    }

    public function test_it_throws_exception_for_missing_app_url(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Missing required field: docker.app_url');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_throws_exception_for_missing_compose_file(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Missing required field: docker.compose_file');

        $config = [
            'version' => '1.0',
            'docker' => [
                'primary_service' => 'app',
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_throws_exception_for_missing_primary_service(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Missing required field: docker.primary_service');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_validates_wait_for_section(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
                'wait_for' => [
                    [
                        'service' => 'db',
                        'timeout' => 60,
                    ],
                ],
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_it_validates_verify_timeout(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
                'verify_timeout' => 120,
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_it_throws_exception_for_invalid_verify_timeout(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('docker.verify_timeout must be a positive integer');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
                'verify_timeout' => 0,
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_throws_exception_for_invalid_timeout(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('timeout must be a positive integer');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
                'wait_for' => [
                    [
                        'service' => 'db',
                        'timeout' => -1,
                    ],
                ],
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_validates_readiness_probe_fields(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
                'wait_for' => [
                    [
                        'service' => 'app',
                        'timeout' => 300,
                        'healthcheck' => true,
                        'ready_command' => 'php artisan --version',
                        'ready_log' => 'is ready!',
                    ],
                ],
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_it_throws_for_non_boolean_healthcheck(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('healthcheck must be a boolean');

        $this->validator->validate($this->waitForConfig(['healthcheck' => 'yes']));
    }

    public function test_it_throws_for_empty_ready_command(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('ready_command must be a non-empty string');

        $this->validator->validate($this->waitForConfig(['ready_command' => '   ']));
    }

    public function test_it_throws_for_invalid_ready_log_regex(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('ready_log is not a valid regular expression');

        $this->validator->validate($this->waitForConfig(['ready_log' => '(unterminated']));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function waitForConfig(array $extra): array
    {
        return [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
                'wait_for' => [
                    array_merge(['service' => 'app', 'timeout' => 60], $extra),
                ],
            ],
        ];
    }

    public function test_it_validates_command_definitions(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'setup' => [
                'initialize' => [
                    [
                        'command' => 'composer install',
                        'description' => 'Install dependencies',
                        'timeout' => 300,
                        'retry' => 2,
                    ],
                ],
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_it_accepts_empty_command_string(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'commands' => [
                'clear' => [
                    'command' => '',
                    'description' => 'Sync environment',
                ],
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_it_throws_exception_for_missing_command(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('missing required field: command');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'setup' => [
                'initialize' => [
                    [
                        'description' => 'Install dependencies',
                    ],
                ],
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_accepts_parallel_command_list_for_user_commands(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'commands' => [
                'validate' => [
                    'command' => [
                        'composer validate',
                        'vendor/bin/phpstan',
                        'vendor/bin/phpunit',
                    ],
                    'description' => 'Run checks in parallel',
                ],
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_it_rejects_empty_parallel_command_list(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('commands.validate.command list must contain at least one command');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'commands' => [
                'validate' => [
                    'command' => [],
                    'description' => 'bad',
                ],
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_rejects_non_string_item_in_parallel_command_list(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('commands.validate.command[1] must be a string');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'commands' => [
                'validate' => [
                    'command' => ['ok', 123],
                    'description' => 'bad',
                ],
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_rejects_empty_string_item_in_parallel_command_list(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('commands.validate.command[0] must be a non-empty string');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'commands' => [
                'validate' => [
                    'command' => ['   '],
                    'description' => 'bad',
                ],
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_accepts_parallel_false_on_a_command_list(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'commands' => [
                'fresh' => [
                    'command' => [
                        'composer install',
                        'php artisan migrate:fresh --seed',
                    ],
                    'parallel' => false,
                    'description' => 'Run steps in order',
                ],
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_it_rejects_non_boolean_parallel_flag(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('commands.fresh.parallel must be a boolean');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'commands' => [
                'fresh' => [
                    'command' => ['composer install', 'php artisan migrate'],
                    'parallel' => 'no',
                    'description' => 'bad',
                ],
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_rejects_parallel_flag_on_a_single_command(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('commands.fresh.parallel only applies to a list of commands');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'commands' => [
                'fresh' => [
                    'command' => 'php artisan migrate',
                    'parallel' => false,
                    'description' => 'bad',
                ],
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_rejects_parallel_command_list_in_setup_entries(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('parallel list form is only supported under the `commands:` section');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'setup' => [
                'initialize' => [
                    [
                        'command' => ['composer install', 'npm install'],
                        'description' => 'bad',
                    ],
                ],
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_validates_valid_secrets_section(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'secrets' => [
                'provider' => 'env',
                'required' => ['SECRET_ONE', 'SECRET_TWO'],
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_it_validates_secrets_section_with_defaults(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'secrets' => [
                'required' => ['SECRET_ONE'],
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_it_throws_exception_for_non_array_secrets(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('secrets must be an array');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'secrets' => 'invalid',
        ];

        $this->validator->validate($config);
    }

    public function test_it_throws_exception_for_unsupported_secrets_provider(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unsupported secrets provider: vault');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'secrets' => [
                'provider' => 'vault',
                'required' => ['SECRET_ONE'],
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_throws_exception_for_non_array_secrets_required(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('secrets.required must be an array');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'secrets' => [
                'required' => 'not-an-array',
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_throws_exception_for_empty_string_in_secrets_required(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('secrets.required[0] must be a non-empty string');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'secrets' => [
                'required' => [''],
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_throws_exception_for_non_string_provider(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('secrets.provider must be a string');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'app_url' => 'http://localhost:8080',
            ],
            'secrets' => [
                'provider' => 123,
            ],
        ];

        $this->validator->validate($config);
    }
}
