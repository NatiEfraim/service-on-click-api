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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UserService
{
    const MIN_LEN = 1;
    const MAX_LEN = 9;

    public function __construct(private readonly StorageService $storageService) {}

    /** The authenticated user with roles, permissions and unit. @return mixed */
    public function user(): mixed
    {
        try {
            $user = User::where('id', Auth::id())->where('is_deleted', false)->with('roles', 'permissions', 'unit')->first();

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

    /** Paginate the users, optionally by a personal number / personal id prefix or a full name prefix. @param ?string $identifier @return mixed */
    public function index(?string $identifier = null): mixed
    {
        try {
            $query = User::with(['roles', 'permissions'])->where('is_deleted', false);

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

    /** Create a user. @param array $data personal_number, full_name, phone, unit_id @return mixed */
    public function store(array $data): mixed
    {
        try {
            $user = User::create([
                'personal_number' => $data['personal_number'],
                'full_name' => $data['full_name'],
                'phone' => $data['phone'] ?? null,
                'unit_id' => $data['unit_id'] ?? null,
                'is_deleted' => false,
            ]);

            $user->load(['roles', 'permissions']);

            return new UserResource($user);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** The user of a login; when several personal numbers match, the highest role wins (a deleted user only if nothing else matches). @param array<int, ?string> $personalNumbers @return ?User */
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
            ->sortBy(fn (User $user) => [
                (int) $user->is_deleted,
                $user->roles->map(fn ($role) => $priority[$role->name] ?? 5)->min() ?? 5,
            ])
            ->first();
    }

    /**
     * The user of an ADFS login, from the database only - nobody is created here.
     * First by the personal number; when no one has it, by the ID number Vatican holds for that personal number
     * (the mobile app knows people by ID number only). Whichever of the two the row lacks is filled in from Vatican.
     * @param string $personalNumber @param ?string $usernameNumber the number the ADFS user name ends with (ARMY\c9810738) @return ?User
     */
    public function findForAdfsLogin(string $personalNumber, ?string $usernameNumber = null): ?User
    {
        $user = $this->findForLogin([$personalNumber, $usernameNumber]);

        // Vatican is asked only when its answer can matter: nobody has this personal number, or the row has no ID number yet.
        $vatican = is_null($user) || is_null($user->personal_id) ? $this->getUserFromVatican($personalNumber) : null;

        if (is_null($user) && !is_null($vatican['personal_id'] ?? null)) {
            $user = User::where('personal_id', $vatican['personal_id'])->first();
        }

        if ($user && $vatican) {
            $this->fillMissingIdentifiers($user, $vatican);
        }

        return $user;
    }

    /** Fill in the personal number / ID number the row lacks, from Vatican; a value another row already holds is left out (both columns are unique). @param User $user @param array{personal_number: string, personal_id: ?string} $vatican */
    private function fillMissingIdentifiers(User $user, array $vatican): void
    {
        $fromVatican = [
            'personal_number' => preg_match('/^\d{7,8}$/', $vatican['personal_number']) === 1 ? $vatican['personal_number'] : null,
            'personal_id' => $vatican['personal_id'],
        ];

        foreach ($fromVatican as $column => $value) {
            if (!is_null($user->{$column}) || is_null($value)) {
                continue;
            }

            if (User::where($column, $value)->exists()) {
                Log::warning("Login: the {$column} Vatican gave for user {$user->id} belongs to another user, so it was not filled in.");
                continue;
            }

            $user->{$column} = $value;
        }

        if ($user->isDirty()) {
            $user->save();
        }
    }

    /** A person with no role at all becomes a plain user - e.g. after the last manager role was taken away - so they can still see their own bookings. @param User $user */
    public function ensureUserRole(User $user): void
    {
        if ($user->roles()->doesntExist()) {
            $user->assignRole(RoleEnum::USER->value);
        }
    }

    /** Look a person up by personal number in Vatican for the ID number it holds; null when it does not know them or cannot be reached. @param string $personalNumber @return ?array{personal_number: string, personal_id: ?string} */
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

    /** An ID number as the database keeps it - nine digits, leading zeros included; null for anything that cannot be one (empty, too long, not digits, all zeros). @param mixed $value @return ?string */
    private function normalizePersonalId(mixed $value): ?string
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
