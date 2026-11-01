<?php

namespace App\Providers;

use App\Domain\Workflow\Workflow;
use App\Enums\BookingTypeEnum;
use App\Enums\RoleEnum;
use App\Models\BookingTypeHasRole;
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
        // The booking route's rules. Built once per request (or test), on first use - the database is read then, not at boot.
        $this->app->singleton(Workflow::class, fn (): Workflow => new Workflow(
            controlOfficerBookingTypeIds: BookingTypeHasRole::query()
                ->where('role_id', RoleEnum::TRANSPORTATION_CONTROL_OFFICER->value)
                ->pluck('booking_type_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            transportationBookingTypeId: BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId(),
            barberBookingTypeId: BookingTypeEnum::BARBER_BOOKING->getBookingTypeId(),
        ));
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
