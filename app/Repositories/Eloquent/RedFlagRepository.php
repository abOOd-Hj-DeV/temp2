<?php

// app/Repositories/Eloquent/RedFlagRepository.php

namespace App\Repositories\Eloquent;

use App\Models\RedFlag;
use App\Repositories\Contracts\RedFlagRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RedFlagRepository implements RedFlagRepositoryInterface
{
    public function findById(string $id): ?RedFlag
    {
        return RedFlag::with(['patient', 'assessment'])->find($id);
    }

    public function findByPatientId(string $patientId): Collection
    {
        return RedFlag::with('assessment')
            ->where('patient_id', $patientId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function create(array $data): RedFlag
    {
        return RedFlag::create($data);
    }

    public function update(RedFlag $redFlag, array $data): bool
    {
        return $redFlag->update($data);
    }

    public function delete(RedFlag $redFlag): bool
    {
        return $redFlag->delete();
    }

    public function getLatestByPatientId(string $patientId): ?RedFlag
    {
        return RedFlag::where('patient_id', $patientId)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    public function getOpenRedFlags(array $filters = []): Collection
    {
        $query = RedFlag::with(['patient', 'assessment'])
            ->where('status', 'open');

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function updateStatus(string $id, string $status, ?string $actionTaken = null): bool
    {
        $updateData = ['status' => $status];

        if ($actionTaken !== null) {
            $updateData['action_taken'] = $actionTaken;
            $updateData['updated_at'] = now();
        }

        return RedFlag::where('id', $id)->update($updateData);
    }

    public function assignTo(string $id, string $userId): bool
    {
        return RedFlag::where('id', $id)->update([
            'assigned_to' => $userId,
            'updated_at' => now(),
        ]);
    }

    public function getStats(array $filters = []): array
    {
        $query = RedFlag::query();

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        return [
            'total' => $query->count(),
            'open' => $query->where('status', 'open')->count(),
            'resolved' => $query->where('status', 'resolved')->count(),
            'high_priority' => $query->where('priority', 'high')->count(),
            'by_type' => $query->select('type', DB::raw('count(*) as count'))
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
        ];
    }
}
