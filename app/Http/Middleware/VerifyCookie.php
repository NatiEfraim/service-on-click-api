<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The login sets the personal access token in a cookie; every request reads it back as the bearer token. */
class VerifyCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $cookieName = config('auth.access_token_name');

        if ($cookieName && $request->hasCookie($cookieName)) {
            $request->headers->set('Authorization', 'Bearer ' . $request->cookie($cookieName));
        }

        return $next($request);
    }
}
