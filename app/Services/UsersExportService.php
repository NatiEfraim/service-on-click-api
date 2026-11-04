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

/** r2b users stream: writes a cumulative daily delta file to the bucket and sends it to the FTP; driven by users.is_exported_role. */
class UsersExportService
{
    use GuardsDigitalHatchRuns, HatchExportable;

    public function __construct(private readonly StorageService $storageService)
    {
    }

    /** Builds today's users file and sends it to the FTP. @param bool $force re-send even if this content was already sent @return array{enabled: bool, busy?: bool, destination?: string, built?: array<string, mixed>, file?: ?string, marked?: int, errors?: array<int, string>} */
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

    /** Bucket-only export for when HATCH_ENABLED=false; steps aside while the hatch is on. @return array{skipped?: string, built?: array<string, mixed>, marked?: int, errors?: array<int, string>} */
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

            // In the bucket now, so mark them sent.
            $marked = $errors === [] ? User::markRolesExported($waiting) : 0;

            return ['built' => $built, 'marked' => $marked, 'errors' => $errors];
        } finally {
            $lock->release();
        }
    }

    /** Builds the day's users file in the bucket, merging with what earlier runs put there. @param array<int, ?string> $waiting user id => updated_at @return array{written: bool, file?: string, users?: int, in_file?: int, reason?: string} */
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
     * Builds the user rows for the file: waiting people (is_exported_role=0) who have an ID number, a role and are not deleted. Super admin gets all booking types.
     * @param array<int, ?string> $waiting filled: user id => updated_at
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
