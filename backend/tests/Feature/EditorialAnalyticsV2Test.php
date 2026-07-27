<?php

namespace Tests\Feature;

use App\Filament\Pages\EditorialAnalytics;
use App\Models\Article;
use App\Models\ArticleView;
use App\Models\Author;
use App\Models\EditorialAnalyticsDaily;
use App\Models\EditorialAnalyticsEvent;
use App\Models\EditorialSupervision;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Analytics\EditorialAnalyticsRollupService;
use App\Services\Analytics\EditorialAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EditorialAnalyticsV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_event_api_requires_consent_excludes_bots_and_records_pseudonymous_attribution(): void
    {
        $article = Article::factory()->published()->create();
        $payload = [
            'event_type' => 'homepage_article_click',
            'article_slug' => $article->slug,
            'visitor_token' => 'reader-token-1234567890',
            'session_token' => 'reader-session-1234567890',
            'consent_version' => 1,
            'analytics_consent' => true,
            'context_page' => 'homepage',
            'target_url' => "https://razbudise.mk/{$article->slug}?private=not-stored",
        ];

        $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson('/api/editorial-analytics/events', [
            ...$payload,
            'analytics_consent' => false,
        ])->assertUnprocessable();

        $this->withHeader('User-Agent', 'Googlebot/2.1')->postJson('/api/editorial-analytics/events', $payload)
            ->assertAccepted()
            ->assertJson(['recorded' => false]);
        $this->assertDatabaseCount('editorial_analytics_events', 0);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; Mobile)')->postJson('/api/editorial-analytics/events', $payload)
            ->assertAccepted()
            ->assertJson(['recorded' => true]);
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; Mobile)')->postJson('/api/editorial-analytics/events', $payload)
            ->assertAccepted();

        $event = EditorialAnalyticsEvent::query()->firstOrFail();
        $this->assertSame('homepage', $event->source_channel);
        $this->assertSame('mobile', $event->device);
        $this->assertSame('razbudise.mk', $event->target_host);
        $this->assertNotSame($payload['visitor_token'], $event->visitor_hash);
        $this->assertStringNotContainsString('private', json_encode($event->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('editorial_analytics_events', 1);
    }

    public function test_backoffice_and_authenticated_editorial_traffic_is_excluded(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $article = Article::factory()->published()->create();
        $payload = [
            'event_type' => 'article_open',
            'article_slug' => $article->slug,
            'visitor_token' => 'reader-token-1234567890',
            'consent_version' => 1,
            'analytics_consent' => true,
            'context_page' => 'article',
        ];

        $this->actingAs($admin)
            ->withHeader('User-Agent', 'Mozilla/5.0')
            ->postJson('/api/editorial-analytics/events', $payload)
            ->assertAccepted()
            ->assertJson(['recorded' => false]);
        auth()->logout();
        $this->withHeader('User-Agent', 'Mozilla/5.0')
            ->postJson('/api/editorial-analytics/events', [...$payload, 'referrer' => 'https://razbudise.mk/backoffice/articles'])
            ->assertAccepted()
            ->assertJson(['recorded' => false]);

        $this->assertDatabaseCount('editorial_analytics_events', 0);
    }

    public function test_rollups_are_idempotent_and_role_scopes_apply_to_reports_and_csv(): void
    {
        $this->seed();
        $editor = $this->userWithRole('editor');
        $authorUser = $this->userWithRole('author');
        $chief = $this->userWithRole('editor_in_chief');
        $supervisedAuthor = Author::factory()->create(['name' => 'Supervised Author']);
        $ownAuthor = Author::factory()->create(['name' => 'Own Author']);
        $otherAuthor = Author::factory()->create(['name' => 'Unrelated Author']);
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $ownAuthor->id]);
        EditorialSupervision::factory()->create(['editor_id' => $editor->id, 'author_id' => $supervisedAuthor->id]);

        $supervised = Article::factory()->published()->create(['author_id' => $supervisedAuthor->id, 'title' => 'Supervised story']);
        $own = Article::factory()->published()->create(['author_id' => $ownAuthor->id, 'title' => 'Own story']);
        $unrelated = Article::factory()->published()->create(['author_id' => $otherAuthor->id, 'title' => 'Unrelated story']);
        $this->recordOpen($supervised, 'supervised-reader', 'homepage', 'desktop');
        $this->recordOpen($own, 'own-reader', 'facebook', 'mobile');
        $this->recordOpen($unrelated, 'other-reader', 'direct', 'desktop');

        $rollups = app(EditorialAnalyticsRollupService::class);
        $first = $rollups->rollup(today(), today());
        $count = EditorialAnalyticsDaily::query()->count();
        $second = $rollups->rollup(today(), today());
        $this->assertSame($count, EditorialAnalyticsDaily::query()->count());
        $this->assertSame($first['rows'], $second['rows']);

        $analytics = app(EditorialAnalyticsService::class);
        $editorReport = $analytics->report($editor, ['range' => 'today']);
        $this->assertSame('supervised', $editorReport['scope']);
        $this->assertSame(['Supervised story'], $editorReport['top_articles']->pluck('title')->all());
        $this->assertSame(1, $editorReport['metrics']['page_views']);
        $this->assertSame([$supervisedAuthor->id], $editorReport['authors']->pluck('author_id')->all());
        $this->assertSame(1, $editorReport['authors']->sole()['first_24_hour_views']);
        $this->assertSame(1, $editorReport['authors']->sole()['first_7_day_views']);

        $authorReport = $analytics->report($authorUser, ['range' => 'today']);
        $this->assertSame('author', $authorReport['scope']);
        $this->assertSame(['Own story'], $authorReport['top_articles']->pluck('title')->all());
        $this->assertSame([$ownAuthor->id], $authorReport['authors']->pluck('author_id')->all());
        $this->assertSame(1, $authorReport['authors']->sole()['first_24_hour_views']);
        $this->assertSame(1, $authorReport['authors']->sole()['first_7_day_views']);

        $chiefReport = $analytics->report($chief, ['range' => 'today']);
        $this->assertSame('editorial', $chiefReport['scope']);
        $this->assertEqualsCanonicalizing(
            ['Supervised story', 'Own story', 'Unrelated story'],
            $chiefReport['top_articles']->pluck('title')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$supervisedAuthor->id, $ownAuthor->id, $otherAuthor->id],
            $chiefReport['authors']->pluck('author_id')->all(),
        );

        $csv = $analytics->exportRows($editor, ['range' => 'today']);
        $this->assertSame(['Supervised story'], collect($csv)->pluck('title')->all());
        $this->assertNotContains('visitor_hash', $analytics->exportHeaders());
        $this->assertNotContains('session_hash', $analytics->exportHeaders());
    }

    public function test_source_device_and_date_comparison_filters_use_real_aggregate_rows(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $article = Article::factory()->published()->create();
        $this->recordOpen($article, 'homepage-reader', 'homepage', 'desktop');
        $this->recordOpen($article, 'facebook-reader', 'facebook', 'mobile');
        app(EditorialAnalyticsRollupService::class)->rollup(today(), today());

        $report = app(EditorialAnalyticsService::class)->report($admin, [
            'range' => 'today',
            'source' => 'homepage',
            'device' => 'desktop',
            'compare' => true,
        ]);

        $this->assertSame(1, $report['metrics']['page_views']);
        $this->assertSame(today()->toDateString(), $report['date']['start']);
        $this->assertSame(today()->subDay()->toDateString(), $report['date']['previous_start']);
        $this->assertSame('homepage', $report['sources'][0]['key']);
        $this->assertSame('desktop', $report['devices'][0]['key']);
    }

    public function test_period_unique_visitors_are_not_summed_across_days_or_dimensions(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $article = Article::factory()->published()->create(['published_at' => now()->subDays(10)]);
        $reader = 'same-reader-across-period';
        $this->recordOpen($article, $reader, 'homepage', 'desktop', now()->subDay());
        $this->recordOpen($article, $reader, 'facebook', 'mobile', now(), true);

        app(EditorialAnalyticsRollupService::class)->rollup(today()->subDay(), today());
        $analytics = app(EditorialAnalyticsService::class);
        $report = $analytics->report($admin, ['range' => 'last_7_days', 'metric' => 'unique_visitors']);

        $this->assertSame(1, $report['metrics']['unique_visitors']);
        $this->assertSame(1, $report['top_articles']->first()['unique_visitors']);
        $this->assertSame(1, $report['authors']->first()['unique_visitors']);
        $this->assertSame([1, 1], collect($report['series'])->where('value', '>', 0)->pluck('value')->all());
        $this->assertSame(1, $analytics->exportRows($admin, ['range' => 'last_7_days'])[0]['unique_visitors']);
    }

    public function test_author_first_window_metrics_use_the_scoped_publication_cohort_and_real_view_dimensions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00'));

        try {
            $this->seed();
            $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
            $authorUser = $this->userWithRole('author');
            $ownAuthor = Author::factory()->create(['name' => 'Window Author']);
            $unrelatedAuthor = Author::factory()->create(['name' => 'Unrelated Window Author']);
            TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $ownAuthor->id]);

            $launch = Article::factory()->published()->create([
                'author_id' => $ownAuthor->id,
                'title' => 'Measured launch',
                'published_at' => now()->subDays(10),
            ]);
            $secondLaunch = Article::factory()->published()->create([
                'author_id' => $ownAuthor->id,
                'title' => 'Second measured launch',
                'published_at' => now()->subDays(3),
            ]);
            Article::factory()->published()->create([
                'author_id' => $ownAuthor->id,
                'title' => 'Launch without views',
                'published_at' => now()->subDay(),
            ]);
            $unrelatedLaunch = Article::factory()->published()->create([
                'author_id' => $unrelatedAuthor->id,
                'published_at' => now()->subDays(2),
            ]);
            $olderLaunch = Article::factory()->published()->create([
                'author_id' => $ownAuthor->id,
                'title' => 'Outside selected publication cohort',
                'published_at' => now()->subDays(40),
            ]);

            $this->recordOpen($launch, 'launch-hour-2', 'homepage', 'desktop', $launch->published_at->copy()->addHours(2));
            $this->recordOpen($launch, 'launch-hour-23', 'homepage', 'desktop', $launch->published_at->copy()->addHours(23));
            $this->recordOpen($launch, 'launch-hour-25', 'homepage', 'desktop', $launch->published_at->copy()->addHours(25));
            $this->recordOpen($launch, 'launch-day-8', 'homepage', 'desktop', $launch->published_at->copy()->addDays(8));
            $this->recordOpen($launch, 'launch-facebook', 'facebook', 'mobile', $launch->published_at->copy()->addHours(3));
            $this->recordOpen($secondLaunch, 'second-launch-hour-1', 'homepage', 'desktop', $secondLaunch->published_at->copy()->addHour());
            $this->recordOpen($unrelatedLaunch, 'unrelated-hour-1', 'homepage', 'desktop', $unrelatedLaunch->published_at->copy()->addHour());
            $this->recordOpen($olderLaunch, 'older-launch-hour-1', 'homepage', 'desktop', $olderLaunch->published_at->copy()->addHour());
            app(EditorialAnalyticsRollupService::class)->rollup(today()->subDays(29), today());

            $analytics = app(EditorialAnalyticsService::class);
            $filters = [
                'range' => 'last_30_days',
                'author_id' => $ownAuthor->id,
                'source' => 'homepage',
                'device' => 'desktop',
            ];
            $adminRow = $analytics->report($admin, $filters)['authors']->sole();

            $this->assertSame($ownAuthor->id, $adminRow['author_id']);
            $this->assertSame(3, $adminRow['articles_published']);
            $this->assertSame(3, $adminRow['first_24_hour_views']);
            $this->assertSame(4, $adminRow['first_7_day_views']);

            $articleRow = $analytics->report($admin, [...$filters, 'article_id' => $launch->id])['authors']->sole();
            $this->assertSame(1, $articleRow['articles_published']);
            $this->assertSame(2, $articleRow['first_24_hour_views']);
            $this->assertSame(3, $articleRow['first_7_day_views']);

            $authorReport = $analytics->report($authorUser, [
                'range' => 'last_30_days',
                'source' => 'homepage',
                'device' => 'desktop',
            ]);
            $this->assertSame([$ownAuthor->id], $authorReport['authors']->pluck('author_id')->all());
            $this->assertSame(3, $authorReport['authors']->sole()['first_24_hour_views']);
            $this->assertTrue($analytics->report($authorUser, [
                'range' => 'last_30_days',
                'author_id' => $unrelatedAuthor->id,
            ])['authors']->isEmpty());

            $this->actingAs($admin)
                ->get(EditorialAnalytics::getUrl(parameters: [
                    'period' => 'last_30_days',
                    'author' => (string) $ownAuthor->id,
                    'source' => 'homepage',
                    'device' => 'desktop',
                ]))
                ->assertOk()
                ->assertSee('Прегледи во првите 24 часа')
                ->assertSee('Прегледи во првите 7 дена')
                ->assertSee('Први 24 ч.')
                ->assertSee('Први 7 д.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_author_first_window_metrics_are_hidden_when_raw_retention_cannot_cover_the_cohort(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00'));

        try {
            config()->set('editorial_analytics.raw_event_retention_days', 30);
            $this->seed();
            $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
            $author = Author::factory()->create(['name' => 'Historical Author']);
            $article = Article::factory()->published()->create([
                'author_id' => $author->id,
                'published_at' => now()->subDays(40),
            ]);
            $this->recordOpen($article, 'historical-view', 'direct', 'desktop', $article->published_at->copy()->addHour());
            $day = $article->published_at->toDateString();

            $report = app(EditorialAnalyticsService::class)->report($admin, [
                'range' => 'custom',
                'custom_from' => $day,
                'custom_to' => $day,
                'author_id' => $author->id,
            ]);
            $authorRow = $report['authors']->sole();

            $this->assertNull($authorRow['first_24_hour_views']);
            $this->assertNull($authorRow['first_7_day_views']);

            $this->actingAs($admin)
                ->get(EditorialAnalytics::getUrl(parameters: [
                    'period' => 'custom',
                    'from' => $day,
                    'to' => $day,
                    'author' => (string) $author->id,
                ]))
                ->assertOk()
                ->assertDontSee('Прегледи во првите 24 часа')
                ->assertDontSee('Прегледи во првите 7 дена')
                ->assertDontSee('Први 24 ч.')
                ->assertDontSee('Први 7 д.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rollup_uses_maximum_cumulative_engagement_and_scroll_per_session(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $article = Article::factory()->published()->create();
        $this->recordOpen($article, 'engaged-reader', 'homepage', 'desktop');
        foreach ([10000, 20000, 30000] as $engagedMs) {
            $this->recordEvent($article, 'engaged_session', 'engaged-reader', 'homepage', 'desktop', engagedMs: $engagedMs);
        }
        foreach ([25, 50] as $depth) {
            $this->recordEvent($article, 'scroll_depth', 'engaged-reader', 'homepage', 'desktop', scrollDepth: $depth);
        }
        $this->recordEvent($article, 'completion', 'engaged-reader', 'homepage', 'desktop');

        app(EditorialAnalyticsRollupService::class)->rollup(today(), today());
        $report = app(EditorialAnalyticsService::class)->report($admin, [
            'range' => 'today',
            'source' => 'homepage',
        ]);

        $this->assertSame(1, $report['metrics']['engaged_sessions']);
        $this->assertSame(30.0, $report['metrics']['average_engaged_seconds']);
        $this->assertSame(50.0, $report['metrics']['average_scroll_depth']);
        $this->assertSame(100.0, $report['metrics']['completion_rate']);
    }

    public function test_article_detail_and_csv_include_real_click_and_referrer_metrics(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $article = Article::factory()->published()->create(['title' => 'Measured article']);
        $this->recordOpen($article, 'detail-reader', 'external_referral', 'desktop', referrerHost: 'example.org');
        $this->recordEvent($article, 'homepage_article_click', 'click-one', 'homepage', 'desktop');
        $this->recordEvent($article, 'outbound_click', 'click-two', 'external_referral', 'desktop');
        $this->recordEvent($article, 'social_share', 'click-three', 'external_referral', 'desktop');
        app(EditorialAnalyticsRollupService::class)->rollup(today(), today());

        $analytics = app(EditorialAnalyticsService::class);
        $report = $analytics->report($admin, ['range' => 'today', 'article_id' => $article->id]);
        $detail = $report['article_detail'];
        $this->assertSame($article->id, $detail['id']);
        $this->assertNotNull($detail['published_at']);
        $this->assertSame(1, $detail['homepage_clicks']);
        $this->assertSame(1, $detail['outbound_clicks']);
        $this->assertSame(1, $detail['social_shares']);
        $this->assertSame([['host' => 'example.org', 'visits' => 1]], $detail['referrers']);

        $csv = $analytics->exportRows($admin, ['range' => 'today', 'article_id' => $article->id])[0];
        $this->assertSame(1, $csv['homepage_clicks']);
        $this->assertSame(1, $csv['outbound_clicks']);
        $this->assertSame(1, $csv['social_shares']);

        $this->actingAs($admin)
            ->get(EditorialAnalytics::getUrl(parameters: [
                'period' => 'today',
                'article' => (string) $article->id,
            ]))
            ->assertOk()
            ->assertSee('Објавено')
            ->assertSee('example.org');
    }

    public function test_article_session_events_keep_the_entry_source_through_the_public_api(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $article = Article::factory()->published()->create();
        $base = [
            'visitor_token' => 'source-reader-token-1234567890',
            'session_token' => 'source-session-token-1234567890',
            'analytics_consent' => true,
            'consent_version' => 1,
            'context_page' => 'homepage',
        ];
        $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson("/api/articles/{$article->slug}/view", $base)->assertAccepted();
        foreach ([
            ['event_type' => 'engaged_session', 'engaged_ms' => 30000],
            ['event_type' => 'scroll_depth', 'scroll_depth' => 50],
            ['event_type' => 'completion'],
        ] as $event) {
            $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson('/api/editorial-analytics/events', [
                ...$base,
                ...$event,
                'article_slug' => $article->slug,
            ])->assertAccepted()->assertJson(['recorded' => true]);
        }

        $this->assertSame(4, EditorialAnalyticsEvent::query()->where('source_channel', 'homepage')->count());
        app(EditorialAnalyticsRollupService::class)->rollup(today(), today());
        $report = app(EditorialAnalyticsService::class)->report($admin, ['range' => 'today', 'source' => 'homepage']);
        $this->assertSame(1, $report['metrics']['page_views']);
        $this->assertSame(30.0, $report['metrics']['average_engaged_seconds']);
        $this->assertSame(50.0, $report['metrics']['average_scroll_depth']);
        $this->assertSame(100.0, $report['metrics']['completion_rate']);
    }

    public function test_retention_prunes_raw_rows_but_preserves_daily_aggregates(): void
    {
        $article = Article::factory()->published()->create(['published_at' => now()->subDays(500)]);
        $old = now()->subDays(400);
        $this->recordOpen($article, 'old-reader', 'direct', 'desktop', $old);
        $this->recordOpen($article, 'current-reader', 'direct', 'desktop');
        $rollups = app(EditorialAnalyticsRollupService::class);
        $rollups->rollup($old->copy()->startOfDay(), $old->copy()->startOfDay());
        $this->assertDatabaseHas('editorial_analytics_daily', ['day' => $old->toDateString(), 'article_id' => $article->id]);

        $this->assertSame(2, $rollups->pruneRawEvents(395));
        $this->assertFalse(EditorialAnalyticsEvent::query()->whereDate('event_date', $old->toDateString())->exists());
        $this->assertDatabaseMissing('article_views', ['viewed_on' => $old->toDateString()]);
        $this->assertTrue(EditorialAnalyticsEvent::query()->whereDate('event_date', today()->toDateString())->exists());
        $this->assertDatabaseHas('article_views', ['viewed_on' => today()->toDateString()]);
        $this->assertFalse(ArticleView::query()->where('viewed_on', today()->toDateString())->firstOrFail()->is_returning);
        $this->assertDatabaseHas('editorial_analytics_daily', ['day' => $old->toDateString(), 'article_id' => $article->id]);
    }

    public function test_global_quality_metrics_are_hidden_from_author_scope(): void
    {
        $this->seed();
        $authorUser = $this->userWithRole('author');
        $author = Author::factory()->create();
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $author->id]);
        Article::factory()->published()->create(['author_id' => $author->id]);
        $this->recordEvent(null, 'article_open', 'missing-article-reader', 'direct', 'desktop');

        $authorReport = app(EditorialAnalyticsService::class)->report($authorUser, ['range' => 'today']);
        $this->assertNull($authorReport['data_quality']['missing_article_events']);
        $this->assertSame([], $authorReport['site_interactions']);

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $adminReport = app(EditorialAnalyticsService::class)->report($admin, ['range' => 'today']);
        $this->assertSame(1, $adminReport['data_quality']['missing_article_events']);
    }

    public function test_authenticated_backoffice_marks_the_browser_as_editorial_staff(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->actingAs($admin)
            ->get('/backoffice')
            ->assertOk()
            ->assertSee('razbudise-editorial-staff', false);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }

    private function recordOpen(
        Article $article,
        string $reader,
        string $source,
        string $device,
        ?Carbon $occurredAt = null,
        bool $returning = false,
        ?string $referrerHost = null,
    ): void {
        $occurredAt ??= now();
        $visitorHash = hash('sha256', $reader);
        EditorialAnalyticsEvent::query()->create([
            'article_id' => $article->id,
            'author_id' => $article->author_id,
            'category_id' => $article->category_id,
            'event_type' => 'article_open',
            'visitor_hash' => $visitorHash,
            'session_hash' => hash('sha256', $reader.'-session'),
            'dedupe_key' => hash('sha256', $reader.'-'.$article->id.'-'.$occurredAt->toDateString()),
            'event_date' => $occurredAt->toDateString(),
            'occurred_at' => $occurredAt,
            'context_page' => 'article',
            'source_channel' => $source,
            'referrer_host' => $referrerHost,
            'device' => $device,
            'is_returning' => $returning,
            'consent_version' => 1,
        ]);
        ArticleView::query()->create([
            'article_id' => $article->id,
            'visitor_hash' => $visitorHash,
            'session_hash' => hash('sha256', $reader.'-session'),
            'viewed_on' => $occurredAt->toDateString(),
            'occurred_at' => $occurredAt,
            'referrer_host' => $referrerHost,
            'source_channel' => $source,
            'context_page' => 'article',
            'device' => $device,
            'is_returning' => $returning,
        ]);
    }

    private function recordEvent(
        ?Article $article,
        string $eventType,
        string $reader,
        string $source,
        string $device,
        ?int $engagedMs = null,
        ?int $scrollDepth = null,
    ): void {
        EditorialAnalyticsEvent::query()->create([
            'article_id' => $article?->id,
            'author_id' => $article?->author_id,
            'category_id' => $article?->category_id,
            'event_type' => $eventType,
            'visitor_hash' => hash('sha256', $reader),
            'session_hash' => hash('sha256', $reader.'-session'),
            'dedupe_key' => hash('sha256', implode('|', [$reader, $article?->id ?? 0, $eventType, $engagedMs, $scrollDepth])),
            'event_date' => today()->toDateString(),
            'occurred_at' => now(),
            'context_page' => $source === 'homepage' ? 'homepage' : 'article',
            'source_channel' => $source,
            'device' => $device,
            'engaged_ms' => $engagedMs,
            'scroll_depth' => $scrollDepth,
            'is_returning' => false,
            'consent_version' => 1,
        ]);
    }
}
