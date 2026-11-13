<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ServiceRequest extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'new', 'priority' => 'normal', 'version' => 1];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'version' => 'integer'];
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function packages()
    {
        return $this->hasMany(WorkPackage::class);
    }

    public function messages()
    {
        return $this->hasMany(RequestMessage::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->active) {
            $query->whereRaw('1=0');

            return;
        }
        if ($user->hasRole('dispatcher') || $user->hasRole('finance')) {
            return;
        }
        if ($user->kind === 'client') {
            $query->where('organization_id', $user->organization_id);
            if (! $user->accept_work) {
                $query->whereIn('site_id', $user->sites()->select('sites.id'));
            }

            return;
        }
        if ($user->hasRole('technician')) {
            $query->whereHas('packages', fn ($p) => $p->where('lead_id', $user->id)->orWhereHas('visits', fn ($v) => $v->where('technician_id', $user->id)->where('status', '!=', 'cancelled')));

            return;
        }
        $query->whereRaw('1=0');
    }

    public function label(): string
    {
        return match ($this->status) {
            'new', 'processing' => 'Заявка получена', 'assigned' => 'Выезд запланирован',
            'in_progress' => 'Работы выполняются', 'awaiting_acceptance' => 'Нужно решение ответственного',
            'rework_pending' => 'Проверяем замечание', 'on_hold' => 'Диспетчер планирует следующий шаг',
            'awaiting_settlement' => 'Работы приняты · завершаем расчёт',
            'closed' => 'Заявка завершена', 'cancelled' => 'Заявка отменена', default => 'Проверяем следующий шаг',
        };
    }
}
