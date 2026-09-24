<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Review;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TherapistReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $patientUser;

    private Patient $patient;

    private User $otherPatientUser;

    private User $therapistUser;

    private User $otherTherapistUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);

        $this->patientUser = $this->makeUser('patient', '+963900000840');
        $this->patient = $this->makePatient($this->patientUser);
        $this->otherPatientUser = $this->makeUser('patient', '+963900000841');
        $this->makePatient($this->otherPatientUser);
        $this->therapistUser = $this->makeUser('therapist', '+963900000842');
        $this->makeTherapist($this->therapistUser);
        $this->otherTherapistUser = $this->makeUser('therapist', '+963900000843');
        $this->makeTherapist($this->otherTherapistUser);
    }

    private function makeUser(string $role, string $whatsapp): User
    {
        $user = User::create([
            'name' => "Test {$role}", 'email' => "{$role}.{$whatsapp}@example.com",
            'password' => 'x', 'whatsapp_number' => $whatsapp, 'role' => $role,
            'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makePatient(User $user): Patient
    {
        return Patient::create([
            'user_id' => $user->id, 'full_name' => 'Patient '.$user->whatsapp_number,
            'age' => 30, 'gender' => 'female', 'language' => 'ar',
        ]);
    }

    private function makeTherapist(User $user): Therapist
    {
        return Therapist::create([
            'user_id' => $user->id, 'full_name' => "Dr {$user->whatsapp_number}", 'specialty' => 'cbt', 'country' => 'JO',
            'availability' => [], 'approval_status' => 'approved',
        ]);
    }

    private int $sessionHour = 8;

    private function makeSession(User $patient, User $therapist, string $status): TherapySession
    {
        return TherapySession::create([
            'patient_id' => $patient->id, 'therapist_id' => $therapist->id,
            'session_date' => now()->subDays(2)->toDateString(),
            'session_time' => sprintf('%02d:00', $this->sessionHour++),
            'medium' => 'meet', 'status' => $status, 'is_initial' => true, 'price' => 0,
            'payment_status' => 'free',
        ]);
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, ['*'], 'api');
    }

    public function test_patient_can_review_only_after_a_completed_session_and_only_once(): void
    {
        $tid = $this->therapistUser->id;
        $this->actAs($this->patientUser);

        // No session at all → not eligible.
        $this->postJson("/api/v1/therapists/{$tid}/reviews", ['rating' => 5])
            ->assertStatus(422)->assertJsonValidationErrors(['therapist_id']);

        // A pending session is not enough.
        $this->makeSession($this->patientUser, $this->therapistUser, 'pending');
        $this->postJson("/api/v1/therapists/{$tid}/reviews", ['rating' => 5])->assertStatus(422);

        $this->makeSession($this->patientUser, $this->therapistUser, 'completed');

        $this->postJson("/api/v1/therapists/{$tid}/reviews", ['rating' => 0])->assertStatus(422)->assertJsonValidationErrors(['rating']);
        $this->postJson("/api/v1/therapists/{$tid}/reviews", ['rating' => 6])->assertStatus(422);
        $this->postJson("/api/v1/therapists/{$tid}/reviews", ['rating' => 4, 'comment' => str_repeat('x', 1001)])->assertStatus(422);

        $this->postJson("/api/v1/therapists/{$tid}/reviews", ['rating' => 4, 'comment' => 'Very helpful.'])
            ->assertCreated()
            ->assertJsonPath('review.rating', 4)
            ->assertJsonPath('review.comment', 'Very helpful.');

        $this->assertSame(4.0, (float) Therapist::findOrFail($tid)->rating);
        $this->assertDatabaseHas('audit_logs', ['action' => 'review.created', 'user_id' => $this->patientUser->id]);

        // Second review for the same therapist is rejected.
        $this->postJson("/api/v1/therapists/{$tid}/reviews", ['rating' => 1])->assertStatus(409);
        $this->assertSame(1, Review::where('patient_id', $this->patient->user_id)->count());

        // Editing the existing review recomputes the average.
        $this->putJson("/api/v1/therapists/{$tid}/reviews/mine", ['rating' => 2])
            ->assertOk()->assertJsonPath('review.rating', 2)->assertJsonPath('review.comment', 'Very helpful.');
        $this->assertSame(2.0, (float) Therapist::findOrFail($tid)->rating);
        $this->getJson("/api/v1/therapists/{$tid}/reviews/mine")->assertOk()->assertJsonPath('review.rating', 2);

        // Another therapist the patient never saw: not eligible, own review absent.
        $this->postJson("/api/v1/therapists/{$this->otherTherapistUser->id}/reviews", ['rating' => 5])->assertStatus(422);
        $this->getJson("/api/v1/therapists/{$this->otherTherapistUser->id}/reviews/mine")->assertOk()->assertJsonPath('review', null);
        $this->putJson("/api/v1/therapists/{$this->otherTherapistUser->id}/reviews/mine", ['rating' => 5])->assertStatus(422);
    }

    public function test_reviews_are_anonymous_and_average_is_shown_to_patients_and_the_therapist(): void
    {
        $tid = $this->therapistUser->id;
        $this->makeSession($this->patientUser, $this->therapistUser, 'completed');
        $this->makeSession($this->otherPatientUser, $this->therapistUser, 'completed');

        $this->actAs($this->patientUser);
        $this->postJson("/api/v1/therapists/{$tid}/reviews", ['rating' => 5, 'comment' => 'Great'])->assertCreated();
        $this->actAs($this->otherPatientUser);
        $this->postJson("/api/v1/therapists/{$tid}/reviews", ['rating' => 2])->assertCreated();

        $this->assertSame(3.5, (float) Therapist::findOrFail($tid)->rating);

        $list = $this->getJson("/api/v1/therapists/{$tid}/reviews")->assertOk()
            ->assertJsonPath('reviews_count', 2)
            ->json();
        $this->assertEqualsWithDelta(3.5, $list['rating'], 0.001);
        $this->assertCount(2, $list['data']['data']);
        foreach ($list['data']['data'] as $row) {
            $this->assertArrayNotHasKey('patient_id', $row);
            $this->assertArrayNotHasKey('patient', $row);
        }

        // Directory entry reflects the new average.
        $this->getJson("/api/v1/therapists/{$tid}")->assertOk()->assertJsonPath('data.rating', 3.5);

        // The therapist sees their own reviews, still without patient identity.
        $this->actAs($this->therapistUser);
        $own = $this->getJson('/api/v1/therapists/me/reviews')->assertOk()->json('data.data');
        $this->assertCount(2, $own);
        $this->assertArrayNotHasKey('patient_id', $own[0]);

        // Therapists cannot post reviews.
        $this->postJson("/api/v1/therapists/{$this->otherTherapistUser->id}/reviews", ['rating' => 5])->assertForbidden();
    }
}
