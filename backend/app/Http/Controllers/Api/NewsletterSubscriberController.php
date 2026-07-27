<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewsletterSubscriberResource;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NewsletterSubscriberController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
        ]);

        $email = Str::lower($data['email']);
        $subscriber = NewsletterSubscriber::query()->firstOrNew(['email' => $email]);
        $created = ! $subscriber->exists;

        $subscriber->fill([
            'name' => $data['name'] ?? $subscriber->name,
            'status' => 'active',
            'source' => $data['source'] ?? $subscriber->source,
            'subscribed_at' => now(),
            'unsubscribed_at' => null,
        ])->save();

        return NewsletterSubscriberResource::make($subscriber)
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }
}
