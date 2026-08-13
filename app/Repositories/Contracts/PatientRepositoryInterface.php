<?php
// app/Repositories/Contracts/PatientRepositoryInterface.php

namespace App\Repositories\Contracts;

use App\Models\Patient;

interface PatientRepositoryInterface
{
    public function findById(string $id): ?Patient;
    public function findByUserId(string $userId): ?Patient;
    public function create(array $data): Patient;
    public function update(Patient $patient, array $data): bool;
    public function delete(Patient $patient): bool;

    public function getPatientWithDetails(string $userId): ?Patient;
    public function updatePatientProfile(string $userId, array $data): bool;
    public function profileExists(string $userId): bool;
    public function getBasicInfo(string $userId): array;

    public function updateSafetyFlag(string $userId, bool $flag): bool;
    public function updateAssessmentScore(string $userId, int $score): bool;
    public function updateComplianceLevel(string $userId, string $level): bool;
    public function updateTherapist(string $userId, ?string $therapistId): bool;
    public function updateSubscription(string $userId, ?string $subscriptionId): bool;

    public function getPatientStats(string $userId): array;
}
