<?php

namespace App\Repositories\Eloquent;

use App\Enums\ApprovalStatus;
use App\Models\Therapist;
use App\Repositories\Contracts\TherapistRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class TherapistRepository implements TherapistRepositoryInterface
{
    public function findByUserId(string $userId): ?Therapist
    {
        return Therapist::where('user_id', $userId)->first();
    }

    public function create(array $data): Therapist
    {
        return Therapist::create($data);
    }

    public function update(Therapist $therapist, array $data): bool
    {
        return $therapist->update($data);
    }

    public function listApproved(array $filters, int $perPage): LengthAwarePaginator
    {
        return Therapist::query()
            ->where('approval_status', ApprovalStatus::APPROVED->value)
            ->when($filters['specialty'] ?? null, fn ($q, $v) => $q->where('specialty', $v))
            ->when($filters['country'] ?? null, fn ($q, $v) => $q->where('country', $v))
            ->when($filters['language'] ?? null, fn ($q, $v) => $q->whereJsonContains('languages', $v))
            ->when(
                ($filters['accepting_clients'] ?? false) === true,
                fn ($q) => $q->whereColumn('clients_count', '<', 'clients_limit')
            )
            ->orderByDesc('rating')
            ->paginate($perPage);
    }

    public function listByApprovalStatus(string $status, int $perPage): LengthAwarePaginator
    {
        return Therapist::where('approval_status', $status)
            ->orderBy('created_at')
            ->paginate($perPage);
    }
}
