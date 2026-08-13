<?php
// app/Models/Assessment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
class Assessment extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'patient_id', 'type', 'score',  'completed_at' , 'answers'
    ];

    protected $casts = [
        'score' => 'integer',
        'completed_at' => 'datetime',
    ];
      public function setAnswersAttribute(array $answers): void
    {
        $encryptedAnswers = [];

        foreach ($answers as $key => $value) {
            // تشفير كل إجابة على حدة
            $encryptedAnswers[$key] = [
                'encrypted' => Crypt::encryptString((string) $value),
                'hash' => hash('sha256', (string) $value)
            ];
        }

        $this->attributes['answers'] = json_encode($encryptedAnswers);
    }

     public function getAnswersAttribute(?string $value): ?array
    {
        if (!$value) return null;

        try {
            $encryptedData = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            $decryptedAnswers = [];

            foreach ($encryptedData as $key => $encryptedItem) {
                $decryptedAnswers[$key] = (int) Crypt::decryptString($encryptedItem['encrypted']);
            }

            return $decryptedAnswers;
        } catch (\Exception $e) {
            \Log::error('Failed to decrypt assessment answers', [
                'assessment_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    public function getAnswer(string $questionKey): ?int
    {
        try {
            $encryptedData = json_decode($this->attributes['answers'] ?? '', true);

            if (!isset($encryptedData[$questionKey])) {
                return null;
            }

            return (int) Crypt::decryptString($encryptedData[$questionKey]['encrypted']);
        } catch (\Exception $e) {
            \Log::error('Failed to decrypt single answer', [
                'assessment_id' => $this->id,
                'question' => $questionKey,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
     public function verifyAnswer(string $questionKey, int $expectedValue): bool
    {
        $encryptedData = json_decode($this->attributes['answers'] ?? '', true);

        if (!isset($encryptedData[$questionKey])) {
            return false;
        }

        $expectedHash = hash('sha256', (string) $expectedValue);
        return hash_equals($encryptedData[$questionKey]['hash'], $expectedHash);
    }
     public function getEncryptedAnswersArray(): array
    {
        return json_decode($this->attributes['answers'] ?? '{}', true) ?: [];
    }
      public function verifyAllAnswers(array $answers): bool
    {
        $encryptedData = json_decode($this->attributes['answers'] ?? '', true);

        if (count($encryptedData) !== count($answers)) {
            return false;
        }

        foreach ($answers as $key => $value) {
            $expectedHash = hash('sha256', (string) $value);

            if (!isset($encryptedData[$key]) ||
                !hash_equals($encryptedData[$key]['hash'], $expectedHash)) {
                return false;
            }
        }

        return true;
    }

    /**
     * العلاقة مع المريض
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'user_id');
    }

    /**
     * تحديد إذا كانت النتيجة تتطلب إشعار عاجل
     */
    public function requiresUrgentAttention(): bool
    {
        $thresholds = [
            'phq9' => 15, // مثال: PHQ-9 فوق 15 يحتاج تدخل عاجل
            'gad7' => 15  // مثال: GAD-7 فوق 15 يحتاج تدخل عاجل
        ];

        return $this->score >= ($thresholds[$this->type] ?? 20);
    }
}
