<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetBackofficeLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        app()->setLocale($user instanceof User ? $user->preferredLocale() : 'en');

        return $next($request);
    }
}
