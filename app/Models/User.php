<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Events\Auth\CredentialsRevoked;
use App\Services\Auth\OtpService;
use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, HasUUID, Notifiable;

    protected string $guard_name = 'api';

    protected $fillable = [
        'name', 'email', 'password', 'role',
        'whatsapp_number', 'timezone', 'is_active', 'last_login',
        'phone_verified_at', 'login_attempts', 'deletion_scheduled_at',
        'privacy_policy_version', 'privacy_accepted_at', 'password_changed_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'credential_version',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'last_login' => 'datetime',
        'deletion_scheduled_at' => 'datetime',
        'anonymized_at' => 'datetime',
        'privacy_accepted_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'is_active' => 'boolean',
        'role' => UserRole::class,
        'credential_version' => 'integer',
    ];

    /**
     * `users.role` is the single source of truth; the Spatie role used by the
     * `role:` middleware is derived from it so the two can never diverge.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->exists && $user->isDirty('password')) {
                $user->credential_version = (int) $user->getOriginal('credential_version') + 1;
            }
        });
        static::saved(function (User $user): void {
            if ($user->wasChanged('password')) {
                $user->invalidateAuthChallenges();
            }
            if ($user->role instanceof UserRole && ($user->wasRecentlyCreated || $user->wasChanged('role'))) {
                $user->syncSpatieRole();
            }
        });
    }

    public function syncSpatieRole(): void
    {
        if (! $this->role instanceof UserRole) {
            return;
        }

        Role::findOrCreate($this->role->value, $this->guard_name);
        $this->syncRoles([$this->role->value]);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class, 'user_id');
    }

    /** Revoke every access and refresh token (logout everywhere / compromise). */
    public function revokeAllTokens(): void
    {
        $version = DB::transaction(function (): int {
            $user = static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $user->forceFill(['credential_version' => (int) $user->credential_version + 1])->save();
            $user->invalidateAuthChallenges();
            $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $user->tokens()->delete();
            CredentialsRevoked::dispatch((string) $user->id);

            return (int) $user->credential_version;
        });
        $this->credential_version = $version;
        $this->syncOriginalAttribute('credential_version');
    }

    public function invalidateAuthChallenges(): void
    {
        DB::table('auth_login_challenges')->where('user_id', $this->id)->delete();
        app(OtpService::class)->invalidate($this, OtpService::PURPOSE_LOGIN_2FA);
        app(OtpService::class)->invalidate($this, OtpService::PURPOSE_PASSWORD_RESET);
    }

    public function patient(): HasOne
    {
        return $this->hasOne(Patient::class, 'user_id');
    }

    public function therapist(): HasOne
    {
        return $this->hasOne(Therapist::class, 'user_id');
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    public function supports(): HasMany
    {
        return $this->hasMany(Support::class, 'user_id');
    }

    public function assignedSupports(): HasMany
    {
        return $this->hasMany(Support::class, 'assigned_to');
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class, 'user_id');
    }

    public function reviewedDocuments(): HasMany
    {
        return $this->hasMany(DocumentRequest::class, 'reviewer_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }

    public function assignedRedFlags(): HasMany
    {
        return $this->hasMany(RedFlag::class, 'assigned_to');
    }

    public function isPatient(): bool
    {
        return $this->role === UserRole::PATIENT;
    }

    public function isTherapist(): bool
    {
        return $this->role === UserRole::THERAPIST;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [UserRole::ADMIN, UserRole::SUPER_ADMIN], true);
    }

    public function isVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /** IANA zone used to interpret and display this user's dates; storage stays UTC. */
    public function timezone(): string
    {
        $tz = (string) ($this->attributes['timezone'] ?? '');

        return $tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : (string) config('app.timezone', 'UTC');
    }
}
