<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResultSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['contributions' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Submitted results are immutable.'));
        static::deleting(fn () => throw new \LogicException('Submitted results are immutable.'));
    }
}
