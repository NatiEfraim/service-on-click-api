<?php

namespace App\Services;

use App\Domain\Sync\ExportFileNames;
use App\Enums\DigitalHatchFileEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\BookingType;
use App\Models\User;
use App\Traits\GuardsDigitalHatchRuns;
use App\Traits\HatchExportable;

/**
 * The users stream of the digital hatch (r2b): the people who have a role and the booking types they hold it on, so the mobile side knows
 * who may do what. One snapshot per day under the day's name (SrvOnClick_r2b_users_<Y-m-d>.json), rewritten while the day goes on only when
 * it changed. run() writes it to the bucket (export-json/users); export() does that and puts the newest file on the FTP.
 * Queued by SendHatchUsersJob (command digital-hatch:send-users).
 */
class UsersExportService
{
    use GuardsDigitalHatchRuns, HatchExportable;

    public function __construct(private readonly StorageService $storageService)
    {
    }

    /**
     * Builds today's users file and sends it. A failure to build still lets the file already in the bucket go out.
     * @param bool $force send even when this exact content was sent before
     * @return array{enabled: bool, busy?: bool, destination?: string, built?: array<string, mixed>, file?: ?string, errors?: array<int, string>}
     *         built = what run() did, file = the name sent (null: nothing new)
     */
    public function export(bool $force = false): array
    {
        return $this->locked('send-users', function () use ($force): array {
            $errors = [];

            $built = $this->building('users file', fn (): array => $this->run(), $errors);
            $sent = $this->sendNewestFile(DigitalHatchFileEnum::USERS, $force);

            return ['destination' => $this->exportDestination(), 'built' => $built, 'file' => $sent['file'], 'errors' => [...$errors, ...$sent['errors']]];
        });
    }

    /** @return array{written: bool, file?: string, users?: int, reason?: string} reason: no_users | unchanged */
    public function run(): array
    {
        $users = $this->payload();

        if ($users === []) {
            return ['written' => false, 'reason' => 'no_users'];
        }

        // The day is counted in the timezone the mobile side uses for its own "today" (as the status file does).
        $fileName = ExportFileNames::usersFile(now(config('app.export_json.timezone')), DigitalHatchFileEnum::USERS->fileNamePrefix());
        $directory = DigitalHatchFileEnum::USERS->bucketDirectory();
        $json = json_encode(['users' => $users], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($this->storageService->bucketFileContents($fileName, $directory) === $json) {
            return ['written' => false, 'file' => $fileName, 'users' => count($users), 'reason' => 'unchanged'];
        }

        $this->storageService->putJsonToBucket($fileName, $directory, $json);
        $this->noteWrittenToBucket($fileName, $json);

        return ['written' => true, 'file' => $fileName, 'users' => count($users)];
    }

    /**
     * The "users" list of the file, ordered by ID number. A person is in it when they have an ID number (how the mobile app knows people),
     * are not deleted and hold a role on at least one booking type. The role is the RoleEnum value (the id both systems share) and the
     * booking types come from the "{role}_{booking type}" permissions; the super admin holds no such permission and gets every booking type.
     * @return array<int, array{personal_id: string, phone: ?string, unit_id: ?int, booking_type_roles: array<int, array{role_id: int, booking_type_ids: array<int, int>}>, created_at: string, updated_at: string}>
     */
    public function payload(): array
    {
        $superAdmin = RoleEnum::SUPER_ADMIN->toSpatieRoleName();
        $bookingTypeIds = BookingType::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $users = User::query()
            ->where('is_deleted', false)
            ->whereNotNull('personal_id')
            ->where(fn ($query) => $query->has('permissions')->orWhereHas('roles', fn ($roles) => $roles->where('name', $superAdmin)))
            ->with(['permissions:id,name', 'roles:id,name'])
            ->get();

        $rows = [];

        foreach ($users as $user) {
            $personalId = UserService::normalizePersonalId($user->personal_id);

            if ($personalId === null) {
                continue;
            }

            $idsByRole = [];

            foreach ($user->permissions as $permission) {
                $parsed = PermissionEnum::parsePermissionName($permission->name);

                if ($parsed !== null) {
                    $idsByRole[$parsed['permission']->toRoleEnum()->value][] = $parsed['booking_type_id'];
                }
            }

            if ($user->roles->contains('name', $superAdmin)) {
                $idsByRole[RoleEnum::SUPER_ADMIN->value] = $bookingTypeIds;
            }

            if ($idsByRole === []) {
                continue;
            }

            ksort($idsByRole);

            $bookingTypeRoles = [];
            foreach ($idsByRole as $roleId => $ids) {
                $ids = array_values(array_unique($ids));
                sort($ids);

                $bookingTypeRoles[] = ['role_id' => $roleId, 'booking_type_ids' => $ids];
            }

            $phone = trim((string) $user->phone);

            $rows[] = [
                'personal_id' => $personalId,
                'phone' => $phone === '' ? null : $phone,
                'unit_id' => $user->unit_id === null ? null : (int) $user->unit_id,
                'booking_type_roles' => $bookingTypeRoles,
                'created_at' => (string) ($user->created_at?->getTimestamp() ?? 0),
                'updated_at' => (string) ($user->updated_at?->getTimestamp() ?? 0),
            ];
        }

        usort($rows, fn (array $first, array $second): int => strcmp($first['personal_id'], $second['personal_id']));

        return $rows;
    }
}
