<?php

declare(strict_types=1);

namespace Cortex\Agents;

use Cortex\Templates\TemplateDirectory;

/**
 * Builds the inner markdown for the Cortex-managed AGENTS.md block (without markers).
 *
 * Currently sources content only from templates/agents/*.md. The longer-form
 * ticket workflow templates under templates/steps and templates/ticket-types
 * are intentionally NOT included here — they describe a workflow we are not
 * actively running, but are kept on disk so we can revive them (e.g. when we
 * incorporate specs) without recreating them.
 */
final class AgentsManagedBodyProvider
{
    /**
     * @param string|null $templatesRoot Override the templates root directory (used by tests).
     *                                   When null, resolves via TemplateDirectory::resolve(),
     *                                   which yields a phar:// path inside a Phar build.
     */
    public function __construct(private readonly ?string $templatesRoot = null)
    {
    }

    public function getMarkdown(): string
    {
        $templatesRoot = $this->templatesRoot ?? TemplateDirectory::resolve();
        $agentsDir = $templatesRoot . '/agents';

        $sections = [];

        // Note: scandir() (not glob()) is used so this works when the CLI
        // is distributed as a Phar — glob() does not operate on the
        // phar:// stream wrapper and would silently return no matches.
        if (is_dir($agentsDir)) {
            $entries = scandir($agentsDir);
            if ($entries !== false) {
                $files = [];
                foreach ($entries as $entry) {
                    if (preg_match('/^[0-9]{2}-.+\.md$/', $entry) === 1) {
                        $files[] = $agentsDir . '/' . $entry;
                    }
                }
                sort($files, SORT_STRING);

                foreach ($files as $file) {
                    $chunk = file_get_contents($file);
                    if ($chunk !== false && trim($chunk) !== '') {
                        $sections[] = rtrim($chunk);
                    }
                }
            }
        }

        return implode("\n\n---\n\n", $sections);
    }
}
