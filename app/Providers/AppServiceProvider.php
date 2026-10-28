<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /** Register any application services. */
    public function register(): void
    {
        //
    }

    /** Bootstrap services: rate limiting. */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(config('app.rate_limitation'))->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response('בקשות מרובות לשרת. נא לנסות שוב מאוחר יותר', Response::HTTP_TOO_MANY_REQUESTS, $headers);
                });
        });
    }
}
