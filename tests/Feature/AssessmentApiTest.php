<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\RedFlag;
use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);

        $this->user = User::create([
            'name' => 'Patient One',
            'email' => 'p1@example.com',
            'password' => bcrypt('Secret123!'),
            'role' => 'patient',
            'whatsapp_number' => '+963900000002',
            'is_active' => true,
            'phone_verified_at' => now(),
            'login_attempts' => 0,
        ]);
        $this->user->assignRole('patient');

        $this->patient = Patient::create([
            'user_id' => $this->user->id,
            'full_name' => 'Patient One',
            'age' => 30,
            'gender' => 'male',
            'language' => 'ar',
        ]);

        Sanctum::actingAs($this->user, ['*'], 'api');
    }

    private function answers(int $count, int $value, array $overrides = []): array
    {
        $answers = [];
        for ($i = 1; $i <= $count; $i++) {
            $answers["q{$i}"] = $value;
        }

        return array_merge($answers, $overrides);
    }

    public function test_phq9_submission_scores_and_interprets(): void
    {
        $response = $this->postJson('/api/v1/patients/assessment', [
            'type' => 'phq9',
            'answers' => $this->answers(9, 1, ['q9' => 0]), // score 9 → mild
        ]);

        $response->assertCreated()
            ->assertJsonPath('assessment.score', 8)
            ->assertJsonPath('interpretation.level', 'mild')
            ->assertJsonPath('red_flag_created', false);

        $this->assertSame(8, $this->patient->refresh()->assessment_score);
        $this->assertSame(1, $this->patient->user->notifications()->count());
    }

    public function test_phq9_item_nine_always_raises_safety_flag(): void
    {
        // Total score only 8 — below every threshold — but q9 > 0.
        $response = $this->postJson('/api/v1/patients/assessment', [
            'type' => 'phq9',
            'answers' => $this->answers(9, 0, ['q9' => 2, 'q1' => 3, 'q2' => 3]),
        ]);

        $response->assertCreated()->assertJsonPath('red_flag_created', true);

        $flag = RedFlag::where('patient_id', $this->patient->user_id)->sole();
        $this->assertSame('safety', $flag->type->value);
        $this->assertSame('high', $flag->priority->value);
        $this->assertSame('open', $flag->status);
        $this->assertNotNull($flag->assessment_id);
        $this->assertTrue($this->patient->refresh()->safety_flag);
    }

    public function test_high_score_raises_low_mood_flag(): void
    {
        $this->postJson('/api/v1/patients/assessment', [
            'type' => 'gad7',
            'answers' => $this->answers(7, 3), // 21 — severe
        ])->assertCreated()->assertJsonPath('interpretation.level', 'severe');

        $flag = RedFlag::where('patient_id', $this->patient->user_id)->sole();
        $this->assertSame('low_mood', $flag->type->value);
        $this->assertSame('high', $flag->priority->value);
    }

    public function test_moderate_score_creates_no_flag(): void
    {
        $this->postJson('/api/v1/patients/assessment', [
            'type' => 'gad7',
            'answers' => $this->answers(7, 1), // 7 — mild
        ])->assertCreated()->assertJsonPath('red_flag_created', false);

        $this->assertSame(0, RedFlag::count());
    }

    public function test_answers_must_match_instrument_length_and_range(): void
    {
        $this->postJson('/api/v1/patients/assessment', [
            'type' => 'phq9',
            'answers' => $this->answers(7, 1), // wrong count for phq9
        ])->assertUnprocessable();

        $this->postJson('/api/v1/patients/assessment', [
            'type' => 'gad7',
            'answers' => $this->answers(7, 1, ['q3' => 9]), // out of range
        ])->assertUnprocessable();
    }

    public function test_answers_are_encrypted_at_rest(): void
    {
        $this->postJson('/api/v1/patients/assessment', [
            'type' => 'phq9',
            'answers' => $this->answers(9, 1),
        ])->assertCreated();

        $raw = \DB::table('assessments')->soleValue('answers');

        // Question keys are structural; answer values must never be plaintext.
        $this->assertStringNotContainsString('"q1":1', (string) $raw);
        $this->assertStringContainsString('encrypted', (string) $raw);
        $this->assertStringContainsString('hash', (string) $raw);
    }

    public function test_history_returns_paginated_assessments_with_stats(): void
    {
        $this->postJson('/api/v1/patients/assessment', [
            'type' => 'phq9', 'answers' => $this->answers(9, 1),
        ])->assertCreated();
        $this->postJson('/api/v1/patients/assessment', [
            'type' => 'gad7', 'answers' => $this->answers(7, 2),
        ])->assertCreated();

        $this->getJson('/api/v1/patients/assessment/history')
            ->assertOk()
            ->assertJsonPath('statistics.total_assessments', 2)
            ->assertJsonCount(2, 'assessments');
    }
}
