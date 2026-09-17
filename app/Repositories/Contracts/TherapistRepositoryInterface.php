<?php

namespace App\Repositories\Contracts;

use App\Models\Therapist;
use Illuminate\Pagination\LengthAwarePaginator;

interface TherapistRepositoryInterface
{
    public function findByUserId(string $userId): ?Therapist;

    public function create(array $data): Therapist;

    public function update(Therapist $therapist, array $data): bool;

    /**
     * Paginated list of approved therapists, with optional filters.
     * Filters: specialty, country, language, accepting_clients (bool).
     */
    public function listApproved(array $filters, int $perPage): LengthAwarePaginator;

    public function listByApprovalStatus(string $status, int $perPage): LengthAwarePaginator;
}
