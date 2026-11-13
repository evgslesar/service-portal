<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcceptanceRound extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function result()
    {
        return $this->belongsTo(ResultSnapshot::class, 'result_snapshot_id');
    }

    public function package()
    {
        return $this->belongsTo(WorkPackage::class, 'work_package_id');
    }
}
