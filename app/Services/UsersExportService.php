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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The users stream of the digital hatch (r2b), driven by the flag users.is_exported_role: the people who have a role and the booking types they
 * hold it on, so the mobile side knows who may do what.
 *  - a person waits while is_exported_role = 0: they are new, or their roles changed here (User::markRolesChanged, called when an admin or the
 *    super admin grants or takes a role away);
 *  - the waiting people go in the day's file (SrvOnClick_r2b_users_<Y-m-d>.json), and only once it has gone is_exported_role = 1 is set on them
 *    (User::markRolesExported).
 * The day's file keeps what earlier runs of the day put in it, so a later run never takes a person out of it; a person whose roles changed again
 * replaces their row. The mobile importer upserts each person by ID number, so the file lists the people who changed, not everybody.
 * run() writes the file to the bucket (export-json/users). Two ways out, ONE flag, so only the one the HATCH_ENABLED switch picks works:
 *  - HATCH_ENABLED=true: export() = run() and the newest file on the FTP; queued by SendHatchUsersJob (command digital-hatch:send-users);
 *  - HATCH_ENABLED=false: exportToBucket() = run() alone (command exportUsersJson:bucket); it steps aside while the hatch is on.
 * Two senders would take turns clearing the flag and each would carry only part of the changes. Both take the same lock and write the same file
 * name, so switching the hatch on or off in the middle of a day loses nobody: the day's file keeps the people the other way already sent.
 */
class UsersExportService
{
    use GuardsDigitalHatchRuns, HatchExportable;

    public function __construct(private readonly StorageService $storageService)
    {
    }

    /**
     * Builds today's users file and sends it. A failure to build still lets the file already in the bucket go out.
     * @param bool $force send the day's file again even when this exact content was sent before
     * @return array{enabled: bool, busy?: bool, destination?: string, built?: array<string, mixed>, file?: ?string, marked?: int, errors?: array<int, string>}
     *         built = what run() did, file = the name sent (null: nothing new), marked = people set to is_exported_role = 1
     */
    public function export(bool $force = false): array
    {
        return $this->locked('send-users', function () use ($force): array {
            $errors = [];
            $waiting = [];

            $built = $this->building('users file', function () use (&$waiting): array {
                return $this->run($waiting);
            }, $errors);
            $sent = $this->sendNewestFile(DigitalHatchFileEnum::USERS, $force);
            $errors = [...$errors, ...$sent['errors']];

            // Only what really went is marked: after a failed build or send the people stay waiting for the next run.
            $marked = $errors === [] ? User::markRolesExported($waiting) : 0;

            return ['destination' => $this->exportDestination(), 'built' => $built, 'file' => $sent['file'], 'marked' => $marked, 'errors' => $errors];
        });
    }

    /**
     * The bucket-only way out, for when the digital hatch is off: puts the waiting people in the day's file in the bucket (run()) and marks them
     * sent, since the bucket is where the mobile side reads it. The same rule as export(): only is_exported_role = 0 goes out, and they are marked
     * only if the file is in the bucket; after a failure they stay waiting. It steps aside while the hatch is on (digital-hatch:send-users does
     * this and puts the file on the FTP too) and while EXPORT_JSON_ENABLED=false.
     * @return array{skipped?: string, built?: array<string, mixed>, marked?: int, errors?: array<int, string>} skipped = digital_hatch | export_json_disabled | busy
     */
    public function exportToBucket(): array
    {
        if (!config('app.export_json.enabled', true)) {
            return ['skipped' => 'export_json_disabled'];
        }

        if ($this->enabled()) {
            return ['skipped' => 'digital_hatch'];
        }

        // The lock of digital-hatch:send-users: the two ways never write the day's file at once, not even while the switch is being flipped.
        $lock = Cache::lock('digital-hatch:send-users', 600);

        if (!$lock->get()) {
            Log::info('Digital hatch users export to the bucket skipped: another run is working.');

            return ['skipped' => 'busy'];
        }

        try {
            $errors = [];
            $waiting = [];

            $built = $this->building('users file', function () use (&$waiting): array {
                return $this->run($waiting);
            }, $errors);

            // In the bucket (written now, or it held them as they are), so they are sent; after a failure they stay waiting for the next run.
            $marked = $errors === [] ? User::markRolesExported($waiting) : 0;

            return ['built' => $built, 'marked' => $marked, 'errors' => $errors];
        } finally {
            $lock->release();
        }
    }

