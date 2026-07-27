<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdBookingRequest;
use App\Http\Resources\Ads\AdBookingRequestResource;
use App\Services\Ads\AdBookingSubmissionService;
use Illuminate\Http\JsonResponse;

class AdBookingRequestController extends Controller
{
    public function store(StoreAdBookingRequest $request, AdBookingSubmissionService $submission): JsonResponse
    {
        $bookingRequest = $submission->submit($request->validated());

        return AdBookingRequestResource::make($bookingRequest)
            ->response()
            ->setStatusCode(201);
    }
}
