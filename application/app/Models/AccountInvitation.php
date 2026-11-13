<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class AccountInvitation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'revoked_at' => 'datetime', 'account_version' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function setupUrl(): string
    {
        return URL::temporarySignedRoute('invitation.show', $this->expires_at, ['invitation' => $this->id]);
    }

    public function usable(): bool
    {
        return ! $this->consumed_at && ! $this->revoked_at && $this->expires_at->isFuture()
            && $this->user?->active && $this->account_version === $this->user->session_version;
    }
}
