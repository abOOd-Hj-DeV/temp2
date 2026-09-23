<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey as StoredKey;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replay protection for mutating endpoints. A client that sends the same
 * `Idempotency-Key` twice (network retry, double tap) receives the stored
 * first response instead of a second side effect. The (user, key) pair is
 * unique at the database level, so two concurrent identical requests cannot
 * both execute: the loser sees 409 while the winner is in flight.
 *
 * The header is optional so existing clients keep working; every route that
 * creates money- or clinical-state should carry this middleware.
 *
 * A 5xx releases the key only while nothing was committed; once the handler
 * has committed a transaction the key is kept (and the 5xx recorded) so a
 * client retry cannot create a second primary effect.
 */
class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    private const TTL_HOURS = 24;

    private const COMMITTED_ATTR = 'idempotency.committed';

    private static bool $listening = false;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(self::HEADER, ''));
        $user = $request->user();

        if ($key === '' || ! $user || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        if (strlen($key) < 8 || strlen($key) > 128 || ! ctype_print($key)) {
            return new JsonResponse(['message' => 'Idempotency-Key must be 8–128 printable characters.'], 422);
        }

        $route = $request->method().' '.$request->path();
        $hash = $this->fingerprint($request);

        try {
            $stored = DB::transaction(fn () => StoredKey::create([
                'user_id' => $user->id,
                'key' => $key,
                'route' => $route,
                'request_hash' => $hash,
                'locked_at' => now(),
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->replay($user->id, $key, $route, $hash);
        }

        $this->trackCommits($request);

        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            if (! $request->attributes->get(self::COMMITTED_ATTR, false)) {
                $stored->delete();

                return $response;
            }

            Log::critical('Idempotent request failed after commit; key retained to block a second side effect', [
                'user_id' => $user->id,
                'route' => $route,
                'status' => $response->getStatusCode(),
            ]);
        }

        $stored->forceFill([
            'response_code' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
            'completed_at' => now(),
        ])->save();

        return $response;
    }

    private function trackCommits(Request $request): void
    {
        $request->attributes->set(self::COMMITTED_ATTR, false);

        if (self::$listening) {
            return;
        }

        self::$listening = true;

        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) {
            if ($event->connection->transactionLevel() === 0) {
                app('request')->attributes->set(self::COMMITTED_ATTR, true);
            }
        });
    }

    private function replay(string $userId, string $key, string $route, string $hash): Response
    {
        $stored = StoredKey::where('user_id', $userId)->where('key', $key)->first();

        if (! $stored || $stored->expires_at->isPast()) {
            return new JsonResponse(['message' => 'Idempotency-Key expired. Retry with a new key.'], 409);
        }

        if ($stored->route !== $route || $stored->request_hash !== $hash) {
            return new JsonResponse(['message' => 'Idempotency-Key was already used with a different request.'], 422);
        }

        if ($stored->completed_at === null) {
            return new JsonResponse(['message' => 'A request with this Idempotency-Key is still being processed.'], 409);
        }

        try {
            $rawBody = (string) $stored->response_body;
        } catch (DecryptException) {
            // Row written before bodies were encrypted; still a valid replay.
            $rawBody = (string) $stored->getRawOriginal('response_body');
        }

        $body = json_decode($rawBody, true) ?? [];

        return new JsonResponse($body, (int) $stored->response_code, ['Idempotency-Replayed' => 'true']);
    }

    private function fingerprint(Request $request): string
    {
        $payload = $request->except(array_keys($request->allFiles()));
        ksort($payload);

        $files = [];
        foreach ($request->allFiles() as $field => $file) {
            $files[$field] = is_array($file) ? count($file) : [$file->getClientOriginalName(), $file->getSize()];
        }

        return hash('sha256', json_encode([$payload, $files]));
    }
}
