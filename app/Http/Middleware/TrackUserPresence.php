<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feeds the Online/Offline column on User Management and enforces the
 * "disabled" switch: a disabled account is signed out on its next request
 * instead of staying logged in until the session expires.
 */
class TrackUserPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            if ($user->isDisabled()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors(['username' => __('This account has been disabled. Contact your administrator.')]);
            }

            $user->touchLastSeen();
        }

        return $next($request);
    }
}
