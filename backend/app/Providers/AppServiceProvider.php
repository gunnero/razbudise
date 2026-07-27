<?php

namespace App\Providers;

use App\Models\AdminAuditLog;
use App\Models\MediaAsset;
use App\Observers\MediaAssetDeletionObserver;
use App\Observers\NewsroomAuditNotificationObserver;
use App\Policies\MediaAssetPolicy;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AdminAuditLog::observe(NewsroomAuditNotificationObserver::class);
        MediaAsset::observe(MediaAssetDeletionObserver::class);
        Gate::policy(MediaAsset::class, MediaAssetPolicy::class);

        FilamentTimezone::set('Europe/Skopje');

        RateLimiter::for('ad-placements', function (Request $request): array {
            $ip = $request->ip() ?: 'unknown';
            $isLoopbackSsr = in_array($ip, ['127.0.0.1', '::1'], true);

            return [
                Limit::perSecond($isLoopbackSsr ? 250 : 20)->by("ad-placement-burst|{$ip}"),
                Limit::perMinute($isLoopbackSsr ? 12000 : 300)->by("ad-placement-sustained|{$ip}"),
            ];
        });
        RateLimiter::for('ad-impressions', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->ip() ?: 'unknown'));
        RateLimiter::for('ad-clicks', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->ip() ?: 'unknown'));
        RateLimiter::for('media-uploads', function (Request $request): array {
            $actor = $request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(20)->by("media-upload-burst|{$actor}"),
                Limit::perHour(120)->by("media-upload-sustained|{$actor}"),
            ];
        });
        RateLimiter::for('media-library', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('media-library|'.($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown')));
    }
}
