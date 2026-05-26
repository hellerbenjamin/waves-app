<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            // The token is hidden from the global auth.user prop, so pass the
            // public link explicitly here.
            'shareUrl' => $user->share_token ? route('profile.shared', $user->share_token) : null,
        ]);
    }

    /** Mint (once) the one link that shares all of this user's events. */
    public function share(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->share_token) {
            $user->update(['share_token' => Str::random(32)]);
        }

        return response()->json(['share_url' => route('profile.shared', $user->share_token)]);
    }

    /** Revoke the profile link; individual event/track/media shares are untouched. */
    public function unshare(Request $request): SymfonyResponse
    {
        $request->user()->update(['share_token' => null]);

        return response()->noContent();
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
