<?php
// app/Repositories/Contracts/UserRepositoryInterface.php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface UserRepositoryInterface
{
    // Basic CRUD operations
    public function findById(string $id): ?User;
    public function create(array $data): User;
    public function update(User $user, array $data): bool;
    public function delete(User $user): bool;

    // Email-related queries
    public function findByEmail(string $email): ?User;
    public function findActiveByEmail(string $email): ?User;

    // WhatsApp-related queries
    public function findByWhatsapp(string $whatsappNumber): ?User;
    public function findActiveByWhatsapp(string $whatsappNumber): ?User;

    // Deprecated but kept for backward compatibility
    public function findInactiveByWhatsapp(string $whatsappNumber): ?User;

    // User management
    public function updateLastLogin(User $user): void;

    // Role-based queries
    public function findByRole(string $role): Collection;
    public function findByRolePaginated(string $role, int $perPage = 15): LengthAwarePaginator;
}
