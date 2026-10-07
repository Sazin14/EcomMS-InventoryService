<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use App\Services\AlertService;
use App\Services\StockService;
use App\Services\WarehouseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data for the six "Nova X1" variants created by ProductService's CatalogDemoSeeder
 * (variant ids 1-6 on a fresh ProductService database).
 *
 *   php artisan db:seed --class=InventoryDemoSeeder
 *
 * Gives you one variant for every availability status:
 *   1 in_stock   2 preorder (out of stock, pre-order allowed)   3 in_stock
 *   4 low_stock  5 in_stock, stock split over two warehouses    6 out_of_stock (pre-order not allowed)
 */
class InventoryDemoSeeder extends Seeder
{
    public function run(StockService $stock, WarehouseService $warehouses, AlertService $alerts): void
    {
        $main = Warehouse::firstOrCreate(['code' => 'MAIN'], ['name' => 'Main Warehouse', 'address' => 'Dhaka']);
        $hub = Warehouse::firstOrCreate(['code' => 'CTG'], ['name' => 'Chattogram Hub', 'address' => 'Chattogram']);

        // make sure exactly one default exists
        if (! Warehouse::where('is_default', true)->exists()) {
            $warehouses->update($main, ['is_default' => true]);
        }

        $combos = [
            1 => ['8GB', '128GB', 'Space Grey'],
            2 => ['8GB', '128GB', 'Ocean Blue'],
            3 => ['8GB', '512GB', 'Space Grey'],
            4 => ['8GB', '512GB', 'Ocean Blue'],
            5 => ['16GB', '512GB', 'Space Grey'],
            6 => ['16GB', '512GB', 'Ocean Blue'],
        ];

        $variants = [];

        foreach ($combos as $id => $combo) {
            $variants[] = [
                'variant_id' => $id,
                'sku' => strtoupper('NX1-'.implode('-', array_map(fn ($v) => Str::slug($v, ''), $combo))),
                'product_name' => 'Nova X1',
                'variant_label' => implode(' / ', $combo),
            ];
        }

        $stock->syncVariants($variants);

        $actor = ['type' => 'system', 'id' => null];
        $receive = fn (int $variant, int $warehouse, int $qty) => $stock->receive([
            'variant_id' => $variant, 'warehouse_id' => $warehouse, 'quantity' => $qty,
            'reference_type' => 'seed', 'reason' => 'Demo opening stock',
        ], $actor);

        $receive(1, $main->id, 12);
        $receive(3, $main->id, 7);
        $receive(4, $main->id, 3);
        $receive(5, $main->id, 10);
        $receive(5, $hub->id, 10);

        // 4: low stock (3 left, reorder level 5)
        $stock->setReorderLevel(4, $main->id, 5);

        // 2: out of stock, but customers may pre-order up to 50 units
        // (standalone demo only: in real use the flag comes from the product form via ProductService,
        //  and the next sync from ProductService overwrites it)
        \App\Models\InventoryItem::where('variant_id', 2)->update([
            'allow_preorder' => true,
            'preorder_limit' => 50,
            'expected_restock_at' => now()->addDays(14)->toDateString(),
        ]);

        // 6: out of stock and pre-order NOT allowed (nothing received, policy untouched)

        foreach ([2, 4, 6] as $variantId) {
            $alerts->checkStock($variantId);
        }

        $this->command?->info('Inventory demo data created.');
    }
}
