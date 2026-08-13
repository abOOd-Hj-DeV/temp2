<?php
// app/Repositories/Eloquent/UserRepository.php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class UserRepository implements UserRepositoryInterface
{
    /**
     * Find user by WhatsApp number (any status)
     */
    public function findByWhatsapp(string $whatsappNumber): ?User
    {
        return User::where('whatsapp_number', $whatsappNumber)->first();
    }

    /**
     * Find ACTIVE user by WhatsApp number
     */
    public function findActiveByWhatsapp(string $whatsappNumber): ?User
    {
        return User::where('whatsapp_number', $whatsappNumber)
            ->where('is_active', true)
            ->whereNotNull('phone_verified_at')
            ->first();
    }

    /**
     * Find user by email (any status)
     */
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    /**
     * Find active user by email
     */
    public function findActiveByEmail(string $email): ?User
    {
        return User::where('email', $email)
            ->where('is_active', true)
            ->whereNotNull('phone_verified_at')
            ->first();
    }

    /**
     * DEPRECATED: This method is no longer needed as we use findByWhatsapp with status check
     * Keep for backward compatibility
     */
    public function findInactiveByWhatsapp(string $whatsappNumber): ?User
    {
        return User::where('whatsapp_number', $whatsappNumber)
            ->where('is_active', false)
            ->whereNull('phone_verified_at')
            ->first();
    }

    // ... باقي الدوال تبقى كما هي
    public function findById(string $id): ?User
    {
        return User::find($id);
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, array $data): bool
    {
        return $user->update($data);
    }

    public function delete(User $user): bool
    {
        return $user->delete();
    }

    public function updateLastLogin(User $user): void
    {
        $user->update(['last_login' => now()]);
    }

    public function findByRole(string $role): Collection
    {
        return User::where('role', $role)->get();
    }

    public function findByRolePaginated(string $role, int $perPage = 15): LengthAwarePaginator
    {
        return User::where('role', $role)->paginate($perPage);
    }
}
