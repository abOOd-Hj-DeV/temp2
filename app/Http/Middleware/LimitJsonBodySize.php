<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps non-multipart request bodies well below PHP's post_max_size so a
 * client can't push megabytes of JSON through validation.
 */
class LimitJsonBodySize
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('sakina.max_json_body_bytes', 262144);
        $contentType = (string) $request->header('Content-Type', '');

        if ($limit > 0 && ! str_starts_with(strtolower($contentType), 'multipart/form-data')) {
            $length = (int) $request->server('CONTENT_LENGTH', 0);
            if ($length === 0) {
                $length = strlen($request->getContent());
            }

            if ($length > $limit) {
                return response()->json(['message' => 'Request body too large.'], 413);
            }
        }

        return $next($request);
    }
}
