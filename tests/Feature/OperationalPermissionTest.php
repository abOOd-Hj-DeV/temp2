<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSessionPermission;
use App\Models\AccountInvitation;
use App\Models\Patient;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Services\Account\StaffAccountService;
use App\Services\Auth\AuthService;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CommittedDatabase;
use Tests\TestCase;

class OperationalPermissionTest extends TestCase
{
    use CommittedDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));
        Cache::flush();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role): User
    {
        $n = ++$this->sequence;

        return User::create([
            'name' => 'Synthetic Permission User', 'email' => "permission{$n}@example.test",
            'whatsapp_number' => '+1555777'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'password' => 'SyntheticOld1!', 'role' => $role, 'is_active' => true, 'phone_verified_at' => now(),
        ]);
    }

    private function fixture(): array
    {
        $patient = $this->user('patient');
        $therapist = $this->user('therapist');
        Therapist::create([
            'user_id' => $therapist->id, 'full_name' => 'Synthetic Therapist', 'specialty' => 'cbt', 'country' => 'US',
            'approval_status' => 'approved', 'availability' => [],
        ]);
        Patient::create([
            'user_id' => $patient->id, 'full_name' => 'Synthetic Patient', 'age' => 30,
            'gender' => 'other', 'language' => 'en', 'therapist_id' => $therapist->id,
        ]);
        $session = TherapySession::create([
            'patient_id' => $patient->id, 'therapist_id' => $therapist->id,
            'session_date' => now()->addDays(3)->toDateString(), 'session_time' => '10:00',
            'medium' => 'zoom', 'price' => 0, 'payment_status' => 'free', 'status' => 'pending', 'is_initial' => true,
        ]);

        return [$patient, $therapist, $session];
    }

    private function headers(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('synthetic')->plainTextToken];
    }

    public function test_therapist_appointment_revocation_denies_confirmation_and_restoration_works(): void
    {
        [, $therapist, $session] = $this->fixture();
        Role::findByName('therapist', 'api')->revokePermissionTo('manage appointments');
        $therapist->givePermissionTo('book appointments');
        $this->assertFalse($therapist->fresh()->hasPermissionTo('manage appointments', 'api'));
        $this->postJson("/api/v1/sessions/{$session->id}/confirm", [], $this->headers($therapist))->assertForbidden();
        $this->assertSame('pending', $session->fresh()->status->value);
        $therapist->givePermissionTo('manage appointments');
        $this->postJson("/api/v1/sessions/{$session->id}/confirm", [], $this->headers($therapist))->assertOk();
        $this->assertSame('confirmed', $session->fresh()->status->value);
    }

    public function test_patient_booking_permission_cannot_be_replaced_by_therapist_permission(): void
    {
        [$patient, , $session] = $this->fixture();
        Role::findByName('patient', 'api')->revokePermissionTo('book appointments');
        $patient->givePermissionTo('manage appointments');
        foreach (["/api/v1/sessions/{$session->id}/cancel", '/api/v1/sessions/book', '/api/v1/subscriptions', '/api/v1/therapist/switch'] as $uri) {
            $this->postJson($uri, [], $this->headers($patient))->assertForbidden();
        }
        $this->assertSame('pending', $session->fresh()->status->value);
        $patient->givePermissionTo('book appointments');
        $this->postJson('/api/v1/sessions/book', [], $this->headers($patient))->assertUnprocessable();
        $this->getJson('/api/v1/sessions', $this->headers($patient))->assertOk();
    }

    public function test_patient_care_revocation_blocks_canonical_and_alias_operations_not_account_rights(): void
    {
        [$patient] = $this->fixture();
        $this->postJson('/api/v1/patients/mood', ['score' => 8], $this->headers($patient))->assertCreated();
        Role::findByName('patient', 'api')->revokePermissionTo('access patient care');
        foreach (['/patients/dashboard', '/dashboard', '/patients/progress', '/patients/appointments', '/appointments',
            '/patients/programs', '/patients/recommendations', '/patients/assessment/history', '/patients/mood/history',
            '/patients/content', '/patients/support', '/subscriptions/current', '/therapist/switch'] as $path) {
            $this->getJson('/api/v1'.$path, $this->headers($patient))->assertForbidden();
        }
        $this->postJson('/api/v1/mood', ['score' => 8], $this->headers($patient))->assertForbidden();
        $this->postJson('/api/v1/patients/mood', ['score' => 8], $this->headers($patient))->assertForbidden();
        $this->assertDatabaseCount('mood_logs', 1);
        $this->getJson('/api/v1/patients/profile', $this->headers($patient))->assertOk();
        $this->getJson('/api/v1/patients/emergency', $this->headers($patient))->assertOk();
        $patient->givePermissionTo('access patient care');
        $this->postJson('/api/v1/mood', ['score' => 8], $this->headers($patient))->assertCreated();
        $this->assertDatabaseCount('mood_logs', 2);
    }

    public function test_therapist_care_revocation_covers_aliases_content_clients_and_practice(): void
    {
        [, $therapist] = $this->fixture();
        $this->getJson('/api/v1/therapists/me/dashboard', $this->headers($therapist))->assertOk();
        Role::findByName('therapist', 'api')->revokePermissionTo('manage therapist care');
        foreach (['/therapists/me/dashboard', '/therapists/dashboard', '/therapists/clients', '/clients', '/therapists/content',
            '/therapists/wallet', '/wallet', '/therapists/reports', '/reports', '/therapists/me/sessions',
            '/therapists/me/reviews', '/therapists/me/blocked-periods', '/therapists/me/switch-requests'] as $path) {
            $this->getJson('/api/v1'.$path, $this->headers($therapist))->assertForbidden();
        }
        $this->postJson('/api/v1/therapists/content', [], $this->headers($therapist))->assertForbidden();
        $this->postJson('/api/v1/wallet/withdraw', [], $this->headers($therapist))->assertForbidden();
        $this->getJson('/api/v1/therapists/me/documents', $this->headers($therapist))->assertOk();
        $therapist->givePermissionTo('manage therapist care');
        $this->getJson('/api/v1/therapists/me/dashboard', $this->headers($therapist))->assertOk();
    }

    public function test_shared_session_reads_and_writes_honor_role_specific_permissions(): void
    {
        [$patient, $therapist, $session] = $this->fixture();
        foreach ([$patient, $therapist] as $user) {
            $this->getJson("/api/v1/sessions/{$session->id}", $this->headers($user))->assertOk();
            Role::findByName($user->role->value, 'api')->revokePermissionTo('view appointments');
            $this->getJson("/api/v1/sessions/{$session->id}", $this->headers($user))->assertForbidden();
            $this->getJson("/api/v1/sessions/{$session->id}/recommendation", $this->headers($user))->assertForbidden();
        }
        Role::findByName('therapist', 'api')->revokePermissionTo('manage appointments');
        $this->postJson("/api/v1/sessions/{$session->id}/cancel", [], $this->headers($therapist))->assertForbidden();
        $this->assertSame('pending', $session->fresh()->status->value);
    }

    public function test_permissions_and_extra_spatie_roles_never_escape_role_status_or_ownership_ceilings(): void
    {
        [$patient, $therapist, $session] = $this->fixture();
        $patient->givePermissionTo(Permission::where('guard_name', 'api')->get());
        $patient->assignRole(['therapist', 'admin']);
        $this->postJson("/api/v1/sessions/{$session->id}/confirm", [], $this->headers($patient))->assertForbidden();
        $this->getJson('/api/v1/therapists/clients', $this->headers($patient))->assertForbidden();
        $this->getJson('/api/v1/admin/users', $this->headers($patient))->assertForbidden();
        $therapist->givePermissionTo(Permission::where('guard_name', 'api')->get());
        $therapist->assignRole('patient');
        $this->getJson('/api/v1/patients/dashboard', $this->headers($therapist))->assertForbidden();
        $this->postJson('/api/v1/subscriptions', [], $this->headers($therapist))->assertForbidden();
        $therapist->therapist->update(['approval_status' => 'rejected']);
        $this->postJson("/api/v1/sessions/{$session->id}/confirm", [], $this->headers($therapist))->assertForbidden();
        $stranger = $this->user('therapist');
        Therapist::create(['user_id' => $stranger->id, 'full_name' => 'Synthetic Stranger', 'specialty' => 'cbt', 'country' => 'US', 'approval_status' => 'approved']);
        $this->postJson("/api/v1/sessions/{$session->id}/confirm", [], $this->headers($stranger))->assertUnprocessable()->assertJsonValidationErrorFor('session');
        $this->postJson("/api/v1/sessions/{$session->id}/cancel", [], $this->headers($stranger))->assertForbidden();
        $finance = $this->user('finance_partner');
        $finance->givePermissionTo(Permission::where('guard_name', 'api')->get());
        $this->getJson('/api/v1/therapists/clients', $this->headers($finance))->assertForbidden();
        $this->getJson('/api/v1/patients/dashboard', $this->headers($finance))->assertForbidden();
        $this->postJson("/api/v1/sessions/{$session->id}/confirm", [], $this->headers($finance))->assertForbidden();
        $this->assertSame('pending', $session->fresh()->status->value);
    }

    public function test_chat_permission_revocation_preserves_pair_ownership_and_baseline_access(): void
    {
        [$patient, $therapist] = $this->fixture();
        $this->getJson("/api/v1/chat/{$therapist->id}", $this->headers($patient))->assertOk();
        foreach ([$patient, $therapist] as $user) {
            Role::findByName($user->role->value, 'api')->revokePermissionTo('access care chat');
            $this->getJson('/api/v1/chat', $this->headers($user))->assertForbidden();
            $this->postJson('/api/v1/chat/'.($user->id === $patient->id ? $therapist->id : $patient->id), ['body' => 'Synthetic message'], $this->headers($user))->assertForbidden();
        }
        $patient->givePermissionTo('access care chat');
        $this->getJson("/api/v1/chat/{$therapist->id}", $this->headers($patient))->assertOk();
        $this->getJson('/api/v1/chat/'.$this->user('therapist')->id, $this->headers($patient))->assertNotFound();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_permission_migration_is_scoped_additive_and_never_restores_revocations(): void
    {
        $admin = Role::findByName('admin', 'api');
        $admin->revokePermissionTo('manage users');
        $patient = Role::findByName('patient', 'api');
        $patient->revokePermissionTo(['book appointments', 'access care chat']);
        $therapist = Role::findByName('therapist', 'api');
        $therapist->revokePermissionTo('manage therapist care');
        $finance = Role::findByName('finance_partner', 'api');
        $finance->givePermissionTo('manage content');
        Permission::findByName('access patient care', 'api')->delete();
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_03_110001_add_care_permissions.php');
        $migration->up();
        $this->assertTrue($patient->fresh()->hasPermissionTo('access patient care', 'api'));
        $this->assertFalse($patient->fresh()->hasPermissionTo('book appointments', 'api'));
        $this->assertFalse($patient->fresh()->hasPermissionTo('access care chat', 'api'));
        $this->assertFalse($therapist->fresh()->hasPermissionTo('manage therapist care', 'api'));
        $this->assertFalse($admin->fresh()->hasPermissionTo('manage users', 'api'));
        $this->assertTrue($finance->fresh()->hasPermissionTo('manage content', 'api'));
        $this->assertFalse($finance->fresh()->hasPermissionTo('access patient care', 'api'));
        $patient->fresh()->revokePermissionTo('access patient care');
        $migration->up();
        $migration->down();
        $this->assertFalse($patient->fresh()->hasPermissionTo('access patient care', 'api'));
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
    }

    public function test_operational_route_inventory_has_explicit_permission_gates_and_seeded_baselines(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (str_starts_with($route->uri(), 'api/v1/sessions')) {
                $this->assertContains(EnsureSessionPermission::class, $middleware, $route->uri());
            }
            foreach ($middleware as $gate) {
                if (! str_starts_with($gate, PermissionMiddleware::class.':')) {
                    continue;
                }
                [$permission] = explode(',', substr($gate, strlen(PermissionMiddleware::class) + 1));
                if (! in_array($permission, ['access patient care', 'manage therapist care', 'access care chat'], true)) {
                    continue;
                }
                $this->assertContains('status', $middleware, $route->uri());
                $roles = $permission === 'access care chat' ? ['patient', 'therapist'] : [$permission === 'access patient care' ? 'patient' : 'therapist'];
                foreach ($roles as $role) {
                    $this->assertTrue(Role::findByName($role, 'api')->hasPermissionTo($permission, 'api'), $route->uri());
                }
            }
        }
    }

    public function test_resend_rereads_activation_and_cooldown_not_stale_model(): void
    {
        $actor = $this->user('admin');
        $sender = new FakeWhatsAppSender;
        $this->app->instance(WhatsAppSenderInterface::class, $sender);
        $user = app(StaffAccountService::class)->invite($actor, [
            'name' => 'Synthetic Invite', 'email' => 'stale-invite@example.test',
            'whatsapp_number' => '+15558880001', 'role' => 'finance_partner',
        ])['user'];
        preg_match('/activation code ([A-Z0-9]{8})/', $sender->messages[0]['message'], $matches);
        $this->travel(61)->seconds();
        app(AuthService::class)->activate($user->whatsapp_number, $matches[1], 'SyntheticNew1!');
        try {
            app(StaffAccountService::class)->resendInvitation($actor, $user);
            $this->fail('A stale unverified snapshot must not permit resend after activation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user', $exception->errors());
        }
        $this->assertSame(1, AccountInvitation::where('user_id', $user->id)->count());
        $this->assertTrue($user->fresh()->isVerified());
    }
}
