<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'specialty'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Role helpers
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isCoordinator(): bool
    {
        return $this->role === 'coordinator';
    }

    public function isSurgeon(): bool
    {
        return $this->role === 'surgeon';
    }

    // Filament panel access: only admins and coordinators may log in.
    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, ['admin', 'coordinator'], true);
    }

    // Surgeries this user performs as the surgeon
    public function surgeriesAsSurgeon(): HasMany
    {
        return $this->hasMany(Surgery::class, 'surgeon_id');
    }

    // Surgeries this user (coordinator) created / scheduled
    public function surgeriesCreated(): HasMany
    {
        return $this->hasMany(Surgery::class, 'created_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
