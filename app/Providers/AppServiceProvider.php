<?php

namespace App\Providers;

use App\Models\EventInvite;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Resolve the contribution token to its invite. An explicit binder (not
        // {invite:token} implicit binding) so the shared upload-trait methods,
        // whose signature is just Request, can still read it via the route.
        Route::bind('invite', fn (string $token) => EventInvite::where('token', $token)->firstOrFail());
    }
}
