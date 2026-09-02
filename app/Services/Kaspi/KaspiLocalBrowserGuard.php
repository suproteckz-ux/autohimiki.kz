<?php

namespace App\Services\Kaspi;

class KaspiLocalBrowserGuard
{
    public function assertAllowed(): void
    {
        if (! app()->environment('local') || $this->platform() !== 'Windows' || PHP_SAPI !== 'cli'
            || ! config('services.kaspi.local_browser_enabled', false)) {
            throw new \RuntimeException('local_browser_disabled: requires Windows CLI, APP_ENV=local and KASPI_LOCAL_BROWSER_ENABLED=true');
        }
    }

    protected function platform(): string
    {
        return PHP_OS_FAMILY;
    }
}
