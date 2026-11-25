<?php

namespace Database\Seeders\TestSeeders;

use App\Enums\BookingTypeEnum;
use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Models\BookingTypeHasRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Users for local development: the team (log in with the personal number) and a few people who place bookings. */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $managers = [
            ['full_name' => 'רועי סחייק', 'personal_id' => '206094468', 'phone' => '0545452493', 'personal_number' => '99804761', 'role' => RoleEnum::SUPER_ADMIN],
            ['full_name' => 'נתנאל רפאל אפרים', 'personal_id' => '315552810', 'phone' => '0532157802', 'personal_number' => '99810738', 'role' => RoleEnum::SUPER_ADMIN],
            ['full_name' => 'ליאון לב', 'personal_id' => '325639797', 'phone' => '0547453538', 'personal_number' => '99815080', 'role' => RoleEnum::SUPER_ADMIN],
            ['full_name' => 'דניאל יצחק קם', 'personal_id' => '214440067', 'phone' => '0535215312', 'personal_number' => '99815386', 'role' => RoleEnum::SUPER_ADMIN],
            ['full_name' => 'משה כרמל', 'personal_id' => '057723439', 'phone' => '0529272108', 'personal_number' => '99802210', 'role' => RoleEnum::SUPER_ADMIN],
            ['full_name' => 'לירן אסלן', 'personal_id' => '038156246', 'phone' => '0524322023', 'personal_number' => '99801758', 'role' => RoleEnum::SUPER_ADMIN],
            ['full_name' => 'קארין יעקב', 'personal_id' => '315852525', 'phone' => '0509029409', 'personal_number' => '1585252', 'role' => RoleEnum::SUPER_ADMIN],
            ['full_name' => 'יניב ביטון', 'personal_id' => '040953390', 'phone' => '0529461712', 'personal_number' => '6999168', 'role' => RoleEnum::ADMIN],
            // People from the mobile project, here only as plain users.
            ['full_name' => 'נתן חיים מססה', 'personal_id' => '213103088', 'phone' => '0533363586', 'personal_number' => '8883898', 'role' => RoleEnum::USER],
            ['full_name' => 'אוראל בושארי', 'personal_id' => '301268629', 'phone' => '0546658545', 'personal_number' => '9819516', 'role' => RoleEnum::USER],
            ['full_name' => 'ישראל אורלקר', 'personal_id' => '034879304', 'phone' => '0549743172', 'personal_number' => '9802982', 'role' => RoleEnum::USER],
            ['full_name' => 'איתי בובליל', 'personal_id' => '066632001', 'phone' => '0509017902', 'personal_number' => '7357992', 'role' => RoleEnum::USER],
            ['full_name' => 'איל סנאי', 'personal_id' => '033431636', 'phone' => '0529204903', 'personal_number' => '5266969', 'role' => RoleEnum::USER],
            ['full_name' => 'יוסי מלמד', 'personal_id' => '025401530', 'phone' => '0507260200', 'personal_number' => '5086095', 'role' => RoleEnum::USER],
            ['full_name' => 'שירן כהן איטח', 'personal_id' => '037033107', 'phone' => '0525843888', 'personal_number' => '7453637', 'role' => RoleEnum::USER],
            ['full_name' => 'רון קצוקי', 'personal_id' => '212738793', 'phone' => '0546843414', 'personal_number' => '9809405', 'role' => RoleEnum::USER],
            ['full_name' => 'נועה גולדברג ראובן', 'personal_id' => '206912214', 'phone' => '0545465999', 'personal_number' => '998126531', 'role' => RoleEnum::USER],
        ];

        foreach ($managers as $manager) {
            $roleEnum = $manager['role'];

            $user = User::where('personal_number', $manager['personal_number'])->first()
                ?? User::where('personal_id', $manager['personal_id'])->first()
                ?? new User();

            $user->fill([
                'full_name' => $manager['full_name'],
                'personal_number' => $manager['personal_number'],
                'personal_id' => $manager['personal_id'],
                'phone' => $manager['phone'],
                'unit_id' => UnitEnum::H->value,
                'is_deleted' => false,
            ])->save();

            self::assignRoleWithRequesterGrants($user, $roleEnum);
        }

        $this->seedPilotViceApprovers();
    }

    /**
     * The five pilot "גורם מטפל" users, shared with the mobile PilotUserSeeder by personal_id: vice approver scoped to
     * OUT-unit food (10) + transportation (8) ONLY (the 2026-11-25 flow switch moved the handler select from in-unit;
     * verified against the live mobile grants) - assignRoleWithRequesterGrants would spread the role over every type
     * that has it, and the next users file would then overwrite their narrow mobile grants with wide ones.
     */
    private function seedPilotViceApprovers(): void
    {
        $typeIds = array_filter([
            BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId(),
            BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId(),
        ]);

        $pilots = [
            ['full_name' => 'לירז עזרא', 'personal_id' => '207814310', 'phone' => '0528707372'],
            ['full_name' => 'עידן עזרא', 'personal_id' => '206923559', 'phone' => '0505784601'],
            ['full_name' => 'מיה ונונו', 'personal_id' => '214975476', 'phone' => '0586771669'],
            ['full_name' => 'אופיר אזולאי', 'personal_id' => '322765132', 'phone' => '0526543696'],
            ['full_name' => 'רוני יעקובי', 'personal_id' => '215236290', 'phone' => '0558866844'],
        ];

        foreach ($pilots as $pilot) {
            // Matched by the ID number (their personal numbers arrive with the users xlsx imports and are kept as found).
            $user = User::where('personal_id', $pilot['personal_id'])->first() ?? new User();

            $user->fill([
                'full_name' => $user->full_name ?: $pilot['full_name'],
                'personal_id' => $pilot['personal_id'],
                'phone' => $user->phone ?: $pilot['phone'],
                'unit_id' => $user->unit_id ?: UnitEnum::H->value,
                'is_deleted' => false,
            ])->save();

            $user->assignRole(RoleEnum::VICE_APPROVER->value);
            $user->assignRole(RoleEnum::USER->value);

            foreach ($typeIds as $typeId) {
                $user->givePermissionTo([
                    RoleEnum::VICE_APPROVER->toPermissionEnum()->keyForId($typeId),
                    RoleEnum::USER->toPermissionEnum()->keyForId($typeId),
                ]);
            }
        }
    }

    /**
     * The role with its "{role}_{booking type id}" permissions - and, for every management role, the plain user role with
     * user_{id} on the same types, so whoever manages a type can also open its requests (admin_1 comes with user_1, and so
     * on). Only where the type takes the plain user: the clearance grant has its own requester role and no user_14 exists.
     * The super admin keeps no per-type permissions, as everywhere.
     * Shared with users:reset --personalId (ResetUsersCommand), so both paths give the same guarantee.
     */
    public static function assignRoleWithRequesterGrants(User $user, RoleEnum $roleEnum): void
    {
        $user->assignRole($roleEnum->value);

        if ($roleEnum === RoleEnum::SUPER_ADMIN) {
            return;
        }

        $linkedTypeIds = BookingTypeHasRole::where('role_id', $roleEnum->value)
            ->pluck('booking_type_id')
            ->map(fn ($id): int => (int) $id);

        $user->givePermissionTo(
            $linkedTypeIds
                ->map(fn (int $id): string => $roleEnum->toPermissionEnum()->keyForId($id))
                ->all()
        );

        if ($roleEnum === RoleEnum::USER) {
            return;
        }

        $requesterTypeIds = BookingTypeHasRole::where('role_id', RoleEnum::USER->value)
            ->whereIn('booking_type_id', $linkedTypeIds)
            ->pluck('booking_type_id')
            ->map(fn ($id): int => (int) $id);

        if ($requesterTypeIds->isEmpty()) {
            return;
        }

        $user->assignRole(RoleEnum::USER->value);

        $user->givePermissionTo(
            $requesterTypeIds
                ->map(fn (int $id): string => RoleEnum::USER->toPermissionEnum()->keyForId($id))
                ->all()
        );
    }
}
