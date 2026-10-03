<?php

namespace App\Services\Patient;

use App\Models;
use App\Models\Payment;
use App\Models\RedFlag;
use App\Models\User;
use App\Repositories\Contracts\AssessmentRepositoryInterface;
use App\Repositories\Contracts\PatientRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\AuditLogService;
use App\Services\Files\AccountFileFence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Account lifecycle: scheduled deletion (30-day grace) and GDPR-style
 * self-service data export.
 */
class PatientAccountService
{
    private const DELETION_GRACE_DAYS = 30;

    public function __construct(
        private UserRepositoryInterface $users,
        private PatientRepositoryInterface $patients,
        private AssessmentRepositoryInterface $assessments,
        private AuditLogService $audit,
    ) {}

    /**
     * Revoke every token now; PruneScheduledDeletionsJob anonymises the
     * account after the grace period. The account stays "active" so that
     * logging back in within the window cancels the request.
     */
    public function requestDeletion(User $user): array
    {
        $scheduledAt = now()->addDays(self::DELETION_GRACE_DAYS);

        DB::transaction(function () use ($user, $scheduledAt) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            AccountFileFence::lock([$user->id]);
            if (! $this->users->update($user, ['deletion_scheduled_at' => $scheduledAt])) {
                throw new \RuntimeException('Account deletion could not be scheduled.');
            }

            // Deactivate every live session token immediately.
            $user->revokeAllTokens();
            $this->audit->record($user, AuditLogService::ACCOUNT_DELETION_REQUESTED, $user->id, [
                'scheduled_at' => $scheduledAt->toISOString(),
            ]);
        });
        Log::info('Account deletion scheduled', ['user_id' => $user->id]);

