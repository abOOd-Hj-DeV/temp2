<?php
// app/Repositories/Contracts/AssessmentRepositoryInterface.php

namespace App\Repositories\Contracts;

use App\Models\Assessment;
use Illuminate\Database\Eloquent\Collection;

interface AssessmentRepositoryInterface
{
    /**
     * البحث عن تقييم بواسطة ID
     */
    public function findById(string $id): ?Assessment;

    /**
     * البحث عن تقييمات المريض
     */
    public function findByPatientId(string $patientId): Collection;

    /**
     * إنشاء تقييم جديد
     */
    public function create(array $data): Assessment;

    /**
     * تحديث تقييم
     */
    public function update(Assessment $assessment, array $data): bool;

    /**
     * حذف تقييم
     */
    public function delete(Assessment $assessment): bool;

    /**
     * الحصول على آخر تقييم للمريض
     */
    public function getLatestByPatientId(string $patientId, string $type = null): ?Assessment;

    /**
     * الحصول على سجل التقييمات مع التقسيم (pagination)
     */
    public function getPatientHistory(string $patientId, int $perPage = 10): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    /**
     * الحصول على متوسط تقييمات المريض
     */
    public function getAverageScore(string $patientId, string $type = null): float;

    /**
     * التحقق إذا كان المريض أجرى تقييم اليوم
     */
    public function hasAssessmentToday(string $patientId, string $type = null): bool;

    /**
     * الحصول على التقييمات الحرجة (Red Flags)
     */
    public function getCriticalAssessments(array $filters = []): Collection;
}
