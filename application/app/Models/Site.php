<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    protected $guarded = [];

    protected $attributes = ['active' => true, 'timezone' => 'Europe/Moscow'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function contracts()
    {
        return $this->belongsToMany(Contract::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->active) {
            $query->whereRaw('1=0');

            return;
        }
        if ($user->hasRole('dispatcher') || $user->hasRole('administrator')) {
            return;
        }
        $query->where('organization_id', $user->organization_id)->where(function ($q) use ($user) {
            if ($user->kind !== 'client') {
                $q->whereRaw('1=0');
            } elseif (! $user->accept_work) {
                $q->whereIn('id', $user->sites()->select('sites.id'));
            }
        });
    }
}
