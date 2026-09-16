<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Progress\PercentReporter;
use PHPUnit\Framework\TestCase;

final class PercentReporterTest extends TestCase
{
    public function test_emits_each_ten_percent_boundary(): void
    {
        $lines = [];
        $reporter = new PercentReporter(100, 'Anonymizing users', function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        for ($i = 0; $i < 100; $i++) {
            $reporter->add(1);
        }

        self::assertSame([
            'Anonymizing users 10%',
            'Anonymizing users 20%',
            'Anonymizing users 30%',
            'Anonymizing users 40%',
            'Anonymizing users 50%',
            'Anonymizing users 60%',
            'Anonymizing users 70%',
            'Anonymizing users 80%',
            'Anonymizing users 90%',
            'Anonymizing users 100%',
        ], $lines);
    }

    public function test_skips_unreached_buckets_when_progress_jumps(): void
    {
        $lines = [];
        $reporter = new PercentReporter(10, 'Sanitizing dump', function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $reporter->add(3);
        $reporter->add(7);

        self::assertSame([
            'Sanitizing dump 30%',
            'Sanitizing dump 100%',
        ], $lines);
    }

    public function test_set_can_move_progress_forward(): void
    {
        $lines = [];
        $reporter = new PercentReporter(1000, 'Downloading dump', function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $reporter->set(99);
        $reporter->set(100);
        $reporter->set(250);

        self::assertSame([
            'Downloading dump 10%',
            'Downloading dump 20%',
        ], $lines);
    }

    public function test_finish_emits_100_for_empty_total(): void
    {
        $lines = [];
        $reporter = new PercentReporter(0, 'Anonymizing empty', function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $reporter->add(1);
        $reporter->finish();

        self::assertSame(['Anonymizing empty 100%'], $lines);
    }

    public function test_finish_is_idempotent(): void
    {
        $lines = [];
        $reporter = new PercentReporter(4, 'Compressing dump', function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $reporter->finish();
        $reporter->finish();

        self::assertSame(['Compressing dump 100%'], $lines);
    }
}
