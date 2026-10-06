<?php

namespace App\Support;

use App\Models\Business;
use LogicException;

final class TenantContext
{
    private ?Business $business = null;

    public function set(Business $business): void
    {
        $this->business = $business;
    }

    public function clear(): void
    {
        $this->business = null;
    }

    public function business(): Business
    {
        return $this->business ?? throw new LogicException('Tenant context is required.');
    }

    public function id(): int
    {
        return $this->business()->id;
    }

    public function run(Business $business, callable $callback): mixed
    {
        $previous = $this->business;
        $this->set($business);
        try {
            return $callback();
        } finally {
            $this->business = $previous;
        }
    }
}
