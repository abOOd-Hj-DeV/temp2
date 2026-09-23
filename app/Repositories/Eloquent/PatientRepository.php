<?php

// app/Repositories/Eloquent/PatientRepository.php

namespace App\Repositories\Eloquent;

use App\Models\Patient;
use App\Repositories\Contracts\PatientRepositoryInterface;

class PatientRepository implements PatientRepositoryInterface
{
    /**
     * إيجاد المريض بواسطة ID
     */
    public function findById(string $id): ?Patient
    {
        return Patient::find($id);
    }

    /**
     * إيجاد المريض بواسطة user_id
     */
    public function findByUserId(string $userId): ?Patient
    {
        return Patient::where('user_id', $userId)->first();
    }

    /**
     * إنشاء مريض جديد
     */
    public function create(array $data): Patient
    {
        return Patient::create($data);
    }

    /**
     * تحديث بيانات المريض
     */
    public function update(Patient $patient, array $data): bool
    {
        return $patient->update($data);
    }

    /**
     * حذف المريض
     */
    public function delete(Patient $patient): bool
    {
        return $patient->delete();
    }

    /**
     * الحصول على المريض مع تفاصيله
     */
    public function getPatientWithDetails(string $userId): ?Patient
    {
        return Patient::with(['user'])
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * تحديث بروفايل المريض
     */
    public function updatePatientProfile(string $userId, array $data): bool
    {
        return Patient::where('user_id', $userId)->update($data);
    }

    /**
     * التحقق من وجود بروفايل للمريض
     */
    public function profileExists(string $userId): bool
    {
        return Patient::where('user_id', $userId)->exists();
    }

    /**
     * الحصول على المعلومات الأساسية للمريض
     */
    public function getBasicInfo(string $userId): array
    {
        $patient = Patient::where('user_id', $userId)
            ->select(['full_name', 'age', 'gender', 'language'])
            ->first();

        return $patient ? $patient->toArray() : [];
    }

    /**
     * تحديث العلم الآمن (Safety Flag)
     */
    public function updateSafetyFlag(string $userId, bool $flag): bool
    {
        return Patient::where('user_id', $userId)
            ->update(['safety_flag' => $flag]);
    }

    /**
     * تحديث درجة التقييم
     */
    public function updateAssessmentScore(string $userId, int $score): bool
    {
        return Patient::where('user_id', $userId)
            ->update(['assessment_score' => $score]);
    }

    /**
     * تحديث مستوى الالتزام
     */
    public function updateComplianceLevel(string $userId, string $level): bool
    {
        return Patient::where('user_id', $userId)
            ->update(['compliance_level' => $level]);
    }

    /**
     * تحديث تعيين المعالج
     */
    public function updateTherapist(string $userId, ?string $therapistId): bool
    {
        return Patient::where('user_id', $userId)
            ->update(['therapist_id' => $therapistId]);
    }

    /**
     * تحديث الاشتراك
     */
    public function updateSubscription(string $userId, ?string $subscriptionId): bool
    {
        return Patient::where('user_id', $userId)
            ->update(['subscription_id' => $subscriptionId]);
    }

    /**
     * الحصول على إحصائيات المريض
     */
    public function getPatientStats(string $userId): array
    {
        $patient = $this->findByUserId($userId);

        if (! $patient) {
            return [];
        }

        return [
            'profile_completion' => $this->calculateProfileCompletion($patient),
            'days_since_joined' => $patient->created_at->diffInDays(now()),
            'has_therapist' => ! is_null($patient->therapist_id),
            'has_subscription' => ! is_null($patient->subscription_id),
            'assessment_completed' => ! is_null($patient->assessment_score),
            'safety_flagged' => (bool) $patient->safety_flag,
            'compliance_level' => $patient->compliance_level ?? 'not_set',
            'last_activity' => $patient->updated_at->diffForHumans(),
        ];
    }

    /**
     * حساب اكتمال البروفايل (داخلية)
     */
    private function calculateProfileCompletion(Patient $patient): int
    {
        $fields = [
            'full_name' => 25,
            'age' => 25,
            'gender' => 25,
            'language' => 25,
        ];

        $completed = 0;
        foreach ($fields as $field => $weight) {
            if (! empty($patient->$field)) {
                $completed += $weight;
            }
        }

        return $completed;
    }
}
