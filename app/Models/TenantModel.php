<?php
namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

abstract class TenantModel extends Model
{
    protected $guarded = ['id', 'business_id'];
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $query->where($query->getModel()->qualifyColumn('business_id'), app(TenantContext::class)->id());
        });
        static::creating(function (Model $model) { $model->business_id = app(TenantContext::class)->id(); });
        static::updating(function (Model $model) {
            if ((int) $model->business_id !== app(TenantContext::class)->id()) { throw new \LogicException('Cross-tenant update denied.'); }
        });
    }
    public function business() { return $this->belongsTo(Business::class); }
}
