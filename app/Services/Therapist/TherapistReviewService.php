<?php

namespace App\Services\Therapist;

use App\Enums\SessionStatus;
use App\Exceptions\ConflictException;
use App\Models\Patient;
use App\Models\Review;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Services\AuditLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Patient ratings of therapists. A patient may review a therapist only after
 * at least one completed session with them, once per therapist; the review
 * can be edited afterwards. Therapist.rating is the rounded mean of all
 * reviews and is recomputed on every write.
 */
class TherapistReviewService
{
    public function __construct(private AuditLogService $audit) {}

    public function create(Patient $patient, string $therapistId, array $data): Review
    {
        $therapist = $this->eligibleTherapist($patient, $therapistId);

        if (Review::where('patient_id', $patient->user_id)->where('therapist_id', $therapist->user_id)->exists()) {
            throw new ConflictException('You have already reviewed this therapist; edit your existing review instead.');
        }

        try {
            $review = DB::transaction(function () use ($patient, $therapist, $data) {
                $review = Review::create([
                    'id' => (string) Str::uuid(),
                    'patient_id' => $patient->user_id,
                    'therapist_id' => $therapist->user_id,
                    'rating' => (int) $data['rating'],
                    'comment' => $data['comment'] ?? null,
                ]);

                $this->recalculate($therapist);

                $this->audit->record($patient->user_id, AuditLogService::REVIEW_CREATED, $review->id, [
                    'therapist_id' => $therapist->user_id, 'rating' => $review->rating,
                ]);

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictException('You have already reviewed this therapist; edit your existing review instead.');
        }

        return $review;
    }

    public function update(Patient $patient, string $therapistId, array $data): Review
    {
        $review = Review::where('patient_id', $patient->user_id)
            ->where('therapist_id', $therapistId)
            ->first()
            ?? throw ValidationException::withMessages(['review' => 'You have not reviewed this therapist yet.']);

        return DB::transaction(function () use ($review, $data) {
            $payload = [];
            if (isset($data['rating'])) {
                $payload['rating'] = (int) $data['rating'];
            }
            if (array_key_exists('comment', $data)) {
                $payload['comment'] = $data['comment'];
            }
            $review->update($payload);

            $this->recalculate($review->therapist);

            $this->audit->record($review->patient_id, AuditLogService::REVIEW_UPDATED, $review->id, [
                'therapist_id' => $review->therapist_id, 'rating' => $review->rating,
            ]);

            return $review->refresh();
        });
    }

    public function mine(Patient $patient, string $therapistId): ?Review
    {
        return Review::where('patient_id', $patient->user_id)->where('therapist_id', $therapistId)->first();
    }

    /**
     * Public (authenticated) view of a therapist's reviews: no patient identity
     * is exposed, only the rating, comment and date.
     */
    public function forTherapist(string $therapistId, int $perPage = 15): array
    {
        $therapist = Therapist::where('user_id', $therapistId)->firstOrFail();

        $rows = Review::where('therapist_id', $therapist->user_id)
            ->orderByDesc('created_at')
            ->paginate(max(1, min($perPage, 50)));

        return [
            'therapist_id' => $therapist->user_id,
            'rating' => $therapist->rating,
            'reviews_count' => Review::where('therapist_id', $therapist->user_id)->count(),
            'data' => $rows->through(fn (Review $r) => $this->toPublicArray($r)),
        ];
    }

    /** A therapist reading their own reviews (still anonymous). */
    public function ownReviews(Therapist $therapist, int $perPage = 15): LengthAwarePaginator
    {
        return Review::where('therapist_id', $therapist->user_id)
            ->orderByDesc('created_at')
            ->paginate(max(1, min($perPage, 50)))
            ->through(fn (Review $r) => $this->toPublicArray($r));
    }

    private function eligibleTherapist(Patient $patient, string $therapistId): Therapist
    {
        $therapist = Therapist::where('user_id', $therapistId)->first()
            ?? throw ValidationException::withMessages(['therapist_id' => 'Therapist not found.']);

        $completed = TherapySession::where('patient_id', $patient->user_id)
            ->where('therapist_id', $therapist->user_id)
            ->where('status', SessionStatus::COMPLETED->value)
            ->exists();

        if (! $completed) {
            throw ValidationException::withMessages([
                'therapist_id' => 'You can review a therapist only after completing a session with them.',
            ]);
        }

        return $therapist;
    }

    private function recalculate(Therapist $therapist): void
    {
        $avg = Review::where('therapist_id', $therapist->user_id)->avg('rating');

        Therapist::where('user_id', $therapist->user_id)
            ->update(['rating' => $avg === null ? 0 : round((float) $avg, 2)]);
    }

    public function toArray(Review $review): array
    {
        return [
            'id' => $review->id,
            'therapist_id' => $review->therapist_id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];
    }

    private function toPublicArray(Review $review): array
    {
        return [
            'id' => $review->id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }
}
