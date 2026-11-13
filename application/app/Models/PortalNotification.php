<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PortalNotification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function request()
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }

    public function package()
    {
        return $this->belongsTo(WorkPackage::class, 'work_package_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)
            ->when(! $user->hasRole('finance'), fn ($q) => $q->where('audience', '!=', 'finance_internal'))
            ->when(! $user->hasRole('finance') && ! ($user->active && $user->kind === 'client' && $user->accept_work), fn ($q) => $q->where('audience', '!=', 'finance'))
            ->whereHas('request', fn ($q) => $q->visibleTo($user))
            ->where(fn ($q) => $q->whereNull('work_package_id')->orWhereHas('package', fn ($p) => $p->visibleTo($user)));
    }
}
