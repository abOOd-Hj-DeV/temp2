<?php

namespace Tests\Feature;

use App\Jobs\EscalateStaleRedFlagsJob;
use App\Jobs\PruneScheduledDeletionsJob;
use App\Jobs\SendSessionRemindersJob;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\RedFlag;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapistSwitch;
use App\Models\TherapySession;
use App\Models\User;
use App\Notifications\RedFlagEscalatedNotification;
use App\Notifications\RedFlagRaisedNotification;
use App\Services\Messaging\WhatsAppSenderInterface;
use App\Services\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Weeks1To7HardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private Patient $patient;

    private User $therapistUser;

    private Therapist $therapist;

    private User $admin;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);
        Storage::fake('local');

        $this->patientUser = $this->makeUser('patient', '+963900000120');
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id,
            'full_name' => 'Hardening Patient', 'age' => 30,
            'gender' => 'other', 'language' => 'en',
        ]);

        $this->therapistUser = $this->makeUser('therapist', '+963900000121');
        $this->therapist = $this->makeTherapist($this->therapistUser, 'approved');

        $this->admin = $this->makeUser('admin', '+963900000122');
        $this->date = now()->addDay()->toDateString();
    }

    private function makeUser(string $role, string $whatsapp, string $password = 'Secret123!'): User
    {
        $user = User::create([
            'name' => "Test {$role}",
            'email' => "{$role}.{$whatsapp}@example.com",
            'password' => Hash::make($password),
            'whatsapp_number' => $whatsapp,
            'role' => $role,
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeTherapist(User $user, string $status, int $limit = 10): Therapist
    {
        return Therapist::create([
            'user_id' => $user->id,
            'full_name' => 'Dr. '.$user->whatsapp_number, 'specialty' => 'anxiety', 'country' => 'DE',
            'languages' => ['en'],
            'availability' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['09:00-12:00']
            ),
            'approval_status' => $status,
            'clients_limit' => $limit,
            'license_path' => 'licenses/'.$user->id.'/lic.pdf',
        ]);
    }

    private function bookAs(User $patient, array $overrides = [])
    {
        Sanctum::actingAs($patient, ['*'], 'api');

        return $this->postJson('/api/v1/sessions/book', array_merge([
            'therapist_id' => $this->therapist->user_id,
            'session_date' => $this->date,
            'session_time' => '10:00',
            'medium' => 'zoom',
        ], $overrides));
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->image('proof.png', 10, 10);
    }

    private function phpDisguisedAsPng(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'evil');
        file_put_contents($tmp, '<?php echo 1;');

        return new UploadedFile($tmp, 'evil.png', 'image/png', null, true);
    }

    // ---------------------------------------------------------------- auth

    public function test_otp_send_alias_and_no_otp_in_logs(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Alias User', 'email' => 'alias@example.com', 'whatsapp_number' => '00963 911 111 111',
            'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'role' => 'patient', 'privacy_accepted' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['whatsapp_number' => '+963911111111']);

        // Same number in a different format is treated as the same account.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Dup', 'email' => 'dup@example.com', 'whatsapp_number' => '+963-911-111-111',
            'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'role' => 'patient', 'privacy_accepted' => true,
        ])->assertStatus(422);

        // Cooldown applies to the canonical number regardless of formatting; the
        // response is identical either way, only the delivery count differs.
        $sender = $this->app->make(WhatsAppSenderInterface::class);
        $sent = count($sender->messages);
        $this->postJson('/api/v1/auth/otp/send', ['whatsapp_number' => '963911111111'])->assertOk();
        $this->assertCount($sent, $sender->messages);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/otp/send', ['whatsapp_number' => '963911111111'])->assertOk();
        $this->assertCount($sent + 1, $sender->messages);
    }

    public function test_status_endpoint_does_not_enumerate_accounts(): void
    {
        $known = $this->postJson('/api/v1/auth/status', ['whatsapp_number' => $this->patientUser->whatsapp_number])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/auth/status', ['whatsapp_number' => '+963999999999'])->assertOk()->json();

        $this->assertSame(['verification_pending'], array_keys($known));
        $this->assertSame($known, $unknown);
    }

    // ------------------------------------------------------------- patient

    public function test_profile_update_post_alias_and_aliases_resolve(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        $this->postJson('/api/v1/patients/profile/update', ['full_name' => 'Renamed'])->assertOk();
        $this->assertSame('Renamed', $this->patient->refresh()->full_name);

        $this->getJson('/api/v1/appointments')->assertOk();
        $this->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_account_deletion_grace_period_can_be_cancelled_by_login_then_anonymized(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->deleteJson('/api/v1/patients/account', ['password' => 'Secret123!'])->assertOk();

        $user = $this->patientUser->refresh();
        $this->assertNotNull($user->deletion_scheduled_at);
        $this->assertTrue($user->is_active);

        // Login during grace period cancels the deletion.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', [
            'whatsapp_number' => $user->whatsapp_number, 'password' => 'Secret123!',
        ])->assertOk();
        $this->assertNull($user->refresh()->deletion_scheduled_at);

        // Schedule again and let it elapse: personal data is anonymized, records kept.
        $session = TherapySession::create([
            'patient_id' => $this->patient->user_id, 'therapist_id' => $this->therapist->user_id,
            'session_date' => $this->date, 'session_time' => '10:00', 'status' => 'completed',
            'medium' => 'zoom', 'price' => 50, 'payment_status' => 'paid',
        ]);
        $user->forceFill(['deletion_scheduled_at' => now()->subMinute()])->save();

        // Owned uploads and free-text mood notes must go; other users' files must not.
        $disk = Storage::disk('local');
        $disk->put('payment-proofs/mine.png', 'x');
        $disk->put('payment-proofs/theirs.png', 'y');
        $payment = Payment::create([
            'therapy_session_id' => $session->id, 'amount' => 50,
            'proof_file_path' => 'payment-proofs/mine.png', 'status' => 'approved',
        ]);
        $otherPatientUser = $this->makeUser('patient', '+963900000129');
        Patient::create(['user_id' => $otherPatientUser->id, 'full_name' => 'Other', 'age' => 30, 'gender' => 'other', 'language' => 'en']);
        $otherSession = TherapySession::create([
            'patient_id' => $otherPatientUser->id, 'therapist_id' => $this->therapist->user_id,
            'session_date' => $this->date, 'session_time' => '11:00', 'status' => 'completed',
            'medium' => 'zoom', 'price' => 50, 'payment_status' => 'paid',
        ]);
        Payment::create([
            'therapy_session_id' => $otherSession->id, 'amount' => 50,
            'proof_file_path' => 'payment-proofs/theirs.png', 'status' => 'approved',
        ]);
        DB::table('mood_logs')->insert([
            'id' => (string) Str::uuid(), 'patient_id' => $this->patient->user_id,
            'score' => 4, 'notes' => 'Argued with my brother Ahmad again', 'log_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->app->call([new PruneScheduledDeletionsJob, 'handle']);

        $user->refresh();
        $this->assertNotNull($user->anonymized_at);
        $this->assertFalse($user->is_active);
        $this->assertNotSame('+963900000120', $user->whatsapp_number);
        $this->assertSame('Anonymous patient', $this->patient->refresh()->full_name);
        $this->assertDatabaseHas('therapy_sessions', ['id' => $session->id, 'payment_status' => 'paid']);

        $disk->assertMissing('payment-proofs/mine.png');
        $disk->assertExists('payment-proofs/theirs.png');
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => 50, 'status' => 'approved']);
        $this->assertSame(1, DB::table('mood_logs')->where('patient_id', $this->patient->user_id)->count());
        $this->assertNull(DB::table('mood_logs')->where('patient_id', $this->patient->user_id)->value('notes'));
    }

    public function test_anonymization_is_retried_when_an_owned_upload_cannot_be_deleted(): void
    {
        $this->therapistUser->forceFill(['deletion_scheduled_at' => now()->subMinute()])->save();
        Therapist::whereKey($this->therapist->user_id)->update(['license_file_path' => 'licenses/lic.pdf']);

        $failing = \Mockery::mock(Filesystem::class);
        $failing->shouldReceive('exists')->with('licenses/lic.pdf')->andReturn(true);
        $failing->shouldReceive('delete')->with('licenses/lic.pdf')->andReturn(false);
        Storage::set('local', $failing);

        $this->app->call([new PruneScheduledDeletionsJob, 'handle']);

        $this->therapistUser->refresh();
        $this->assertNull($this->therapistUser->anonymized_at);
        $this->assertNotNull($this->therapistUser->deletion_scheduled_at);
        $this->assertSame('licenses/lic.pdf', $this->therapist->refresh()->license_file_path);

        // Storage recovers: the next run completes and clears the licence reference.
        Storage::fake('local');
        Storage::disk('local')->put('licenses/lic.pdf', 'pdf');
        $this->app->call([new PruneScheduledDeletionsJob, 'handle']);

        $this->assertNotNull($this->therapistUser->refresh()->anonymized_at);
        $this->assertNull($this->therapist->refresh()->license_file_path);
        Storage::disk('local')->assertMissing('licenses/lic.pdf');
    }

    public function test_mood_logging_is_daily_unique_and_low_streak_raises_red_flag(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        $this->postJson('/api/v1/mood', ['score' => 7])->assertCreated();
        // Second post the same day updates instead of duplicating.
        $this->postJson('/api/v1/mood', ['score' => 2])->assertOk();
        $this->assertSame(1, $this->patient->moodLogs()->count());

        $this->postJson('/api/v1/mood', ['score' => 11])->assertStatus(422);
        $this->postJson('/api/v1/mood', ['score' => 5, 'log_date' => now()->addDay()->toDateString()])->assertStatus(422);

        $streak = (int) config('sakina.mood_alert_streak');
        for ($i = 1; $i < $streak; $i++) {
            $this->postJson('/api/v1/mood', ['score' => 2, 'log_date' => now()->subDays($i)->toDateString()])->assertCreated();
        }

        $this->assertDatabaseHas('red_flags', ['patient_id' => $this->patient->user_id, 'type' => 'low_mood']);

        $chart = $this->getJson('/api/v1/patients/mood/chart?days=7')->assertOk()->json();
        $this->assertCount(7, $chart['series']);
        $this->assertSame($streak, $chart['summary']['current_low_streak']);
    }

    public function test_mood_log_accepts_anxiety_energy_sleep_and_activity_axes(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        $this->postJson('/api/v1/mood', [
            'score' => 6, 'anxiety' => 4, 'energy' => 5,
            'sleep_hours' => 7.5, 'activity_level' => 3,
        ])->assertCreated()
            ->assertJsonPath('mood.anxiety', 4)
            ->assertJsonPath('mood.energy', 5)
            ->assertJsonPath('mood.sleep_hours', 7.5)
            ->assertJsonPath('mood.activity_level', 3);

        $this->assertDatabaseHas('mood_logs', [
            'patient_id' => $this->patient->user_id,
            'anxiety' => 4, 'energy' => 5, 'activity_level' => 3,
        ]);

        // Out-of-range axes rejected; score-only still accepted (backward compat).
        $this->postJson('/api/v1/mood', ['score' => 6, 'anxiety' => 11])->assertStatus(422);
        $this->postJson('/api/v1/mood', ['score' => 6, 'sleep_hours' => 30])->assertStatus(422);
        $this->postJson('/api/v1/mood', ['score' => 8])->assertOk();
        $this->postJson('/api/v1/mood', [
            'score' => 5, 'anxiety' => 6, 'sleep_hours' => 6.5,
            'log_date' => now()->subDay()->toDateString(),
        ])->assertCreated();

        $chart = $this->getJson('/api/v1/patients/mood/chart?days=7')->assertOk()->json();
        $yesterday = $chart['series'][count($chart['series']) - 2];
        $this->assertSame(6, $yesterday['anxiety']);
        $this->assertSame(6.5, $yesterday['sleep_hours']);
        $this->assertSame(6.5, $chart['summary']['average_sleep_hours']);
    }

    public function test_low_mood_streak_requires_consecutive_calendar_days_and_strict_integers(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        // Numeric strings are not accepted (F-12).
        $this->postJson('/api/v1/mood', ['score' => '2'])->assertStatus(422);

        // Low scores on non-adjacent days (today, -2, -4): no streak, no flag (F-04).
        foreach ([0, 2, 4] as $ago) {
            $this->postJson('/api/v1/mood', ['score' => 2, 'log_date' => now()->subDays($ago)->toDateString()])->assertCreated();
        }

        $this->assertDatabaseMissing('red_flags', ['patient_id' => $this->patient->user_id, 'type' => 'low_mood']);
        $this->assertSame(1, $this->getJson('/api/v1/patients/mood/chart?days=7')->json('summary.current_low_streak'));

        // Filling the gap makes today, -1, -2 consecutive -> flag raised.
        $this->postJson('/api/v1/mood', ['score' => 1, 'log_date' => now()->subDay()->toDateString()])->assertCreated();
        $this->assertDatabaseHas('red_flags', ['patient_id' => $this->patient->user_id, 'type' => 'low_mood']);
    }

    public function test_historical_mood_backfill_does_not_raise_a_current_streak_alert(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        // A low run that ended five days ago is history, not a live signal (C-010).
        foreach ([5, 6, 7] as $ago) {
            $this->postJson('/api/v1/mood', ['score' => 1, 'log_date' => now()->subDays($ago)->toDateString()])->assertCreated();
        }

        $this->assertDatabaseMissing('red_flags', ['patient_id' => $this->patient->user_id, 'type' => 'low_mood']);
        $this->assertSame(0, $this->getJson('/api/v1/patients/mood/chart?days=14')->json('summary.current_low_streak'));
    }

    // ---------------------------------------------------------- therapists

    public function test_unapproved_therapist_is_blocked_from_operational_endpoints(): void
    {
        $pendingUser = $this->makeUser('therapist', '+963900000130');
        $this->makeTherapist($pendingUser, 'pending');
        Sanctum::actingAs($pendingUser, ['*'], 'api');

        $this->getJson('/api/v1/therapists/dashboard')->assertForbidden();
        $this->getJson('/api/v1/clients')->assertForbidden();
        $this->getJson('/api/v1/wallet')->assertForbidden();
        $this->getJson('/api/v1/reports')->assertForbidden();
        // Onboarding stays open.
        $this->postJson('/api/v1/therapists/settings', ['bio' => 'hello there'])->assertOk();
    }

    public function test_therapist_cannot_change_own_clients_limit_but_admin_can(): void
    {
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson('/api/v1/therapists/settings', ['clients_limit' => 999])->assertStatus(422);
        $this->assertSame(10, $this->therapist->refresh()->clients_limit);

        Sanctum::actingAs($this->admin, ['*'], 'api');
        $this->putJson("/api/v1/admin/therapists/{$this->therapist->user_id}/clients-limit", ['clients_limit' => 3])->assertOk();
        $this->assertSame(3, $this->therapist->refresh()->clients_limit);
        $this->assertDatabaseHas('audit_logs', ['action' => 'therapist.limit_changed']);
    }

    public function test_overlapping_availability_windows_rejected_and_unapproved_not_bookable(): void
    {
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson('/api/v1/therapists/settings', [
            'availability' => ['monday' => ['09:00-12:00', '11:00-13:00']],
        ])->assertStatus(422);

        $rejectedUser = $this->makeUser('therapist', '+963900000131');
        $rejected = $this->makeTherapist($rejectedUser, 'rejected');

        $this->bookAs($this->patientUser, ['therapist_id' => $rejected->user_id])->assertStatus(422);
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->getJson('/api/v1/therapists')->assertOk()
            ->assertJsonMissing(['user_id' => $rejected->user_id]);
    }

    // ------------------------------------------------------------ sessions

    public function test_slot_and_capacity_are_enforced_for_different_patients(): void
    {
        $this->bookAs($this->patientUser)->assertCreated();

        $other = $this->makeUser('patient', '+963900000140');
        Patient::create(['user_id' => $other->id, 'full_name' => 'Other', 'age' => 25, 'gender' => 'other', 'language' => 'en']);

        $this->bookAs($other)->assertStatus(422); // same slot, different patient

        $this->therapist->forceFill(['clients_limit' => 1])->save();
        $this->bookAs($other, ['session_time' => '11:00'])->assertStatus(422); // capacity
        $this->assertSame(1, $this->therapist->refresh()->clients_count);
    }

    public function test_session_report_post_session_view_and_reminders(): void
    {
        Subscription::create([
            'patient_id' => $this->patient->user_id, 'type' => '4_weeks',
            'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(),
            'price' => 150, 'verification_status' => 'approved',
        ]);
        $this->bookAs($this->patientUser)->assertCreated();
        $session = TherapySession::firstOrFail();

        // Patient can view their post-session page, not someone else's.
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->getJson("/api/v1/patients/post-session/{$session->id}")->assertOk()
            ->assertJsonPath('completed', false);

        $stranger = $this->makeUser('patient', '+963900000150');
        Patient::create(['user_id' => $stranger->id, 'full_name' => 'S', 'age' => 25, 'gender' => 'other', 'language' => 'en']);
        Sanctum::actingAs($stranger, ['*'], 'api');
        $this->getJson("/api/v1/patients/post-session/{$session->id}")->assertNotFound();

        // Therapist confirms, then reports after the session time.
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$session->id}/confirm")->assertOk();

        // Reminder job fires the 24h reminder exactly once.
        $this->travelTo(now()->parse($this->date.' 10:00')->subHours(23)->subMinute());
        $this->app->call([new SendSessionRemindersJob, 'handle']);
        $this->app->call([new SendSessionRemindersJob, 'handle']);
        $this->assertTrue($session->refresh()->reminder_sent);
        $this->assertSame(
            1,
            $this->patientUser->notifications()->where('type', 'App\Notifications\SessionReminderNotification')->count()
        );

        $this->travelTo(now()->parse($this->date.' 10:00')->addHour());
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$session->id}/attendance")->assertOk();

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson("/api/v1/sessions/{$session->id}/report", ['summary' => 'Patient engaged well; homework assigned.'])
            ->assertOk()->assertJsonPath('session.status', 'completed');
        // Editing a completed report is an audited revision.
        $this->postJson("/api/v1/sessions/{$session->id}/report", ['summary' => 'Patient engaged well; homework assigned; revised.'])
            ->assertOk()->assertJsonPath('session.report_revision', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'session.report_revised', 'entity_id' => $session->id]);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->getJson("/api/v1/patients/post-session/{$session->id}")->assertOk()
            ->assertJsonPath('completed', true)
            ->assertJsonPath('session.summary', 'Patient engaged well; homework assigned; revised.');
    }

    public function test_therapist_client_endpoints_scope_to_own_clients(): void
    {
        $this->bookAs($this->patientUser)->assertCreated();

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/clients/{$this->patient->user_id}")->assertOk();
        $this->postJson("/api/v1/therapists/clients/{$this->patient->user_id}/notes", ['body' => 'First impression: anxious.'])->assertCreated();
        $this->getJson("/api/v1/therapists/clients/{$this->patient->user_id}/notes")->assertOk()->assertJsonCount(1, 'notes');

        $otherTherapist = $this->makeUser('therapist', '+963900000160');
        $this->makeTherapist($otherTherapist, 'approved');
        Sanctum::actingAs($otherTherapist, ['*'], 'api');
        $this->getJson("/api/v1/clients/{$this->patient->user_id}")->assertNotFound();
        $this->getJson("/api/v1/therapists/clients/{$this->patient->user_id}/notes")->assertNotFound();
    }

    public function test_therapist_switch_flow(): void
    {
        Subscription::create([
            'patient_id' => $this->patient->user_id, 'type' => '4_weeks',
            'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(),
            'price' => 150, 'verification_status' => 'approved',
        ]);
        // Far enough away not to trip the 48-hour switch lock.
        $this->bookAs($this->patientUser, ['session_date' => now()->addDays(3)->toDateString()])->assertCreated();

        $targetUser = $this->makeUser('therapist', '+963900000170');
        $target = $this->makeTherapist($targetUser, 'approved');

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson('/api/v1/therapist/switch', [
            'new_therapist_id' => $target->user_id, 'reason' => 'I would prefer a different approach.',
        ])->assertStatus(202);
        $this->postJson('/api/v1/therapist/switch', [
            'new_therapist_id' => $target->user_id, 'reason' => 'I would prefer a different approach.',
        ])->assertStatus(409);

        $switchId = $this->getJson('/api/v1/therapist/switch')->json('data.0.id');

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson("/api/v1/admin/therapist-switches/{$switchId}/review", ['action' => 'approve'])->assertForbidden();
        // The current therapist is not the addressee of the request.
        $this->postJson("/api/v1/therapists/me/switch-requests/{$switchId}/decide", ['action' => 'accept'])->assertForbidden();

        // Head Master cannot decide before the requested therapist accepted.
        Sanctum::actingAs($this->admin, ['*'], 'api');
        $this->postJson("/api/v1/admin/therapist-switches/{$switchId}/review", ['action' => 'approve'])
            ->assertUnprocessable()->assertJsonValidationErrorFor('switch');

        Sanctum::actingAs($targetUser, ['*'], 'api');
        $this->getJson('/api/v1/therapists/me/switch-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/therapists/me/switch-requests/{$switchId}/decide", ['action' => 'accept'])
            ->assertOk()->assertJsonPath('data.therapist_decision', 'accepted');
        $this->postJson("/api/v1/therapists/me/switch-requests/{$switchId}/decide", ['action' => 'decline'])->assertStatus(409);

        Sanctum::actingAs($this->admin, ['*'], 'api');
        $this->postJson("/api/v1/admin/therapist-switches/{$switchId}/review", ['action' => 'approve'])->assertOk();
        $this->postJson("/api/v1/admin/therapist-switches/{$switchId}/review", ['action' => 'reject'])->assertStatus(409);
        $this->assertSame($target->user_id, $this->patient->refresh()->therapist_id);
    }

    public function test_therapist_switch_is_locked_while_a_session_is_within_48_hours(): void
    {
        Subscription::create([
            'patient_id' => $this->patient->user_id, 'type' => '4_weeks',
            'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(),
            'price' => 150, 'verification_status' => 'approved',
        ]);
        $this->bookAs($this->patientUser)->assertCreated(); // tomorrow 10:00 => inside the window
        $session = TherapySession::firstOrFail();

        $targetUser = $this->makeUser('therapist', '+963900000171');
        $target = $this->makeTherapist($targetUser, 'approved');
        $payload = ['new_therapist_id' => $target->user_id, 'reason' => 'I would prefer a different approach.'];

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson('/api/v1/therapist/switch', $payload)
            ->assertUnprocessable()->assertJsonValidationErrorFor('therapist');
        $this->assertSame(0, TherapistSwitch::count());

        // A completed or cancelled session no longer locks the switch.
        $session->update(['status' => 'completed']);
        $this->postJson('/api/v1/therapist/switch', $payload)->assertStatus(202);
    }

    public function test_therapist_switch_lock_boundary_is_exactly_48_hours(): void
    {
        Subscription::create([
            'patient_id' => $this->patient->user_id, 'type' => '4_weeks',
            'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(),
            'price' => 150, 'verification_status' => 'approved',
        ]);
        $this->travelTo(now()->setTime(9, 0));
        $this->bookAs($this->patientUser, ['session_date' => now()->addDays(2)->toDateString(), 'session_time' => '10:00'])
            ->assertCreated(); // starts in 49h

        $targetUser = $this->makeUser('therapist', '+963900000172');
        $target = $this->makeTherapist($targetUser, 'approved');
        $payload = ['new_therapist_id' => $target->user_id, 'reason' => 'I would prefer a different approach.'];

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson('/api/v1/therapist/switch', $payload)->assertStatus(202);
        TherapistSwitch::query()->delete();

        $this->travel(2)->hours(); // now starts in 47h
        $this->postJson('/api/v1/therapist/switch', $payload)->assertUnprocessable();
    }

    // ------------------------------------------------------------ payments

    public function test_duplicate_proof_upload_is_rejected_and_review_is_single_shot(): void
    {
        $this->bookAs($this->patientUser)->assertCreated(); // free initial
        $this->bookAs($this->patientUser, ['session_time' => '11:00'])->assertCreated();
        $session = TherapySession::where('payment_status', 'pending')->firstOrFail();

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->post("/api/v1/sessions/{$session->id}/proof", ['proof' => $this->png()], ['Accept' => 'application/json'])->assertStatus(202);
        $this->post("/api/v1/sessions/{$session->id}/proof", ['proof' => $this->png()], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(1, Payment::count());

        $payment = Payment::firstOrFail();
        Sanctum::actingAs($this->admin, ['*'], 'api');
        $this->postJson("/api/v1/admin/payments/{$payment->id}/review", ['action' => 'approve'])->assertOk();
        $this->postJson("/api/v1/admin/payments/{$payment->id}/review", ['action' => 'reject', 'note' => 'nope'])->assertStatus(409);

        $session->refresh();
        $this->assertSame('paid', $session->payment_status->value);
        $this->assertSame('approved', $payment->refresh()->status->value);
        $this->assertNotNull($payment->reviewed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.reviewed']);
    }

    // -------------------------------------------------------------- wallet

    public function test_wallet_reflects_completed_paid_sessions_and_withdrawals(): void
    {
        TherapySession::create([
            'patient_id' => $this->patient->user_id, 'therapist_id' => $this->therapist->user_id,
            'session_date' => now()->subDay()->toDateString(), 'session_time' => '10:00', 'status' => 'completed',
            'medium' => 'zoom', 'price' => 100, 'payment_status' => 'paid', 'attendance_confirmed_at' => now()->subDay(),
        ]);
        TherapySession::create([
            'patient_id' => $this->patient->user_id, 'therapist_id' => $this->therapist->user_id,
            'session_date' => $this->date, 'session_time' => '11:00', 'status' => 'confirmed',
            'medium' => 'zoom', 'price' => 100, 'payment_status' => 'paid',
        ]);

        config(['sakina.platform_commission_rate' => 0.2, 'sakina.min_withdrawal_amount' => 10]);

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $wallet = $this->getJson('/api/v1/wallet')->assertOk()->json();
        $this->assertEquals(80, $wallet['available']);
        $this->assertEquals(80, $wallet['pending_earnings']);

        $this->postJson('/api/v1/wallet/withdraw', ['amount' => 500, 'payout_details' => ['method' => 'bank_transfer', 'account' => 'DE00 1234']])->assertStatus(422);
        $this->postJson('/api/v1/wallet/withdraw', ['amount' => 50, 'payout_details' => ['method' => 'bank_transfer', 'account' => 'DE00 1234']])->assertStatus(202);
        $this->postJson('/api/v1/wallet/withdraw', ['amount' => 10, 'payout_details' => ['method' => 'bank_transfer', 'account' => 'DE00 1234']])->assertStatus(409);

        $wallet = $this->getJson('/api/v1/wallet')->assertOk()->json();
        $this->assertEquals(30, $wallet['available']);

        $withdrawalId = $wallet['recent_withdrawals'][0]['id'];
        Sanctum::actingAs($this->admin, ['*'], 'api');
        $this->postJson("/api/v1/admin/withdrawals/{$withdrawalId}/review", ['action' => 'reject', 'note' => 'bad iban'])->assertOk();

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->assertEquals(80, $this->getJson('/api/v1/wallet')->json('available'));
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('sessions.completed', 1);
    }

    // --------------------------------------------------------------- files

    public function test_file_upload_and_download_authorization(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        $res = $this->post('/api/v1/files/upload', ['file' => $this->png(), 'purpose' => 'payment_proof'], ['Accept' => 'application/json'])
            ->assertCreated();
        $path = $res->json('file.path');
        Storage::disk('local')->assertExists($path);

        $this->post('/api/v1/files/upload', [
            'file' => $this->phpDisguisedAsPng(), 'purpose' => 'other',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post("/api/v1/files/download/{$path}")->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/api/v1/files/download/../../.env')->assertStatus(403);
        $this->post('/api/v1/files/download/%2e%2e/%2e%2e/.env')->assertStatus(403);
        $this->post('/api/v1/files/download/payment_proofs/../../.env')->assertStatus(403);

        $stranger = $this->makeUser('patient', '+963900000180');
        Sanctum::actingAs($stranger, ['*'], 'api');
        $this->post("/api/v1/files/download/{$path}")->assertNotFound(); // existence is not revealed

        Sanctum::actingAs($this->admin, ['*'], 'api');
        $this->post("/api/v1/files/download/{$path}")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'file.downloaded']);
    }

    // --------------------------------------------------------------- admin

    public function test_red_flag_admin_endpoints(): void
    {
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $answers = array_fill_keys(array_map(fn ($i) => "q{$i}", range(1, 9)), 0);
        $answers['q9'] = 1;
        $this->postJson('/api/v1/patients/assessment', ['type' => 'phq9', 'answers' => $answers])->assertCreated();

        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->getJson('/api/v1/admin/red-flags')->assertForbidden();

        Sanctum::actingAs($this->admin, ['*'], 'api');
        $list = $this->getJson('/api/v1/admin/red-flags')->assertOk()->json();
        $this->assertCount(1, $list['data']);
        $id = $list['data'][0]['id'];

        $this->postJson("/api/v1/admin/red-flags/{$id}/assign", ['user_id' => $this->patientUser->id])->assertStatus(422);

        // Approved therapist with no relationship to the patient: rejected (F-03).
        $this->postJson("/api/v1/admin/red-flags/{$id}/assign", ['user_id' => $this->therapistUser->id])->assertStatus(422);

        // Pending therapist, even if treating the patient: rejected.
        $pendingUser = $this->makeUser('therapist', '+963900000190');
        $this->makeTherapist($pendingUser, 'pending');
        $this->patient->update(['therapist_id' => $pendingUser->id]);
        $this->postJson("/api/v1/admin/red-flags/{$id}/assign", ['user_id' => $pendingUser->id])->assertStatus(422);

        // Approved therapist who treats the patient: accepted.
        $this->patient->update(['therapist_id' => $this->therapistUser->id]);
        $this->postJson("/api/v1/admin/red-flags/{$id}/assign", ['user_id' => $this->therapistUser->id])->assertOk()
            ->assertJsonPath('data.assigned_to', $this->therapistUser->id);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->therapistUser->id]);

        // Clinical supervisor can be assigned and can work the flag itself (F-02).
        $supervisor = $this->makeUser('clinical_supervisor', '+963900000191');
        $this->postJson("/api/v1/admin/red-flags/{$id}/assign", ['user_id' => $supervisor->id])->assertOk();

        Sanctum::actingAs($supervisor, ['*'], 'api');
        $this->getJson('/api/v1/admin/red-flags')->assertOk();
        $this->getJson("/api/v1/admin/red-flags/{$id}")->assertOk();
        $this->getJson('/api/v1/admin/payments')->assertForbidden();
        $this->postJson("/api/v1/admin/red-flags/{$id}/status", ['status' => 'resolved'])->assertStatus(422);
        $this->postJson("/api/v1/admin/red-flags/{$id}/status", ['status' => 'resolved', 'action_taken' => 'Called patient.'])->assertOk();
        $this->assertSame(0, $this->getJson('/api/v1/admin/red-flags')->json('stats.open'));
    }

    public function test_red_flag_alerts_reach_the_supervisor_and_the_current_therapist_once_each(): void
    {
        $supervisor = $this->makeUser('clinical_supervisor', '+963900000193');
        $this->patient->update(['therapist_id' => $this->therapist->user_id]);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $answers = array_fill_keys(array_map(fn ($i) => "q{$i}", range(1, 9)), 0);
        $answers['q9'] = 3;
        $this->postJson('/api/v1/patients/assessment', ['type' => 'phq9', 'answers' => $answers])->assertCreated();

        $flag = RedFlag::sole();
        $this->assertSame($supervisor->id, $flag->assigned_to);
        $recipients = DB::table('notifications')->where('type', RedFlagRaisedNotification::class)->pluck('notifiable_id');
        $this->assertEqualsCanonicalizing([$supervisor->id, $this->therapistUser->id], $recipients->all());

        // Replayed: still exactly one per recipient.
        app(NotificationService::class)->redFlagRaised($flag->fresh());
        $this->assertSame(2, DB::table('notifications')->where('type', RedFlagRaisedNotification::class)->count());

        // Supervisor who is also the assigned therapist is notified once; an
        // unapproved therapist is not alerted at all.
        DB::table('notifications')->delete();
        $this->patient->update(['therapist_id' => $supervisor->id]);
        app(NotificationService::class)->redFlagRaised(RedFlag::sole()->fresh());
        $this->assertSame([$supervisor->id], DB::table('notifications')->pluck('notifiable_id')->all());

        DB::table('notifications')->delete();
        $this->patient->update(['therapist_id' => $this->therapist->user_id]);
        Therapist::whereKey($this->therapist->user_id)->update(['approval_status' => 'pending']);
        app(NotificationService::class)->redFlagRaised(RedFlag::sole()->fresh());
        $this->assertSame([$supervisor->id], DB::table('notifications')->pluck('notifiable_id')->all());
    }

    public function test_red_flag_escalation_retry_only_reaches_staff_missed_by_the_previous_attempt(): void
    {
        $supervisor = $this->makeUser('clinical_supervisor', '+963900000194');

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $answers = array_fill_keys(array_map(fn ($i) => "q{$i}", range(1, 9)), 0);
        $answers['q9'] = 3;
        $this->postJson('/api/v1/patients/assessment', ['type' => 'phq9', 'answers' => $answers])->assertCreated();
        $flag = RedFlag::sole();

        $notifications = app(NotificationService::class);
        $escalations = fn () => DB::table('notifications')->where('type', RedFlagEscalatedNotification::class)->pluck('notifiable_id');

        // Attempt 1 reached only the supervisor before the run died.
        $this->assertSame(1, $notifications->redFlagEscalated($flag, [$supervisor]));
        // Attempt 2 covers everyone: only the admin is new.
        $this->assertSame(1, $notifications->redFlagEscalated($flag, [$supervisor, $this->admin]));
        $this->assertEqualsCanonicalizing([$supervisor->id, $this->admin->id], $escalations()->all());
        $this->assertSame(0, $notifications->redFlagEscalated($flag, [$supervisor, $this->admin]));
    }

    public function test_stale_high_priority_red_flags_are_escalated_to_all_clinical_staff_once(): void
    {
        $supervisor = $this->makeUser('clinical_supervisor', '+963900000192');

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $answers = array_fill_keys(array_map(fn ($i) => "q{$i}", range(1, 9)), 0);
        $answers['q9'] = 3;
        $this->postJson('/api/v1/patients/assessment', ['type' => 'phq9', 'answers' => $answers])->assertCreated();

        $flag = RedFlag::sole();
        $this->assertSame('high', $flag->priority->value);

        // Not yet stale: nothing happens.
        (new EscalateStaleRedFlagsJob)->handle(app(NotificationService::class));
        $this->assertNull($flag->fresh()->escalated_at);

        RedFlag::whereKey($flag->id)->update(['created_at' => now()->subMinutes(config('sakina.red_flag_escalation_minutes') + 1)]);

        $before = DB::table('notifications')->count();
        (new EscalateStaleRedFlagsJob)->handle(app(NotificationService::class));
        $this->assertNotNull($flag->fresh()->escalated_at);
        // supervisor + admin each get one escalation notification
        $this->assertSame($before + 2, DB::table('notifications')->count());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $supervisor->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->admin->id]);

        // Idempotent: second run sends nothing more.
        (new EscalateStaleRedFlagsJob)->handle(app(NotificationService::class));
        $this->assertSame($before + 2, DB::table('notifications')->count());
    }

    public function test_notifications_endpoints(): void
    {
        $this->bookAs($this->patientUser)->assertCreated();

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $list = $this->getJson('/api/v1/notifications')->assertOk()->json();
        $this->assertSame(1, $list['unread_count']);
        $this->postJson("/api/v1/notifications/{$list['data'][0]['id']}/read")->assertOk();
        $this->assertSame(0, $this->getJson('/api/v1/notifications')->json('unread_count'));
    }
}
