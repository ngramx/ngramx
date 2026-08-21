<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\ArrayDeepMerger;
use PHPUnit\Framework\TestCase;

class ArrayDeepMergerTest extends TestCase
{
    private ArrayDeepMerger $merger;

    protected function setUp(): void
    {
        $this->merger = new ArrayDeepMerger();
    }

    public function test_it_merges_sibling_keys(): void
    {
        $merged = $this->merger->merge(
            ['hooks' => ['onWorktreeCreate' => ['echo a']]],
            ['hooks' => ['onEnvironmentUp' => ['echo b']]],
        );

        $this->assertSame(
            [
                'hooks' => [
                    'onWorktreeCreate' => ['echo a'],
                    'onEnvironmentUp' => ['echo b'],
                ],
            ],
            $merged,
        );
    }

    public function test_it_replaces_list_values(): void
    {
        $merged = $this->merger->merge(
            ['hooks' => ['onWorktreeCreate' => ['echo user']]],
            ['hooks' => ['onWorktreeCreate' => ['echo project']]],
        );

        $this->assertSame(
            ['hooks' => ['onWorktreeCreate' => ['echo project']]],
            $merged,
        );
    }

    public function test_empty_list_clears_previous_list(): void
    {
        $merged = $this->merger->merge(
            ['hooks' => ['onWorktreeCreate' => ['echo user']]],
            ['hooks' => ['onWorktreeCreate' => []]],
        );

        $this->assertSame(
            ['hooks' => ['onWorktreeCreate' => []]],
            $merged,
        );
    }
}
