<?php

namespace App\Services;

use App\Enums\BookingTypeEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Imports one booking-type user list from the bucket's import-xlsx folder (IMPORT_BOOKING_TYPE_USERS.md). Each of the
 * four *:xlsx commands passes its own file name and booking types, and the file becomes the full list of those types:
 * everyone in it gets the direct user_{booking type id} permission of those types plus the common (infosec + ICT) types
 * of COMMON_BOOKING_TYPES, and everyone else loses the file's own permissions (the common ones are never revoked).
 * A person gets the plain user role only when they have no role at all. A person is matched by מספר אישי, else by ת.ז,
 * and created when missing; an existing person only has empty fields filled, never overwritten. A file with no valid
 * person fails, so it can never strip everyone. The run is one transaction; the file moves to loaded-xlsx when done and
 * stays in import-xlsx when it failed. A file whose exact bytes already sit in loaded-xlsx under its own name (or a dated
 * copy of it) was imported before: the run says so and touches nothing (--force imports it anyway; a renewed list with
 * the same name has different bytes and imports normally). --dry-run rolls everything back.
 */
class ImportUsersXlsxService
{
    /**
     * Booking types every person in every file gets on top of the file's own. A common type whose user_{id} permission
     * does not exist is skipped with one warning, never a failure - today that is CLEARANCE_GRANT_BOOKING, whose
     * requester is the department security officer, so RoleAndPermissionSeeder does not create (and even deletes)
     * user_14. If USER is ever added to that route, the next run grants user_14 with no code change here.
     * @var array<int, BookingTypeEnum>
     */
    public const COMMON_BOOKING_TYPES = [
        // ביטחון מידע (Infosec)
        BookingTypeEnum::CLEARANCE_GRANT_BOOKING,
        BookingTypeEnum::REMOVABLE_MEDIA_BOOKING,
        BookingTypeEnum::SECURITY_INCIDENT_BOOKING,

        // תקשוב (ICT)
        BookingTypeEnum::SOFTWARE_INSTALL_BOOKING,
        BookingTypeEnum::PRINTER_INSTALL_BOOKING,
        BookingTypeEnum::EMAIL_FAULT_BOOKING,
        BookingTypeEnum::USER_RENAME_BOOKING,
        BookingTypeEnum::USER_OPEN_BOOKING,
        BookingTypeEnum::USER_MOVE_BOOKING,
    ];

    /** The exact Hebrew header texts of the files => the field key used here. Headers not listed (unit, rank, service type) are ignored. */
    private const HEADERS = [
        'מספר אישי' => 'personal_number',
        'ת.ז' => 'personal_id',
        'שם פרטי' => 'first_name',
        'שם משפחה' => 'last_name',
        'מספר נייד' => 'phone',
    ];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<int, string> warnings and skip reasons in file order; the command prints them and each is logged too */
    private array $messages = [];

    /** @var array<int, true> ids of the users the file holds: everyone else loses the file's own permissions */
    private array $userIdsInFile = [];

