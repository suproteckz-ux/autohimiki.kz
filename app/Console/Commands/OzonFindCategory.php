<?php

namespace App\Console\Commands;

use App\Services\Ozon\OzonClient;
use Illuminate\Console\Command;

class OzonFindCategory extends Command
{
    protected $signature = 'ozon:find-category {query : Category name fragment to search for (case-insensitive)}';

    protected $description = 'Search Ozon description-category tree in memory; nothing is saved or cached';

    public function handle(OzonClient $client): int
    {
        $query = (string) $this->argument('query');
        $this->line('Fetching category tree from Ozon API…');

        try {
            $data = $client->categoryTree();
        } catch (\RuntimeException $e) {
            $this->error('API error: '.$e->getMessage());

            return self::FAILURE;
        }

        $tree = $data['result'] ?? [];
        unset($data);

        if (! is_array($tree) || $tree === []) {
            $this->error('Unexpected response: result is empty or not an array.');

            return self::FAILURE;
        }

        $matches = [];
        $this->walk($tree, $query, [], $matches);
        unset($tree);

        if ($matches === []) {
            $this->warn('No matches found for: '.$query);

            return self::SUCCESS;
        }

        $this->info('Found '.count($matches).' match(es) for "'.$query.'":');

        foreach ($matches as $i => $m) {
            $this->newLine();
            $this->line(($i + 1).'. Category name:             '.$client->safeDisplay($m['category_name']));
            $this->line('   description_category_id:   '.$client->safeDisplay($m['description_category_id']));
            $this->line('   Parent path:               '.$client->safeDisplay($m['path']));

            if ($m['types'] === []) {
                $this->line('   Types:                     (none)');
            } else {
                foreach ($m['types'] as $j => $type) {
                    $prefix = $j === 0 ? '   Types:' : '         ';
                    $this->line($prefix.'  type_name='.$client->safeDisplay($type['type_name'] ?? '').'  type_id='.$client->safeDisplay($type['type_id'] ?? ''));
                }
            }
        }

        $this->newLine();
        $this->line('Read only. No data was saved or cached.');

        return self::SUCCESS;
    }

    /** @param array<int,mixed> $nodes @param string[] $path @param array<int,mixed> $matches */
    private function walk(array $nodes, string $query, array $path, array &$matches): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $name = (string) ($node['category_name'] ?? '');
            $id = $node['description_category_id'] ?? '';
            $types = is_array($node['type'] ?? null) ? $node['type'] : [];
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $currentPath = [...$path, $name];

            if (mb_stripos($name, $query) !== false) {
                $matches[] = [
                    'category_name' => $name,
                    'description_category_id' => $id,
                    'path' => $path !== [] ? implode(' > ', $path) : '(root)',
                    'types' => $types,
                ];
            }

            if ($children !== []) {
                $this->walk($children, $query, $currentPath, $matches);
            }
        }
    }
}
