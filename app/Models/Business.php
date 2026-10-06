<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    protected $guarded = ['id','owner_id','status'];
    protected function casts(): array { return ['profile'=>'array']; }
    public function users() { return $this->belongsToMany(User::class)->withPivot(['role','permissions'])->withTimestamps(); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function documents() { return $this->hasMany(KnowledgeDocument::class); }
    public function leads() { return $this->hasMany(Lead::class); }
    public function widgets() { return $this->hasMany(ChatWidget::class); }
}
