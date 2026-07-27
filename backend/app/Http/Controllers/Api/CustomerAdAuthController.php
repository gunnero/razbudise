<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerAdLoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CustomerAdAuthController extends Controller
{
    public function csrfToken(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'csrf_token' => $request->session()->token(),
            ],
        ]);
    }

    public function login(CustomerAdLoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are invalid.',
            ]);
        }

        $request->session()->regenerate();
        $user = $request->user()->load('adCustomer');

        if (
            ! $user->adCustomer
            || $user->adCustomer->status !== 'active'
            || $user->adCustomer->dashboard_deactivated_at
            || $user->adCustomer->former_customer_at
        ) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403);
        }

        $user->adCustomer->forceFill(['last_active_at' => now()])->save();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'customer' => [
                    'id' => $user->adCustomer->id,
                    'company_name' => $user->adCustomer->company_name,
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['logged_out' => true]]);
    }
}
