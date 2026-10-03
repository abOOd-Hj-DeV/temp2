<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;

class EnsureSessionPermission
{
    public function handle(Request $request, Closure $next)
    {
        $role = $request->user()->role;
        $care = match ($role) {
            UserRole::PATIENT => 'access patient care',
            UserRole::THERAPIST => 'manage therapist care',
            UserRole::ADMIN, UserRole::SUPER_ADMIN, UserRole::CLINICAL_SUPERVISOR => null,
            default => abort(403),
        };
        $permission = in_array($request->method(), ['GET', 'HEAD'], true)
            ? 'view appointments'
            : ($role === UserRole::PATIENT ? 'book appointments' : 'manage appointments');
        $middleware = app(PermissionMiddleware::class);
        $authorized = fn ($request) => $middleware->handle($request, $next, $permission, 'api');

        return $care === null ? $authorized($request) : $middleware->handle($request, $authorized, $care, 'api');
    }
}
