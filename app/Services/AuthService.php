<?php

namespace App\Services;

use App\Enums\HttpStatusCodeEnum;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Token;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login and logout with a Passport personal access token kept in a cookie.
 * Both login controllers - the app's AuthController and the ADFS one that replaces it on the servers -
 * end here, so they answer the same way.
 */
class AuthService
{
    public function __construct(
        private readonly UserService $userService,
        private readonly StorageService $storageService,
    ) {
    }

    /** The user of a login with a personal number (the app's own login, used where there is no ADFS). @param Request $request @return User|HttpStatusCodeEnum */
    public function findUserForLogin(Request $request): User|HttpStatusCodeEnum
    {
        try {
            $personalNumber = (string) $request->input('personal_number');

            if (preg_match('/^\d{7,8}$/', $personalNumber) !== 1) {
                return HttpStatusCodeEnum::BAD_REQUEST;
            }

            $user = $this->userService->findForLogin([$personalNumber]);

            return $user && !$user->is_deleted ? $user : HttpStatusCodeEnum::NOT_FOUND;
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Revoke the user's earlier tokens, issue a new personal access token and answer with who the user is and the token cookie. @param User $user @return JsonResponse */
    public function loginResponse(User $user): JsonResponse
    {
        Token::where('user_id', $user->id)->update(['revoked' => true]);

        $tokenName = config('auth.access_token_name');
        $token = $user->createToken($tokenName);

        $roles = [];
        $userRoles = $user->roles()->orderBy('id')->pluck('name')->toArray();
        if (count($userRoles) > 0) $roles[] = $userRoles[0];

        $userPermissions = [];
        foreach ($user->getDirectPermissions() as $permission) {
            $userPermissions[] = $permission->name;
        }

        return response()->json(
            [
                "full name" => $user->full_name,
                "personal_number" => $user->personal_number,
                "role" => $roles,
                "permissions" => $userPermissions
            ],
            Response::HTTP_CREATED
        )
            ->withCookie(Cookie::make($tokenName, $token->accessToken));
    }

    /** Revoke the token of this request and forget its cookie. @param ?User $user @return JsonResponse */
    public function logoutResponse(?User $user): JsonResponse
    {
        try {
            $token = $user?->token();

            // A transient (session) token has nothing to revoke.
            if ($token && method_exists($token, 'revoke')) {
                $token->revoke();
            }

            return response()->json(['message' => 'התנתקות בוצעה בהצלחה'])
                ->withCookie(Cookie::forget(config('auth.access_token_name')));
        } catch (\Exception $e) {
            Log::error('Error in AuthService: logoutResponse function: ' . $e->getMessage());
            $this->storageService->logToS3();
            return response()->json(
                ['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
