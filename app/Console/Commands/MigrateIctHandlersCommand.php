<?php

namespace App\Console\Commands;

use App\Domain\Workflow\Routes\IctServiceDeskRoute;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Enums\StatusBookingEnum;
use App\Models\Booking;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BookingStatusRecorder;
use Database\Seeders\ProdSeeders\RoleAndPermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * One-off migration to the ICT service desk route (types 20-25): everyone holding ict_handler_N becomes a desk representative
 * (vice_approver on the ICT types) - or the desk commander (admin_ict), when their personal number is in --commanders - and the
 * open requests the old route left approved (status 3) move to "בטיפול המוקד" (111). Run BEFORE RoleAndPermissionSeeder alone would:
 * the holders are read first, then the seeder runs here (it deletes the ict_handler_N permissions), then the new grants are written.
 * With --dry-run nothing is changed: it only prints the plan.
 */
class MigrateIctHandlersCommand extends Command
{
    protected $signature = 'ict:migrate-handlers
        {--commanders= : Comma-separated personal numbers that become מפקד תקשוב (admin_ict); everyone else becomes נציג מוקד (vice_approver)}
        {--dry-run : Print the plan without changing anything}';

    protected $description = 'Moves the ict_handler holders to the service desk roles (vice_approver / admin_ict) and the approved (3) ICT requests to 111';

    public function handle(AuditService $audit, BookingStatusRecorder $recorder): int
    {
        $ictTypeIds = IctServiceDeskRoute::bookingTypeIds();

        if ($ictTypeIds === []) {
            $this->error('No ICT booking type exists in this database.');

            return self::FAILURE;
        }

        $commanders = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('commanders')))));

        // 1. Who holds ict_handler_N today - read BEFORE the seeder deletes those permissions.
        $holders = User::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', 'like', PermissionEnum::ICT_HANDLER->value . '%'))
            ->get();

        $approved = Booking::query()
            ->whereIn('booking_type_id', $ictTypeIds)
            ->where('status_id', StatusBookingEnum::APPROVED->value)
            ->orderBy('id')
            ->get();

        foreach ($holders as $holder) {
            $becomes = in_array((string) $holder->personal_number, $commanders, true) ? 'מפקד תקשוב (admin_ict)' : 'נציג מוקד (vice_approver)';
            $this->line("  {$holder->personal_number} ({$holder->full_name}): {$becomes}");
        }

        $this->line(sprintf('%d holder(s) of ict_handler; %d approved (3) ICT request(s) move to 111.', $holders->count(), $approved->count()));

        if ($this->option('dry-run')) {
            $this->warn('Nothing was changed (--dry-run).');

            return self::SUCCESS;
        }

        // 2. The seeder: creates admin_ict_N and vice_approver_N through the route, deletes the ict_handler_N permissions.
        (new RoleAndPermissionSeeder())->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () use ($holders, $commanders, $ictTypeIds, $audit): void {
            $ictHandlerRole = Role::query()->where('id', RoleEnum::ICT_HANDLER->value)->first();

            foreach ($holders as $holder) {
                /** @var User $user */
                $user = $holder->fresh();
                $before = $user->getAllPermissions()->pluck('name')->sort()->values()->all();

                $roleEnum = in_array((string) $user->personal_number, $commanders, true) ? RoleEnum::ADMIN_ICT : RoleEnum::VICE_APPROVER;
                $names = array_map(fn (int $bookingTypeId): string => $roleEnum->toPermissionEnum()->keyForId($bookingTypeId), $ictTypeIds);

                $user->assignRole($roleEnum->value);
                $user->givePermissionTo(Permission::query()->whereIn('name', $names)->get());

                // The old role goes once no permission of it is left (the seeder deleted them all).
                $stillHandles = $user->fresh()->getAllPermissions()->pluck('name')
                    ->contains(function (string $name): bool {
                        $parsed = PermissionEnum::parsePermissionName($name);

                        return $parsed !== null && $parsed['permission'] === PermissionEnum::ICT_HANDLER;
                    });

                if ($ictHandlerRole !== null && !$stillHandles) {
                    $user->removeRole($ictHandlerRole);
                }

                $after = $user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all();

                // Web-only roles and types: the mobile app never hears of this, so markRolesChanged is not needed.
                $audit->record([
                    'action' => 'user.permissions.change',
                    'entity_type' => 'user',
                    'entity_id' => $user->actorId(),
                    'unit_id' => $user->unit_id,
                    'before' => ['permissions' => $before],
                    'after' => ['permissions' => $after],
                    'reason' => 'מעבר מוקד תקשוב: מנהל תקשוב הוסב לתפקידי המוקד (ict:migrate-handlers)',
                    'source' => 'automation',
                ]);
            }
        });

        // 3. The open requests the old route approved (3) are now "בטיפול המוקד" (111) - through the recorder, so the timeline and audit carry it.
        $moved = 0;

        foreach ($approved as $request) {
            DB::transaction(function () use ($request, $recorder, $audit, &$moved): void {
                $booking = Booking::query()->whereKey($request->id)->lockForUpdate()->first();

                if ($booking === null || (int) $booking->status_id !== StatusBookingEnum::APPROVED->value) {
                    return;
                }

                $before = ['status_id' => (int) $booking->status_id, 'version' => (int) $booking->version];
                $correlationId = $recorder->apply($booking, StatusBookingEnum::IN_SERVICE_DESK, null, 'system');

                $audit->record([
                    'action' => 'booking.status_change',
                    'entity_type' => 'booking',
                    'entity_id' => $booking->serial_number,
                    'booking_serial' => $booking->serial_number,
                    'unit_id' => $booking->unit_id,
                    'booking_type_id' => $booking->booking_type_id,
                    'before' => $before,
                    'after' => ['status_id' => StatusBookingEnum::IN_SERVICE_DESK->value, 'version' => (int) $booking->version],
                    'reason' => 'מעבר מוקד תקשוב: קריאה שאושרה במסלול הישן הועברה לטיפול המוקד',
                    'correlation_id' => $correlationId,
                    'source' => 'system',
                ], null);

                $moved++;
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info(sprintf('Done: %d person(s) migrated, %d request(s) moved to 111.', $holders->count(), $moved));

        return self::SUCCESS;
    }
}
