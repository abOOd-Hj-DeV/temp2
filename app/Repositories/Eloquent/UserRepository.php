<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class UserRepository implements UserRepositoryInterface
{
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

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function findActiveByEmail(string $email): ?User
    {
        return User::where('email', $email)
            ->where('is_active', true)
            ->whereNotNull('phone_verified_at')
            ->first();
    }

    public function findByWhatsapp(string $whatsappNumber): ?User
    {
        return User::where('whatsapp_number', $whatsappNumber)->first();
    }

    public function findActiveByWhatsapp(string $whatsappNumber): ?User
    {
        return User::where('whatsapp_number', $whatsappNumber)
            ->where('is_active', true)
            ->whereNotNull('phone_verified_at')
            ->first();
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