    /**
     * Puts the waiting people in the bucket file of the day (export-json/users), next to what earlier runs of the day put there.
     * @param array<int, ?string> $waiting filled with the people who are in the file now: user id => updated_at as stored
     * @return array{written: bool, file?: string, users?: int, in_file?: int, reason?: string} reason: nothing_waiting | unchanged
     */
    public function run(array &$waiting = []): array
    {
        $rows = $this->payload($waiting);

        if ($rows === []) {
            return ['written' => false, 'reason' => 'nothing_waiting'];
        }

        // The day is counted in the timezone the mobile side uses for its own "today" (as the status file does).
        $fileName = ExportFileNames::usersFile(now(config('app.export_json.timezone')), DigitalHatchFileEnum::USERS->fileNamePrefix());
        $directory = DigitalHatchFileEnum::USERS->bucketDirectory();
        $existing = $this->storageService->bucketFileContents($fileName, $directory);

        $inFile = [];
        foreach ((json_decode((string) $existing, true)['users'] ?? []) as $row) {
            if (is_array($row) && isset($row['personal_id'])) {
                $inFile[(string) $row['personal_id']] = $row;
            }
        }

        // A person whose roles changed again replaces their row.
        foreach ($rows as $row) {
            $inFile[$row['personal_id']] = $row;
        }

        $users = array_values($inFile);
        usort($users, fn (array $first, array $second): int => strcmp((string) $first['personal_id'], (string) $second['personal_id']));

        $json = json_encode(['users' => $users], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $result = ['file' => $fileName, 'users' => count($rows), 'in_file' => count($users)];

        if ($existing === $json) {
            return ['written' => false, ...$result, 'reason' => 'unchanged'];
        }

        $this->storageService->putJsonToBucket($fileName, $directory, $json);
        $this->noteWrittenToBucket($fileName, $json);

        return ['written' => true, ...$result];
    }

    /**
     * The "users" list of a file, ordered by ID number: the people waiting to be sent (is_exported_role = 0). A person is in it when they have an
     * ID number (how the mobile app knows people), are not deleted and hold a role on at least one booking type. The role is the RoleEnum value
     * (the id both systems share) and the booking types come from the "{role}_{booking type}" permissions; the super admin holds no such
     * permission and gets every booking type. A waiting person who does not qualify is left out and stays waiting.
     * @param array<int, ?string> $waiting filled with the people listed: user id => updated_at as stored (User::markRolesExported checks it)
     * @return array<int, array{personal_id: string, phone: ?string, unit_id: ?int, booking_type_roles: array<int, array{role_id: int, booking_type_ids: array<int, int>}>, created_at: string, updated_at: string}>
     */
    public function payload(array &$waiting = []): array
    {
        $superAdmin = RoleEnum::SUPER_ADMIN->toSpatieRoleName();
        $bookingTypeIds = BookingType::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $users = User::query()
            ->where('is_deleted', false)
            ->where('is_exported_role', false)
            ->whereNotNull('personal_id')
            ->where(fn ($query) => $query->has('permissions')->orWhereHas('roles', fn ($roles) => $roles->where('name', $superAdmin)))
            ->with(['permissions:id,name', 'roles:id,name'])
            ->get();

        $rows = [];
        $waiting = [];

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
            $waiting[$user->id] = $user->getRawOriginal('updated_at');
        }

        usort($rows, fn (array $first, array $second): int => strcmp($first['personal_id'], $second['personal_id']));

        return $rows;
    }
}
