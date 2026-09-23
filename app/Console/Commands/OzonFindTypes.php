<?php

namespace App\Console\Commands;

use App\Services\Ozon\OzonClient;
use Illuminate\Console\Command;

class OzonFindTypes extends Command
{
    protected $signature = 'ozon:find-types {category_id : Ozon description_category_id}';

    protected $description = 'List all types for a specific Ozon category branch; nothing is saved or cached';

    public function handle(OzonClient $client): int
    {
        $id = (int) $this->argument('category_id');
        if ($id <= 0) {
            $this->error('Invalid category_id: must be a positive integer.');

            return self::FAILURE;
        }

        $this->line("Fetching Ozon category branch for description_category_id={$id}…");

        try {
            $data = $client->categoryBranch($id);
        } catch (\RuntimeException $e) {
            $this->error('API error: '.$e->getMessage());

            return self::FAILURE;
        }

        $nodes = $data['result'] ?? [];
        unset($data);

        if (! is_array($nodes) || $nodes === []) {
            $this->warn("No data returned for category_id={$id}.");

            return self::SUCCESS;
        }

        $rows = [];
        $this->collectTypes($nodes, $rows);
        unset($nodes);

        if ($rows === []) {
            $this->warn("No types found in category_id={$id} or its children.");

            return self::SUCCESS;
        }

        $this->info('Found '.count($rows).' type(s):');
        $this->newLine();

        $tableRows = array_map(fn ($r) => [
            $client->safeDisplay($r['category_id']),
            $client->safeDisplay($r['category_name']),
            $client->safeDisplay($r['type_id']),
            $client->safeDisplay($r['type_name']),
            $client->safeDisplay($r['disabled'] ? 'disabled' : 'active'),
        ], $rows);

        $this->table(['Category ID', 'Category name', 'type_id', 'type_name', 'Status'], $tableRows);

        $this->newLine();
        $this->line('Read only. No data was saved or cached.');

        return self::SUCCESS;
    }

    /** @param array<int,mixed> $nodes @param array<int,mixed> $rows */
    private function collectTypes(array $nodes, array &$rows): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $catId = $node['description_category_id'] ?? '';
            $catName = (string) ($node['category_name'] ?? '');
            $types = is_array($node['type'] ?? null) ? $node['type'] : [];
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];

            foreach ($types as $type) {
                if (! is_array($type)) {
                    continue;
                }
                $rows[] = [
                    'category_id' => $catId,
                    'category_name' => $catName,
                    'type_id' => $type['type_id'] ?? '',
                    'type_name' => $type['type_name'] ?? '',
                    'disabled' => (bool) ($type['disabled'] ?? false),
                ];
            }

            if ($children !== []) {
                $this->collectTypes($children, $rows);
            }
        }
    }
}
