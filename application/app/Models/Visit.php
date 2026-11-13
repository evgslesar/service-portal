<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Visit extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'scheduled', 'required' => true, 'version' => 1, 'execution_round' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'execution_round' => 'integer', 'checklist' => 'array', 'usage_draft' => 'array', 'stock_document_ids' => 'array', 'required' => 'boolean', 'planned_start' => 'immutable_datetime', 'planned_end' => 'immutable_datetime', 'actual_start' => 'immutable_datetime', 'actual_end' => 'immutable_datetime'];
    }

    public function package()
    {
        return $this->belongsTo(WorkPackage::class, 'work_package_id');
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function continuation()
    {
        return $this->hasOne(self::class, 'continues_visit_id');
    }

    public function previousVisit()
    {
        return $this->belongsTo(self::class, 'continues_visit_id');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function appointment(): string
    {
        $tz = $this->package->request->site->timezone;

        return $this->planned_start->timezone($tz)->translatedFormat('j F, H:i').'–'.$this->planned_end->timezone($tz)->format('H:i').' · '.$tz;
    }
}
