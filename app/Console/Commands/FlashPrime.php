<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Services\AvailabilityService;
use App\Services\FlashSaleGate;
use Illuminate\Console\Command;

/**
 * Sets the Redis flash-sale counter to the database's "available".
 *   php artisan inventory:flash-prime 12     one variant
 *   php artisan inventory:flash-prime        every flash-sale variant (this is what the scheduler runs)
 */
class FlashPrime extends Command
{
    protected $signature = 'inventory:flash-prime {variant? : variant id; leave out to resync every flash-sale variant}';

    protected $description = 'Set the Redis flash-sale counter from the database';

    public function handle(FlashSaleGate $gate, AvailabilityService $availability): int
    {
        $items = InventoryItem::where('flash_sale', true)
            ->when($this->argument('variant'), fn ($q, $id) => $q->where('variant_id', (int) $id))
            ->get();

        if ($this->argument('variant') && $items->isEmpty()) {
            $this->error('That variant is not a flash-sale variant. Switch it on first (PUT admin/stock/{variant}/policy).');

            return self::FAILURE;
        }

        foreach ($items as $item) {
            $available = $availability->availableFor($item->variant_id);
            $gate->prime($item->variant_id, $available);
            $this->line("variant {$item->variant_id} ({$item->sku}): counter = {$available}");
        }

        return self::SUCCESS;
    }
}
