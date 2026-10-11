<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReceiptItem extends Model
{
    protected $fillable = ['purchase_receipt_id', 'purchase_order_item_id', 'variant_id', 'sku', 'quantity'];
}
