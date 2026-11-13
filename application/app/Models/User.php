<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'totp_secret', 'recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const STAFF_ROLES = ['dispatcher', 'technician', 'finance', 'warehouse', 'administrator'];

    public const STAFF_PERMISSIONS = ['manage_directory'];

    protected $attributes = [
        'kind' => 'client',
        'active' => true,
        'accept_work' => false,
        'session_version' => 1,
        'mfa_reset_required' => false,
    ];

    public function hasRole(string $role): bool
    {
        return $this->active && $this->kind === 'staff' && in_array($role, $this->roles ?? [], true);
    }

    public function canManageUsers(): bool
    {
        return $this->hasRole('administrator') || ($this->hasRole('dispatcher') && in_array('manage_directory', $this->permissions ?? [], true));
    }

    public function requiresMfa(): bool
    {
        return $this->totp_confirmed_at !== null;
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'roles' => 'array',
            'permissions' => 'array',
            'mfa_reset_required' => 'boolean',
            'accept_work' => 'boolean',
            'active' => 'boolean',
            'session_version' => 'integer',
            'totp_secret' => 'encrypted',
            'recovery_codes' => 'array',
            'totp_confirmed_at' => 'datetime',
        ];
    }
}
