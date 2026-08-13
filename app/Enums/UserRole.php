<?php
// app/Enums/UserRole.php

namespace App\Enums;

enum UserRole: string
{
    case PATIENT = 'patient';
    case THERAPIST = 'therapist';
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case CLINICAL_SUPERVISOR = 'clinical_supervisor';
    case FINANCE_PARTNER = 'finance_partner';
    case SUPPORT_AGENT = 'support_agent';
    case CONTENT_MANAGER = 'content_manager';

    public function label(): string
    {
        return match($this) {
            self::PATIENT => 'Patient',
            self::THERAPIST => 'Therapist',
            self::SUPER_ADMIN => 'Super Admin',
            self::ADMIN => 'Admin',
            self::CLINICAL_SUPERVISOR => 'Clinical Supervisor',
            self::FINANCE_PARTNER => 'Finance Partner',
            self::SUPPORT_AGENT => 'Support Agent',
            self::CONTENT_MANAGER => 'Content Manager',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
