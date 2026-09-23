<?php

namespace App\Services\Ozon;

use Illuminate\Http\Client\Response;

class OzonConnectionResponsePreview
{
    private array $secrets = [];

    public function message(mixed $value): string
    {
        $this->secrets = array_values(array_filter([
            (string) config('ozon.api_key'), (string) config('ozon.client_id'),
        ], fn ($value) => $value !== ''));

        return is_scalar($value) ? $this->text((string) $value) : mb_substr((string) json_encode($this->jsonValue($value), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 0, 500);
    }

    public function build(Response $response): array
    {
        $this->secrets = array_values(array_filter([
            (string) config('ozon.api_key'), (string) config('ozon.client_id'),
        ], fn ($value) => $value !== ''));
        // Never print cookies. Also redact their values if echoed into the body.
        foreach ($response->headers() as $name => $values) {
            if (strtolower($name) === 'set-cookie') {
                foreach ((array) $values as $cookie) {
                    if (preg_match('/^[^=]+=([^;]+)/', $cookie, $match)) {
                        $this->secrets[] = $match[1];
                    }
                }
            }
        }
        $result = ['HTTP status' => (string) $response->status(), 'Content-Type' => $this->text($response->header('Content-Type'))];
        foreach (['Server' => 'Server', 'X-Request-ID' => 'Request-ID', 'Request-ID' => 'Request-ID',
            'X-Ozon-Request-ID' => 'Ozon Request-ID', 'X-Trace-ID' => 'Trace-ID', 'Trace-ID' => 'Trace-ID', 'Traceparent' => 'Traceparent'] as $header => $label) {
            $value = $response->header($header);
            if ($value !== '' && ! isset($result[$label])) {
                $result[$label] = $this->text($value);
            }
        }
        $json = $response->json();
        if (is_array($json)) {
            $safe = [];
            foreach (['error', 'code', 'message', 'details'] as $key) {
                if (array_key_exists($key, $json)) {
                    $safe[$key] = $this->jsonValue($json[$key]);
                }
            }
            $preview = json_encode((object) $safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            $preview = $this->text($response->body(), false);
        }
        $result['Response preview'] = mb_substr((string) $preview, 0, 500);

        return $result;
    }

    private function jsonValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[omitted]';
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                if (preg_match('/apikey|clientid|authorization|cookie|credential|password|secret|token|headers|request/i', preg_replace('/[^a-z0-9]/i', '', (string) $key))) {
                    continue;
                }
                $safe[$this->text((string) $key)] = $this->jsonValue($item, $depth + 1);
                if (count($safe) >= 30) {
                    break;
                }
            }

            return $safe;
        }

        return is_scalar($value) ? $this->text((string) $value) : null;
    }

    private function text(string $text, bool $limit = true): string
    {
        $text = mb_scrub($text, 'UTF-8');
        // Decode before redaction so encoded copies of credentials cannot leak.
        for ($i = 0; $i < 3; $i++) {
            $text = html_entity_decode(rawurldecode($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        foreach ($this->secrets as $secret) {
            $text = str_replace([$secret, base64_encode($secret)], '[redacted]', $text);
        }
        // Remove active/hidden HTML, form values and attributes; keep readable error text.
        $text = preg_replace('~<(script|style|form|textarea)\b[^>]*>.*?</\1\s*>~is', '[omitted]', $text);
        $text = strip_tags($text);
        // An echoed request header block is never useful for response diagnosis.
        $text = preg_replace('/(?:request[ _-]*headers|credentials)\s*[:=].*/is', '[redacted]', $text);
        $text = preg_replace('/(?:api[ _-]*key|client[ _-]*id|authorization|proxy-authorization|set-cookie|cookie|password|secret|access[ _-]*token|refresh[ _-]*token)\s*["\x27]?\s*[:=][^\r\n]*/i', '[redacted]', $text);
        $text = preg_replace('~\bhttps?://[^\s/@]+:[^\s/@]+@~i', 'https://[redacted]@', $text);
        $text = preg_replace('/\b(?:Bearer|Basic)\s+\S+/i', '[redacted]', $text);
        $text = preg_replace('/[\x00-\x1f\x7f]/', ' ', $text);

        return $limit ? mb_substr($text, 0, 500) : $text;
    }
}
