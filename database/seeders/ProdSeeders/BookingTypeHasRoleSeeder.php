<?php

namespace Database\Seeders\ProdSeeders;

use App\Domain\Workflow\Routes\RouteRegistry;
use App\Enums\BookingTypeEnum;
use App\Enums\RoleEnum;
use App\Models\BookingType;
use App\Models\BookingTypeHasRole;
use Illuminate\Database\Seeder;

class BookingTypeHasRoleSeeder extends Seeder
{
    /** Roles on every booking type but the barber's; each gets a "{role}_{booking_type_id}" permission. @var array<int> */
    private array $commonRoleIds = [
        RoleEnum::ADMIN->value,
        RoleEnum::APPROVER->value,
        RoleEnum::VICE_APPROVER->value,
        RoleEnum::USER->value,
    ];

    /**
     * BARBER_BOOKING has one step - the barber answers a received booking (approve 11, reject 12) - and the one who answers is its APPROVER:
     * approver_5. The admin of the type holds that turn too, as on every type. There is no vice approver: nobody has a step before the approver's, so
     * no vice_approver_5 permission is created, the roles screens offer only the approver flag for this type and Workflow gives that role the buttons.
     * @var array<int>
     */
    private array $barberRoleIds = [
        RoleEnum::ADMIN->value,
        RoleEnum::APPROVER->value,
        RoleEnum::USER->value,
    ];

    public function run(): void
    {
        $bookingTypes = BookingType::query()->get(['id']);

        foreach ($bookingTypes as $bookingType) {
            $roleIds = array_values(array_unique($this->roleIdsByBookingType((int) $bookingType->id)));

            foreach ($roleIds as $roleId) {
                BookingTypeHasRole::query()->updateOrCreate([
                    'booking_type_id' => $bookingType->id,
                    'role_id' => $roleId,
                ]);
            }

            // On a database seeded before the roles of a type changed, drop the roles the type no longer has (a permission already created for one
            // stays until the database is rebuilt: nobody is revoked here).
            BookingTypeHasRole::query()
                ->where('booking_type_id', $bookingType->id)
                ->whereNotIn('role_id', $roleIds)
                ->delete();
        }
    }

    /** Infosec types: USER submits, INFOSEC_OFFICER handles. No generic ADMIN/APPROVER/VICE_APPROVER. @var array<int> */
    private array $infosecRoleIds = [
        RoleEnum::USER->value,
        RoleEnum::INFOSEC_OFFICER->value,
    ];

    /** ICT types: USER submits, ICT_HANDLER handles. No generic ADMIN/APPROVER/VICE_APPROVER. @var array<int> */
    private array $ictRoleIds = [
        RoleEnum::USER->value,
        RoleEnum::ICT_HANDLER->value,
    ];

    /** Infosec booking type ids (resolved once and cached). @var array<int>|null */
    private ?array $infosecIds = null;

    /** ICT booking type ids (resolved once and cached). @var array<int>|null */
    private ?array $ictIds = null;

    /** @return array<int> Every role the booking type has. */
    private function roleIdsByBookingType(int $bookingTypeId): array
    {
        // A type with a route of its own (the clearance grant) has the route's requester, owner and handler roles - nothing of the approver chain.
        if (($route = RouteRegistry::byBookingTypeId()[$bookingTypeId] ?? null) !== null) {
            return array_values(array_unique(array_map(
                fn (RoleEnum $role): int => $role->value,
                [$route->requesterRole(), $route->ownerRole(), ...$route->roles()],
            )));
        }

        if ($bookingTypeId === BookingTypeEnum::BARBER_BOOKING->getBookingTypeId()) {
            return $this->barberRoleIds;
        }

        if (in_array($bookingTypeId, $this->getInfosecIds(), true)) {
            return $this->infosecRoleIds;
        }

        if (in_array($bookingTypeId, $this->getIctIds(), true)) {
            return $this->ictRoleIds;
        }

        return [
            ...$this->commonRoleIds,
            ...$this->extraRoleIdsByBookingType($bookingTypeId),
        ];
    }

    /** @return array<int> */
    private function getInfosecIds(): array
    {
        if ($this->infosecIds === null) {
            $this->infosecIds = array_filter([
                BookingTypeEnum::CLEARANCE_GRANT_BOOKING->getBookingTypeId(),
                BookingTypeEnum::REMOVABLE_MEDIA_BOOKING->getBookingTypeId(),
                BookingTypeEnum::SECURITY_INCIDENT_BOOKING->getBookingTypeId(),
            ]);
        }

        return $this->infosecIds;
    }

    /** @return array<int> */
    private function getIctIds(): array
    {
        if ($this->ictIds === null) {
            $this->ictIds = array_filter([
                BookingTypeEnum::SOFTWARE_INSTALL_BOOKING->getBookingTypeId(),
                BookingTypeEnum::PRINTER_INSTALL_BOOKING->getBookingTypeId(),
                BookingTypeEnum::EMAIL_FAULT_BOOKING->getBookingTypeId(),
                BookingTypeEnum::USER_RENAME_BOOKING->getBookingTypeId(),
                BookingTypeEnum::USER_OPEN_BOOKING->getBookingTypeId(),
                BookingTypeEnum::USER_MOVE_BOOKING->getBookingTypeId(),
            ]);
        }

        return $this->ictIds;
    }

    /** @return array<int> Roles on top of $commonRoleIds, only for specific booking types. */
    private function extraRoleIdsByBookingType(int $bookingTypeId): array
    {
        // A type whose route starts at a control officer (transportation 6, in-unit food 7): Workflow::hasControlOfficerStage reads this link.
        $controlOfficerRole = RoleEnum::controlOfficerRoleForBookingType($bookingTypeId);

        return $controlOfficerRole === null ? [] : [$controlOfficerRole->value];
    }
}
