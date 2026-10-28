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

    /** Creates an account named from Vatican, with the user role; null when Vatican does not know the person. @param string $personalNumber @return ?User */
    public function createFromVatican(string $personalNumber): ?User
    {
        $data = $this->getUserFromVatican($personalNumber);

        if (is_null($data)) {
            return null;
        }

        return $this->createUser($data['personal_number'], $data['full_name']);
    }

    /** Creates a plain user (or returns the one already there): it can log in and see its own bookings, and every management endpoint refuses it. @param string $personalNumber, ?string $fullName @return User */
    public function createUser(string $personalNumber, ?string $fullName = null): User
    {
        $user = User::firstOrCreate(['personal_number' => $personalNumber], ['full_name' => $fullName]);

        $this->ensureUserRole($user);

        return $user;
    }

    /** A person with no role at all becomes a plain user - e.g. after the last manager role was taken away - so they can still see their own bookings. @param User $user */
    public function ensureUserRole(User $user): void
    {
        if ($user->roles()->doesntExist()) {
            $user->assignRole(RoleEnum::USER->value);
        }
    }

    /** Look a person up by personal number in Vatican; null when it does not know them or cannot be reached. @param string $personalNumber @return ?array{full_name: string, personal_number: string} */
    private function getUserFromVatican(string $personalNumber): ?array
    {
        try {
            $client = new Client();
            $vaticanUrl = config('services.vatican.url');
            $vaticanToken = config('services.vatican.token');

            $queryParams = [
                'columns' => implode(',', [
                    AdfsColumnsEnum::PERSONAL_NUMBER->value,
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
                $vaticanUrl . "/api/users/" . $personalNumber,
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
                'full_name' => trim(($userFromVatican['first_name'] ?? '') . ' ' . ($userFromVatican['surname'] ?? '')),
                'personal_number' => $userFromVatican['personal_number'],
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
}
