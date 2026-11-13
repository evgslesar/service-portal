<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['covered_categories' => 'array', 'terms' => 'array', 'starts_on' => 'date', 'ends_on' => 'date', 'active' => 'boolean'];
    }

    public function sites()
    {
        return $this->belongsToMany(Site::class);
    }

    public function scopeEligible(Builder $query, Site $site): void
    {
        $today = now($site->timezone)->toDateString();
        $query->where('organization_id', $site->organization_id)->where('active', true)
            ->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)
            ->whereHas('sites', fn ($q) => $q->where('sites.id', $site->id));
    }
}
