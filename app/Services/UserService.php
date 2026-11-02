<?php

namespace App\Services;

use App\Enums\AdfsColumnsEnum;
use App\Enums\HttpStatusCodeEnum;
use App\Enums\PaginationEnum;
use App\Enums\RoleEnum;
use App\Http\Resources\UserResource;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ResponseException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserService
{
    const MIN_LEN = 1;
    const MAX_LEN = 9;

    public function __construct(private readonly StorageService $storageService) {}

    /** The authenticated user with roles, permissions and unit. */
    public function user(): mixed
    {
        try {
            $user = User::where('id', Auth::id())->where('is_deleted', false)->with('roles', 'permissions', 'unit', 'department')->first();

            if (is_null($user)) {
                return HttpStatusCodeEnum::NOT_FOUND;
            }

            return new UserResource($user);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Paginates users, optionally by personal number / personal id prefix or full-name prefix. */
    public function index(?string $identifier = null): mixed
    {
        try {
            $query = User::with(['roles', 'permissions', 'unit', 'department'])->where('is_deleted', false);

            // Admins manage their own unit's people (assertMayManage); the super admin everybody.
            /** @var User $actor Auth::user() is typed as the Authenticatable contract, which has no hasRole(). */
            $actor = Auth::user();
            if (!$actor->hasRole(RoleEnum::SUPER_ADMIN->toSpatieRoleName())) {
                $query->where('unit_id', $actor->unit_id);
            }

            if (!is_null($identifier)) {
                $identifier = str_replace(' ', '', $identifier);

                if (ctype_digit($identifier)) {
                    if (strlen($identifier) < self::MIN_LEN || strlen($identifier) > self::MAX_LEN) {
                        return HttpStatusCodeEnum::UNPROCESSABLE_ENTITY;
                    }

                    $query->where(function ($q) use ($identifier) {
                        $q->where('personal_number', 'like', $identifier . '%')
                            ->orWhere('personal_id', 'like', $identifier . '%');
                    });
                } else {
                    $query->whereRaw("REPLACE(full_name, ' ', '') LIKE ?", [$identifier . '%']);
                }
            }

            return UserResource::collection($query->paginate(PaginationEnum::PER_PAGE->value));
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Creates a user. @param array $data personal_number, full_name, phone, unit_id, department_id (optional) */
    public function store(array $data): mixed
    {
        try {
            $user = DB::transaction(function () use ($data): User {
                $user = User::create([
                    'personal_number' => $data['personal_number'],
                    'full_name' => $data['full_name'],
                    'phone' => $data['phone'] ?? null,
                    'unit_id' => $data['unit_id'] ?? null,
                    'department_id' => $data['department_id'] ?? null,
                    'is_deleted' => false,
                ]);

                app(AuditService::class)->record([
                    'action' => 'user.create',
                    'entity_type' => 'user',
                    'entity_id' => $user->actorId(),
                    'unit_id' => $user->unit_id,
                    'after' => $user->only(['personal_number', 'full_name', 'unit_id', 'department_id']),
                ], Auth::user());

                return $user;
            });

            $user->load(['roles', 'permissions', 'unit', 'department']);

            return new UserResource($user);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** The user of a login; with several matching personal numbers the highest role wins (a deleted user only if nothing else matches). */
    public function findForLogin(array $personalNumbers): ?User
    {
        $priority = [
            RoleEnum::SUPER_ADMIN->toSpatieRoleName() => 0,
            RoleEnum::ADMIN->toSpatieRoleName() => 1,
            RoleEnum::APPROVER->toSpatieRoleName() => 2,
            RoleEnum::VICE_APPROVER->toSpatieRoleName() => 3,
            RoleEnum::USER->toSpatieRoleName() => 4,
        ];

        return User::whereIn('personal_number', array_values(array_unique(array_filter($personalNumbers))))
            ->with('roles')
            ->get()
            ->sortBy(fn ($user) => [
                (int) $user->is_deleted,
                $user->roles->map(fn ($role) => $priority[$role->name] ?? 5)->min() ?? 5,
            ])
            ->first();
    }

    /**
     * The user of an ADFS login, from the database only (nobody is created). Found by personal number, else by the ID number Vatican holds
     * for it; whatever the row lacks (personal number, ID number, full name) is filled in from Vatican, so a bare row the mobile app
     * created from an ID number alone becomes the full person instead of a duplicate.
     * @param ?string $usernameNumber the number the ADFS user name ends with (ARMY\c9810738)
     */
    public function findForAdfsLogin(string $personalNumber, ?string $usernameNumber = null): ?User
    {
        $user = $this->findForLogin([$personalNumber, $usernameNumber]);

        // Vatican is asked only when it can matter: no row has this personal number, or the row lacks an ID number or a name.
        $vatican = is_null($user) || is_null($user->personal_id) || blank($user->full_name) ? $this->getUserFromVatican($personalNumber) : null;

        if (is_null($user) && !is_null($vatican['personal_id'] ?? null)) {
            $user = User::where('personal_id', $vatican['personal_id'])->first();
        }

        if ($user && $vatican) {
            $this->fillMissingDetails($user, $vatican);
        }

        return $user;
    }

    /**
     * Fills in what the row lacks from Vatican, never overwriting a value the row has. The personal/ID number is skipped when another row
     * holds it (both columns are unique).
     * @param array{personal_number: string, personal_id: ?string, full_name: ?string} $vatican
     */
    private function fillMissingDetails(User $user, array $vatican): void
    {
        $fromVatican = [
            'personal_number' => preg_match('/^\d{7,8}$/', $vatican['personal_number']) === 1 ? $vatican['personal_number'] : null,
            'personal_id' => $vatican['personal_id'],
            'full_name' => $vatican['full_name'],
        ];

        foreach ($fromVatican as $column => $value) {
            if (!blank($user->{$column}) || blank($value)) {
                continue;
            }

            if ($column !== 'full_name' && User::where($column, $value)->exists()) {
                Log::warning("Login: the {$column} Vatican gave for user {$user->id} belongs to another user, so it was not filled in.");
                continue;
            }

            $user->{$column} = $value;
        }

        if ($user->isDirty()) {
            $user->save();
        }
    }

    /** Gives a person with no role the plain user role (e.g. after their last manager role was removed) so they still see their own bookings. */
    public function ensureUserRole(User $user): void
    {
        if ($user->roles()->doesntExist()) {
            $user->assignRole(RoleEnum::USER->value);
        }
    }

    /** Looks a person up in Vatican for their ID number and name; null when unknown or unreachable. @return ?array{personal_number: string, personal_id: ?string, full_name: ?string} */
    private function getUserFromVatican(string $personalNumber): ?array
    {
        try {
            $client = app(Client::class);
            $vaticanUrl = config('services.vatican.url');
            $vaticanToken = config('services.vatican.token');

            $queryParams = [
                'columns' => implode(',', [
                    AdfsColumnsEnum::PERSONAL_NUMBER->value,
                    AdfsColumnsEnum::PERSONAL_ID->value,
                    AdfsColumnsEnum::FIRST_NAME->value,
                    AdfsColumnsEnum::SURNAME->value,
                ]),
            ];

            $response = $client->post(
                $vaticanUrl . "/api/systems/login",
                [
                    'verify' => false,
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                    'json' => [
                        'token' => $vaticanToken,
                    ]
                ]
            );
            $loginToVatican = json_decode($response->getBody()->getContents(), true);
            $vaticanAccessToken = $loginToVatican['access_token'];

            $response = $client->get(
                $vaticanUrl . "/api/users/" . rawurlencode($personalNumber),
                [
                    'verify' => false,
                    'query' => $queryParams,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $vaticanAccessToken,
                    ],
                ]
            );

            $userFromVatican = json_decode($response->getBody(), true);
            if (is_array($userFromVatican) && array_is_list($userFromVatican)) {
                $userFromVatican = $userFromVatican[0] ?? null;
            }
            if (empty($userFromVatican['personal_number'])) {
                return null;
            }

            return [
                'personal_number' => (string) $userFromVatican['personal_number'],
                'personal_id' => $this->normalizePersonalId($userFromVatican['personal_id'] ?? null),
                'full_name' => $this->fullName($userFromVatican['first_name'] ?? null, $userFromVatican['surname'] ?? null),
            ];
        } catch (ResponseException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                Log::error('Vatican lookup failed: ' . $e->getMessage());
            }
            return null;
        } catch (\Exception $e) {
            Log::error('Vatican lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The user id of every ID number given, in one query. An unknown person gets a bare row (ID number and the booking's unit), because the mobile
     * app's people arrive only through bookings and logs. Throws on a database error so a queued job retries.
     * @param array<string, ?int> $unitIdByPersonalId normalized ID number => unit id @return array<string, int> ID number => user id
     */
    public function idsByPersonalId(array $unitIdByPersonalId): array
    {
        if ($unitIdByPersonalId === []) {
            return [];
        }

        $userIds = User::whereIn('personal_id', array_map('strval', array_keys($unitIdByPersonalId)))
            ->pluck('id', 'personal_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($unitIdByPersonalId as $personalId => $unitId) {
            $personalId = (string) $personalId;

            if (isset($userIds[$personalId])) {
                continue;
            }

            try {
                $userIds[$personalId] = User::create([
                    'personal_id' => $personalId,
                    'unit_id' => $unitId,
                    'is_deleted' => false,
                ])->id;
            } catch (UniqueConstraintViolationException) {
                // Another job saved this person a moment ago.
                $userIds[$personalId] = (int) User::where('personal_id', $personalId)->value('id');
            }
        }

        return $userIds;
    }

    /** First name and surname as one name; null when Vatican gave neither. */
    private function fullName(mixed $firstName, mixed $surname): ?string
    {
        $parts = array_filter([$firstName, $surname], fn ($part) => is_string($part) && trim($part) !== '');

        return $parts === [] ? null : implode(' ', array_map('trim', $parts));
    }

    /** An ID number as stored: nine digits with leading zeros; null for anything else (empty, too long, not digits, all zeros). @return ?string */
    public static function normalizePersonalId(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        $digits = trim((string) $value);

        if (preg_match('/^\d{1,9}$/', $digits) !== 1 || trim($digits, '0') === '') {
            return null;
        }

        return str_pad($digits, 9, '0', STR_PAD_LEFT);
    }
}
