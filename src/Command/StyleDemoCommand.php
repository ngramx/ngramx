<?php

declare(strict_types=1);

namespace Ngramx\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StyleDemoCommand extends Command
{
    private const COLOR_TEAL = '#2ED9C3';
    private const COLOR_PURPLE = '#7D55C7';
    private const COLOR_SMOKE = '#D2DCE5';
    private const COLOR_DIM = '#6B7B8D';

    private const ANSI_STRIKE = "\e[9m";
    private const ANSI_RESET = "\e[0m";

    protected function configure(): void
    {
        $this
            ->setName('demo')
            ->setDescription('Demo different output styles for the review completion display')
            ->addArgument('style', InputArgument::OPTIONAL, 'Show a single style (1-6) or omit to show all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = $input->getArgument('style');

        $data = $this->sampleData();

        if ($style !== null) {
            $method = 'showStyle' . $style;
            if (method_exists($this, $method)) {
                $this->$method($output, $data);
            } else {
                $output->writeln('<fg=red>Unknown style: ' . $style . '. Use 1-6.</>');

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        for ($i = 1; $i <= 6; $i++) {
            $method = 'showStyle' . $i;
            $this->$method($output, $data);
            $output->writeln('');
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{title: string, description: string, pr_url: string, linear_url: string, test_urls: list<array{label: string, url: string}>, test_plan: list<array{description: string, steps: list<string>, tested?: bool}>}
     */
    private function sampleData(): array
    {
        return [
            'title' => 'GIG-1603: Invoice PDF Export With Custom Templates',
            'description' => 'Adds PDF export to the invoice detail page with support for custom Blade templates. Invoices can now be downloaded or emailed directly from the action menu.',
            'pr_url' => 'https://github.com/gigabyte-software/client-app/pull/247',
            'linear_url' => 'https://linear.app/gigabyte/issue/GIG-1603',
            'test_urls' => [
                ['label' => 'Invoice detail', 'url' => 'https://client.localhost/invoices/INV-0042?bypass=hello@example.com'],
                ['label' => 'PWA invoice view', 'url' => 'https://pwa.localhost/invoices/INV-0042?bypass=hello@example.com'],
            ],
            'test_plan' => [
                [
                    'description' => 'PDF download from the invoice detail page',
                    'tested' => true,
                    'steps' => [
                        'Navigate to an invoice with line items',
                        'Click the "Actions" dropdown → "Download PDF"',
                        'Verify the PDF opens and contains the correct invoice data',
                        'Check that the total, tax, and line items match the UI',
                    ],
                ],
                [
                    'description' => 'Email invoice to client',
                    'tested' => true,
                    'steps' => [
                        'Open the same invoice',
                        'Click "Actions" → "Email to Client"',
                        'Confirm the modal shows the client\'s email pre-filled',
                        'Send and check Mailpit for the delivered email with PDF attachment',
                    ],
                ],
                [
                    'description' => 'Custom template selection (updated after review)',
                    'steps' => [
                        'Go to Settings → Invoice Templates',
                        'Upload or select a custom Blade template',
                        'Return to the invoice and export — verify it uses the new template',
                    ],
                ],
            ],
        ];
    }

    /**
     * Style 1: Single-line border box, strikethrough headline only (steps hidden).
     *
     * @param array{title: string, description: string, pr_url: string, linear_url: string|null, test_urls: list<array{label: string, url: string}>, test_plan: list<array{description: string, steps: list<string>, tested?: bool}>} $data
     */
    private function showStyle1(OutputInterface $output, array $data): void
    {
        $teal = self::COLOR_TEAL;
        $smoke = self::COLOR_SMOKE;
        $dim = self::COLOR_DIM;
        $purple = self::COLOR_PURPLE;

        $output->writeln("<fg={$purple}>══════════════════════════════════════════════════════════════</>");
        $output->writeln("<fg={$purple}> STYLE 1 — Single box, strikethrough hides steps</>");
        $output->writeln("<fg={$purple}>══════════════════════════════════════════════════════════════</>");
        $output->writeln('');

        $output->writeln("<fg={$purple}>┌──────────────────────────────────────────────────────────────────────────────┐</>");
        $output->writeln("<fg={$purple}>│</> <fg={$teal};options=bold>{$data['title']}</>");
        $output->writeln("<fg={$purple}>│</> <fg={$smoke}>{$data['description']}</>");
        $output->writeln("<fg={$purple}>└──────────────────────────────────────────────────────────────────────────────┘</>");

        $output->writeln('');
        $output->writeln("<fg={$teal}>▸ How to Test</>");

        $activeBlocks = array_filter($data['test_plan'], fn ($b) => empty($b['tested']));
        $testedBlocks = array_filter($data['test_plan'], fn ($b) => !empty($b['tested']));

        foreach ($data['test_plan'] as $block) {
            $tested = !empty($block['tested']);
            $isLast = $block === end($data['test_plan']);
            $branch = $isLast ? '└─' : '├─';

            $output->writeln('');
            if ($tested) {
                $output->writeln("<fg={$purple}>{$branch}</> " . self::ANSI_STRIKE . "<fg={$dim}>{$block['description']}</>" . self::ANSI_RESET);
            } else {
                $output->writeln("<fg={$purple}>{$branch}</> <fg={$smoke}>{$block['description']}</>");
                $gutter = $isLast ? '   ' : '<fg=' . $purple . '>│</>  ';
                foreach ($block['steps'] as $j => $step) {
                    $stepBranch = ($j === count($block['steps']) - 1) ? '└─' : '├─';
                    $output->writeln("   {$gutter}<fg={$dim}>{$stepBranch}</> <fg={$dim}>{$step}</>");
                }
            }
        }

        $output->writeln('');
        $output->writeln("<fg={$teal}>➜ PR:</> {$data['pr_url']}");
        if (!empty($data['linear_url'])) {
            $output->writeln("<fg={$teal}>➜ Linear:</> {$data['linear_url']}");
        }
        foreach ($data['test_urls'] as $link) {
            $output->writeln("<fg={$teal}>➜ {$link['label']}:</> {$link['url']}");
        }
        $output->writeln('');
    }

    /**
     * Style 2: Double-line border box, strikethrough headline with steps shown dimly.
     *
     * @param array{title: string, description: string, pr_url: string, linear_url: string|null, test_urls: list<array{label: string, url: string}>, test_plan: list<array{description: string, steps: list<string>, tested?: bool}>} $data
     */
    private function showStyle2(OutputInterface $output, array $data): void
    {
        $teal = self::COLOR_TEAL;
        $smoke = self::COLOR_SMOKE;
        $dim = self::COLOR_DIM;
        $purple = self::COLOR_PURPLE;

        $output->writeln("<fg={$purple}>══════════════════════════════════════════════════════════════</>");
        $output->writeln("<fg={$purple}> STYLE 2 — Double box, strikethrough with dim steps visible</>");
        $output->writeln("<fg={$purple}>══════════════════════════════════════════════════════════════</>");
        $output->writeln('');

        $output->writeln("<fg={$purple}>╔══════════════════════════════════════════════════════════════════════════════╗</>");
        $output->writeln("<fg={$purple}>║</> <fg={$teal};options=bold>{$data['title']}</>");
        $output->writeln("<fg={$purple}>║</> <fg={$smoke}>{$data['description']}</>");
        $output->writeln("<fg={$purple}>╚══════════════════════════════════════════════════════════════════════════════╝</>");

        $output->writeln('');
        $output->writeln("<fg={$teal}>▸ How to Test</>");

        foreach ($data['test_plan'] as $block) {
            $tested = !empty($block['tested']);
            $isLast = $block === end($data['test_plan']);
            $branch = $isLast ? '└─' : '├─';
            $gutter = $isLast ? '   ' : "<fg={$purple}>│</>  ";

            $output->writeln('');
            if ($tested) {
                $output->writeln("<fg={$purple}>{$branch}</> " . self::ANSI_STRIKE . "<fg={$dim}>{$block['description']}</>" . self::ANSI_RESET);
                foreach ($block['steps'] as $j => $step) {
                    $stepBranch = ($j === count($block['steps']) - 1) ? '└─' : '├─';
                    $output->writeln("   {$gutter}<fg=#3D4A56>{$stepBranch} {$step}</>");
                }
            } else {
                $output->writeln("<fg={$purple}>{$branch}</> <fg={$smoke}>{$block['description']}</>");
                foreach ($block['steps'] as $j => $step) {
                    $stepBranch = ($j === count($block['steps']) - 1) ? '└─' : '├─';
                    $output->writeln("   {$gutter}<fg={$dim}>{$stepBranch}</> <fg={$dim}>{$step}</>");
                }
            }
        }

        $output->writeln('');
        $output->writeln("<fg={$teal}>➜ PR:</> {$data['pr_url']}");
        if (!empty($data['linear_url'])) {
            $output->writeln("<fg={$teal}>➜ Linear:</> {$data['linear_url']}");
        }
        foreach ($data['test_urls'] as $link) {
            $output->writeln("<fg={$teal}>➜ {$link['label']}:</> {$link['url']}");
        }
        $output->writeln('');
    }

    /**
     * Style 3: Heavy top/bottom rules (no side borders), strikethrough with ✓ prefix.
     *
     * @param array{title: string, description: string, pr_url: string, linear_url: string|null, test_urls: list<array{label: string, url: string}>, test_plan: list<array{description: string, steps: list<string>, tested?: bool}>} $data
     */
    private function showStyle3(OutputInterface $output, array $data): void
    {
        $teal = self::COLOR_TEAL;
        $smoke = self::COLOR_SMOKE;
        $dim = self::COLOR_DIM;
        $purple = self::COLOR_PURPLE;

        $output->writeln("<fg={$purple}>══════════════════════════════════════════════════════════════</>");
        $output->writeln("<fg={$purple}> STYLE 3 — Heavy rules, ✓ prefix on tested blocks</>");
        $output->writeln("<fg={$purple}>══════════════════════════════════════════════════════════════</>");
        $output->writeln('');

        $ruleWidth = 78;
        $contentWidth = $ruleWidth - 4;
        $indent = '  ';
        $rule = str_repeat('━', $ruleWidth);

        $output->writeln("<fg={$purple}>{$rule}</>");
        foreach ($this->wordWrap($data['title'], $contentWidth) as $line) {
            $output->writeln("{$indent}<fg={$teal};options=bold>{$line}</>");
        }
        foreach ($this->wordWrap($data['description'], $contentWidth) as $line) {
            $output->writeln("{$indent}<fg={$smoke}>{$line}</>");
        }
        $output->writeln("<fg={$purple}>{$rule}</>");

        $output->writeln('');
        $output->writeln("<fg={$teal}>▸ How to Test</>");

        foreach ($data['test_plan'] as $block) {
            $tested = !empty($block['tested']);
            $isLast = $block === end($data['test_plan']);
            $branch = $isLast ? '└─' : '├─';
            $gutter = $isLast ? '   ' : "<fg={$purple}>│</>  ";

            $output->writeln('');
            if ($tested) {
                $output->writeln("<fg={$purple}>{$branch}</> <fg=#4A7A4A>✓</> " . self::ANSI_STRIKE . "<fg={$dim}>{$block['description']}</>" . self::ANSI_RESET);
            } else {
                $output->writeln("<fg={$purple}>{$branch}</> <fg={$smoke}>{$block['description']}</>");
                foreach ($block['steps'] as $j => $step) {
                    $stepBranch = ($j === count($block['steps']) - 1) ? '└─' : '├─';
                    $output->writeln("   {$gutter}<fg={$dim}>{$stepBranch}</> <fg={$dim}>{$step}</>");
                }
            }
        }

        $output->writeln('');
        $output->writeln("<fg={$teal}>➜ PR:</> {$data['pr_url']}");
        if (!empty($data['linear_url'])) {
            $output->writeln("<fg={$teal}>➜ Linear:</> {$data['linear_url']}");
        }
        foreach ($data['test_urls'] as $link) {
            $output->writeln("<fg={$teal}>➜ {$link['label']}:</> {$link['url']}");
        }
        $output->writeln('');
    }

    /**
     * Style 4: Purple accent bar, strikethrough with [tested] label.
     *
     * @param array{title: string, description: string, pr_url: string, linear_url: string|null, test_urls: list<array{label: string, url: string}>, test_plan: list<array{description: string, steps: list<string>, tested?: bool}>} $data
     */
    private function showStyle4(OutputInterface $output, array $data): void
    {
        $teal = self::COLOR_TEAL;
        $smoke = self::COLOR_SMOKE;
        $dim = self::COLOR_DIM;
        $purple = self::COLOR_PURPLE;

        $output->writeln("<fg={$purple}>══════════════════════════════════════════════════════════════</>");
        $output->writeln("<fg={$purple}> STYLE 4 — Accent bar, [tested] label</>");
        $output->writeln("<fg={$purple}>══════════════════════════════════════════════════════════════</>");
        $output->writeln('');

        $output->writeln("<fg={$purple}>▍</> <fg={$teal};options=bold>{$data['title']}</>");
        $output->writeln("<fg={$purple}>▍</> <fg={$smoke}>{$data['description']}</>");

        $output->writeln('');
        $output->writeln("<fg={$teal}>▸ How to Test</>");

        foreach ($data['test_plan'] as $block) {
            $tested = !empty($block['tested']);
            $isLast = $block === end($data['test_plan']);
            $branch = $isLast ? '└─' : '├─';
            $gutter = $isLast ? '   ' : "<fg={$purple}>│</>  ";

            $output->writeln('');
            if ($tested) {
                $output->writeln("<fg={$purple}>{$branch}</> " . self::ANSI_STRIKE . "<fg={$dim}>{$block['description']}</>" . self::ANSI_RESET . " <fg={$dim}>[tested]</>");
            } else {
                $output->writeln("<fg={$purple}>{$branch}</> <fg={$smoke}>{$block['description']}</>");
                foreach ($block['steps'] as $j => $step) {
                    $stepBranch = ($j === count($block['steps']) - 1) ? '└─' : '├─';
                    $output->writeln("   {$gutter}<fg={$dim}>{$stepBranch}</> <fg={$dim}>{$step}</>");
                }
            }
        }

        $output->writeln('');
        $output->writeln("<fg={$teal}>➜ PR:</> {$data['pr_url']}");
        if (!empty($data['linear_url'])) {
            $output->writeln("<fg={$teal}>➜ Linear:</> {$data['linear_url']}");
        }
        foreach ($data['test_urls'] as $link) {
            $output->writeln("<fg={$teal}>➜ {$link['label']}:</> {$link['url']}");
        }
        $output->writeln('');
    }

    /**
     * Style 5: Rounded box, strikethrough with dim steps collapsed under a toggle hint.
     *
     * @param array{title: string, description: string, pr_url: string, linear_url: string|null, test_urls: list<array{label: string, url: string}>, test_plan: list<array{description: string, steps: list<string>, tested?: bool}>} $data
     */
    private function showStyle5(OutputInterface $output, array $data): void
    {
        $teal = self::COLOR_TEAL;
        $smoke = self::COLOR_SMOKE;
        $dim = self::COLOR_DIM;
        $purple = self::COLOR_PURPLE;

        $output->writeln("<fg={$purple}>══════════════════════════════════════════════════════════════</>");
        $output->writeln("<fg={$purple}> STYLE 5 — Rounded box, strikethrough + step count</>");
        $output->writeln("<fg={$purple}>══════════════════════════════════════════════════════════════</>");
        $output->writeln('');

        $output->writeln("<fg={$purple}>╭──────────────────────────────────────────────────────────────────────────────╮</>");
        $output->writeln("<fg={$purple}>│</> <fg={$teal};options=bold>{$data['title']}</>");
        $output->writeln("<fg={$purple}>│</> <fg={$smoke}>{$data['description']}</>");
        $output->writeln("<fg={$purple}>╰──────────────────────────────────────────────────────────────────────────────╯</>");

        $output->writeln('');
        $output->writeln("<fg={$teal}>▸ How to Test</>");

        foreach ($data['test_plan'] as $block) {
            $tested = !empty($block['tested']);
            $isLast = $block === end($data['test_plan']);
            $branch = $isLast ? '└─' : '├─';
            $gutter = $isLast ? '   ' : "<fg={$purple}>│</>  ";

            $output->writeln('');
            if ($tested) {
                $stepCount = count($block['steps']);
                $output->writeln("<fg={$purple}>{$branch}</> " . self::ANSI_STRIKE . "<fg={$dim}>{$block['description']}</>" . self::ANSI_RESET . " <fg={$dim}>({$stepCount} steps)</>");
            } else {
                $output->writeln("<fg={$purple}>{$branch}</> <fg={$smoke}>{$block['description']}</>");
                foreach ($block['steps'] as $j => $step) {
                    $stepBranch = ($j === count($block['steps']) - 1) ? '└─' : '├─';
                    $output->writeln("   {$gutter}<fg={$dim}>{$stepBranch}</> <fg={$dim}>{$step}</>");
                }
            }
        }

        $output->writeln('');
        $output->writeln("<fg={$teal}>➜ PR:</> {$data['pr_url']}");
        if (!empty($data['linear_url'])) {
            $output->writeln("<fg={$teal}>➜ Linear:</> {$data['linear_url']}");
        }
        foreach ($data['test_urls'] as $link) {
            $output->writeln("<fg={$teal}>➜ {$link['label']}:</> {$link['url']}");
        }
        $output->writeln('');
    }

    /**
     * Style 6: Single box + teal accent rule, strikethrough with ✓ and dim steps visible.
     *
     * @param array{title: string, description: string, pr_url: string, linear_url: string|null, test_urls: list<array{label: string, url: string}>, test_plan: list<array{description: string, steps: list<string>, tested?: bool}>} $data
     */
    private function showStyle6(OutputInterface $output, array $data): void
    {
        $teal = self::COLOR_TEAL;
        $smoke = self::COLOR_SMOKE;
        $dim = self::COLOR_DIM;
        $purple = self::COLOR_PURPLE;

        $output->writeln("<fg={$purple}>══════════════════════════════════════════════════════════════</>");
        $output->writeln("<fg={$purple}> STYLE 6 — Box + teal rule, ✓ with dim steps</>");
        $output->writeln("<fg={$purple}>══════════════════════════════════════════════════════════════</>");
        $output->writeln('');

        $output->writeln("<fg={$purple}>┌──────────────────────────────────────────────────────────────────────────────┐</>");
        $output->writeln("<fg={$purple}>│</> <fg={$teal};options=bold>{$data['title']}</>");
        $output->writeln("<fg={$purple}>│</>");
        $output->writeln("<fg={$purple}>│</> <fg={$smoke}>{$data['description']}</>");
        $output->writeln("<fg={$purple}>└──────────────────────────────────────────────────────────────────────────────┘</>");

        $output->writeln('');
        $output->writeln("<fg={$teal}>▸ How to Test</>");
        $output->writeln("<fg={$teal}>  ──────────────────────────────────────────────────</>");

        foreach ($data['test_plan'] as $block) {
            $tested = !empty($block['tested']);
            $isLast = $block === end($data['test_plan']);
            $branch = $isLast ? '└─' : '├─';
            $gutter = $isLast ? '   ' : "<fg={$purple}>│</>  ";

            $output->writeln('');
            if ($tested) {
                $output->writeln("<fg={$purple}>{$branch}</> <fg=#4A7A4A>✓</> " . self::ANSI_STRIKE . "<fg={$dim}>{$block['description']}</>" . self::ANSI_RESET);
                foreach ($block['steps'] as $j => $step) {
                    $stepBranch = ($j === count($block['steps']) - 1) ? '└─' : '├─';
                    $output->writeln("   {$gutter}<fg=#3D4A56>{$stepBranch} {$step}</>");
                }
            } else {
                $output->writeln("<fg={$purple}>{$branch}</> <fg={$smoke}>{$block['description']}</>");
                foreach ($block['steps'] as $j => $step) {
                    $stepBranch = ($j === count($block['steps']) - 1) ? '└─' : '├─';
                    $output->writeln("   {$gutter}<fg={$dim}>{$stepBranch}</> <fg={$dim}>{$step}</>");
                }
            }
        }

        $output->writeln('');
        $output->writeln("<fg={$teal}>➜ PR:</> {$data['pr_url']}");
        if (!empty($data['linear_url'])) {
            $output->writeln("<fg={$teal}>➜ Linear:</> {$data['linear_url']}");
        }
        foreach ($data['test_urls'] as $link) {
            $output->writeln("<fg={$teal}>➜ {$link['label']}:</> {$link['url']}");
        }
        $output->writeln('');
    }

    /**
     * @return list<string>
     */
    private function wordWrap(string $text, int $width): array
    {
        $wrapped = wordwrap($text, $width, "\n", true);

        return explode("\n", $wrapped);
    }
}
