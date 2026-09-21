<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backs the "change your temporary password" modal. Pages still render (the
 * layout shows the modal on top of them), but nothing can be submitted until
 * the user has picked their own password — the only writes allowed are the
 * password change itself and signing out.
 */
class RequirePasswordChange
{
    private const ALLOWED_ROUTES = ['password.temporary', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->isMethodSafe() && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Change your temporary password first.'], 423);
            }

            return back()->withErrors(['password' => 'Change your temporary password before doing anything else.'], 'temporaryPassword');
        }

        return $next($request);
    }
}
