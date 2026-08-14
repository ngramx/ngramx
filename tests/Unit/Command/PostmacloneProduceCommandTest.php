<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\PostmacloneCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\Validator\ConfigValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PostmacloneProduceCommandTest extends TestCase
{
    public function test_produce_requires_all_or_dataset(): void
    {
        $command = new PostmacloneCommand(new ConfigLoader(new ConfigValidator()));
        $tester = new CommandTester($command);
        $exit = $tester->execute(['action' => 'produce']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--all or --dataset', $tester->getDisplay());
    }

    public function test_produce_unknown_dataset(): void
    {
        $fixture = dirname(__DIR__, 2) . '/fixtures/postmaclone/factory-postmaclone.yml';
        $command = new PostmacloneCommand(new ConfigLoader(new ConfigValidator()));
        $tester = new CommandTester($command);
        $exit = $tester->execute([
            'action' => 'produce',
            '--config' => $fixture,
            '--dataset' => 'missing',
        ]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString("Unknown dataset 'missing'", $tester->getDisplay());
    }
}
