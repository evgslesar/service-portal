<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestMessage extends Model
{
    protected $guarded = [];

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function request()
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id');
    }
}
