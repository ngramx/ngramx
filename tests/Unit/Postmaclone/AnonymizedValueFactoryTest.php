<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\ColumnRule;
use Ngramx\Postmaclone\Anonymizer\AnonymizedValueFactory;
use Ngramx\Postmaclone\FakerMethodResolver;
use PHPUnit\Framework\TestCase;

class AnonymizedValueFactoryTest extends TestCase
{
    public function test_clear_returns_empty_string(): void
    {
        $factory = $this->factory();
        $this->assertSame('', $factory->value(new ColumnRule('token', 'clear'), 'live-secret'));
    }

    public function test_email_or_name_follows_current_cell(): void
    {
        $factory = $this->factory();
        $email = $factory->value(new ColumnRule('pm', 'emailOrName'), 'pm@example.com');
        $name = $factory->value(new ColumnRule('pm', 'emailOrName'), 'Alice Smith');

        $this->assertIsString($email);
        $this->assertStringContainsString('@', $email);
        $this->assertIsString($name);
        $this->assertStringNotContainsString('@', $name);
        $this->assertNotSame('Alice Smith', $name);
    }

    public function test_unique_prefix_still_resolves_email_or_name(): void
    {
        $factory = $this->factory();
        $email = $factory->value(new ColumnRule('pm', 'uniqueEmailOrName'), 'pm@example.com');
        $name = $factory->value(new ColumnRule('pm', 'uniqueEmailOrName'), 'Alice Smith');

        $this->assertIsString($email);
        $this->assertStringContainsString('@', $email);
        $this->assertNotSame('pm@example.com', $email);
        $this->assertIsString($name);
        $this->assertStringNotContainsString('@', $name);
        $this->assertNotSame('Alice Smith', $name);
    }

    public function test_consistent_reuses_replacement_for_same_original(): void
    {
        $factory = $this->factory();
        $rule = new ColumnRule('email', 'safeEmail', consistent: true);
        $first = $factory->value($rule, 'alice@example.com');
        $again = $factory->value($rule, 'alice@example.com');
        $other = $factory->value($rule, 'bob@example.com');

        $this->assertSame($first, $again);
        $this->assertNotSame($first, $other);
    }

    public function test_json_rewrite_replaces_tokens_in_place(): void
    {
        $factory = $this->factory();
        $rule = new ColumnRule(
            column: 'tokens',
            json: ['access_token' => 'clear', 'refresh_token' => 'clear'],
        );
        $rewritten = $factory->value($rule, '{"access_token":"live","refresh_token":"also-live","tenant":"abc"}');
        $decoded = json_decode((string) $rewritten, true);

        $this->assertIsArray($decoded);
        $this->assertSame('', $decoded['access_token']);
        $this->assertSame('', $decoded['refresh_token']);
        $this->assertSame('abc', $decoded['tenant']);
    }

    private function factory(): AnonymizedValueFactory
    {
        return new AnonymizedValueFactory(new FakerMethodResolver('en_GB', 42));
    }
}
