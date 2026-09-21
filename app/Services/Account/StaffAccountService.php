<?php

namespace App\Services\Account;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Models\AccountInvitation;
use App\Models\Therapist;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Messaging\WhatsAppSenderInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Staff and therapist accounts are never self-registered: management
 * creates them, the platform sends a one-time activation code over
 * WhatsApp, and the invitee chooses a password with it. Until then the
 * account is inactive and unverified, so it cannot log in.
 *
 * Who may create whom:
 *   super_admin         -> admin, clinical_supervisor, finance_partner, therapist
 *   admin               -> clinical_supervisor, finance_partner, therapist
 *   clinical_supervisor -> therapist
 */
class StaffAccountService
{
    public const INVITATION_TTL_HOURS = 24;

    public const CODE_LENGTH = 8;

    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const MAX_ATTEMPTS = 5;

    private const RESEND_COOLDOWN_SECONDS = 60;

    private const CREATABLE_ROLES = [
        'super_admin' => ['admin', 'clinical_supervisor', 'finance_partner', 'therapist'],
        'admin' => ['clinical_supervisor', 'finance_partner', 'therapist'],
        'clinical_supervisor' => ['therapist'],
    ];

    public function __construct(
        private WhatsAppSenderInterface $whatsApp,
        private AuditLogService $audit,
    ) {}

    /** @return list<string> */
    public static function creatableRolesFor(User $actor): array
    {
        return self::CREATABLE_ROLES[$actor->role?->value] ?? [];
    }

    /**
     * @param  array{name: string, email: string, whatsapp_number: string, role: string, therapist?: array}  $data
     * @return array{user: User, expires_at: \Illuminate\Support\Carbon}
     */
    public function invite(User $actor, array $data): array
    {
        $role = UserRole::from($data['role']);

        if (! in_array($role->value, self::creatableRolesFor($actor), true)) {
            throw new AccessDeniedHttpException('You are not allowed to create accounts with this role.');
        }

        return $this->createInvited($actor, $role, $data);
    }

    /**
     * First-run only: creates the platform's first super_admin. Refuses
     * whenever one already exists, so it can never be used to add another.
     */
    public function bootstrapSuperAdmin(array $data): array
    {
        if (User::where('role', UserRole::SUPER_ADMIN->value)->exists()) {
            throw new \RuntimeException('A super_admin already exists; create further staff from the admin API.');
        }

        return $this->createInvited(null, UserRole::SUPER_ADMIN, $data);
    }

    private function createInvited(?User $actor, UserRole $role, array $data): array
    {
        $email = mb_strtolower(trim($data['email']));

        $this->assertIdentityAvailable($email, $data['whatsapp_number']);

        [$user, $invitation, $code] = DB::transaction(function () use ($actor, $role, $data, $email) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'role' => $role->value,
                'whatsapp_number' => $data['whatsapp_number'],
                'is_active' => false,
                'phone_verified_at' => null,
                'login_attempts' => 0,
            ]);

            if ($role === UserRole::THERAPIST) {
                $profile = $data['therapist'] ?? [];
                Therapist::create([
                    'user_id' => $user->id,
                    'full_name' => $profile['full_name'] ?? $data['name'],
                    'specialty' => $profile['specialty'],
                    'country' => $profile['country'],
                    'languages' => $profile['languages'] ?? [],
                    'bio' => $profile['bio'] ?? null,
                    'availability' => [],
                    'approval_status' => ApprovalStatus::APPROVED->value,
                    'clients_limit' => $profile['clients_limit'] ?? 20,
                ]);
            }

            [$invitation, $code] = $this->issue($user, $actor);

            $this->audit->record($actor ?? $user, AuditLogService::STAFF_ACCOUNT_CREATED, $user->id, [
                'role' => $role->value,
                'bootstrap' => $actor === null,
            ]);

