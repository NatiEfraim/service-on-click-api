<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use App\Services\UserService;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Log;

use GuzzleHttp\Client;

use Symfony\Component\HttpFoundation\Response;


class AuthController extends Controller
{
    public function __construct(private UserService $UserService, private AuthService $AuthService) {}

    public function login(Request $request): JsonResponse|string
    {
        try {
            // The token goes into the ADFS URL, which is called with the service account: it must stay one path segment.
            $token = $request->token;
            if (!is_string($token) || $token === '' || strlen($token) > 2048) {
                return response()->json('בקשה שגויה', Response::HTTP_BAD_REQUEST);
            }

            $client = new Client();
            $adfsUrl = config('services.adfs.url');
            $adfsUser = config('services.adfs.user');
            $adfsPassword = config('services.adfs.password');

            // Guzzle 8 ignores 'ntlm' in the 'auth' option, so NTLM is set on cURL directly
            $userFromADFS = $client->get(
                $adfsUrl . "/api/token/" . rawurlencode($token),
                [
                    'verify' => false,
                    'curl' => [
                        CURLOPT_HTTPAUTH => CURLAUTH_NTLM,
                        CURLOPT_USERPWD => $adfsUser . ':' . $adfsPassword,
                    ],
                ]
            );

            $userDecoded = json_decode($userFromADFS->getBody());
            if (is_array($userDecoded)) {
                $userDecoded = $userDecoded[0] ?? null;
            }
            if (is_null($userDecoded)) {
                return response()->json(["errors" => ["message" => 'המשתמש לא קיים במערכת']], Response::HTTP_FORBIDDEN);
            }

            $personalNumber = $userDecoded->personal_number ?? null;
            if (!is_null($personalNumber)) {
                // the number in the user name (ARMY\c9810738) may be how the system stores the person
                $usernameNumber = preg_match('/(\d+)$/', (string) ($userDecoded->username ?? ''), $match) ? $match[1] : null;
                $user = $this->UserService->findForLogin([(string) $personalNumber, $usernameNumber])
                    ?? $this->UserService->createFromVatican((string) $personalNumber)
                    ?? $this->UserService->createUser((string) $personalNumber);
            } else {
                return response()->json('מספר אישי לא תקין', Response::HTTP_NOT_FOUND);
            }

            if ($user->is_deleted) {
                return response()->json(["errors" => ["message" => 'המשתמש לא קיים במערכת']], Response::HTTP_FORBIDDEN);
            }

            // Revokes the earlier tokens, issues the personal access token and sets it in the cookie.
            return $this->AuthService->loginResponse($user);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json('חלה שגיאה בעת ההתחברות', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        return $this->AuthService->logoutResponse($request->user());
    }
}
