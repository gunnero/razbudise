<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOneFiveFourBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = config('services.onefivefour.article_ingestion_token');

        if (! is_string($configuredToken) || trim($configuredToken) === '') {
            return response()->json([
                'message' => 'OneFiveFour article ingestion is not configured.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $providedToken = $request->bearerToken();

        if (! is_string($providedToken) || ! hash_equals($configuredToken, $providedToken)) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
