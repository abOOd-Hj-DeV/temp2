<?php

namespace App\Services\Package;

use App\Exceptions\ConflictException;
use App\Models\Package;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Head Master (clinical_supervisor) / admin catalogue of subscription
 * packages. Patients only ever see published packages; the session count
 * and duration are frozen onto each subscription at purchase time so later
 * catalogue edits never alter what a patient already paid for.
 */
class PackageService
{
    public function __construct(private AuditLogService $audit) {}

    public function all(): Collection
    {
        return Package::orderBy('price')->get();
    }

    public function published(): Collection
    {
        return Package::published()->orderBy('price')->get();
    }

    public function findPublished(string $idOrCode): ?Package
    {
        return Package::published()
            ->where(fn ($q) => Str::isUuid($idOrCode) ? $q->where('id', $idOrCode) : $q->where('code', $idOrCode))
            ->first();
    }

    public function create(User $actor, array $data): Package
    {
        try {
            // Own (save-pointed) transaction so a unique violation cannot abort an enclosing one on PostgreSQL.
            $package = DB::transaction(fn () => Package::create($data + ['created_by' => $actor->id, 'is_published' => false]));
        } catch (UniqueConstraintViolationException) {
            throw new ConflictException('A package with this code already exists.');
        }

        $this->audit->record($actor, AuditLogService::PACKAGE_CREATED, $package->id, $data);

        return $package;
    }

    /** Published packages are read-only except for name/description. */
    public function update(User $actor, Package $package, array $data): Package
    {
        return DB::transaction(function () use ($actor, $package, $data) {
            $locked = Package::whereKey($package->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_published) {
                $illegal = array_diff(array_keys($data), ['name', 'description']);
                if ($illegal !== []) {
                    throw new ConflictException('Unpublish the package before changing '.implode(', ', $illegal).'.');
                }
            }

            $locked->update($data);
            $this->audit->record($actor, AuditLogService::PACKAGE_UPDATED, $locked->id, $data);

            return $locked;
        });
    }

    public function setPublished(User $actor, Package $package, bool $published): Package
    {
        return DB::transaction(function () use ($actor, $package, $published) {
            $locked = Package::whereKey($package->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_published === $published) {
                throw new ConflictException($published ? 'Already published.' : 'Already unpublished.');
            }

            if ($published && ($locked->number_of_sessions < 1 || $locked->duration_days < 1 || $locked->daily_sessions_quota < 1)) {
                throw ValidationException::withMessages([
                    'package' => 'Set the number of sessions, duration and daily quota before publishing.',
                ]);
            }

            $locked->update([
                'is_published' => $published,
                'published_by' => $published ? $actor->id : $locked->published_by,
                'published_at' => $published ? now() : $locked->published_at,
            ]);

            $this->audit->record($actor, $published ? AuditLogService::PACKAGE_PUBLISHED : AuditLogService::PACKAGE_UNPUBLISHED, $locked->id);

            return $locked;
        });
    }

    public function toArray(Package $package, bool $admin = false): array
    {
        $out = [
            'id' => $package->id,
            'code' => $package->code,
            'name' => $package->name,
            'description' => $package->description,
            'price' => $package->price,
            'number_of_sessions' => $package->number_of_sessions,
            'duration_days' => $package->duration_days,
            'daily_sessions_quota' => $package->daily_sessions_quota,
        ];

        if ($admin) {
            $out += [
                'is_published' => $package->is_published,
                'published_at' => $package->published_at?->toISOString(),
                'created_at' => $package->created_at?->toISOString(),
            ];
        }

        return $out;
    }
}
