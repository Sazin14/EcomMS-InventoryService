<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\WarehouseController;
use App\Http\Controllers\Internal\PreorderController;
use App\Http\Controllers\Internal\ReservationController;
use App\Http\Controllers\Internal\VariantSyncController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\Storefront\AvailabilityController;
use Illuminate\Support\Facades\Route;

Route::get('availability', [AvailabilityController::class, 'index']);

Route::middleware('internal')->prefix('internal')->group(function () {
    Route::post('variants/sync', [VariantSyncController::class, 'sync']);

    Route::post('reservations', [ReservationController::class, 'store']);
    Route::get('reservations/{reservation}', [ReservationController::class, 'show']);
    Route::post('reservations/{reservation}/confirm', [ReservationController::class, 'confirm']);
    Route::post('reservations/{reservation}/release', [ReservationController::class, 'release']);
    Route::post('reservations/{reservation}/fulfil', [ReservationController::class, 'fulfil']);
    Route::post('reservations/{reservation}/returns', [ReturnController::class, 'store']);

    Route::get('preorders', [PreorderController::class, 'index']);
    Route::post('preorders', [PreorderController::class, 'store']);
    Route::delete('preorders/{reference}/{variantId}', [PreorderController::class, 'destroy']);
});

Route::middleware(['auth.jwt', 'authz'])->prefix('admin')->group(function () {
    Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('permission:inventory.view');
    Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('permission:warehouse.manage');
    Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('permission:warehouse.manage');

    Route::get('stock', [StockController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('stock/{variant}', [StockController::class, 'show'])->middleware('permission:inventory.view');
    Route::get('movements', [StockController::class, 'movements'])->middleware('permission:inventory.view');
    Route::post('stock/receive', [StockController::class, 'receive'])->middleware('permission:inventory.receive');
    Route::post('stock/adjust', [StockController::class, 'adjust'])->middleware('permission:inventory.adjust');
    Route::post('stock/transfer', [StockController::class, 'transfer'])->middleware('permission:inventory.transfer');
    Route::put('stock/{variant}/warehouses/{warehouse}/reorder-level', [StockController::class, 'reorderLevel'])->middleware('permission:inventory.manage');
    Route::put('stock/{variant}/policy', [StockController::class, 'policy'])->middleware('permission:inventory.manage');

    Route::get('alerts', [DashboardController::class, 'alerts'])->middleware('permission:inventory.view');
    Route::post('alerts/read-all', [DashboardController::class, 'markAllRead'])->middleware('permission:inventory.view');
    Route::post('alerts/{alert}/read', [DashboardController::class, 'markRead'])->middleware('permission:inventory.view');
    Route::get('inventory/preorders', [DashboardController::class, 'preorders'])->middleware('permission:inventory.view');
    Route::get('inventory/preorders/summary', [DashboardController::class, 'preorderSummary'])->middleware('permission:inventory.view');

    Route::get('reservations', [ReturnController::class, 'reservations'])->middleware('permission:inventory.view');
    Route::post('reservations/{reservation}/returns', [ReturnController::class, 'store'])->middleware('permission:inventory.receive');
    Route::get('inventory/returns', [ReturnController::class, 'index'])->middleware('permission:inventory.view');

    Route::get('reports/low-stock', [ReportController::class, 'lowStock'])->middleware('permission:inventory.view');
    Route::get('reports/out-of-stock', [ReportController::class, 'outOfStock'])->middleware('permission:inventory.view');
    Route::get('reports/preorder-demand', [ReportController::class, 'preorderDemand'])->middleware('permission:inventory.view');
    Route::get('reports/purchase-suggestions', [ReportController::class, 'purchaseSuggestions'])->middleware('permission:purchasing.view');

    Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:purchasing.view');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('permission:purchasing.view');
    Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:purchasing.manage');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:purchasing.manage');

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchasing.view');
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchasing.view');
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchasing.manage');
    Route::post('purchase-orders/from-suggestions', [PurchaseOrderController::class, 'fromSuggestions'])->middleware('permission:purchasing.manage');
    Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchasing.manage');
    Route::post('purchase-orders/{purchaseOrder}/status', [PurchaseOrderController::class, 'changeStatus'])->middleware('permission:purchasing.manage');
    Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->middleware('permission:inventory.receive');
});
