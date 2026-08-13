<?php
// app/Services/ExportService.php

namespace App\Services\Patient;

use App\Models\Patient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ExportService
{
    /**
     * تصدير بيانات المريض (إصدار MVP مبسط)
     */
    public function exportPatientData(Patient $patient): array
    {
        try {
            // جمع البيانات الأساسية للمريض
            $exportData = [
                'patient_info' => $this->getPatientInfo($patient),
                'export_metadata' => [
                    'exported_at' => now()->toISOString(),
                    'format' => 'json',
                    'version' => '1.0'
                ]
            ];

            // في الإصدار الكامل، سنضيف:
            // - جلسات المريض
            // - سجلات المزاج
            // - التقييمات
            // - الوحدات التعليمية

            Log::info('Patient data export generated', [
                'patient_id' => $patient->user_id,
                'data_points' => count($exportData['patient_info'])
            ]);

            return $exportData;

        } catch (\Exception $e) {
            Log::error('Failed to export patient data', [
                'patient_id' => $patient->user_id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * الحصول على معلومات المريض
     */
    private function getPatientInfo(Patient $patient): array
    {
        return [
            'personal_information' => [
                'full_name' => $patient->full_name,
                'age' => $patient->age,
                'gender' => $patient->gender,
                'language' => $patient->language,
                'compliance_level' => $patient->compliance_level
            ],
            'clinical_information' => [
                'assessment_score' => $patient->assessment_score,
                'safety_flag' => (bool) $patient->safety_flag,
                'therapist_assigned' => !is_null($patient->therapist_id),
                'subscription_active' => !is_null($patient->subscription_id)
            ],
            'program_information' => [
                'joined_at' => $patient->created_at->toISOString(),
                'days_in_program' => $patient->created_at->diffInDays(now()),
                'profile_completion_percentage' => $this->calculateProfileCompletion($patient)
            ]
        ];
    }

    /**
     * حساب نسبة اكتمال البروفايل
     */
    private function calculateProfileCompletion(Patient $patient): int
    {
        $fields = [
            'full_name' => ['weight' => 25, 'check' => !empty($patient->full_name)],
            'age' => ['weight' => 25, 'check' => !empty($patient->age)],
            'gender' => ['weight' => 25, 'check' => !empty($patient->gender)],
            'language' => ['weight' => 25, 'check' => !empty($patient->language)],
        ];

        $completed = 0;
        foreach ($fields as $field) {
            if ($field['check']) {
                $completed += $field['weight'];
            }
        }

        return min($completed, 100);
    }

    /**
     * إنشاء رابط تحميل للبيانات المصدرة
     */
    public function generateDownloadUrl(array $exportData): string
    {
        try {
            // في الإصدار الحالي، نعيد رابطاً وهمياً
            // في الأسبوع العاشر، سننشئ ملفاً حقيقياً في S3

            $exportId = 'export_' . uniqid();

            // تخزين البيانات مؤقتاً (في الإصدار الكامل، سيتم تخزينها في S3)
            $tempPath = "exports/{$exportId}.json";
            $jsonData = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            // في الإصدار الحقيقي، نستخدم:
            // Storage::disk('s3')->put($tempPath, $jsonData);

            // للتجربة، نستخدم التخزين المحلي
            Storage::disk('local')->put($tempPath, $jsonData);

            Log::info('Export file created', [
                'export_id' => $exportId,
                'path' => $tempPath
            ]);

            // في الإنتاج، سيكون الرابط:
            // return Storage::disk('s3')->temporaryUrl($tempPath, now()->addDays(7));

            // للإصدار الحالي، نعيد رابطاً وهمياً
            return route('api.exports.download', ['id' => $exportId]);

        } catch (\Exception $e) {
            Log::error('Failed to generate download URL', [
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * تنزيل البيانات المصدرة
     */
    public function downloadExport(string $exportId)
    {
        $tempPath = "exports/{$exportId}.json";

        if (!Storage::disk('local')->exists($tempPath)) {
            throw new \Exception('Export file not found');
        }

        return Storage::disk('local')->download($tempPath, "patient_export_{$exportId}.json");
    }

    /**
     * تنظيف الملفات المؤقتة القديمة
     */
    public function cleanupOldExports(int $days = 7): void
    {
        $files = Storage::disk('local')->files('exports');

        foreach ($files as $file) {
            $lastModified = Storage::disk('local')->lastModified($file);
            $fileAge = now()->timestamp - $lastModified;

            if ($fileAge > ($days * 24 * 60 * 60)) {
                Storage::disk('local')->delete($file);
                Log::info('Old export file deleted', ['file' => $file]);
            }
        }
    }
}
