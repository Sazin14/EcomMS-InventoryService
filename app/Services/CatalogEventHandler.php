<?php

namespace App\Services;

use App\Support\RabbitMq\EventHandler;
use Carbon\CarbonImmutable;

class CatalogEventHandler implements EventHandler
{
    public function __construct(private StockService $stock) {}

    public function handle(array $envelope): void
    {
        $data = $envelope['data'] ?? [];
        $at = CarbonImmutable::parse($envelope['occurred_at']);

        match ($envelope['type']) {
            'catalog.variant.upserted' => $this->stock->applyCatalogUpsert($data, $at),
            'catalog.variant.deleted' => $this->stock->applyCatalogDelete((int) $data['variant_id'], $at, $data['sku'] ?? null),
            default => null,
        };
    }
}
