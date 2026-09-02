<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Worktree\AgentRunReader;
use PHPUnit\Framework\TestCase;

class AgentRunReaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ngramx-agent-run-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (is_file($entry)) {
                unlink($entry);
            }
        }

        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function test_it_reads_a_marker_file(): void
    {
        $this->writeMarker(json_encode([
            'source' => 'codabyte',
            'issue' => 'COR-301',
            'startedAt' => '2026-01-01T00:00:00+00:00',
        ], JSON_THROW_ON_ERROR));

        $run = (new AgentRunReader())->read($this->tmpDir);

        $this->assertNotNull($run);
        $this->assertSame('codabyte', $run->source);
        $this->assertSame('COR-301', $run->issue);
    }

    public function test_it_returns_null_when_there_is_no_marker_file(): void
    {
        $this->assertNull((new AgentRunReader())->read($this->tmpDir));
    }

    public function test_it_returns_null_for_a_directory_that_does_not_exist(): void
    {
        $this->assertNull((new AgentRunReader())->read($this->tmpDir . '/nope'));
    }

    /**
     * The likely real-world corruption: we read while the runner is mid-write.
     * It must cost the agent column and nothing more.
     */
    public function test_it_returns_null_for_truncated_json(): void
    {
        $this->writeMarker('{"source": "codaby');

        $this->assertNull((new AgentRunReader())->read($this->tmpDir));
    }

    public function test_it_returns_null_for_an_empty_file(): void
    {
        $this->writeMarker('');

        $this->assertNull((new AgentRunReader())->read($this->tmpDir));
    }

    public function test_it_returns_null_for_whitespace_only(): void
    {
        $this->writeMarker("\n  \n");

        $this->assertNull((new AgentRunReader())->read($this->tmpDir));
    }

    /**
     * Valid JSON that is not an object — `"hello"` or `[1,2,3]` — decodes fine
     * but has no fields to read.
     */
    public function test_it_returns_null_for_json_that_is_not_an_object(): void
    {
        $this->writeMarker('"just a string"');

        $this->assertNull((new AgentRunReader())->read($this->tmpDir));
    }

    public function test_a_trailing_slash_on_the_path_is_handled(): void
    {
        $this->writeMarker(json_encode(['source' => 'codabyte'], JSON_THROW_ON_ERROR));

        $run = (new AgentRunReader())->read($this->tmpDir . '/');

        $this->assertNotNull($run);
        $this->assertSame('codabyte', $run->source);
    }

    private function writeMarker(string $contents): void
    {
        file_put_contents($this->tmpDir . '/' . AgentRunReader::MARKER_FILENAME, $contents);
    }
}
