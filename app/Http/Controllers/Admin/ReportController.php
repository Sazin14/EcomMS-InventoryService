<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;

/**
 * Every report: JSON page by default, or ?format=csv for a download.
 * Filters: ?search=  (low-stock also ?warehouse_id=)   Paging: ?per_page= (max 200)
 */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function lowStock(Request $request)
    {
        return $this->respond($request, $this->reports->lowStock($this->filters($request)), 'low-stock', [
            'Variant ID' => 'variant_id', 'SKU' => 'sku', 'Product' => 'product_name', 'Variant' => 'variant_label',
            'Warehouse' => 'warehouse', 'On hand' => 'on_hand', 'Reserved' => 'reserved',
            'Available' => 'available', 'Reorder level' => 'reorder_level', 'State' => 'state',
        ]);
    }

    public function outOfStock(Request $request)
    {
        return $this->respond($request, $this->reports->outOfStock($this->filters($request)), 'out-of-stock', [
            'Variant ID' => 'variant_id', 'SKU' => 'sku', 'Product' => 'product_name', 'Variant' => 'variant_label',
            'Pending pre-orders' => 'pending_preorders', 'Pre-order allowed' => 'allow_preorder',
            'On order (suppliers)' => 'on_order', 'Expected restock' => 'expected_restock_at',
        ]);
    }

    public function preorderDemand(Request $request)
    {
        return $this->respond($request, $this->reports->preorderDemand($this->filters($request)), 'preorder-demand', [
            'Variant ID' => 'variant_id', 'SKU' => 'sku', 'Product' => 'product_name', 'Variant' => 'variant_label',
            'Pending units' => 'pending_preorders', 'Pending orders' => 'pending_orders',
            'Oldest pre-order' => 'oldest_preorder_at', 'Available now' => 'available',
            'On order (suppliers)' => 'on_order', 'Not covered yet' => 'uncovered',
            'Limit' => 'preorder_limit', 'Expected restock' => 'expected_restock_at',
        ]);
    }

    public function purchaseSuggestions(Request $request)
    {
        return $this->respond($request, $this->reports->suggestions($this->filters($request)), 'purchase-suggestions', [
            'Variant ID' => 'variant_id', 'SKU' => 'sku', 'Product' => 'product_name', 'Variant' => 'variant_label',
            'Pending pre-orders' => 'pending_preorders', 'Reorder level (total)' => 'reorder_total',
            'Available now' => 'available', 'On order (suppliers)' => 'on_order', 'To buy' => 'to_buy',
        ]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);
    }

    private function respond(Request $request, $query, string $name, array $columns)
    {
        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () use ($query, $columns) {
                $out = fopen('php://output', 'w');
                fputcsv($out, array_keys($columns));

                foreach ($query->cursor() as $row) {
                    fputcsv($out, array_map(fn ($key) => $this->safe($row->{$key} ?? null), array_values($columns)));
                }

                fclose($out);
            }, $name.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
        }

        return $query->paginate(min((int) $request->query('per_page', 50), 200));
    }

    /** Spreadsheet programs run cells that start with = + - @ as formulas; product names are user-typed text. */
    private function safe(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_string($value) && $value !== '' && ! is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
