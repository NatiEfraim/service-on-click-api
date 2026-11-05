<?php

namespace App\Http\Controllers;

use App\Models\User;
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
                $usernameNumber = preg_match('/(\d+)$/', (string) ($userDecoded->username ?? ''), $match) ? $match[1] : null;
                $user = $this->UserService->findForAdfsLogin((string) $personalNumber, $usernameNumber);
            } else {
                return response()->json('מספר אישי לא תקין', Response::HTTP_NOT_FOUND);
            }

            if (is_null($user)) {
                return response()->json(["errors" => ["message" => 'המשתמש לא קיים במערכת']], Response::HTTP_FORBIDDEN);
            }

            if (!User::where('id', $user->id)->where('is_deleted', false)->exists()) {
                return response()->json(["errors" => ["message" => 'המשתמש לא קיים במערכת']], Response::HTTP_FORBIDDEN);
            }


            if ($user->roles()->doesntExist()) {
                return response()->json(["errors" => ["message" => 'המשתמש לא קיים במערכת']], Response::HTTP_FORBIDDEN);
            }

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
