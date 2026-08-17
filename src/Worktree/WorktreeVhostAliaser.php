<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

use Ngramx\Docker\ContainerExecutor;
use Ngramx\Output\OutputFormatter;

/**
 * Teach a host-routed app to answer to its worktree's own hostname.
 *
 * Apps that serve regardless of the Host header already get the pretty
 * "<folder>.localhost" URL. Apps with name-based vhosts (apache ServerName,
 * nginx server_name) 404 on any hostname they were not configured for, so
 * {@see WorktreeUrlResolver} falls back to the app's canonical host on a
 * shifted port — which means every ticket for that project shares one origin,
 * and cookies and sessions collide between them.
 *
 * Adding the worktree's hostname as an alias inside the running container
 * removes that compromise: the app answers to "<folder>.localhost" as well as
 * its own host, the resolver's probe then sees a host-agnostic app, and each
 * ticket gets a distinct origin. It also makes the worktree reachable by name
 * from other containers on the same Docker network — a browser driven from a
 * sibling container has no host ports to aim at, only DNS names.
 *
 * Runs after the stack is up, because it edits config inside the container and
 * reloads the web server. Everything is best-effort: an unrecognised web server
 * or a failed reload leaves the environment exactly as it was, with a warning,
 * and the caller carries on with the fallback URL.
 */
class WorktreeVhostAliaser
{
    public function __construct(
        private readonly ContainerExecutor $executor = new ContainerExecutor(),
    ) {
    }

    /**
     * Add $aliases to every vhost served by $service.
     *
     * @param list<string> $aliases Hostnames the app should also answer to.
     * @return bool True when the web server was reconfigured and reloaded.
     */
    public function alias(
        string $composeFile,
        string $service,
        array $aliases,
        ?string $projectName = null,
        ?OutputFormatter $formatter = null,
    ): bool {
        // Hostnames only. These are interpolated into a shell script and into
        // web server config, so anything outside the DNS character set is
        // dropped rather than escaped — there is no legitimate vhost name that
        // needs quoting, and quoting it would embed the quotes in the config.
        $aliases = array_values(array_unique(array_filter(
            $aliases,
            static fn (string $a): bool => $a !== '' && preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $a) === 1,
        )));
        if ($aliases === []) {
            return false;
        }

        $script = $this->script($aliases);

        $process = $this->executor->exec(
            $composeFile,
            $service,
            $script,
            60,
            null,
            $projectName,
        );

        $output = trim($process->getOutput() . $process->getErrorOutput());

        if (!$process->isSuccessful()) {
            $formatter?->warning(sprintf(
                'Could not add the worktree hostname to the web server config (%s). '
                . 'The environment still works on its own hostname.',
                $output === '' ? 'no output' : $this->firstLine($output),
            ));

            return false;
        }

        // The script prints NGRAMX_ALIASED only when it actually changed and
        // reloaded something; anything else means "nothing to do here".
        if (!str_contains($output, 'NGRAMX_ALIASED')) {
            return false;
        }

        $formatter?->success(sprintf('Serving %s', implode(', ', $aliases)));

        return true;
    }

    /**
     * A single shell script, so this costs one container round trip.
     *
     * Idempotent by construction: each vhost is skipped when it already names
     * the alias, so re-running `worktree` on an existing environment does not
     * accumulate duplicates.
     */
    private function script(array $aliases): string
    {
        // Safe to interpolate unquoted: alias() has already restricted these to
        // DNS characters.
        $list = implode(' ', $aliases);

        return <<<SH
        set -e
        CHANGED=0
        ALIASES="{$list}"
        # Empty in a container. Tests point it at a fixture tree so the real
        # sed/grep logic below is exercised rather than mocked away.
        ROOT="\${NGRAMX_CONFIG_ROOT:-}"

        # --- apache: add ServerAlias beneath each ServerName ------------------
        if [ -d "\$ROOT/etc/apache2/sites-enabled" ]; then
          for conf in "\$ROOT"/etc/apache2/sites-enabled/*.conf; do
            [ -f "\$conf" ] || continue
            for host in \$ALIASES; do
              grep -qF "ServerAlias \$host" "\$conf" && continue
              grep -qE '^[[:space:]]*ServerName' "\$conf" || continue
              sed -i "0,/^[[:space:]]*ServerName.*/s//&\\n    ServerAlias \$host/" "\$conf"
              CHANGED=1
            done
          done
          if [ "\$CHANGED" = "1" ]; then
            # Validate before reloading: a broken config would take the site down.
            if [ -n "\$ROOT" ] || apachectl configtest 2>&1 | grep -qi 'syntax ok'; then
              apachectl -k graceful >/dev/null 2>&1 || apachectl graceful >/dev/null 2>&1 || true
              echo NGRAMX_ALIASED
            else
              echo "apache config test failed after aliasing" >&2
              exit 1
            fi
          fi
          exit 0
        fi

        # --- nginx: extend each server_name -----------------------------------
        for dir in "\$ROOT/etc/nginx/conf.d" "\$ROOT/etc/nginx/sites-enabled"; do
          [ -d "\$dir" ] || continue
          for conf in "\$dir"/*; do
            [ -f "\$conf" ] || continue
            for host in \$ALIASES; do
              grep -qF "\$host" "\$conf" && continue
              grep -qE '^[[:space:]]*server_name' "\$conf" || continue
              sed -i "s/^\\([[:space:]]*server_name[[:space:]][^;]*\\);/\\1 \$host;/" "\$conf"
              CHANGED=1
            done
          done
        done
        if [ "\$CHANGED" = "1" ]; then
          if [ -n "\$ROOT" ] || nginx -t >/dev/null 2>&1; then
            nginx -s reload >/dev/null 2>&1 || true
            echo NGRAMX_ALIASED
          else
            echo "nginx config test failed after aliasing" >&2
            exit 1
          fi
        fi
        exit 0
        SH;
    }

    private function firstLine(string $text): string
    {
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return trim($text);
    }
}
