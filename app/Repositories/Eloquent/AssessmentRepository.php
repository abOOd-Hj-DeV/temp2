<?php

// app/Repositories/Eloquent/AssessmentRepository.php

namespace App\Repositories\Eloquent;

use App\Models\Assessment;
use App\Repositories\Contracts\AssessmentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class AssessmentRepository implements AssessmentRepositoryInterface
{
    public function findById(string $id): ?Assessment
    {
        return Assessment::with('patient')->find($id);
    }

    public function findByPatientId(string $patientId): Collection
    {
        return Assessment::where('patient_id', $patientId)
            ->orderBy('completed_at', 'desc')
            ->get();
    }

    public function create(array $data): Assessment
    {
        return Assessment::create($data);
    }

    public function update(Assessment $assessment, array $data): bool
    {
        return $assessment->update($data);
    }

    public function delete(Assessment $assessment): bool
    {
        return $assessment->delete();
    }

    public function getLatestByPatientId(string $patientId, ?string $type = null): ?Assessment
    {
        $query = Assessment::where('patient_id', $patientId)
            ->orderBy('completed_at', 'desc');

        if ($type) {
            $query->where('type', $type);
        }

        return $query->first();
    }

    public function getPatientHistory(string $patientId, int $perPage = 10): LengthAwarePaginator
    {
        return Assessment::where('patient_id', $patientId)
            ->with('patient')
            ->orderBy('completed_at', 'desc')
            ->paginate($perPage);
    }

    public function getAverageScore(string $patientId, ?string $type = null): float
    {
        $query = Assessment::where('patient_id', $patientId);

        if ($type) {
            $query->where('type', $type);
        }

        return (float) $query->avg('score');
    }

    public function hasAssessmentToday(string $patientId, ?string $type = null): bool
    {
        $query = Assessment::where('patient_id', $patientId)
            ->whereDate('completed_at', today());

        if ($type) {
            $query->where('type', $type);
        }

        return $query->exists();
    }

    public function getCriticalAssessments(array $filters = []): Collection
    {
        $query = Assessment::with('patient')
            ->where(function ($q) {
                $q->where('score', '>=', 15); // عتبة الخطر
            });

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['from_date'])) {
            $query->where('completed_at', '>=', $filters['from_date']);
        }

        return $query->orderBy('score', 'desc')->get();
    }
}
