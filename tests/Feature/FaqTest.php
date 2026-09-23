<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FaqTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->manager = $this->makeUser('content_manager', '+963900000030');
        $this->patient = $this->makeUser('patient', '+963900000031');
    }

    private function makeUser(string $role, string $phone): User
    {
        $u = User::create([
            'name' => $role, 'email' => "{$role}{$phone}@x.com", 'password' => bcrypt('Secret123!'),
            'role' => $role, 'whatsapp_number' => $phone, 'is_active' => true,
            'phone_verified_at' => now(), 'login_attempts' => 0,
        ]);
        $u->assignRole($role);

        return $u;
    }

    public function test_faq_public_list_and_staff_crud(): void
    {
        Faq::create([
            'id' => (string) Str::uuid(),
            'question' => 'How do I book?', 'answer' => 'Via the sessions screen.',
            'sort_order' => 1, 'is_published' => true,
        ]);
        Faq::create([
            'id' => (string) Str::uuid(),
            'question' => 'Draft Q', 'answer' => 'Draft A',
            'sort_order' => 2, 'is_published' => false,
        ]);

        // Public endpoint: published only, no auth needed.
        $list = $this->getJson('/api/v1/faqs')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('How do I book?', $list[0]['question']);

        // Patients cannot reach the admin CRUD.
        Sanctum::actingAs($this->patient, ['*'], 'api');
        $this->getJson('/api/v1/admin/faqs')->assertForbidden();

        // Content manager CRUD.
        Sanctum::actingAs($this->manager, ['*'], 'api');
        $id = $this->postJson('/api/v1/admin/faqs', [
            'question' => 'New?', 'answer' => 'Yes.', 'sort_order' => 3,
        ])->assertCreated()->json('faq.id');

        $this->putJson("/api/v1/admin/faqs/{$id}", ['is_published' => false])
            ->assertOk()->assertJsonPath('faq.is_published', false);

        $this->deleteJson("/api/v1/admin/faqs/{$id}")->assertOk();
        $this->assertDatabaseMissing('faqs', ['id' => $id]);
    }
}
