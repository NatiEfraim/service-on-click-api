<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatusCodeEnum;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app's own login, by personal number - for local development and tests.
 * It asks for no secret, so the servers replace this file with ADFS/AuthController.php (see the Dockerfile).
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    /** POST /api/login - log in with a personal number; the personal access token is set in a cookie. @param Request $request @return JsonResponse */
    public function login(Request $request): JsonResponse
    {
        $user = $this->authService->findUserForLogin($request);

        if ($user instanceof HttpStatusCodeEnum) {
            return match ($user) {
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => HttpStatusCodeEnum::NOT_FOUND->getMessage()], Response::HTTP_NOT_FOUND),
                default => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
            };
        }

        return $this->authService->loginResponse($user);
    }

    /** POST /api/logout - revoke the token and forget its cookie. @param Request $request @return JsonResponse */
    public function logout(Request $request): JsonResponse
    {
        return $this->authService->logoutResponse($request->user());
    }
}
