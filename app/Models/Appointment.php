<?php

namespace App\Models;

class Appointment extends TenantModel
{
    protected function casts(): array
    {
        return ['starts_at' => 'datetime'];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
