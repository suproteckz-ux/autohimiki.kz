<?php

namespace App\Console\Commands;

use App\Services\Kaspi\KaspiLocalBrowserGuard;
use App\Services\Kaspi\KaspiProductionBridgeService;
use App\Services\Kaspi\KaspiProductionCandidateClient;
use App\Services\Kaspi\KaspiRefreshPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

class KaspiPushNewProductsCommand extends Command
{
    protected $signature = 'kaspi:push-new-products {--dry-run} {--execute} {--diagnostics} {--debug}';

    protected $description = 'Enrich active products in the Без категории queue through the guarded Kaspi pipeline';

    private ?float $lastCandidateRequestAt = null;

    public function handle(KaspiProductionCandidateClient $candidates, KaspiProductionBridgeService $bridge, KaspiLocalBrowserGuard $guard): int
    {
        $dry = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        if ($dry === $execute) {
            $this->error('select_exactly_one_mode_dry_run_execute');

            return self::FAILURE;
        }

        $summary = array_fill_keys(['total_candidates', 'resolved', 'ready', 'processed', 'imported', 'unchanged',
            'updated', 'skipped', 'failed', 'empty_description', 'images_sent', 'images_stored', 'image_mismatches'], 0);
        $cursor = 0;
        $lastProductId = 0;
        $seen = [];
        $batchError = null;

        try {
            $guard->assertAllowed();
            do {
                $page = $this->candidatePage($candidates, ['force_content_refresh' => true, 'scope' => 'new_products',
                    'limit' => 100, 'cursor' => $cursor]);
                foreach ($page['data'] as $candidate) {
                    if ($candidate['product_id'] <= $lastProductId || isset($seen['sku:'.$candidate['sku']])) {
                        throw new \RuntimeException('candidate_invalid_row');
                    }
                    $lastProductId = $candidate['product_id'];
                    $seen['sku:'.$candidate['sku']] = true;
                    $summary['total_candidates']++;
                    $row = ['sku' => $candidate['sku'], 'product_id' => $candidate['product_id'], 'name' => $candidate['name'],
                        'storefront_url' => $candidate['storefront_url'], 'status' => 'skipped', 'reason' => null];
                    $stage = 'prepare';

                    try {
                        // Re-check the scoped queue immediately before enrichment. A manually assigned category removes the product.
                        $fresh = $this->candidateFetch($candidates, ['force_content_refresh' => true, 'scope' => 'new_products',
                            'sku' => $candidate['sku'], 'limit' => 1]);
                        if (count($fresh) !== 1 || $fresh[0]['product_id'] !== $candidate['product_id']
                            || $fresh[0]['storefront_url'] !== $candidate['storefront_url']) {
                            throw new \RuntimeException('product_left_new_products_scope');
                        }
                        if ($fresh[0]['state_fingerprint'] !== $candidate['state_fingerprint']) {
                            throw new \RuntimeException('state_changed');
                        }

                        $prepared = $bridge->prepareRefreshCandidate($fresh[0], (bool) $this->option('debug'),
                            function (string $event, array $parsed) use (&$summary): void {
                                if ($event === 'resolved') {
                                    $summary['resolved']++;
                                }
                            }, $execute);
                        $summary['ready']++;
                        $summary['empty_description'] += $prepared['payload']['content']['description'] === '' ? 1 : 0;
                        $row = array_replace($row, ['status' => 'ready',
                            'kaspi_images_parsed' => $prepared['preview']['kaspi_images_parsed'],
                            'images_to_send' => $prepared['preview']['images_to_send']]);

                        if ($execute) {
                            $stage = 'import';
                            $summary['processed']++;
                            $result = $bridge->send($prepared['payload']);
                            $summary[$result['status'] === 'unchanged' ? 'unchanged' : 'imported']++;
                            $summary['updated'] += $result['status'] === 'imported' ? 1 : 0;
                            $summary['images_sent'] += $result['images_sent'];
                            if (is_int($result['images_stored'])) {
                                $summary['images_stored'] += $result['images_stored'];
                            }
                            $mismatch = ! is_int($result['images_stored']) || $result['images_sent'] !== $result['images_stored'];
                            $summary['image_mismatches'] += $mismatch ? 1 : 0;
                            $row = $result + ['name' => $candidate['name'], 'storefront_url' => $candidate['storefront_url'],
                                'image_count_mismatch' => $mismatch];
                        }
                    } catch (\Throwable $e) {
                        $reason = $this->reason($e);
                        $summary[$stage === 'import' ? 'failed' : 'skipped']++;
                        $row = array_replace($row, ['status' => $stage === 'import' ? 'failed' : 'skipped', 'reason' => $reason]);
                    }
                    $this->json($row);
                    unset($fresh, $prepared, $row);
                }
                $cursor = $page['next_cursor'];
            } while ($cursor !== null);
        } catch (\Throwable $e) {
            $batchError = $this->reason($e);
        }

        $this->json(['summary' => $summary, 'batch_error' => $batchError]);

        return $batchError !== null || $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function candidatePage(KaspiProductionCandidateClient $client, array $options): array
    {
        $this->throttleCandidates();

        return $client->page($options);
    }

    private function candidateFetch(KaspiProductionCandidateClient $client, array $options): array
    {
        $this->throttleCandidates();

        return $client->fetch($options);
    }

    private function throttleCandidates(): void
    {
        if ($this->lastCandidateRequestAt !== null) {
            $milliseconds = (int) ceil(max(0, 1.1 - (microtime(true) - $this->lastCandidateRequestAt)) * 1000);
            if ($milliseconds > 0) {
                Sleep::for($milliseconds)->milliseconds();
            }
        }
        $this->lastCandidateRequestAt = microtime(true);
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
        $allowed = ['product_left_new_products_scope', 'invalid_exact_sku', 'local_browser_disabled',
            'production_base_mismatch', 'widget_configuration_missing', 'candidate_identity_mismatch', 'resolver_not_verified',
            'invalid_preview_response', 'internal_api_token_missing', 'kaspi_internal_api_token_missing',
            'invalid_production_base_url', 'preview_transport_failed', 'import_transport_failed_check_before_retry',
            'invalid_import_response_check_before_retry', 'import_invalid_response', 'candidate_connection_failed_or_timeout',
            'candidate_invalid_json', 'candidate_invalid_cursor', 'candidate_invalid_row', 'wrong_product', 'captcha_detected',
            'candidate_scope_not_confirmed',
            'collector_timeout', 'collector_invalid_json', 'collector_failed', 'collector_empty_or_unavailable',
            'collector_html_too_large', 'parser_empty_or_invalid_html', 'parser_title_missing', 'parser_images_missing',
            'invalid_payload', 'payload_identity_mismatch', 'commercial_attribute_not_allowed', 'image_url_not_allowed',
            'node_process_start_failed'];
        if (in_array($reason, $allowed, true) || in_array($reason, KaspiRefreshPolicy::REASONS, true)
            || preg_match('/^(?:candidate_http_|get_import_http_|post_import_http_)[1-5][0-9]{2}$/D', $reason)
            || preg_match('/^resolver_not_verified_(?:widget_not_found|widget_mismatch|iframe_not_loaded|timeout|captcha_detected|ambiguous_urls|invalid_kaspi_url|storefront_unavailable|kaspi_url_not_opened|browser_error|local_browser_disabled|malformed_node_output|unknown)$/D', $reason)) {
            return $reason;
        }

        return 'kaspi_enrichment_failed';
    }
}
