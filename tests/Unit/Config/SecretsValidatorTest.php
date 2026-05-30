<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\Schema\SecretsConfig;
use Ngramx\Config\Validator\SecretsValidator;
use PHPUnit\Framework\TestCase;

class SecretsValidatorTest extends TestCase
{
    public function test_it_returns_empty_array_when_no_secrets_required(): void
    {
        $validator = new SecretsValidator();
        $secrets = new SecretsConfig(required: []);

        $this->assertSame([], $validator->validate($secrets));
    }

    public function test_it_returns_empty_array_when_all_secrets_present(): void
    {
        $validator = $this->createValidatorWithEnv([
            'SECRET_ONE' => 'value1',
            'SECRET_TWO' => 'value2',
        ]);

        $secrets = new SecretsConfig(required: ['SECRET_ONE', 'SECRET_TWO']);

        $this->assertSame([], $validator->validate($secrets));
    }

    public function test_it_returns_missing_secrets(): void
    {
        $validator = $this->createValidatorWithEnv([
            'SECRET_ONE' => 'value1',
        ]);

        $secrets = new SecretsConfig(required: ['SECRET_ONE', 'SECRET_TWO', 'SECRET_THREE']);
        $missing = $validator->validate($secrets);

        $this->assertSame(['SECRET_TWO', 'SECRET_THREE'], $missing);
    }

    public function test_it_returns_all_as_missing_when_none_set(): void
    {
        $validator = $this->createValidatorWithEnv([]);

        $secrets = new SecretsConfig(required: ['FOO', 'BAR']);
        $missing = $validator->validate($secrets);

        $this->assertSame(['FOO', 'BAR'], $missing);
    }

    public function test_it_treats_empty_string_value_as_present(): void
    {
        $validator = $this->createValidatorWithEnv([
            'EMPTY_SECRET' => '',
        ]);

        $secrets = new SecretsConfig(required: ['EMPTY_SECRET']);

        $this->assertSame([], $validator->validate($secrets));
    }

    /**
     * @param array<string, string> $env
     */
    private function createValidatorWithEnv(array $env): SecretsValidator
    {
        return new class ($env) extends SecretsValidator {
            /** @param array<string, string> $env */
            public function __construct(private readonly array $env)
            {
            }

            protected function getEnvVar(string $name): string|false
            {
                return $this->env[$name] ?? false;
            }
        };
    }
}
