<?php

// app/Repositories/Contracts/RedFlagRepositoryInterface.php

namespace App\Repositories\Contracts;

use App\Models\RedFlag;
use Illuminate\Database\Eloquent\Collection;

interface RedFlagRepositoryInterface
{
    /**
     * البحث عن Red Flag بواسطة ID
     */
    public function findById(string $id): ?RedFlag;

    /**
     * البحث عن Red Flags للمريض
     */
    public function findByPatientId(string $patientId): Collection;

    /**
     * إنشاء Red Flag جديد
     */
    public function create(array $data): RedFlag;

    /**
     * تحديث Red Flag
     */
    public function update(RedFlag $redFlag, array $data): bool;

    /**
     * حذف Red Flag
     */
    public function delete(RedFlag $redFlag): bool;

    /**
     * الحصول على آخر Red Flag للمريض
     */
    public function getLatestByPatientId(string $patientId): ?RedFlag;

    /**
     * الحصول على Red Flags المفتوحة
     */
    public function getOpenRedFlags(array $filters = []): Collection;

    /**
     * تحديث حالة Red Flag
     */
    public function updateStatus(string $id, string $status, ?string $actionTaken = null): bool;

    /**
     * تعيين Red Flag لمستخدم
     */
    public function assignTo(string $id, string $userId): bool;

    /**
     * الحصول على إحصائيات Red Flags
     */
    public function getStats(array $filters = []): array;
}
