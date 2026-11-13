<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $guarded = [];

    protected $hidden = ['path'];

    public function request()
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(RequestMessage::class, 'request_message_id');
    }

    public function visit()
    {
        return $this->belongsTo(Visit::class);
    }
}
