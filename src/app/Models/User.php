<?php

namespace App\Models;

use App\Services\PrivacyAccess;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'registration_workspace_id', 'registration_workspace_kind', 'registration_workspace_experience', 'admin_totp_secret', 'admin_totp_counter', 'admin_totp_confirmed_at'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function sendEmailVerificationNotification(): void
    {
        app(PrivacyAccess::class)->mail((int) $this->id, fn () => $this->notify(new VerifyEmail));
    }

    public function sendPasswordResetNotification($token): void
    {
        app(PrivacyAccess::class)->mail((int) $this->id, fn () => $this->notify(new ResetPassword($token)));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