            return [$user, $invitation, $code];
        });

        $this->deliver($user, $invitation, $code, deleteUserOnFailure: true);

        return ['user' => $user, 'expires_at' => $invitation->expires_at];
    }

    public function resendInvitation(User $actor, User $user): array
    {
        $this->assertManages($actor, $user);

        if ($user->isVerified()) {
            throw ValidationException::withMessages(['user' => __('This account is already activated.')]);
        }

        $latest = AccountInvitation::where('user_id', $user->id)->latest('created_at')->first();
        if ($latest && $latest->created_at->addSeconds(self::RESEND_COOLDOWN_SECONDS)->isFuture()) {
            throw ValidationException::withMessages(['user' => __('Please wait before resending the invitation.')]);
        }

        [$invitation, $code] = DB::transaction(function () use ($user, $actor) {
            AccountInvitation::where('user_id', $user->id)->open()->update(['revoked_at' => now()]);

            $pair = $this->issue($user, $actor);
            $this->audit->record($actor, AuditLogService::STAFF_INVITATION_RESENT, $user->id);

            return $pair;
        });

        $this->deliver($user, $invitation, $code, deleteUserOnFailure: false);

        return ['expires_at' => $invitation->expires_at];
    }

    /**
     * Redeem an activation code: sets the password, marks the number verified
     * and activates the account. Returns false on any invalid/expired/exhausted
     * code so the endpoint stays indistinguishable for attackers.
     */
    public function activate(User $user, string $code, string $password): bool
    {
        return DB::transaction(function () use ($user, $code, $password) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($user->isVerified()) {
                return false;
            }

            $invitation = AccountInvitation::where('user_id', $user->id)->open()->lockForUpdate()->latest('created_at')->first();

            if (! $invitation || ! $invitation->isUsable()) {
                return false;
            }

            if (! hash_equals($invitation->code_hash, $this->hash($code))) {
                $invitation->attempts++;
                if ($invitation->attempts >= self::MAX_ATTEMPTS) {
                    $invitation->revoked_at = now();
                    Log::warning('Account invitation exhausted', ['user_id' => $user->id]);
                }
                $invitation->save();

                return false;
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            $user->forceFill([
                'password' => Hash::make($password),
                'phone_verified_at' => now(),
                'is_active' => true,
                'login_attempts' => 0,
            ])->save();

            $this->audit->record($user, AuditLogService::STAFF_ACCOUNT_ACTIVATED, $user->id);

            return true;
        });
    }

    public function setActive(User $actor, User $user, bool $active): User
    {
        $this->assertManages($actor, $user);

        if (! $user->isVerified()) {
            throw ValidationException::withMessages(['user' => __('This account has not been activated yet.')]);
        }

        return DB::transaction(function () use ($actor, $user, $active) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_active !== $active) {
                $locked->forceFill(['is_active' => $active])->save();

                if (! $active) {
                    $locked->revokeAllTokens();
                }

                $this->audit->record($actor, $active ? AuditLogService::STAFF_ACCOUNT_REACTIVATED : AuditLogService::STAFF_ACCOUNT_DEACTIVATED, $locked->id);
            }

            return $locked;
        });
    }

    public function paginate(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = User::query()
            ->where('role', '!=', UserRole::PATIENT->value)
            ->with(['therapist:user_id,full_name,specialty,approval_status,clients_count,clients_limit'])
            ->orderByDesc('created_at');

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['pending_activation'])) {
            $query->whereNull('phone_verified_at');
        }

        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('whatsapp_number', 'like', $like));
        }

        return $query->paginate($perPage)->through(fn (User $user) => $this->toArray($user));
    }

    public function toArray(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'whatsapp_number' => $user->whatsapp_number,
            'role' => $user->role?->value,
            'is_active' => (bool) $user->is_active,
            'activated' => $user->isVerified(),
            'last_login' => $user->last_login?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
            'therapist' => $user->therapist ? [
                'full_name' => $user->therapist->full_name,
                'specialty' => $user->therapist->specialty,
                'approval_status' => $user->therapist->approval_status?->value,
                'clients_count' => (int) $user->therapist->clients_count,
                'clients_limit' => (int) $user->therapist->clients_limit,
            ] : null,
        ];
    }

    /** @return array{0: AccountInvitation, 1: string} */
    private function issue(User $user, ?User $actor): array
    {
        $code = $this->generateCode();

        $invitation = AccountInvitation::create([
            'user_id' => $user->id,
            'invited_by' => $actor?->id,
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addHours(self::INVITATION_TTL_HOURS),
        ]);

        return [$invitation, $code];
    }

    private function deliver(User $user, AccountInvitation $invitation, string $code, bool $deleteUserOnFailure): void
    {
        try {
            $sent = $this->whatsApp->send($user->whatsapp_number, sprintf(
                'Welcome to Sakina. Your account (%s) is ready: use activation code %s in the app to set your password. It expires in %d hours.',
                $user->role->label(),
                $code,
                self::INVITATION_TTL_HOURS
            ));
        } catch (\Throwable $e) {
            Log::error('Invitation dispatch failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            $sent = false;
        }

        if ($sent) {
            return;
        }

        if ($deleteUserOnFailure) {
            $user->delete();
        } else {
            $invitation->forceFill(['revoked_at' => now()])->save();
        }

        throw ValidationException::withMessages([
            'whatsapp_number' => __('Failed to deliver the activation code to this WhatsApp number.'),
        ]);
    }

    /**
     * Only management may manage other staff; nobody manages themselves,
     * patients are out of scope, and admins cannot touch super_admins or
     * each other.
     */
    private function assertManages(User $actor, User $user): void
    {
        $allowed = $actor->id !== $user->id
            && $user->role !== UserRole::PATIENT
            && (
                $actor->role === UserRole::SUPER_ADMIN
                || ($actor->role === UserRole::ADMIN && ! in_array($user->role, [UserRole::SUPER_ADMIN, UserRole::ADMIN], true))
                || ($actor->role === UserRole::CLINICAL_SUPERVISOR && $user->role === UserRole::THERAPIST)
            );

        if (! $allowed) {
            throw new AccessDeniedHttpException('You are not allowed to manage this account.');
        }
    }

    private function assertIdentityAvailable(string $email, string $whatsapp): void
    {
        $errors = [];

        if (User::where('email', $email)->exists()) {
            $errors['email'] = __('This email is already registered.');
        }

        if (User::where('whatsapp_number', $whatsapp)->exists()) {
            $errors['whatsapp_number'] = __('This WhatsApp number is already registered.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function generateCode(): string
    {
        $code = '';
        $max = strlen(self::CODE_ALPHABET) - 1;

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', strtoupper(trim($code)), (string) config('app.key'));
    }
}
