<?php

namespace App\Models;

use App\Actions\Finance\Finance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class WorkPackage extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'ready_to_schedule', 'version' => 1, 'execution_round' => 1, 'photo_required' => false];

    protected function casts(): array
    {
        return ['contract_snapshot' => 'array', 'checklist_template' => 'array', 'photo_required' => 'boolean', 'version' => 'integer', 'execution_round' => 'integer'];
    }

    public function request()
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }

    public function lead()
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    public function results()
    {
        return $this->hasMany(ResultSnapshot::class);
    }

    public function rounds()
    {
        return $this->hasMany(AcceptanceRound::class);
    }

    public function assessments()
    {
        return $this->hasMany(ReworkAssessment::class);
    }

    /** @return Collection<int, Visit> */
    public function outstandingVisits(): Collection
    {
        $visits = $this->visits()->with(['technician', 'attachments'])->orderBy('id')->get();
        $continued = $visits->where('required', true)->where('status', '!=', 'cancelled')->pluck('continues_visit_id')->filter()->all();

        return $visits->where('required', true)->whereNotIn('id', $continued);
    }

    public function allowsExecution(): bool
    {
        if ($this->kind === 'paid' && ! app(Finance::class)->executionReady($this)) {
            return false;
        }
        if (in_array($this->status, ['accepted', 'cancelled', 'awaiting_acceptance'])) {
            return false;
        }
        if ($this->status !== 'rework_pending') {
            return true;
        }
        $round = $this->rounds()->latest('id')->first();

        return $round?->status === 'rejected' && $this->assessments()->where('acceptance_round_id', $round->id)->where('classification', 'service_defect')->exists();
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereHas('request', fn ($r) => $r->visibleTo($user));
        if ($user->kind === 'staff' && ! $user->hasRole('dispatcher') && ! $user->hasRole('finance')) {
            $query->where(fn ($q) => $q->where('lead_id', $user->id)->orWhereHas('visits', fn ($v) => $v->where('technician_id', $user->id)->where('status', '!=', 'cancelled')));
        }
    }
}
