<?php

namespace App\Providers;

use App\Domain\Clearance\ClearanceRegistry;
use App\Domain\Clearance\NullClearanceRegistry;
use App\Domain\Workflow\Routes\RouteRegistry;
use App\Domain\Workflow\Workflow;
use App\Enums\BookingTypeEnum;
use App\Enums\RoleEnum;
use App\Models\BookingTypeHasRole;
use App\Support\PermissionRules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /** Bind the booking workflow. */
    public function register(): void
    {
        // Lazy: the database is read on first use, not at boot.
        $this->app->singleton(Workflow::class, fn (): Workflow => new Workflow(
            controlOfficerBookingTypeIds: BookingTypeHasRole::query()
                ->where('role_id', RoleEnum::TRANSPORTATION_CONTROL_OFFICER->value)
                ->pluck('booking_type_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            transportationBookingTypeId: BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId(),
            barberBookingTypeId: BookingTypeEnum::BARBER_BOOKING->getBookingTypeId(),
            // The types with a route of their own (the clearance grant), by DB id.
            routes: RouteRegistry::byBookingTypeId(),
        ));

        // Which "{role}_{type}" permissions are real grants (the type has the role); the links are read once per application.
        $this->app->singleton(PermissionRules::class);

        // No clearance registry (ותיקן) is connected yet: bind a real implementation here when there is one.
        $this->app->bind(ClearanceRegistry::class, NullClearanceRegistry::class);
    }

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
