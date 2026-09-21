<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->setOwnPassword($validated['password']);

        return back()->with('status', 'password-updated');
    }

    /**
     * Forced change after signing in with a temporary password. The modal in
     * the app layout posts here; it cannot be dismissed until this succeeds.
     */
    public function updateTemporary(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('temporaryPassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed', 'different:current_password'],
        ]);

        $request->user()->setOwnPassword($validated['password']);

        return back()->with('status', 'Password updated. Welcome aboard!');
    }
}
