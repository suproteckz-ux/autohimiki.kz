<?php

namespace App\Services\Import;

use RuntimeException;

class OnecFileIntake
{
    public function stage(?string $requested = null, bool $dryRun = false): array
    {
        if (! is_dir(config('onec.directory')) && ! ($dryRun && $requested)) {
            throw new RuntimeException('ONEC_INPUT_DIRECTORY is missing or inaccessible.');
        }
        $files = glob(rtrim(config('onec.directory'), '/\\').'/*.xlsx') ?: [];
        $files = array_values(array_filter($files, fn ($p) => is_file($p)
            && ! preg_match('/^(~\$|\.)|\.(tmp|part|partial)\./i', basename($p))));
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $path = $requested ?: ($files[0] ?? null);
        if (! $path) {
            return ['status' => 'no_file'];
        }
        $path = realpath($path);
        if (! $path || ! is_readable($path)) {
            throw new RuntimeException('Input file is missing or unreadable.');
        }
        if (! $dryRun) {
            if ($files === [] || realpath($files[0]) !== $path) {
                throw new RuntimeException('Apply requires the newest file in ONEC_INPUT_DIRECTORY; older/external file requires review.');
            }
            if (isset($files[1]) && filemtime($files[0]) === filemtime($files[1])
                && hash_file('sha256', $files[0]) !== hash_file('sha256', $files[1])) {
                throw new RuntimeException('Different newest files have equal timestamps; chronological order requires review.');
            }
        }
        clearstatcache(true, $path);
        $before = stat($path);
        if ($before['mtime'] > time() - max(1, (int) config('onec.stable_seconds'))
            || $before['size'] === 0 || $before['size'] > config('onec.max_bytes')) {
            throw new RuntimeException('Input is not stable yet, empty, future-dated or too large. Retry after the stability interval.');
        }
        $directory = config('onec.staging');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create private 1C staging directory.');
        }
        $staged = $directory.'/'.bin2hex(random_bytes(16)).'.xlsx';
        try {
            if (! copy($path, $staged)) {
                throw new RuntimeException('Cannot stage 1C input.');
            }
            chmod($staged, 0600);
            $hash = hash_file('sha256', $staged);
            clearstatcache(true, $path);
            $after = stat($path);
            if ($before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']
                || $hash !== hash_file('sha256', $path)) {
                throw new RuntimeException('Input changed while staging; nothing applied.');
            }

            return ['status' => 'staged', 'path' => $staged, 'original' => $path,
                'hash' => $hash, 'mtime' => $before['mtime'], 'size' => $before['size']];
        } catch (\Throwable $e) {
            @unlink($staged);
            throw $e;
        }
    }
}
