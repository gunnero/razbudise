<?php

use App\Http\Controllers\Api\AdBookingRequestController;
use App\Http\Controllers\Api\AdPlacementController;
use App\Http\Controllers\Api\AdProductController;
use App\Http\Controllers\Api\AdTrackingController;
use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\ArticlePreviewController;
use App\Http\Controllers\Api\ArticleViewController;
use App\Http\Controllers\Api\AuthorController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerAdAuthController;
use App\Http\Controllers\Api\CustomerAdCreativeRevisionController;
use App\Http\Controllers\Api\CustomerAdDashboardController;
use App\Http\Controllers\Api\CustomerAdRenewalController;
use App\Http\Controllers\Api\EditorialAnalyticsEventController;
use App\Http\Controllers\Api\HomepageController;
use App\Http\Controllers\Api\Integrations\OneFiveFourArticleController;
use App\Http\Controllers\Api\MediaAssetController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\NewsletterSubscriberController;
use App\Http\Controllers\Api\RedirectController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SiteSettingController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TeamMemberController;
use App\Http\Controllers\Api\TeamOrgChartController;
use App\Http\Middleware\EnsureOneFiveFourBearerToken;
use App\Models\AdInvoice;
use App\Services\Ads\AdInvoiceDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/ads/impression', [AdTrackingController::class, 'impression'])
    ->middleware('throttle:ad-impressions')
    ->name('api.ads.impression');
Route::post('/ads/click/{creative}', [AdTrackingController::class, 'click'])
    ->middleware('throttle:ad-clicks')
    ->name('api.ads.click');
Route::get('/ads/click/{creative}', [AdTrackingController::class, 'redirect'])
    ->middleware('throttle:ad-clicks')
    ->name('api.ads.redirect');
Route::get('/ads/{zoneKey}', [AdPlacementController::class, 'show'])
    ->middleware('throttle:ad-placements')
    ->name('api.ads.placement');
Route::get('/ad-products', [AdProductController::class, 'index']);
Route::get('/ad-products/{zone:key}/availability', [AdProductController::class, 'availability']);
Route::post('/ad-booking-requests', [AdBookingRequestController::class, 'store'])->middleware('throttle:10,1');
Route::post('/ad-booking-creatives', [MediaAssetController::class, 'storePublicAdCreative'])->middleware('throttle:10,1');
Route::delete('/ad-booking-creatives', [MediaAssetController::class, 'destroyPublicAdCreative'])->middleware('throttle:20,1');
Route::post('/newsletter/subscribers', [NewsletterSubscriberController::class, 'store']);
Route::post('/editorial-analytics/events', [EditorialAnalyticsEventController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('api.editorial-analytics.events');
Route::get('/media-assets', [MediaAssetController::class, 'index'])
    ->middleware(['auth', 'throttle:media-library'])
    ->name('api.media-assets.index');
Route::post('/media-assets', [MediaAssetController::class, 'store'])
    ->middleware(['auth', 'throttle:media-uploads'])
    ->name('api.media-assets.store');
Route::get('/menus/{location}', [MenuController::class, 'show']);
Route::get('/site-settings/public', [SiteSettingController::class, 'public']);
Route::get('/team-members', [TeamMemberController::class, 'index']);
Route::get('/team/org-chart', TeamOrgChartController::class);
Route::get('/homepage', HomepageController::class);
Route::get('/redirects/resolve', [RedirectController::class, 'resolve']);
Route::get('/events', [ArticleController::class, 'events']);
Route::get('/articles', [ArticleController::class, 'index']);
Route::get('/articles/{slug}/related', [ArticleController::class, 'related']);
Route::post('/articles/{article:slug}/view', [ArticleViewController::class, 'store'])->middleware('throttle:60,1');
Route::get('/articles/{slug}', [ArticleController::class, 'show']);
Route::get('/preview/articles/{article}', ArticlePreviewController::class)
    ->middleware('signed:relative')
    ->name('api.articles.preview');
Route::get('/authors', [AuthorController::class, 'index']);
Route::get('/authors/{slug}', [AuthorController::class, 'show']);
Route::get('/authors/{slug}/articles', [AuthorController::class, 'articles']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{slug}', [CategoryController::class, 'show']);
Route::get('/categories/{slug}/articles', [CategoryController::class, 'articles']);
Route::get('/search', SearchController::class);
Route::get('/tags', [TagController::class, 'index']);
Route::get('/tags/{slug}', [TagController::class, 'show']);
Route::get('/tags/{slug}/articles', [TagController::class, 'articles']);

Route::middleware('web')->prefix('customer')->group(function (): void {
    Route::get('/csrf-token', [CustomerAdAuthController::class, 'csrfToken'])->middleware('throttle:10,1');
    Route::post('/login', [CustomerAdAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/logout', [CustomerAdAuthController::class, 'logout'])->middleware('throttle:10,1');
    Route::get('/ads', [CustomerAdDashboardController::class, 'index'])->middleware('auth');
    Route::get('/invoices', [CustomerAdDashboardController::class, 'invoices'])->middleware('auth');
    Route::get('/invoices/{invoice}/download', function (Request $request, AdInvoice $invoice, AdInvoiceDocumentService $documents) {
        $user = $request->user();

        abort_unless($user, 403);

        if (! $user->hasPermission('ads.manage')) {
            $invoice->loadMissing('bookingRequest.customer');
            $customer = $user->adCustomer;

            abort_unless(
                $customer
                && $customer->status === 'active'
                && ! $customer->dashboard_deactivated_at
                && ! $customer->former_customer_at
                && $invoice->bookingRequest?->ad_customer_id === $customer->id,
                403,
            );
        }

        return $documents->downloadResponse($invoice);
    })->middleware('auth');
    Route::post('/media/ad-creatives', [MediaAssetController::class, 'storeCustomerAdCreative'])->middleware(['auth', 'throttle:10,1']);
    Route::get('/media/ad-creatives', [MediaAssetController::class, 'customerAdCreatives'])->middleware(['auth', 'throttle:30,1']);
    Route::post('/media/ad-creatives/{mediaAsset}/claim', [MediaAssetController::class, 'claimCustomerAdCreative'])->middleware(['auth', 'throttle:30,1']);
    Route::post('/bookings/{lineItem}/renew', [CustomerAdRenewalController::class, 'store'])->middleware(['auth', 'throttle:10,1']);
    Route::post('/creatives/{lineItem}/revision', [CustomerAdCreativeRevisionController::class, 'store'])->middleware(['auth', 'throttle:10,1']);
});

Route::prefix('v1')->group(function (): void {
    Route::post('/integrations/onefivefour/articles', [OneFiveFourArticleController::class, 'store'])
        ->middleware(EnsureOneFiveFourBearerToken::class);

    Route::get('/status', function () {
        return response()->json([
            'data' => [
                'name' => 'RazbudiSe API',
                'version' => 'v1',
                'mobile_ready' => true,
                'ai_author_generation_enabled' => false,
            ],
        ]);
    });
});
