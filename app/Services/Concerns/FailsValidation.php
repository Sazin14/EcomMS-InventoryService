<?php

namespace App\Services\Concerns;

use Illuminate\Validation\ValidationException;

trait FailsValidation
{
    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
