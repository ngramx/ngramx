<?php

declare(strict_types=1);

namespace Ngramx\Support;

/**
 * Compiles ticket workflow markdown from templates/steps and templates/ticket-types.
 */
final class TicketInstructionsMarkdownCompiler
{
    public function compile(string $templatesRoot): string
    {
        $content = '';

        $parentPath = $templatesRoot . '/ticket-types/parent.md';
        if (file_exists($parentPath)) {
            $parentContent = file_get_contents($parentPath);
            if ($parentContent !== false) {
                $content .= $parentContent;
            }
        }

        $stepOrder = [
            'ticket.md',
            'specs.md',
            'approach.md',
            'planning.md',
            'tests.md',
            'code.md',
        ];

        foreach ($stepOrder as $stepFile) {
            $stepPath = $templatesRoot . '/steps/' . $stepFile;
            if (file_exists($stepPath)) {
                $stepContent = file_get_contents($stepPath);
                if ($stepContent !== false) {
                    $content .= "\n\n" . $stepContent;
                }
            }
        }

        $content .= "\n\n# Ticket Types\n";

        $ticketTypesDir = $templatesRoot . '/ticket-types';
        if (is_dir($ticketTypesDir)) {
            $ticketTypeFiles = [];
            $files = scandir($ticketTypesDir);
            if ($files !== false) {
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..' && $file !== 'parent.md' && pathinfo($file, PATHINFO_EXTENSION) === 'md') {
                        $ticketTypeFiles[] = $file;
                    }
                }
            }
            sort($ticketTypeFiles);

            foreach ($ticketTypeFiles as $ticketTypeFile) {
                $ticketTypePath = $ticketTypesDir . '/' . $ticketTypeFile;
                $ticketTypeContent = file_get_contents($ticketTypePath);
                if ($ticketTypeContent !== false) {
                    $content .= "\n\n" . $ticketTypeContent;
                }
            }
        }

        return $content;
    }
}
