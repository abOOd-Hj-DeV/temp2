<?php
// app/Models/User.php

namespace App\Models;

use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Traits\HasUUID;
use App\Enums\UserRole;
use Laravel\Passport\Contracts\OAuthenticatable;
use Spatie\Permission\Traits\HasRoles;
class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUUID ,HasRoles;
    protected string $guard_name = 'api';
    protected $fillable = [
        'id', 'name', 'email', 'password', 'role',
        'whatsapp_number', 'is_active', 'last_login' ,
        'phone_verified_at', 'login_attempts',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login' => 'datetime',
        'is_active' => 'boolean',
        'role' => UserRole::class,
    ];

    // العلاقات
    public function patient()
    {
        return $this->hasOne(Patient::class, 'user_id');
    }

    public function therapist()
    {
        return $this->hasOne(Therapist::class, 'user_id');
    }

    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages()
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    public function supports()
    {
        return $this->hasMany(Support::class, 'user_id');
    }

    public function assignedSupports()
    {
        return $this->hasMany(Support::class, 'assigned_to');
    }

    public function documentRequests()
    {
        return $this->hasMany(DocumentRequest::class, 'user_id');
    }

    public function reviewedDocuments()
    {
        return $this->hasMany(DocumentRequest::class, 'reviewer_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }

    // Helper Methods
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
        return in_array($this->role, [UserRole::ADMIN, UserRole::SUPER_ADMIN]);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function markAsLoggedIn(): void
    {
        $this->update(['last_login' => now()]);
    }
}
