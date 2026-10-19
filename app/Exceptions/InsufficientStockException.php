<?php

namespace App\Exceptions;

use RuntimeException;

/** 409 with the list of variants that could not be reserved (and whether each can be pre-ordered). */
class InsufficientStockException extends RuntimeException
{
    public function __construct(public array $shortages)
    {
        parent::__construct('Insufficient stock.');
    }

    public function render()
    {
        return response()->json([
            'message' => $this->getMessage(),
            'shortages' => $this->shortages,
        ], 409);
    }
}
