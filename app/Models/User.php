<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, HasUUID, Notifiable;

    protected string $guard_name = 'api';

    protected $fillable = [
        'name', 'email', 'password', 'role',
        'whatsapp_number', 'is_active', 'last_login',
        'phone_verified_at', 'login_attempts', 'deletion_scheduled_at',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'last_login' => 'datetime',
        'deletion_scheduled_at' => 'datetime',
        'anonymized_at' => 'datetime',
        'is_active' => 'boolean',
        'role' => UserRole::class,
    ];

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
}
