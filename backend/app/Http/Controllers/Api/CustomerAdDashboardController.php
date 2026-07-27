<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Ads\CustomerAdDashboardResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerAdDashboardController extends Controller
{
    public function index(Request $request): CustomerAdDashboardResource
    {
        $customer = $request->user()
            ->adCustomer()
            ->with([
                'bookingRequests' => fn ($query) => $query->latest('created_at'),
                'bookingRequests.invoice',
                'bookingRequests.lineItems.creative' => fn ($query) => $query->withCount(['impressions', 'clicks']),
                'bookingRequests.lineItems.revisions',
                'bookingRequests.lineItems.zone',
            ])
            ->first();

        abort_unless(
            $customer
            && $customer->status === 'active'
            && ! $customer->dashboard_deactivated_at
            && ! $customer->former_customer_at,
            403,
        );

        $customer->forceFill(['last_active_at' => now()])->save();

        return CustomerAdDashboardResource::make($customer);
    }

    public function invoices(Request $request): JsonResponse
    {
        $customer = $request->user()
            ->adCustomer()
            ->with(['bookingRequests.invoice'])
            ->first();

        abort_unless(
            $customer
            && $customer->status === 'active'
            && ! $customer->dashboard_deactivated_at
            && ! $customer->former_customer_at,
            403,
        );

        return response()->json([
            'data' => $customer->bookingRequests
                ->filter(fn ($booking): bool => (bool) $booking->invoice)
                ->map(fn ($booking): array => [
                    'id' => $booking->invoice->id,
                    'booking_reference' => $booking->reference,
                    'number' => $booking->invoice->number,
                    'type' => $booking->invoice->type,
                    'status' => $booking->invoice->status,
                    'total_denars' => (int) $booking->invoice->total_denars,
                    'issued_at' => $booking->invoice->issued_at?->toISOString(),
                    'due_at' => $booking->invoice->due_at?->toISOString(),
                ])
                ->values(),
        ]);
    }
}
