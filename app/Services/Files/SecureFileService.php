<?php

namespace App\Services\Files;

use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\Therapist;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Private file storage with per-path authorization. Nothing here is ever
 * reachable through the public /storage symlink.
 *
 * Path families:
 *   uploads/{user_id}/{purpose}/…   generic uploads (owner + staff)
 *   payment-proofs/{patient_id}/…   owner patient + finance/staff
 *   licenses/{therapist_id}/…       owner therapist + staff
 */
class SecureFileService
{
    public const PURPOSES = ['payment_proof', 'license', 'other'];

    public function __construct(private AuditLogService $audit) {}

    public function upload(User $user, UploadedFile $file, string $purpose): array
    {
        $path = $file->store("uploads/{$user->id}/{$purpose}", ['disk' => $this->disk()]);

        return [
            'path' => $path,
            'purpose' => $purpose,
            'original_name' => self::safeOriginalName($file->getClientOriginalName()),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ];
    }

    /**
     * Client-supplied filenames are metadata only (storage names are generated);
     * strip directory components, control characters and cap the length so the
     * value is safe to echo back or log.
     */
    public static function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name, ' .');

        if ($name === '' || ! mb_check_encoding($name, 'UTF-8')) {
            return 'upload';
        }

        return mb_substr($name, 0, 120);
    }

    /**
     * Resolve and authorize a download; returns the sanitized storage path.
     */
    public function authorizeDownload(User $user, string $rawPath): string
    {
        $path = $this->sanitize($rawPath);

        if (! $this->canAccess($user, $path)) {
            // Same response for "not yours" and "does not exist".
            throw new NotFoundHttpException('File not found.');
        }

        if (! Storage::disk($this->disk())->exists($path)) {
            throw new NotFoundHttpException('File not found.');
        }

        $this->audit->record($user, AuditLogService::FILE_DOWNLOADED, null, ['path' => $path]);

        return $path;
    }

    public function disk(): string
    {
        return config('sakina.uploads_disk', 'local');
    }

    /**
     * Reject traversal, absolute paths, NUL bytes and anything outside the
     * known path families.
     */
    public function sanitize(string $raw): string
    {
        $path = str_replace('\\', '/', urldecode($raw));

        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/')) {
            throw new AccessDeniedHttpException('Invalid path.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new AccessDeniedHttpException('Invalid path.');
            }
        }

        if (! preg_match('#^(uploads|payment-proofs|licenses)/[0-9a-f-]{36}/#i', $path)) {
            throw new AccessDeniedHttpException('Invalid path.');
        }

        return $path;
    }

    private function canAccess(User $user, string $path): bool
    {
        [$family, $ownerId] = explode('/', $path, 3);

        $isOwner = $ownerId === $user->id;
        $isStaff = in_array($user->role, [UserRole::ADMIN, UserRole::SUPER_ADMIN], true);
        $isFinance = $isStaff || $user->role === UserRole::FINANCE_PARTNER;

        return match ($family) {
            'uploads' => $isOwner || $isStaff,
            'payment-proofs' => ($isOwner && Payment::where('proof_file_path', $path)->exists()) || $isFinance,
            'licenses' => ($isOwner && Therapist::where('license_file_path', $path)->exists()) || $isStaff,
            default => false,
        };
    }
}
