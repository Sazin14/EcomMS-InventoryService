<?php

namespace App\Services\Concerns;

use Illuminate\Validation\ValidationException;

trait FailsValidation
{
    /** Throws a 422 response with the message attached to a field. */
    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
