<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Anonymizer;

use Ngramx\Config\Schema\Postmaclone\ColumnRule;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\FakerMethodResolver;

/**
 * Produce a replacement cell value from a column rule and the current value.
 */
class AnonymizedValueFactory
{
    /**
     * @var array<string, mixed>
     */
    private array $consistentCache = [];

    private readonly JsonPayloadAnonymizer $json;

    public function __construct(
        private readonly FakerMethodResolver $faker,
        private readonly string $testPassword = PostmacloneConfig::DEFAULT_TEST_PASSWORD,
    ) {
        $this->json = new JsonPayloadAnonymizer($this->scalar(...));
    }

    public function value(ColumnRule $rule, mixed $current): mixed
    {
        if ($rule->isJsonRewrite()) {
            $rewritten = $this->json->tryRewrite($current, $rule);
            if ($rewritten === null) {
                throw new \RuntimeException('JSON rewrite failed');
            }

            return $rewritten;
        }

        return $this->scalar($rule->faker, $current, $rule);
    }

    public function tryValue(ColumnRule $rule, mixed $current): mixed
    {
        try {
            return $this->value($rule, $current);
        } catch (\Throwable) {
            return $current;
        }
    }

    public function scalar(string $faker, mixed $current, ColumnRule $rule): mixed
    {
        $method = trim($faker);
        $unique = $rule->unique;
        if (str_starts_with($method, 'unique') && strlen($method) > 6 && ctype_upper($method[6] ?? '')) {
            $unique = true;
            $method = lcfirst(substr($method, 6));
        }
        if ($method === 'clear') {
            return '';
        }
        if ($method === 'password') {
            return password_hash($this->testPassword, PASSWORD_BCRYPT);
        }
        if ($method === 'emailOrName') {
            $method = is_string($current) && str_contains($current, '@')
                ? 'safeEmail'
                : '{{firstName}} {{lastName}}';
        }

        if ($rule->consistent) {
            $key = $method . "\0" . (is_scalar($current) || $current === null ? (string) $current : serialize($current));
            if (!array_key_exists($key, $this->consistentCache)) {
                $this->consistentCache[$key] = $this->faker->generate($method, $unique);
            }

            return $this->consistentCache[$key];
        }

        return $this->faker->generate($method, $unique);
    }
}
