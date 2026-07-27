<?php

namespace App\Services\Ads;

use App\Models\AdCreative;
use App\Models\AdZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;

class AdDeliveryTokenService
{
    private const VERSION = 1;

    /**
     * @param  array{device?: string|null, category?: string|null, category_id?: int|null, article_id?: int|null}  $context
     * @return array{token: string, expires_at: string, zone_key: string, context: array{device: string|null, category: string|null, category_id: int|null, article_id: int|null}}
     */
    public function issue(AdCreative $creative, AdZone $zone, array $context = []): array
    {
        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addMinutes(max(1, (int) config('ads.delivery_token_ttl_minutes', 15)));
        $normalizedContext = $this->normalizedContext($context);
        $payload = [
            'version' => self::VERSION,
            'delivery_id' => (string) Str::uuid(),
            'creative_id' => (int) $creative->getKey(),
            'campaign_id' => (int) $creative->campaign_id,
            'zone_id' => (int) $zone->getKey(),
            'zone_key' => (string) $zone->key,
            'context' => $normalizedContext,
            'issued_at' => $issuedAt->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(),
        ];

        return [
            'token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            'expires_at' => $expiresAt->toIso8601String(),
            'zone_key' => (string) $zone->key,
            'context' => $normalizedContext,
        ];
    }

    /**
     * @param  array{zone_key?: string|null, device?: string|null, category?: string|null, category_id?: int|null, article_id?: int|null}  $expected
     * @return array{version: int, delivery_id: string, creative_id: int, campaign_id: int, zone_id: int, zone_key: string, context: array{device: string|null, category: string|null, category_id: int|null, article_id: int|null}, issued_at: int, expires_at: int}
     *
     * @throws AuthorizationException
     */
    public function validate(string $token, AdCreative $creative, array $expected = []): array
    {
        return $this->validatedPayload($token, $creative, $expected, rejectExpired: true);
    }

    /**
     * Validate the signed subject and context for redirect continuity without
     * granting an expired token permission to record a click.
     *
     * @param  array{zone_key?: string|null, device?: string|null, category?: string|null, category_id?: int|null, article_id?: int|null}  $expected
     * @return array{version: int, delivery_id: string, creative_id: int, campaign_id: int, zone_id: int, zone_key: string, context: array{device: string|null, category: string|null, category_id: int|null, article_id: int|null}, issued_at: int, expires_at: int}
     *
     * @throws AuthorizationException
     */
    public function validateForRedirect(string $token, AdCreative $creative, array $expected = []): array
    {
        return $this->validatedPayload($token, $creative, $expected, rejectExpired: false);
    }

    /**
     * @param  array{expires_at: int}  $payload
     */
    public function isExpired(array $payload): bool
    {
        return $payload['expires_at'] <= now()->getTimestamp();
    }

    /**
     * @param  array{zone_key?: string|null, device?: string|null, category?: string|null, category_id?: int|null, article_id?: int|null}  $expected
     * @return array{version: int, delivery_id: string, creative_id: int, campaign_id: int, zone_id: int, zone_key: string, context: array{device: string|null, category: string|null, category_id: int|null, article_id: int|null}, issued_at: int, expires_at: int}
     */
    private function validatedPayload(
        string $token,
        AdCreative $creative,
        array $expected,
        bool $rejectExpired,
    ): array {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw $this->invalidToken();
        }

        if (! $this->hasValidShape($payload)
            || $payload['creative_id'] !== (int) $creative->getKey()
            || $payload['campaign_id'] !== (int) $creative->campaign_id
            || ($rejectExpired && $this->isExpired($payload))
            || $payload['issued_at'] > now()->addMinute()->getTimestamp()) {
            throw $this->invalidToken();
        }

        if (array_key_exists('zone_key', $expected)
            && $this->nullableString($expected['zone_key']) !== $payload['zone_key']) {
            throw $this->invalidToken();
        }

        foreach (['device', 'category', 'category_id', 'article_id'] as $key) {
            if (array_key_exists($key, $expected)
                && $this->normalizedContextValue($key, $expected[$key]) !== $payload['context'][$key]) {
                throw $this->invalidToken();
            }
        }

        return $payload;
    }

    private function hasValidShape(mixed $payload): bool
    {
        if (! is_array($payload)
            || ($payload['version'] ?? null) !== self::VERSION
            || ! is_string($payload['delivery_id'] ?? null)
            || ! Str::isUuid($payload['delivery_id'])
            || ! is_int($payload['creative_id'] ?? null)
            || ! is_int($payload['campaign_id'] ?? null)
            || ! is_int($payload['zone_id'] ?? null)
            || ! is_string($payload['zone_key'] ?? null)
            || $payload['zone_key'] === ''
            || ! is_int($payload['issued_at'] ?? null)
            || ! is_int($payload['expires_at'] ?? null)
            || $payload['expires_at'] <= $payload['issued_at']
            || ! is_array($payload['context'] ?? null)) {
            return false;
        }

        $context = $payload['context'];

        return array_keys($context) === ['device', 'category', 'category_id', 'article_id']
            && ($context['device'] === null || is_string($context['device']))
            && ($context['category'] === null || is_string($context['category']))
            && ($context['category_id'] === null || is_int($context['category_id']))
            && ($context['article_id'] === null || is_int($context['article_id']));
    }

    /**
     * @param  array{device?: string|null, category?: string|null, category_id?: int|null, article_id?: int|null}  $context
     * @return array{device: string|null, category: string|null, category_id: int|null, article_id: int|null}
     */
    private function normalizedContext(array $context): array
    {
        return [
            'device' => $this->nullableString($context['device'] ?? null),
            'category' => $this->nullableString($context['category'] ?? null),
            'category_id' => $this->nullableInteger($context['category_id'] ?? null),
            'article_id' => $this->nullableInteger($context['article_id'] ?? null),
        ];
    }

    private function normalizedContextValue(string $key, mixed $value): string|int|null
    {
        return in_array($key, ['category_id', 'article_id'], true)
            ? $this->nullableInteger($value)
            : $this->nullableString($value);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function invalidToken(): AuthorizationException
    {
        return new AuthorizationException('Invalid or expired ad delivery token.');
    }
}
