<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\ColumnRule;
use Ngramx\Postmaclone\Anonymizer\AnonymizedValueFactory;
use Ngramx\Postmaclone\Anonymizer\JsonPayloadAnonymizer;
use Ngramx\Postmaclone\FakerMethodResolver;
use PHPUnit\Framework\TestCase;

class JsonPayloadAnonymizerTest extends TestCase
{
    public function test_rewrites_dotted_paths_and_keeps_other_keys(): void
    {
        $rule = new ColumnRule(
            column: 'payload',
            json: [
                'Contact.Name' => 'company',
                'Contact.EmailAddress' => 'safeEmail',
                'LineItems.*.Description' => 'sentence',
            ],
        );
        $current = json_encode([
            'InvoiceNumber' => 'INV-1',
            'Contact' => [
                'Name' => 'Acme Ltd',
                'EmailAddress' => 'accounts@acme.test',
            ],
            'LineItems' => [
                ['Description' => 'Real job', 'Amount' => 10],
                ['Description' => 'Other job', 'Amount' => 20],
            ],
        ], JSON_THROW_ON_ERROR);

        $rewritten = json_decode($this->anonymizer()->rewrite($current, $rule), true);
        $this->assertIsArray($rewritten);
        $this->assertSame('INV-1', $rewritten['InvoiceNumber']);
        $this->assertSame(10, $rewritten['LineItems'][0]['Amount']);
        $this->assertNotSame('Acme Ltd', $rewritten['Contact']['Name']);
        $this->assertNotSame('accounts@acme.test', $rewritten['Contact']['EmailAddress']);
        $this->assertStringContainsString('@', (string) $rewritten['Contact']['EmailAddress']);
        $this->assertNotSame('Real job', $rewritten['LineItems'][0]['Description']);
        $this->assertNotSame('Other job', $rewritten['LineItems'][1]['Description']);
    }

    public function test_recursive_rewrites_matching_keys_at_any_depth(): void
    {
        $rule = new ColumnRule(
            column: 'payload',
            json: ['email' => 'safeEmail'],
            jsonRecursive: true,
        );
        $current = json_encode([
            'email' => 'top@example.com',
            'nested' => ['email' => 'nested@example.com', 'ok' => true],
        ], JSON_THROW_ON_ERROR);

        $rewritten = json_decode($this->anonymizer()->rewrite($current, $rule), true);
        $this->assertIsArray($rewritten);
        $this->assertNotSame('top@example.com', $rewritten['email']);
        $this->assertNotSame('nested@example.com', $rewritten['nested']['email']);
        $this->assertTrue($rewritten['nested']['ok']);
    }

    public function test_json_array_rewrites_scalar_elements(): void
    {
        $rule = new ColumnRule(column: 'emails', jsonArray: 'safeEmail');
        $current = json_encode(['a@example.com', 'b@example.com'], JSON_THROW_ON_ERROR);

        $rewritten = json_decode($this->anonymizer()->rewrite($current, $rule), true);
        $this->assertIsArray($rewritten);
        $this->assertCount(2, $rewritten);
        $this->assertNotContains('a@example.com', $rewritten);
        $this->assertNotContains('b@example.com', $rewritten);
        $this->assertStringContainsString('@', (string) $rewritten[0]);
    }

    public function test_empty_objects_stay_objects_after_rewrite(): void
    {
        $rule = new ColumnRule(column: 'payload', json: ['email' => 'safeEmail']);
        $current = '{"email":"keep@example.com","empty":{},"nested":{"inner":{}}}';

        $rewritten = $this->anonymizer()->rewrite($current, $rule);

        $this->assertStringContainsString('"empty":{}', $rewritten);
        $this->assertStringContainsString('"inner":{}', $rewritten);
        $this->assertStringNotContainsString('"empty":[]', $rewritten);
        $this->assertStringNotContainsString('"inner":[]', $rewritten);
        $this->assertStringNotContainsString('keep@example.com', $rewritten);
    }

    public function test_try_rewrite_returns_null_for_invalid_json(): void
    {
        $rule = new ColumnRule(column: 'payload', json: ['email' => 'safeEmail']);
        $this->assertNull($this->anonymizer()->tryRewrite('not-json', $rule));
    }

    private function anonymizer(): JsonPayloadAnonymizer
    {
        $factory = new AnonymizedValueFactory(new FakerMethodResolver('en_GB', 42));

        return new JsonPayloadAnonymizer($factory->scalar(...));
    }
}
