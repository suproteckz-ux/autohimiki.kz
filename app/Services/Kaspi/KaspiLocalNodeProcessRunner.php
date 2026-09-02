<?php

namespace App\Services\Kaspi;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class KaspiLocalNodeProcessRunner
{
    public function __construct(private readonly KaspiLocalBrowserGuard $guard) {}

    public function run(array $arguments): array
    {
        $this->guard->assertAllowed();
        $command = [(string) config('services.kaspi.node_binary', 'node'), base_path('scripts/kaspi-widget-resolver.mjs')];
        foreach ($arguments as $name => $value) {
            $command[] = '--'.$name.'='.$value;
        }
        $process = new Process($command, base_path(), $this->browserEnvironment(), null, 100);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => '', 'timeout' => true];
        } catch (\Throwable) {
            throw new \RuntimeException('node_process_start_failed: check KASPI_NODE_BINARY and local Playwright installation');
        }

        return ['exit_code' => $process->getExitCode(), 'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(), 'timeout' => false];
    }

    protected function browserEnvironment(): array
    {
        // Symfony Process otherwise inherits Laravel's complete environment, including API secrets.
        $inherited = array_merge(getenv() ?: [], $_ENV, $_SERVER);
        $environment = array_fill_keys(array_keys($inherited), false);
        foreach (['PATH', 'Path', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'windir', 'COMSPEC', 'ComSpec',
            'PATHEXT', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA', 'TEMP', 'TMP', 'PLAYWRIGHT_BROWSERS_PATH'] as $key) {
            if (isset($inherited[$key]) && is_string($inherited[$key])) {
                $environment[$key] = $inherited[$key];
            }
        }
        $environment['NO_COLOR'] = '1';

        return $environment;
    }
}
