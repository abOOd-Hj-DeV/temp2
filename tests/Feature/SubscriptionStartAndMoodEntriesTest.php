<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Payment;
use App\Models\RedFlag;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionStartAndMoodEntriesTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private Patient $patient;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);

        config(['sakina.uploads_disk' => 'proofs']);
        Storage::fake('proofs');

        $this->patientUser = $this->makeUser('patient', '+963900000330');
        $this->patientUser->update(['timezone' => 'Asia/Damascus']);
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id,
            'full_name' => 'Start Patient', 'age' => 30,
            'gender' => 'male', 'language' => 'ar',
        ]);
        $this->admin = $this->makeUser('admin', '+963900000331');
    }

    private function makeUser(string $role, string $whatsapp): User
    {
        $user = User::create([
            'name' => "Test {$role}",
            'email' => "{$role}.{$whatsapp}@example.com",
            'password' => 'x',
            'whatsapp_number' => $whatsapp,
            'role' => $role,
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_subscription_validity_starts_the_day_after_approval_in_patient_timezone(): void
    {
        // 22:30 UTC = 01:30 next day in Asia/Damascus (UTC+3): the patient's
        // "tomorrow" is two UTC calendar days ahead of the approval instant.
        Carbon::setTestNow(Carbon::parse('2026-03-10 22:30:00', 'UTC'));

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson('/api/v1/subscriptions', [
            'type' => '4_weeks',
            'proof' => UploadedFile::fake()->image('receipt.png'),
        ])->assertCreated();

        $sub = Subscription::where('patient_id', $this->patient->user_id)->sole();
        $this->assertNull($sub->start_date);

        $paymentId = Payment::where('subscription_id', $sub->id)->sole()->id;

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin, ['*'], 'api');
        $this->postJson("/api/v1/admin/payments/{$paymentId}/review", ['action' => 'approve'])->assertOk();

        $sub->refresh();
        $this->assertSame('2026-03-12', $sub->start_date->toDateString());
        $this->assertSame(
            Carbon::parse('2026-03-12')->addDays($sub->duration_days)->toDateString(),
            $sub->end_date->toDateString(),
        );
        // Approved and counted as the patient's live package immediately, even
        // though sessions can only be scheduled from the start date onwards.
        $this->assertTrue($sub->is_active);
        $this->assertSame($sub->id, $this->patient->refresh()->subscription_id);

        Carbon::setTestNow();
    }

    public function test_multiple_mood_entries_per_day_are_kept_and_streak_counts_days_not_entries(): void
    {
        config(['sakina.mood_alert_threshold' => 3, 'sakina.mood_alert_streak' => 3]);
        Sanctum::actingAs($this->patientUser, ['*'], 'api');

        // Three low entries on the same day: one low *day*, no flag.
        foreach ([2, 1, 3] as $i => $score) {
            $this->travelTo(now()->addSeconds($i + 1));
            $this->postJson('/api/v1/mood', ['score' => $score, 'notes' => "entry {$i}"])->assertCreated();
        }

        $this->assertSame(3, $this->patient->moodLogs()->count());
        $this->assertDatabaseMissing('red_flags', ['patient_id' => $this->patient->user_id, 'type' => 'low_mood']);

        $chart = $this->getJson('/api/v1/patients/mood/chart?days=7')->assertOk()->json();
        $today = $chart['series'][count($chart['series']) - 1];
        $this->assertSame(3, $today['entries']);
        $this->assertSame(3, $today['score']);          // latest entry of the day
        $this->assertEqualsWithDelta(2.0, $today['average_score'], 0.001);
        $this->assertSame(3, $chart['summary']['entries']);
        $this->assertSame(1, $chart['summary']['current_low_streak']);

        $history = $this->getJson('/api/v1/patients/mood/history?days=7')->assertOk()->json();
        $this->assertCount(3, $history['entries']);
        $this->assertSame('entry 2', $history['entries'][0]['notes']);
        $this->assertNotNull($history['entries'][0]['logged_at']);

        // Two more low days make three consecutive low days: flag raised once.
        $this->postJson('/api/v1/mood', ['score' => 2, 'log_date' => now()->subDay()->toDateString()])->assertCreated();
        $this->postJson('/api/v1/mood', ['score' => 2, 'log_date' => now()->subDays(2)->toDateString()])
            ->assertCreated()->assertJsonPath('alert_raised', true);

        $this->travelTo(now()->addSecond());
        $this->postJson('/api/v1/mood', ['score' => 1])->assertCreated()->assertJsonPath('alert_raised', false);
        $this->assertSame(1, RedFlag::where('patient_id', $this->patient->user_id)->where('type', 'low_mood')->count());

        // A later, better check-in on the same day makes today a non-low day.
        $this->travelTo(now()->addSecond());
        $this->postJson('/api/v1/mood', ['score' => 8])->assertCreated();
        $this->assertSame(0, $this->getJson('/api/v1/patients/mood/chart?days=7')->json('summary.current_low_streak'));
    }
}
