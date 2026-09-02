<?php

namespace App\Console\Commands;

use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiProductionBridgeService;
use App\Services\Kaspi\KaspiProductionCandidateClient;
use App\Services\Kaspi\KaspiSingleProductPolicy;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class KaspiPushProductionCommand extends Command
{
    protected $signature = 'kaspi:push-production {--sku=} {--limit=} {--all} {--dry-run} {--debug}';

    protected $description = 'Sequential local Kaspi content enrichment with explicit scope';

    public function handle(KaspiProductionBridgeService $bridge, KaspiProductionCandidateClient $candidates, KaspiLocalBrowserGuard $guard): int
    {
        $sku = $this->option('sku');
        $limit = $this->option('limit');
        $all = (bool) $this->option('all');
        if (($sku === null && $limit === null && ! $all)
            || ($all && ($sku !== null || $limit !== null)) || ($sku !== null && $limit !== null)) {
            $this->error('select_exactly_one_mode_sku_limit_all');

            return self::FAILURE;
        }
        if ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1 || strlen((string) $limit) > 9)) {
            $this->error('invalid_limit');

            return self::FAILURE;
        }
        if ($sku === null) {
            return $this->batch($bridge, $candidates, $guard, $all ? null : (int) $limit);
        }
        try {
            KaspiSingleProductPolicy::assertSku($this->option('sku'));
            $prepared = $bridge->prepare($this->option('sku'), (bool) $this->option('debug'));
            $this->line(json_encode($prepared['preview'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            if ($this->option('dry-run')) {
                $this->info('dry_run: no POST performed');

                return self::SUCCESS;
            }
            $this->line(json_encode($bridge->send($prepared['payload']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Do not expose HTTP exceptions, raw browser output or payloads containing credentials.
            $message = $this->reason($e);
            $this->error($message);

            return self::FAILURE;
        }
    }

    private function batch(KaspiProductionBridgeService $bridge, KaspiProductionCandidateClient $candidates, KaspiLocalBrowserGuard $guard, ?int $limit): int
    {
        $started = microtime(true);
        $summary = array_fill_keys(['received', 'unique_candidates', 'processed', 'resolved', 'imported', 'preserved',
            'failed', 'captcha', 'no_widget', 'no_kaspi_url', 'collector_failed', 'parser_failed', 'validation_failed',
            'import_failed', 'descriptions_added', 'images_added', 'attributes_added', 'duplicates_skipped'], 0);
        $seen = [];
        $cursors = [];
        $failed = [];
        $cursor = 0;
        $paginationError = null;
        try {
            $guard->assertAllowed();
            do {
                if (isset($cursors[$cursor])) {
                    throw new \RuntimeException('candidate_invalid_cursor');
                }
                $cursors[$cursor] = true;
                $page = $candidates->page(['limit' => $limit === null ? 100 : min(100, $limit - $summary['processed']), 'cursor' => $cursor]);
                // Immutable product ID cursor, captured before POST can change content eligibility.
                $next = $page['next_cursor'];
                if ($next !== null && (! is_int($next) || $next <= $cursor || isset($cursors[$next]))) {
                    throw new \RuntimeException('candidate_invalid_cursor');
                }
                $summary['received'] += count($page['data']);
                foreach ($page['data'] as $candidate) {
                    $sku = $candidate['sku'];
                    if (isset($seen['sku:'.$sku])) {
                        $summary['duplicates_skipped']++;

                        continue;
                    }
                    $seen['sku:'.$sku] = true;
                    $summary['unique_candidates']++;
                    $summary['processed']++;
                    $stage = 'prepare';
                    try {
                        $prepared = $bridge->prepareCandidate($candidate, (bool) $this->option('debug'), function () use (&$summary) {
                            $summary['resolved']++;
                        });
                        if ($this->option('dry-run')) {
                            $this->json(['sku' => $sku, 'status' => 'dry_run', 'preview' => $prepared['preview']]);
                        } else {
                            $stage = 'import';
                            $result = $bridge->send($prepared['payload']);
                            $summary[$result['status'] === 'imported' ? 'imported' : 'preserved']++;
                            $summary['images_added'] += (int) ($result['gallery_added'] ?? 0);
                            $summary['attributes_added'] += (int) ($result['attributes_added'] ?? 0);
                            $summary['descriptions_added'] += ($result['description'] ?? '') === 'updated' ? 1 : 0;
                            $this->json($result);
                        }
                    } catch (\Throwable $e) {
                        $reason = $this->reason($e);
                        $summary['failed']++;
                        $bucket = match (true) {
                            str_contains($reason, 'captcha') => 'captcha',
                            str_contains($reason, 'widget_not_found') => 'no_widget',
                            str_contains($reason, 'kaspi_url'), str_contains($reason, 'ambiguous_urls') => 'no_kaspi_url',
                            $stage === 'import', str_contains($reason, '_import_'), str_starts_with($reason, 'preview_') => 'import_failed',
                            str_starts_with($reason, 'parser_') => 'parser_failed',
                            $e instanceof ValidationException, in_array($reason, ['invalid_payload', 'payload_identity_mismatch', 'commercial_attribute_not_allowed', 'image_url_not_allowed'], true) => 'validation_failed',
                            str_starts_with($reason, 'collector_'), $reason === 'wrong_product' => 'collector_failed',
                            default => null,
                        };
                        if ($bucket) {
                            $summary[$bucket]++;
                        }
                        $failed[] = ['sku' => $sku, 'reason' => $reason];
                        $this->json(['sku' => $sku, 'status' => 'failed', 'reason' => $reason]);
                    }
                    if ($limit !== null && $summary['processed'] >= $limit) {
                        break;
                    }
                }
                $cursor = $next;
            } while ($cursor !== null && ($limit === null || $summary['processed'] < $limit));
        } catch (\Throwable $e) {
            $paginationError = $this->reason($e);
            $this->json(['status' => 'pagination_aborted', 'reason' => $paginationError]);
        }
        $summary['duration'] = round(microtime(true) - $started, 3);
        $this->json(['summary' => $summary, 'failed_skus' => $failed, 'pagination_error' => $paginationError]);

        return $paginationError !== null || $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function json(array $value): void
    {
        $this->line(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function reason(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return 'invalid_payload';
        }
        $reason = explode(':', $e->getMessage(), 2)[0];
        $allowed = ['invalid_exact_sku', 'local_browser_disabled', 'production_base_mismatch', 'widget_configuration_missing',
            'candidate_identity_mismatch', 'resolver_not_verified', 'invalid_preview_response',
            'invalid_import_response_check_before_retry', 'internal_api_token_missing', 'kaspi_internal_api_token_missing',
            'invalid_production_base_url', 'import_transport_failed_check_before_retry', 'preview_transport_failed',
            'import_invalid_response', 'candidate_connection_failed_or_timeout', 'candidate_invalid_json',
            'candidate_invalid_cursor', 'candidate_invalid_row', 'wrong_product', 'captcha_detected',
            'collector_timeout', 'collector_invalid_json', 'collector_failed', 'collector_empty_or_unavailable', 'collector_html_too_large',
            'parser_empty_or_invalid_html', 'parser_title_missing', 'parser_images_missing', 'invalid_payload',
            'payload_identity_mismatch', 'commercial_attribute_not_allowed', 'image_url_not_allowed', 'node_process_start_failed'];
        if (in_array($reason, $allowed, true)
            || preg_match('/^(?:candidate_http_|get_import_http_|post_import_http_)[1-5][0-9]{2}$/D', $reason)
            || preg_match('/^resolver_not_verified_(?:widget_not_found|widget_mismatch|iframe_not_loaded|timeout|captcha_detected|ambiguous_urls|invalid_kaspi_url|storefront_unavailable|kaspi_url_not_opened|browser_error|local_browser_disabled|malformed_node_output|unknown)$/D', $reason)) {
            return $reason;
        }

        return 'kaspi_enrichment_failed';
    }
}
