<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatusCodeEnum;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Local-development login by personal number; servers swap in ADFS/AuthController.php (see the Dockerfile). */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    /** Log in with a personal number; the personal access token is set in a cookie. */
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

    /** Revoke the token and forget its cookie. */
    public function logout(Request $request): JsonResponse
    {
        return $this->authService->logoutResponse($request->user());
    }
}
