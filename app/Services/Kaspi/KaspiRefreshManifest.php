<?php

namespace App\Services\Kaspi;

/** Local, disposable spool. Created only after the command's Windows/local guard. */
class KaspiRefreshManifest
{
    private $stream;

    private int $count = 0;

    private int $lastId = 0;

    private int $bytes = 0;

    private bool $sealed = false;

    public function __construct()
    {
        $this->stream = @tmpfile();
        if ($this->stream === false) {
            throw new \RuntimeException('manifest_create_failed');
        }
    }

    public function append(array $payload): void
    {
        if ($this->sealed || ! is_int($payload['product_id'] ?? null) || $payload['product_id'] <= $this->lastId) {
            throw new \RuntimeException('manifest_invalid_order');
        }
        // Preserve every field bound by policy=1, including title/source/version.
        $line = KaspiRefreshPolicy::canonical($payload)."\n";
        $length = strlen($line);
        for ($offset = 0; $offset < $length; $offset += $written) {
            $written = @fwrite($this->stream, substr($line, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('manifest_write_failed');
            }
        }
        $this->lastId = $payload['product_id'];
        $this->count++;
        $this->bytes += $length;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function approval(): string
    {
        $this->sealed = true;
        $hash = hash_init('sha256');
        hash_update($hash, '{"policy":1,"ready":[');
        $separator = '';
        foreach ($this->lines() as $line) {
            hash_update($hash, $separator);
            hash_update($hash, $line);
            $separator = ',';
        }
        hash_update($hash, ']}');

        return hash_final($hash);
    }

    public function payloads(): \Generator
    {
        if (! $this->sealed) {
            throw new \RuntimeException('manifest_not_sealed');
        }
        foreach ($this->lines() as $line) {
            yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    private function lines(): \Generator
    {
        if (! @fflush($this->stream) || ! @rewind($this->stream)) {
            throw new \RuntimeException('manifest_read_failed');
        }
        $count = $bytes = 0;
        while (($line = fgets($this->stream)) !== false) {
            $count++;
            $bytes += strlen($line);
            if (! str_ends_with($line, "\n")) {
                throw new \RuntimeException('manifest_read_failed');
            }
            yield substr($line, 0, -1);
        }
        if (! feof($this->stream) || $count !== $this->count || $bytes !== $this->bytes) {
            throw new \RuntimeException('manifest_read_failed');
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream); // tmpfile deletes its local file on close.
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
