<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminPatientService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staff view of the patient roster. Opening a patient record is audited.
 */
class PatientDirectoryController extends Controller
{
    public function __construct(
        private AdminPatientService $patients,
        private AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'therapist_id' => 'nullable|uuid',
            'unassigned' => 'nullable|boolean',
            'safety_flag' => 'nullable|boolean',
            'compliance_level' => 'nullable|in:high,medium,low',
            'is_active' => 'nullable|boolean',
        ]);

        $paginator = $this->patients->paginate($filters, $this->perPage($request));

        return response()->json([
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $patient = $this->patients->find($id) ?? throw new NotFoundHttpException('Patient not found.');

        $this->audit->record($request->user(), AuditLogService::PATIENT_RECORD_VIEWED, $patient->user_id);

        return response()->json(['data' => $this->patients->detail($patient)]);
    }
}
