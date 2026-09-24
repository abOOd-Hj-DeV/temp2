<?php

namespace Tests\Feature;

use App\Models\DocumentRequest;
use App\Models\Therapist;
use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $therapistUser;

    private User $otherTherapistUser;

    private User $patientUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);
        Storage::fake(config('sakina.uploads_disk', 'local'));

        $this->admin = $this->makeUser('admin', '+963900000850');
        $this->therapistUser = $this->makeUser('therapist', '+963900000851');
        $this->makeTherapist($this->therapistUser, 'pending');
        $this->otherTherapistUser = $this->makeUser('therapist', '+963900000852');
        $this->makeTherapist($this->otherTherapistUser, 'approved');
        $this->patientUser = $this->makeUser('patient', '+963900000853');
    }

    private function makeUser(string $role, string $whatsapp): User
    {
        $user = User::create([
            'name' => "Test {$role} {$whatsapp}", 'email' => "{$role}.{$whatsapp}@example.com",
            'password' => 'x', 'whatsapp_number' => $whatsapp, 'role' => $role,
            'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeTherapist(User $user, string $status): Therapist
    {
        return Therapist::create([
            'user_id' => $user->id, 'full_name' => "Dr {$user->whatsapp_number}", 'specialty' => 'cbt', 'country' => 'JO',
            'availability' => [], 'approval_status' => $status,
        ]);
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, ['*'], 'api');
    }

    private function requestDocument(string $docType = 'license'): string
    {
        $this->actAs($this->admin);

        return $this->postJson('/api/v1/admin/documents', [
            'user_id' => $this->therapistUser->id, 'doc_type' => $docType, 'reason' => 'Licence copy expired.',
        ])->assertCreated()->assertJsonPath('data.status', 'requested')->json('data.id');
    }

    public function test_full_workflow_request_upload_reject_reupload_approve(): void
    {
        $id = $this->requestDocument();

        $this->assertDatabaseHas('audit_logs', ['action' => 'document.requested', 'entity_id' => $id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->therapistUser->id]);

        // Duplicate open request for the same type is rejected; other type is fine.
        $this->postJson('/api/v1/admin/documents', ['user_id' => $this->therapistUser->id, 'doc_type' => 'license'])->assertStatus(409);
        $this->postJson('/api/v1/admin/documents', ['user_id' => $this->therapistUser->id, 'doc_type' => 'bogus'])->assertStatus(422);
        $this->postJson('/api/v1/admin/documents', ['user_id' => $this->patientUser->id, 'doc_type' => 'license'])
            ->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        // Reviewing before any upload is a conflict.
        $this->postJson("/api/v1/admin/documents/{$id}/review", ['action' => 'approve'])->assertStatus(409);

        // The therapist (still pending approval) sees and uploads the document.
        $this->actAs($this->therapistUser);
        $list = $this->getJson('/api/v1/therapists/me/documents')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertTrue($list[0]['accepts_upload']);

        $this->postJson("/api/v1/therapists/me/documents/{$id}/upload", ['file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])
            ->assertStatus(422);

        $first = $this->postJson("/api/v1/therapists/me/documents/{$id}/upload", [
            'file' => UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf'),
        ])->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.has_file', true)->json('data');
        $firstPath = DocumentRequest::findOrFail($id)->file_path;
        $this->assertStringStartsWith("uploads/{$this->therapistUser->id}/document_requests/", $firstPath);
        Storage::disk(config('sakina.uploads_disk', 'local'))->assertExists($firstPath);
        $this->assertFalse($first['accepts_upload']);

        // While submitted, a second upload is refused.
        $this->postJson("/api/v1/therapists/me/documents/{$id}/upload", [
            'file' => UploadedFile::fake()->create('again.pdf', 100, 'application/pdf'),
        ])->assertStatus(409);

        // Therapist can download their own file through the secure file endpoint.
        $this->getJson('/api/v1/files/download/'.$firstPath)->assertOk();

        // Admin rejects — a note is mandatory.
        $this->actAs($this->admin);
        $this->postJson("/api/v1/admin/documents/{$id}/review", ['action' => 'reject'])->assertStatus(422)->assertJsonValidationErrors(['note']);
        $this->postJson("/api/v1/admin/documents/{$id}/review", ['action' => 'reject', 'note' => 'Blurry scan.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.review_note', 'Blurry scan.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.rejected', 'entity_id' => $id]);
        $this->getJson('/api/v1/files/download/'.$firstPath)->assertOk();

        // Therapist re-uploads: old file removed, review fields reset.
        $this->actAs($this->therapistUser);
        $this->postJson("/api/v1/therapists/me/documents/{$id}/upload", [
            'file' => UploadedFile::fake()->create('licence-v2.pdf', 100, 'application/pdf'),
        ])->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.review_note', null);
        $doc = DocumentRequest::findOrFail($id);
        $this->assertNotSame($firstPath, $doc->file_path);
        Storage::disk(config('sakina.uploads_disk', 'local'))->assertMissing($firstPath);
        $this->assertNull($doc->reviewer_id);

        // Admin approves; list shows it filtered by status.
        $this->actAs($this->admin);
        $this->postJson("/api/v1/admin/documents/{$id}/review", ['action' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.reviewer_id', $this->admin->id);
        $this->postJson("/api/v1/admin/documents/{$id}/review", ['action' => 'approve'])->assertStatus(409);
        $this->getJson('/api/v1/admin/documents?status=approved')->assertOk()->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.user_id', $this->therapistUser->id);
        $this->getJson('/api/v1/admin/documents?status=submitted')->assertOk()->assertJsonPath('pagination.total', 0);

        // Approved → therapist can no longer upload.
        $this->actAs($this->therapistUser);
        $this->postJson("/api/v1/therapists/me/documents/{$id}/upload", [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertStatus(409);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->admin->id]);
    }

    public function test_ownership_and_role_boundaries(): void
    {
        $id = $this->requestDocument('identity');

        // Another therapist cannot see or upload to someone else's request.
        $this->actAs($this->otherTherapistUser);
        $this->getJson("/api/v1/therapists/me/documents/{$id}")->assertNotFound();
        $this->postJson("/api/v1/therapists/me/documents/{$id}/upload", [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertNotFound();
        $this->assertCount(0, $this->getJson('/api/v1/therapists/me/documents')->assertOk()->json('data'));

        // Patients and therapists have no admin access; finance partner is not staff here.
        $this->actAs($this->patientUser);
        $this->getJson('/api/v1/admin/documents')->assertForbidden();
        $this->getJson('/api/v1/therapists/me/documents')->assertForbidden();

        $this->actAs($this->therapistUser);
        $this->getJson('/api/v1/admin/documents')->assertForbidden();
        $this->postJson("/api/v1/admin/documents/{$id}/review", ['action' => 'approve'])->assertForbidden();

        $finance = $this->makeUser('finance_partner', '+963900000854');
        $this->actAs($finance);
        $this->getJson('/api/v1/admin/documents')->assertForbidden();

        // Once uploaded, another therapist cannot download the file.
        $this->actAs($this->therapistUser);
        $this->postJson("/api/v1/therapists/me/documents/{$id}/upload", [
            'file' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'),
        ])->assertOk();
        $path = DocumentRequest::findOrFail($id)->file_path;

        $this->actAs($this->otherTherapistUser);
        $this->getJson('/api/v1/files/download/'.$path)->assertNotFound();

        $this->actAs($this->admin);
        $this->getJson('/api/v1/files/download/'.$path)->assertOk();
    }
}
