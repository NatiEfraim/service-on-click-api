<?php

namespace App\Services;

use App\Domain\Workflow\TypeRoute;
use App\Enums\ActionTypeEnum;
use App\Enums\RoleEnum;
use App\Exceptions\DomainError;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * The handler (מטפל) of a request of a route type: assigned by the route's assigners (the infosec officer on the clearance grant, the super
 * admin as the type's admin), or taken by a coordinator for themself while nobody has it. Assigning does not move the request: its status,
 * status_changed_at and export flag stay as they are; the version moves on and the timeline and the audit log get a row.
 */
class BookingAssignmentService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * @param ?string $handlerUuid the person who takes the request, or null to clear the assignment (assigners only, in the initial status)
     * @return array{handler: ?array{uuid: string, full_name: ?string, assigned_at: ?string}, version: int}
     */
    public function assign(AccessScope $scope, int $serial, ?string $handlerUuid, int $expectedVersion): array
    {
        $this->assertMayAssign($scope, Booking::query()->where('serial_number', $serial)->first(), $serial, $handlerUuid, $expectedVersion);

        return DB::transaction(function () use ($scope, $serial, $handlerUuid, $expectedVersion): array {
            $booking = Booking::query()->where('serial_number', $serial)->lockForUpdate()->first();
            $target = $this->assertMayAssign($scope, $booking, $serial, $handlerUuid, $expectedVersion);

            $before = ['handler_user_id' => $booking->handler_user_id, 'version' => (int) $booking->version];
            $correlationId = (string) Str::uuid();

            $booking->forceFill([
                'handler_user_id' => $target?->id,
                'handler_assigned_at' => $target === null ? null : now(),
                'version' => (int) $booking->version + 1,
            ])->save();

            ApplicationLog::query()->create([
                'booking_id' => $booking->id,
                'action_type_id' => ActionTypeEnum::ASSIGN_HANDLER->value,
                'status_id' => (int) $booking->status_id,
                'source' => 'internal',
                'correlation_id' => $correlationId,
                'user_id' => $scope->user->id,
                'is_exported' => false,
            ]);

            $this->audit->record([
                'action' => 'booking.handler_change',
                'entity_type' => 'booking',
                'entity_id' => $serial,
                'booking_serial' => $serial,
                'unit_id' => $booking->unit_id,
                'booking_type_id' => $booking->booking_type_id,
                'before' => $before,
                'after' => ['handler_user_id' => $target?->id, 'handler_name' => $target?->full_name, 'version' => (int) $booking->version],
                'correlation_id' => $correlationId,
            ], $scope->user);

            return [
                'handler' => $target === null ? null : [
                    'uuid' => $target->uuid,
                    'full_name' => $target->full_name,
                    'assigned_at' => $booking->handler_assigned_at?->toIso8601String(),
                ],
                'version' => (int) $booking->version,
            ];
        });
    }

    /**
     * The people who may be assigned a request: the unit's holders of one of the route's candidate roles on the type.
     * @return array<int, array{uuid: string, full_name: ?string, personal_number: ?string, roles: array<int, string>}>
     */
    public function candidates(AccessScope $scope, int $serial): array
    {
        $booking = Booking::query()->where('serial_number', $serial)->first();

        if ($booking === null || !$scope->canSeeBooking($booking->unit_id, (int) $booking->booking_type_id, $booking->positionStatus()->value)) {
            throw new DomainError('הקריאה לא ניתנת לביצוע.', 404);
        }

        $route = $scope->workflow()->routeFor((int) $booking->booking_type_id) ?? throw new DomainError('לסוג קריאה זה אין הקצאת מטפל', 404);
        $permissionNames = $this->candidatePermissionNames($route, (int) $booking->booking_type_id);

        if ($permissionNames === []) {
            return [];
        }

        return User::query()
            ->permission($permissionNames)
            ->where('unit_id', $booking->unit_id)
            ->where('is_deleted', false)
            ->with('permissions:id,name')
            ->orderBy('full_name')
            ->get()
            ->map(fn (User $user): array => [
                'uuid' => $user->uuid,
                'full_name' => $user->full_name,
                'personal_number' => $user->personal_number,
                'roles' => $user->permissions->pluck('name')
                    ->filter(fn (string $name): bool => in_array($name, $permissionNames, true))
                    ->map(fn (string $name): string => rtrim(substr($name, 0, -strlen((string) $booking->booking_type_id)), '_'))
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /** Checks the booking, the version, the route's assignment stage, the caller's right and the target person; returns the person (null = clear). */
    private function assertMayAssign(AccessScope $scope, ?Booking $booking, int $serial, ?string $handlerUuid, int $expectedVersion): ?User
    {
        if ($booking === null || !$scope->managesBooking($booking->unit_id, (int) $booking->booking_type_id)) {
            throw new DomainError('הקריאה לא ניתנת לביצוע.', 404);
        }

        if ((int) $booking->version !== $expectedVersion) {
            throw new DomainError('הקריאה עודכנה בינתיים. יש לרענן ולבדוק את המצב העדכני.', 409);
        }

        $bookingTypeId = (int) $booking->booking_type_id;
        $route = $scope->workflow()->routeFor($bookingTypeId);
        $assignment = $route?->assignment();

        if ($route === null || !in_array((int) $booking->status_id, $assignment['statuses'], true)) {
            throw new DomainError('לא ניתן להקצות מטפל בשלב הנוכחי של הקריאה', 403);
        }

        $isAssigner = $scope->workflow()->holdsRouteRole($scope->actingPermissions, $assignment['assigners'], $bookingTypeId);
        $takesForThemself = $handlerUuid !== null
            && $handlerUuid === $scope->user->uuid
            && $booking->handler_user_id === null
            && $scope->workflow()->holdsRouteRole($scope->actingPermissions, $assignment['self'], $bookingTypeId);

        if (!$isAssigner && !$takesForThemself) {
            $this->audit->denied('booking.handler_change', 'booking', $serial, 'not_an_assigner', $scope->user, ['booking_serial' => $serial, 'unit_id' => $booking->unit_id, 'booking_type_id' => $bookingTypeId]);

            throw new DomainError('הפעולה אינה זמינה עבורך בשלב הנוכחי של הקריאה', 403);
        }

        if ($handlerUuid === null) {
            // Clearing is the assigner's, and only while the request has not been scheduled.
            if (!$isAssigner || (int) $booking->status_id !== $route->initialStatus()) {
                throw new DomainError('לא ניתן לבטל הקצאת מטפל בשלב הנוכחי של הקריאה', 403);
            }

            return null;
        }

        $target = User::query()->where('uuid', $handlerUuid)->where('is_deleted', false)->first();
        $permissionNames = $this->candidatePermissionNames($route, $bookingTypeId);

        if ($target === null || (int) $target->unit_id !== (int) $booking->unit_id || !$target->hasAnyPermission($permissionNames)) {
            throw new DomainError('המשתמש שנבחר אינו מורשה לטפל בסוג בקשה זה', 422);
        }

        return $target;
    }

    /** @return array<int, string> the permission names of the route's candidate roles that exist (Spatie throws for an unknown name) */
    private function candidatePermissionNames(TypeRoute $route, int $bookingTypeId): array
    {
        $names = array_map(
            fn (RoleEnum $role): string => $role->toPermissionEnum()->keyForId($bookingTypeId),
            $route->assignment()['candidates'],
        );

        return Permission::query()->whereIn('name', $names)->pluck('name')->all();
    }
}
