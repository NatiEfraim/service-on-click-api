<?php

namespace Database\Seeders\TestSeeders;

use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Models\BookingType;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Users for local development: the team (log in with the personal number) and a few people who place bookings. */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $managers = [
            // full_name, personal_id (ID number), phone, personal_number (ADFS login), role
            ['full_name' => 'רועי סחייק', 'personal_id' => '206094468', 'phone' => '0545452493', 'personal_number' => '99804761', 'role' => RoleEnum::SUPER_ADMIN],
            ['full_name' => 'נתנאל רפאל אפרים', 'personal_id' => '315552810', 'phone' => '0532157802', 'personal_number' => '99810738', 'role' => RoleEnum::ADMIN],
            ['full_name' => 'ליאון לב', 'personal_id' => '325639797', 'phone' => '0547453538', 'personal_number' => '99815080', 'role' => RoleEnum::ADMIN],
            ['full_name' => 'דניאל יצחק קם', 'personal_id' => '214440067', 'phone' => '0535215312', 'personal_number' => '99815386', 'role' => RoleEnum::APPROVER],
            ['full_name' => 'אופיר גולדברג', 'personal_id' => '99801758', 'phone' => '0527576444', 'personal_number' => '99807387', 'role' => RoleEnum::USER],
            ['full_name' => 'משה כרמל', 'personal_id' => '057723439', 'phone' => '0529272108', 'personal_number' => '99802210', 'role' => RoleEnum::ADMIN],
            ['full_name' => 'לירן אסלן', 'personal_id' => '038156246', 'phone' => '0524322023', 'personal_number' => '99801758', 'role' => RoleEnum::ADMIN],
            ['full_name' => 'יניב ביטון', 'personal_id' => '040953390', 'phone' => '0529461712', 'personal_number' => '6999168', 'role' => RoleEnum::ADMIN],
            // People from the mobile project, here only as plain users.
            ['full_name' => 'נתן חיים מססה', 'personal_id' => '213103088', 'phone' => '0533363586', 'personal_number' => '8883898', 'role' => RoleEnum::USER],
            ['full_name' => 'אוראל בושארי', 'personal_id' => '301268629', 'phone' => '0546658545', 'personal_number' => '9819516', 'role' => RoleEnum::USER],
            ['full_name' => 'ישראל אורלקר', 'personal_id' => '034879304', 'phone' => '0549743172', 'personal_number' => '9802982', 'role' => RoleEnum::USER],
            ['full_name' => 'יוסי גוסיינב', 'personal_id' => '320913585', 'phone' => '0509970702', 'personal_number' => '9809109', 'role' => RoleEnum::USER],
            ['full_name' => 'איתי בובליל', 'personal_id' => '066632001', 'phone' => '0509017902', 'personal_number' => '7357992', 'role' => RoleEnum::USER],
            ['full_name' => 'איל סנאי', 'personal_id' => '033431636', 'phone' => '0529204903', 'personal_number' => '5266969', 'role' => RoleEnum::USER],
            ['full_name' => 'יוסף חיים כהן', 'personal_id' => '322552712', 'phone' => '0584205188', 'personal_number' => '9815232', 'role' => RoleEnum::USER],
            ['full_name' => 'יוסי מלמד', 'personal_id' => '025401530', 'phone' => '0507260200', 'personal_number' => '5086095', 'role' => RoleEnum::USER],
            ['full_name' => 'שירן כהן איטח', 'personal_id' => '037033107', 'phone' => '0525843888', 'personal_number' => '7453637', 'role' => RoleEnum::USER],
            ['full_name' => 'רון קצוקי', 'personal_id' => '212738793', 'phone' => '0546843414', 'personal_number' => '9809405', 'role' => RoleEnum::USER],
            ['full_name' => 'אופיר בן סימון', 'personal_id' => '214217648', 'phone' => '0542947393', 'personal_number' => '9813568', 'role' => RoleEnum::USER],
            ['full_name' => 'אלי חיים קארו', 'personal_id' => '053005641', 'phone' => '0542020112', 'personal_number' => '9809269', 'role' => RoleEnum::USER],
            ['full_name' => 'הילה דרעי', 'personal_id' => '216287003', 'phone' => '0522797391', 'personal_number' => '9531058', 'role' => RoleEnum::USER],
            ['full_name' => 'טובה קלנר', 'personal_id' => '303009245', 'phone' => '0525607795', 'personal_number' => '99805335', 'role' => RoleEnum::USER],
        ];

        $bookingTypeIds = BookingType::query()->pluck('id');

        foreach ($managers as $manager) {
            $roleEnum = $manager['role'];

            // A person may already exist by ID number only (imported from the mobile app): complete that row, don't add a second one.
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

            $user->assignRole($roleEnum->value);

            // Super admin manages the whole system; other roles work per booking type.
            if ($roleEnum !== RoleEnum::SUPER_ADMIN) {
                $user->givePermissionTo(
                    $bookingTypeIds->map(fn(int $id): string => $roleEnum->toPermissionEnum()->keyForId($id))->all()
                );
            }
        }

    }
}