        return [
            'message' => __('Account deletion scheduled. Your personal data will be anonymised after :days days unless you log back in.', [
                'days' => self::DELETION_GRACE_DAYS,
            ]),
            'deletion_scheduled_at' => $scheduledAt->toISOString(),
        ];
    }

    public function exportData(User $user): array
    {
        $patient = $this->patients->findByUserId($user->id);

        return [
            'exported_at' => now()->toISOString(),
            'schema_version' => 2,
            'export_scope' => [
                'files' => 'Storage references and metadata, not embedded binary content; existing download authorization still applies.',
                'excluded' => [
                    'therapist_client_notes' => 'Private clinician notes are not patient-visible in the existing API; access/retention requires policy review.',
                    'parallel_layers' => 'Private therapist working documents; patient export access requires policy review.',
                    'audit_logs' => 'Append-only security records may include third-party information; legal access/retention requires review.',
                    'credentials_and_tokens' => 'Authentication secrets and replay/cache internals are not exported.',
                ],
            ],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'whatsapp_number' => $user->whatsapp_number,
                'role' => $user->role->value,
                'created_at' => $user->created_at?->toISOString(),
            ],
            'patient_profile' => $patient ? [
                'full_name' => $patient->full_name,
                'age' => $patient->age,
                'gender' => $patient->gender,
                'language' => $patient->language,
                'assessment_score' => $patient->assessment_score,
                'compliance_level' => $patient->compliance_level,
            ] : null,
            'assessments' => $patient
                ? $this->assessments->findByPatientId($patient->user_id)->map(fn ($a) => [
                    'id' => $a->id,
                    'type' => $a->type->value,
                    'score' => $a->score,
                    'answers' => $a->answers,
                    'completed_at' => $a->completed_at?->toISOString(),
                ])->all()
                : [],
            'sessions' => $patient
                ? $patient->sessions()->get()->map(fn ($s) => [
                    'id' => $s->id,
                    'therapist_id' => $s->therapist_id,
                    'session_date' => $s->session_date?->toDateString(),
                    'session_time' => $s->session_time ? substr((string) $s->session_time, 0, 5) : null,
                    'medium' => $s->medium,
                    'status' => $s->status,
                    'payment_status' => $s->payment_status,
                    'price' => $s->price,
                    'summary' => $s->summary,
                ])->all()
                : [],
            'mood_logs' => $patient
                ? $patient->moodLogs()->orderBy('log_date')->get()->map(fn ($m) => [
                    'log_date' => $m->log_date?->toDateString(),
                    'score' => $m->score,
                    'notes' => $m->notes,
                    'id' => $m->id,
                    'anxiety' => $m->anxiety,
                    'energy' => $m->energy,
                    'sleep_hours' => $m->sleep_hours,
                    'activity_level' => $m->activity_level,
                    'alert_sent' => $m->alert_sent,
                ])->all()
                : [],
            'red_flags' => $patient
                ? RedFlag::where('patient_id', $patient->user_id)->orderBy('created_at')->get()->map(fn ($f) => [
                    'id' => $f->id,
                    'assessment_id' => $f->assessment_id,
                    'type' => $f->type?->value,
                    'priority' => $f->priority?->value,
                    'status' => $f->status,
                    'description' => $f->description,
                    'action_taken' => $f->action_taken,
                    'previous_flag_id' => $f->previous_flag_id,
                    'created_at' => $f->created_at?->toISOString(),
                    'updated_at' => $f->updated_at?->toISOString(),
                ])->all()
                : [],
            'subscriptions' => $patient
                ? $patient->subscriptions()->get()->map(fn ($s) => [
                    'id' => $s->id,
                    'type' => $s->type,
                    'package_id' => $s->package_id,
                    'therapist_id' => $s->therapist_id,
                    'content' => $s->content,
                    'cancelled_at' => $s->cancelled_at?->toISOString(),
                    'cancellation_reason' => $s->cancellation_reason,
                    'payment_proof_path' => $s->payment_proof_path,
                    'start_date' => $s->start_date?->toDateString(),
                    'end_date' => $s->end_date?->toDateString(),
                    'price' => $s->price,
                    'verification_status' => $s->verification_status,
                ])->all()
                : [],
            'payments' => $patient
                ? Payment::query()
                    ->where(fn ($q) => $q
                        ->whereIn('subscription_id', $patient->subscriptions()->select('id'))
                        ->orWhereIn('therapy_session_id', $patient->sessions()->select('id')))
                    ->get()
                    ->map(fn ($p) => [
                        'id' => $p->id,
                        'subscription_id' => $p->subscription_id,
                        'therapy_session_id' => $p->therapy_session_id,
                        'amount' => $p->amount,
                        'status' => $p->status,
                        'note' => $p->note,
                        'proof_file_path' => $p->proof_file_path,
                        'reviewed_at' => $p->reviewed_at?->toISOString(),
                        'created_at' => $p->created_at?->toISOString(),
                    ])->all()
                : [],
            'notifications' => $user->notifications()->get()->map(fn ($n) => [
                'id' => $n->id,
                'type' => class_basename($n->type),
                'data' => $n->data,
                'read_at' => $n->read_at?->toISOString(),
                'created_at' => $n->created_at?->toISOString(),
            ])->all(),
            ...$this->additionalData($user),
        ];
    }

    private function additionalData(User $user): array
    {
        $id = $user->id;
        $files = [];
        if (! $user->anonymized_at && $user->is_active) {
            $disk = Storage::disk(config('sakina.uploads_disk', 'local'));
            foreach (["uploads/{$id}", "payment-proofs/{$id}"] as $directory) {
                foreach ($disk->allFiles($directory) as $path) {
                    $files[] = ['path' => $path, 'size' => $disk->size($path)];
                }
            }
        }

        return [
            'patient_modules' => Models\PatientModule::where('patient_id', $id)->get()->map(fn ($row) => $row->only([
                'id', 'module_id', 'status', 'completed_at', 'homework', 'homework_submitted_at', 'hidden_at', 'created_at', 'updated_at',
            ]))->all(),
            'safety_plans' => Models\SafetyPlan::where('patient_id', $id)->get()->map(fn ($row) => $row->only([
                'id', 'contact_info', 'coping_strategies', 'emergency_contacts', 'warning_signs', 'created_at', 'updated_at',
            ]))->all(),
            'support_tickets' => Models\Support::where('user_id', $id)->with('replies')->get()->map(fn ($row) => [
                ...$row->only(['id', 'type', 'subject', 'description', 'status', 'file_path', 'created_at', 'updated_at']),
                'replies' => $row->replies->map(fn ($reply) => $reply->only(['id', 'user_id', 'is_staff', 'body', 'created_at']))->all(),
            ])->all(),
            'document_requests' => Models\DocumentRequest::where('user_id', $id)->get()->map(fn ($row) => $row->only([
                'id', 'doc_type', 'file_path', 'original_name', 'mime_type', 'reason', 'review_note', 'status', 'submitted_at', 'reviewed_at',
            ]))->all(),
            'therapist_switches' => Models\TherapistSwitch::where('patient_id', $id)->get()->map(fn ($row) => $row->only([
                'id', 'old_therapist_id', 'new_therapist_id', 'reason', 'status', 'timestamp', 'therapist_decision', 'cancelled_session_ids',
            ]))->all(),
            'session_recommendations' => Models\SessionRecommendation::where('patient_id', $id)->get()->map(fn ($row) => $row->only([
                'id', 'session_id', 'package_id', 'program_id', 'note', 'revision', 'created_at', 'updated_at',
            ]))->all(),
            'reviews' => Models\Review::where('patient_id', $id)->get()->map(fn ($row) => $row->only([
                'id', 'therapist_id', 'rating', 'comment', 'created_at', 'updated_at',
            ]))->all(),
            'therapist_contents' => Models\TherapistContent::where(function ($q) use ($id) {
                $q->where('patient_id', $id)->orWhereHas('assignedPatients', fn ($p) => $p->where('patients.user_id', $id));
            })->get()->map(fn ($row) => $row->only(['id', 'therapist_id', 'title', 'content_type', 'body', 'url', 'created_at', 'updated_at']))->all(),
            'conversations' => Models\Conversation::where('patient_id', $id)->with('messages')->get()->map(fn ($row) => [
                ...$row->only(['id', 'therapist_id', 'status', 'last_message_at', 'closed_at', 'created_at']),
                'messages' => $row->messages->map(fn ($message) => $message->only([
                    'id', 'sender_id', 'receiver_id', 'content', 'file_path', 'attachment_type', 'attachment_name', 'attachment_mime', 'attachment_size', 'timestamp', 'is_read', 'read_at',
                ]))->all(),
            ])->all(),
            'legacy_messages' => Models\Message::whereNull('conversation_id')->where(function ($q) use ($id) {
                $q->where('sender_id', $id)->orWhere('receiver_id', $id);
            })->get()->map(fn ($message) => $message->only([
                'id', 'sender_id', 'receiver_id', 'content', 'file_path', 'attachment_type', 'attachment_name',
                'attachment_mime', 'attachment_size', 'timestamp', 'is_read', 'read_at',
            ]))->all(),
            'files' => $files,
        ];
    }
}
