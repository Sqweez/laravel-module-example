<?php

namespace App\Service\SaleOrder\Invoice\Concerns;

use Illuminate\Database\QueryException;

trait RetryOnUniqueViolationTrait
{
    protected function shouldRetryOnUniqueViolation(QueryException $e, int $attempt, int $maxAttempts): bool
    {
        if ($attempt >= $maxAttempts) {
            return false;
        }

        // PostgreSQL: SQLSTATE 23505 = unique_violation
        if ($e->getCode() === '23505') {
            return true;
        }

        // MySQL: SQLSTATE 23000 + driver error 1062 = duplicate entry
        $errorInfo = $e->errorInfo ?? [];

        return ($errorInfo[0] ?? null) === '23000' && ($errorInfo[1] ?? null) === 1062;
    }
}
