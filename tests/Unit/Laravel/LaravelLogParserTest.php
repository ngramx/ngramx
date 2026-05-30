<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Laravel;

use Ngramx\Laravel\LaravelLogParser;
use Ngramx\Laravel\LogEntry;
use PHPUnit\Framework\TestCase;

class LaravelLogParserTest extends TestCase
{
    private LaravelLogParser $parser;

    protected function setUp(): void
    {
        $this->parser = new LaravelLogParser();
    }

    public function test_parse_returns_empty_array_for_empty_input(): void
    {
        $this->assertSame([], $this->parser->parse(''));
        $this->assertSame([], $this->parser->parse('   '));
    }

    public function test_parse_single_entry(): void
    {
        $log = '[2024-01-15 10:30:00] production.ERROR: Something went wrong';

        $entries = $this->parser->parse($log);

        $this->assertCount(1, $entries);
        $this->assertSame('ERROR', $entries[0]->level);
        $this->assertSame('Something went wrong', $entries[0]->message);
        $this->assertSame('2024-01-15', $entries[0]->timestamp->format('Y-m-d'));
        $this->assertNull($entries[0]->stackTrace);
    }

    public function test_parse_multiple_entries(): void
    {
        $log = <<<'LOG'
[2024-01-15 10:30:00] production.ERROR: First error
[2024-01-15 10:31:00] production.WARNING: A warning
[2024-01-15 10:32:00] production.INFO: Just info
LOG;

        $entries = $this->parser->parse($log);

        $this->assertCount(3, $entries);
        $this->assertSame('ERROR', $entries[0]->level);
        $this->assertSame('WARNING', $entries[1]->level);
        $this->assertSame('INFO', $entries[2]->level);
    }

    public function test_parse_entry_with_stack_trace(): void
    {
        $log = <<<'LOG'
[2024-01-15 10:30:00] production.ERROR: SQLSTATE[42S02] {"exception":"[object] (QueryException)
#0 /var/www/html/vendor/laravel/framework/Connection.php(829): run()
#1 /var/www/html/app/Http/Controllers/UserController.php(42): query()
"}
[2024-01-15 10:31:00] production.INFO: Next entry
LOG;

        $entries = $this->parser->parse($log);

        $this->assertCount(2, $entries);
        $this->assertSame('ERROR', $entries[0]->level);
        $this->assertStringContainsString('SQLSTATE', $entries[0]->message);
        $this->assertNotNull($entries[0]->stackTrace);
        $this->assertStringContainsString('#0', $entries[0]->stackTrace);

        $this->assertSame('INFO', $entries[1]->level);
        $this->assertNull($entries[1]->stackTrace);
    }

    public function test_parse_entry_with_json_context(): void
    {
        $log = '[2024-01-15 10:30:00] production.ERROR: User not found {"userId":123,"context":"api"}';

        $entries = $this->parser->parse($log);

        $this->assertCount(1, $entries);
        $this->assertSame('User not found {"userId":123,"context":"api"}', $entries[0]->message);
    }

    public function test_parse_handles_different_environments(): void
    {
        $log = <<<'LOG'
[2024-01-15 10:30:00] local.ERROR: Local error
[2024-01-15 10:31:00] staging.WARNING: Staging warning
[2024-01-15 10:32:00] production.CRITICAL: Prod critical
LOG;

        $entries = $this->parser->parse($log);

        $this->assertCount(3, $entries);
        $this->assertSame('ERROR', $entries[0]->level);
        $this->assertSame('WARNING', $entries[1]->level);
        $this->assertSame('CRITICAL', $entries[2]->level);
    }

    public function test_summarise_groups_duplicate_messages(): void
    {
        $entries = [
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:30:00'), 'ERROR', 'Connection refused'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:31:00'), 'ERROR', 'Connection refused'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:32:00'), 'ERROR', 'Connection refused'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:30:30'), 'WARNING', 'Slow query'),
        ];

        $summary = $this->parser->summarise($entries);

        $this->assertCount(2, $summary);

        $this->assertSame('ERROR', $summary[0]->level);
        $this->assertSame('Connection refused', $summary[0]->message);
        $this->assertSame(3, $summary[0]->count);
        $this->assertSame('2024-01-15 10:32:00', $summary[0]->lastOccurrence->format('Y-m-d H:i:s'));

        $this->assertSame('WARNING', $summary[1]->level);
        $this->assertSame(1, $summary[1]->count);
    }

    public function test_summarise_sorts_by_count_descending(): void
    {
        $entries = [
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:30:00'), 'WARNING', 'Rare warning'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:30:00'), 'ERROR', 'Common error'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:31:00'), 'ERROR', 'Common error'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:32:00'), 'ERROR', 'Common error'),
        ];

        $summary = $this->parser->summarise($entries);

        $this->assertSame(3, $summary[0]->count);
        $this->assertSame(1, $summary[1]->count);
    }

    public function test_summarise_strips_json_context_for_grouping(): void
    {
        $entries = [
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:30:00'), 'ERROR', 'Not found {"userId":1}'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:31:00'), 'ERROR', 'Not found {"userId":2}'),
        ];

        $summary = $this->parser->summarise($entries);

        $this->assertCount(1, $summary);
        $this->assertSame(2, $summary[0]->count);
        $this->assertSame('Not found', $summary[0]->message);
    }

    public function test_summarise_keeps_different_levels_separate(): void
    {
        $entries = [
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:30:00'), 'ERROR', 'Something failed'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:31:00'), 'WARNING', 'Something failed'),
        ];

        $summary = $this->parser->summarise($entries);

        $this->assertCount(2, $summary);
    }

    public function test_filter_errors_and_warnings(): void
    {
        $entries = [
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:30:00'), 'ERROR', 'An error'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:31:00'), 'INFO', 'Info line'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:32:00'), 'WARNING', 'A warning'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:33:00'), 'DEBUG', 'Debug stuff'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:34:00'), 'CRITICAL', 'Critical issue'),
        ];

        $filtered = $this->parser->filterErrorsAndWarnings($entries);

        $this->assertCount(3, $filtered);
        $this->assertSame('ERROR', $filtered[0]->level);
        $this->assertSame('WARNING', $filtered[1]->level);
        $this->assertSame('CRITICAL', $filtered[2]->level);
    }

    public function test_filter_since(): void
    {
        $entries = [
            new LogEntry(new \DateTimeImmutable('2024-01-15 08:00:00'), 'ERROR', 'Old error'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:00:00'), 'ERROR', 'Recent error'),
            new LogEntry(new \DateTimeImmutable('2024-01-15 10:30:00'), 'ERROR', 'Latest error'),
        ];

        $cutoff = new \DateTimeImmutable('2024-01-15 09:00:00');
        $filtered = $this->parser->filterSince($entries, $cutoff);

        $this->assertCount(2, $filtered);
        $this->assertSame('Recent error', $filtered[0]->message);
        $this->assertSame('Latest error', $filtered[1]->message);
    }

    public function test_parse_duration_valid_values(): void
    {
        $this->assertSame(30, $this->parser->parseDuration('30s'));
        $this->assertSame(600, $this->parser->parseDuration('10m'));
        $this->assertSame(7200, $this->parser->parseDuration('2h'));
        $this->assertSame(86400, $this->parser->parseDuration('1d'));
        $this->assertSame(600, $this->parser->parseDuration('10M'));
    }

    public function test_parse_duration_invalid_format_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parseDuration('abc');
    }

    public function test_parse_duration_missing_unit_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parseDuration('10');
    }
}
