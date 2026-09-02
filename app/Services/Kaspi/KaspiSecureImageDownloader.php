<?php

namespace App\Services\Kaspi;

use Illuminate\Support\Facades\Http;

class KaspiSecureImageDownloader
{
    public const MAX_BYTES = 5242880;

    public function download(string $url): array
    {
        if (! KaspiSingleProductPolicy::imageUrl($url)) {
            throw new \RuntimeException('image_url_not_allowed', 422);
        }
        $sink = fopen('php://temp/maxmemory:1048576', 'w+b');
        try {
            $options = $this->networkOptions();
            $options['sink'] = $sink;
            $options['on_headers'] = function ($response): void {
                if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                    throw new \RuntimeException('image_too_large');
                }
            };
            $options['progress'] = function ($total, $downloaded): void {
                if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                    throw new \RuntimeException('image_too_large');
                }
            };
            $response = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()->withOptions($options)->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException('image_http_failed');
            }
            rewind($sink);
            $bytes = stream_get_contents($sink, self::MAX_BYTES + 1);
            // Laravel HTTP fakes do not implement Guzzle's sink callback.
            if ($bytes === '') {
                $bytes = $response->body();
            }
            if (strlen($bytes) > self::MAX_BYTES) {
                throw new \RuntimeException('image_too_large');
            }
            $info = @getimagesizefromstring($bytes);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (! $info || ! isset($extensions[$mime]) || ($info['mime'] ?? '') !== $mime
                || $info[0] * $info[1] > 40000000) {
                throw new \RuntimeException('image_invalid_mime');
            }

            return ['bytes' => $bytes, 'hash' => hash('sha256', $bytes), 'extension' => $extensions[$mime]];
        } catch (\Throwable $e) {
            $reason = in_array($e->getMessage(), ['image_too_large', 'image_http_failed', 'image_invalid_mime', 'image_dns_rejected'], true) ? $e->getMessage() : 'image_download_failed';
            throw new \RuntimeException($reason, 422);
        } finally {
            fclose($sink);
        }
    }

    protected function networkOptions(): array
    {
        // Resolve once, require a public address and pin it. No redirects or environment proxies.
        $ips = gethostbynamel('resources.cdn-kaspi.kz');
        if (! $ips || ! extension_loaded('curl')) {
            throw new \RuntimeException('image_dns_rejected');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('image_dns_rejected');
            }
        }

        return ['proxy' => '', 'curl' => [CURLOPT_RESOLVE => ['resources.cdn-kaspi.kz:443:'.$ips[0]]]];
    }
}
