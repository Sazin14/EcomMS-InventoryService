<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** Turns events from ProductService into changes in Inventory. Unknown event types are ignored on purpose. */
class CatalogEventHandler
{
    public function __construct(private StockService $stock) {}

    public function handle(array $envelope): void
    {
        $data = $envelope['data'] ?? [];
        $at = CarbonImmutable::parse($envelope['occurred_at']);

        match ($envelope['type']) {
            'catalog.variant.upserted' => $this->stock->applyCatalogUpsert($data, $at),
            'catalog.variant.deleted' => $this->stock->applyCatalogDelete((int) $data['variant_id'], $at),
            default => null,   // a newer producer may send types this version does not know yet
        };
    }
}
