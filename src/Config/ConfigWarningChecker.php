<?php

declare(strict_types=1);

namespace Ngramx\Config;

use Ngramx\Config\Schema\NgramxConfig;

class ConfigWarningChecker
{
    /**
     * Check config for missing or unconfigured recommended commands.
     *
     * @return list<string>
     */
    public function check(NgramxConfig $config): array
    {
        $warnings = [];

        foreach (RecommendedCommands::COMMANDS as $name => $meta) {
            if (!isset($config->commands[$name])) {
                $warnings[] = "Recommended command '$name' is not defined in ngramx.yml — {$meta['description']}";
                continue;
            }

            if (trim($config->commands[$name]->command) === '') {
                $warnings[] = "Recommended command '$name' has an empty command string in ngramx.yml — define it to use this workflow";
            }
        }

        return $warnings;
    }
}
