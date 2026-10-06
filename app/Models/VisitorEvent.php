<?php
namespace App\Models;

class VisitorEvent extends TenantModel
{
    protected function casts(): array { return ['metadata'=>'array']; }
}