    /**
     * Imports one file and makes it the full list of its own booking types; throws when it could not be imported
     * (nothing is saved and the file stays in import-xlsx).
     * @param array<int, BookingTypeEnum> $bookingTypes the file's own types
     * @param bool $force import even when the exact same file already sits in loaded-xlsx
     * @return array{summary: string, messages: array<int, string>}
     */
    public function importFile(string $fileName, array $bookingTypes, bool $dryRun = false, bool $force = false): array
    {
        // The four commands share the users table; only one import runs at a time.
        $lock = Cache::lock('import-users-xlsx', 600);

        if (!$lock->get()) {
            throw new \RuntimeException('Another users xlsx import is running; try again when it is done.');
        }

        $this->counts = ['rows' => 0, 'created' => 0, 'updated' => 0, 'granted' => 0, 'unchanged' => 0, 'skipped_rows' => 0, 'revoked' => 0];
        $this->messages = [];
        $this->userIdsInFile = [];

        try {
            $storage = app(StorageService::class);
            $inbox = (string) config('filesystems.folder_name.import_xlsx');
            $loaded = (string) config('filesystems.folder_name.loaded_xlsx');

            $contents = $storage->bucketFileContents($fileName, $inbox);

            if ($contents === null) {
                throw new \RuntimeException("{$fileName} is not waiting in {$inbox}.");
            }

            // The same bytes in loaded-xlsx mean this very file was imported before: say so instead of re-running it.
            if (!$force && ($loadedCopy = $this->loadedCopyOf($contents, $fileName, $loaded)) !== null) {
                $summary = "{$fileName}: not imported - the exact same file is already in {$loaded} ({$loadedCopy}). "
                    . "It stays in {$inbox}; run with --force to import it anyway.";

                Log::info('ImportUsersXlsxService: ' . $summary);

                return ['summary' => $summary, 'messages' => []];
            }

            $filePermissions = $this->filePermissions($bookingTypes, $fileName);
            $permissions = $filePermissions->merge($this->commonPermissions($filePermissions));

            DB::beginTransaction();

            try {
                $this->importOne($contents, $fileName, $permissions);

                // An empty or fully invalid list would take the file's permissions from everyone: refuse it.
                if ($this->userIdsInFile === []) {
                    throw new \RuntimeException("{$fileName}: no valid person in the file - nothing was imported and nothing was revoked.");
                }

                // Only the people in this file keep its own permissions (the common ones are never touched).
                $this->revokeMissing($filePermissions);

                $dryRun ? DB::rollBack() : DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            if (!$dryRun) {
                $storage->moveBucketFile($fileName, $inbox, $loaded);
            }
        } finally {
            $lock->release();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $summary = ($dryRun ? '[DRY RUN - nothing saved] ' : '')
            . "{$fileName}: {$this->counts['rows']} rows, {$this->counts['created']} people created, "
            . "{$this->counts['updated']} had empty details filled, {$this->counts['granted']} given new grants, "
            . "{$this->counts['unchanged']} already complete, {$this->counts['skipped_rows']} rows skipped, "
            . "{$this->counts['revoked']} revoked.";

        Log::info('ImportUsersXlsxService: ' . $summary);

        return ['summary' => $summary, 'messages' => $this->messages];
    }

    /**
     * The name in loaded-xlsx holding exactly these bytes, or null. Only this file's own copies are compared: the
     * original name and the dated names moveBucketFile gives a second load of the same list
     * ({name}_YYYY-mm-dd_HH-ii-ss[_N].{ext}). A plain "{name}_" prefix is not enough: users.xlsx must not match
     * users_car_booking.xlsx.
     */
    private function loadedCopyOf(string $contents, string $fileName, string $loaded): ?string
    {
        $disk = Storage::disk(config('filesystems.storage_service'));
        $storage = app(StorageService::class);

        $base = mb_strtolower(pathinfo($fileName, PATHINFO_FILENAME));
        $extension = mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $hash = hash('sha256', $contents);
        $datedCopy = '/^' . preg_quote($base, '/') . '_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}(_\d+)?$/u';

        foreach ($disk->files($storage->buildBucketKey($loaded)) as $key) {
            $name = basename($key);
            $stem = mb_strtolower(pathinfo($name, PATHINFO_FILENAME));

            $sameFamily = (mb_strtolower($name) === mb_strtolower($fileName))
                || (preg_match($datedCopy, $stem) === 1 && mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)) === $extension);

            if ($sameFamily && hash('sha256', (string) $disk->get($key)) === $hash) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The user_{id} permission row of every type the file grants: all must exist. The permissions are only ever
     * created by RoleAndPermissionSeeder - a missing one fails the run instead of creating a stray permission.
     * @param array<int, BookingTypeEnum> $bookingTypes
     * @return Collection<string, Permission> by permission name
     */
    private function filePermissions(array $bookingTypes, string $fileName): Collection
    {
        $names = array_map(function (BookingTypeEnum $bookingType) use ($fileName): string {
            $name = PermissionEnum::USER->keyForBooking($bookingType);

            if ($name === null) {
                throw new \RuntimeException("{$fileName}: booking type {$bookingType->name} is not in the booking_types table.");
            }

            return $name;
        }, $bookingTypes);

        $permissions = $this->permissionsByName($names);
        $missing = array_diff($names, $permissions->keys()->all());

        if ($missing !== []) {
            throw new \RuntimeException("{$fileName}: the permission(s) " . implode(', ', $missing)
                . ' do not exist. Run RoleAndPermissionSeeder first; nothing is created here.');
        }

        return $permissions;
    }

    /**
     * The common types' user_{id} rows. A type already granted by the file, or whose permission does not exist
     * (user_14 today), is left out - the latter with one warning line.
     * @param Collection<string, Permission> $filePermissions
     * @return Collection<string, Permission> by permission name
     */
    private function commonPermissions(Collection $filePermissions): Collection
    {
        $typesByName = [];

        foreach (self::COMMON_BOOKING_TYPES as $bookingType) {
            $name = PermissionEnum::USER->keyForBooking($bookingType);

            if ($name === null) {
                $this->warn("common booking type {$bookingType->name} skipped: it is not in the booking_types table");
            } elseif (!$filePermissions->has($name)) {
                $typesByName[$name] = $bookingType;
            }
        }

        $permissions = $this->permissionsByName(array_keys($typesByName));

        foreach (array_diff_key($typesByName, $permissions->all()) as $name => $bookingType) {
            $this->warn("common booking type {$bookingType->name} skipped: the permission {$name} does not exist (its requester is not the plain user role)");
        }

        return $permissions;
    }

    /** @param array<int, string> $names @return Collection<string, Permission> by permission name */
    private function permissionsByName(array $names): Collection
    {
        if ($names === []) {
            return new Collection();
        }

        // ->toBase(): an Eloquent collection re-indexes by model id on merge(), which would drop the name keys.
        return Permission::query()
            ->whereIn('name', $names)
            ->where('guard_name', 'passport')
            ->get()
            ->toBase()
            ->keyBy('name');
    }

    /** One file, inside one transaction: every row is a person. @param Collection<string, Permission> $permissions user_{id} rows by name */
    private function importOne(string $contents, string $fileName, Collection $permissions): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'users-xlsx-');

        try {
            file_put_contents($temp, $contents);

            $reader = new Reader();
            $reader->open($temp);

            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    $columns = null;

                    foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                        $cells = $this->cellsOf($row);

                        if ($cells === []) {
                            continue;
                        }

                        if ($columns === null) {
                            // The maintenance file ships without a header row: its first row is already a person.
                            if ($this->looksLikeData($cells)) {
                                $columns = $this->columnsByPosition(count($cells), $fileName);
                            } else {
                                $columns = $this->columnsFromHeader($cells, $fileName);
                                continue;
                            }
                        }

                        $this->counts['rows']++;
                        $this->importPerson($rowNumber, $cells, $columns, $fileName, $permissions);
                    }

                    // The files have one sheet; whatever its name, nothing follows it.
                    break;
                }
            } finally {
                $reader->close();
            }
        } finally {
            @unlink($temp);
        }
    }

    /** @return array<int, mixed> the row's values with strings trimmed (an empty row comes back as []) */
    private function cellsOf(Row $row): array
    {
        $cells = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row->toArray());

        $notEmpty = array_filter($cells, fn ($value): bool => $value !== null && $value !== '');

        return $notEmpty === [] ? [] : $cells;
    }

    /** A first row that starts with a number is data, not headers. @param array<int, mixed> $cells */
    private function looksLikeData(array $cells): bool
    {
        $first = $cells[0] ?? null;

        return is_numeric(is_string($first) ? trim($first) : $first);
    }

    /** Finds each needed column by its exact header text; extra columns are ignored. @param array<int, mixed> $headerCells @return array<string, int> field key => cell index */
    private function columnsFromHeader(array $headerCells, string $fileName): array
    {
        $columns = [];

        foreach ($headerCells as $index => $title) {
            $field = self::HEADERS[trim((string) $title)] ?? null;

            if ($field !== null && !isset($columns[$field])) {
                $columns[$field] = $index;
            }
        }

        $missing = array_diff(array_values(self::HEADERS), array_keys($columns));

        if ($missing !== []) {
            throw new \RuntimeException("{$fileName}: the column(s) \""
                . implode('", "', array_keys(array_intersect(self::HEADERS, $missing)))
                . '" are missing from the header row.');
        }

        return $columns;
    }

    /** A file without a header row: the known layouts by column count. @return array<string, int> field key => cell index */
    private function columnsByPosition(int $columnCount, string $fileName): array
    {
        return match ($columnCount) {
            // מספר אישי | ת.ז | תיאור יחידה | שם פרטי | שם משפחה | מספר נייד
            6 => ['personal_number' => 0, 'personal_id' => 1, 'first_name' => 3, 'last_name' => 4, 'phone' => 5],
            // מספר אישי | ת.ז | שם פרטי | שם משפחה | מספר נייד
            5 => ['personal_number' => 0, 'personal_id' => 1, 'first_name' => 2, 'last_name' => 3, 'phone' => 4],
            default => throw new \RuntimeException("{$fileName}: no header row and an unknown layout ({$columnCount} columns). Add the header row."),
        };
    }

    /** @param array<int, mixed> $cells @param array<string, int> $columns @param Collection<string, Permission> $permissions */
    private function importPerson(int $rowNumber, array $cells, array $columns, string $fileName, Collection $permissions): void
    {
        $personalNumber = $this->normalizePersonalNumber($cells[$columns['personal_number']] ?? null);
        $personalId = $this->normalizePersonalId($cells[$columns['personal_id']] ?? null);

        if ($personalNumber === null || $personalId === null) {
            $this->skipRow($fileName, $rowNumber, $personalNumber === null
                ? 'the personal number "' . $this->rawText($cells, $columns, 'personal_number') . '" is not 5-9 digits'
                : 'the ID number "' . $this->rawText($cells, $columns, 'personal_id') . '" fails the check digit');

            return;
        }

        $fullName = $this->fullName($cells[$columns['first_name']] ?? null, $cells[$columns['last_name']] ?? null);
        $phone = $this->normalizePhone($cells[$columns['phone']] ?? null);

        $byNumber = User::query()->where('personal_number', $personalNumber)->first();
        $byId = User::query()->where('personal_id', $personalId)->first();

        if ($byNumber && $byId && $byNumber->id !== $byId->id) {
            $this->skipRow($fileName, $rowNumber, "personal number {$personalNumber} is user #{$byNumber->id} but ID number {$personalId} is user #{$byId->id}");

            return;
        }

        $user = $byNumber ?? $byId;

        if (!$user) {
            $user = User::create([
                'full_name' => $fullName,
                'personal_number' => $personalNumber,
                'personal_id' => $personalId,
                'phone' => $phone,
                'unit_id' => UnitEnum::H->value,
                'is_deleted' => false,
            ]);

            $this->counts['created']++;
        } else {
            if ($user->personal_number && $user->personal_number !== $personalNumber) {
                $this->warn("{$fileName} row {$rowNumber}: ID {$personalId} already belongs to personal number {$user->personal_number}; "
                    . "the file says {$personalNumber}. Kept {$user->personal_number} - check which one the person logs in with.");
            }

            if ($user->is_deleted) {
                $this->warn("{$fileName} row {$rowNumber}: user #{$user->id} ({$user->personal_number}) is marked deleted. "
                    . 'Permissions granted, the user was not restored.');
            }

            // Only empty fields are filled; what the database already knows always wins.
            $filled = array_filter([
                'personal_number' => $user->personal_number ? null : $personalNumber,
                'personal_id' => $user->personal_id ? null : $personalId,
                'full_name' => $user->full_name ? null : $fullName,
                'phone' => $user->phone ? null : $phone,
                'unit_id' => $user->unit_id ? null : UnitEnum::H->value,
            ], fn ($value): bool => $value !== null);

            if ($filled !== []) {
                $user->fill($filled)->save();
                $this->counts['updated']++;
            }
        }

        $this->grant($user, $permissions);
        $this->userIdsInFile[$user->id] = true;
    }

    /**
     * The plain user role only for a person with no role at all - someone who is already an admin or approver keeps
     * their one role and only gains the permissions. @param Collection<string, Permission> $permissions
     */
    private function grant(User $user, Collection $permissions): void
    {
        $changed = false;

        if (!$user->roles()->exists()) {
            $user->assignRole(RoleEnum::USER->value);
            $changed = true;
        }

        $missing = $permissions->diffKeys($user->getDirectPermissions()->keyBy('name'));

        if ($missing->isNotEmpty()) {
            $user->givePermissionTo($missing->values()->all());
            $changed = true;
        }

        if ($changed) {
            // The next users export file must carry the new grants.
            $user->markRolesChanged();
            $this->counts['granted']++;
        } else {
            $this->counts['unchanged']++;
        }
    }

    /**
     * Makes the file the full list of its own types: those permissions are taken from everyone the file does not hold.
     * The common permissions are never touched - they belong to the people of the other files too.
     * @param Collection<string, Permission> $filePermissions
     */
    private function revokeMissing(Collection $filePermissions): void
    {
        foreach ($filePermissions->keys() as $name) {
            $users = User::query()
                ->whereHas('permissions', fn ($query) => $query->where('name', $name))
                ->whereNotIn('id', array_keys($this->userIdsInFile))
                ->get();

            foreach ($users as $user) {
                $user->revokePermissionTo($name);
                $user->markRolesChanged();
                $this->counts['revoked']++;
                $this->warn("revoked {$name} from user #{$user->id} ({$user->personal_number})");
            }
        }
    }

    private function skipRow(string $fileName, int $rowNumber, string $reason): void
    {
        $this->warn("{$fileName} row {$rowNumber} skipped: {$reason}.");
        $this->counts['skipped_rows']++;
    }

    /** Collects a warning for the command's output and logs it. */
    private function warn(string $message): void
    {
        $this->messages[] = $message;
        Log::warning('ImportUsersXlsxService: ' . $message);
    }

    /** @param array<int, mixed> $cells @param array<string, int> $columns */
    private function rawText(array $cells, array $columns, string $field): string
    {
        return trim((string) ($cells[$columns[$field]] ?? ''));
    }

    /** Excel hands numbers back as ints or floats; this keeps every digit. */
    private function digits(mixed $value): string
    {
        if (is_float($value)) {
            $value = sprintf('%.0f', $value);
        }

        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private function normalizePersonalNumber(mixed $value): ?string
    {
        $digits = $this->digits($value);

        return preg_match('/^\d{5,9}$/', $digits) ? $digits : null;
    }

    /** A leading zero Excel dropped is restored (left-pad to 9 digits), then the check digit must hold. */
    private function normalizePersonalId(mixed $value): ?string
    {
        $digits = $this->digits($value);

        if ($digits === '' || strlen($digits) > 9) {
            return null;
        }

        $digits = str_pad($digits, 9, '0', STR_PAD_LEFT);

        return $this->isValidIsraeliId($digits) ? $digits : null;
    }

    private function isValidIsraeliId(string $id): bool
    {
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $digit = (int) $id[$i] * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($digit, 10) + $digit % 10;
        }

        return $sum % 10 === 0;
    }

    /** "528434874" (Excel dropped the 0) and "055-2579240" both land as 05XXXXXXXX; anything else becomes null, never a skipped row. */
    private function normalizePhone(mixed $value): ?string
    {
        $digits = $this->digits($value);

        if (strlen($digits) === 9 && str_starts_with($digits, '5')) {
            $digits = '0' . $digits;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '9725')) {
            $digits = '0' . substr($digits, 3);
        }

        return preg_match('/^05\d{8}$/', $digits) ? $digits : null;
    }

    /** First + last, single-spaced, with RTL-flipped brackets fixed: "יועז )אליעזר(" -> "יועז (אליעזר)". */
    private function fullName(mixed $firstName, mixed $lastName): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', trim((string) $firstName) . ' ' . trim((string) $lastName)) ?? '');
        $name = preg_replace('/\)([^()]*)\(/u', '($1)', $name) ?? $name;

        return $name === '' ? null : $name;
    }
}
