<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReworkAssessment extends Model
{
    protected $guarded = [];

    public function round()
    {
        return $this->belongsTo(AcceptanceRound::class, 'acceptance_round_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recorded assessments are immutable.'));
        static::deleting(fn () => throw new LogicException('Recorded assessments are immutable.'));
    }
}
