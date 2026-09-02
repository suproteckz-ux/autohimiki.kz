<?php

namespace App\Services\Kaspi;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class KaspiLocalNodeProcessRunner
{
    public function __construct(private readonly KaspiLocalBrowserGuard $guard) {}

    public function run(array $arguments): array
    {
        return $this->execute('kaspi-widget-resolver.mjs', $arguments, 150);
    }

    public function collect(array $arguments): array
    {
        return $this->execute('kaspi-product-page-collector.mjs', $arguments, 150);
    }

    private function execute(string $script, array $arguments, int $timeout): array
    {
        $this->guard->assertAllowed();
        $command = [(string) config('services.kaspi.node_binary', 'node'), base_path('scripts/'.$script)];
        foreach ($arguments as $name => $value) {
            $command[] = '--'.$name.'='.$value;
        }
        $process = new Process($command, base_path(), $this->browserEnvironment(), null, $timeout);
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
