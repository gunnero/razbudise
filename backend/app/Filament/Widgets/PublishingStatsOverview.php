<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AdCampaigns\AdCampaignResource;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Resources\WordpressImportRecords\WordpressImportRecordResource;
use App\Models\AdCampaign;
use App\Models\Article;
use App\Models\MediaAsset;
use App\Models\TeamMember;
use App\Models\WordpressImportRecord;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PublishingStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    protected ?string $heading = 'RazbudiSe publishing snapshot';

    protected ?string $description = 'Fast counts for content, media, migration, and ad readiness.';

    protected function getStats(): array
    {
        $publishedArticles = Article::query()->published()->count();
        $draftArticles = Article::query()->whereIn('status', ['draft', 'review', 'scheduled'])->count();
        $mediaAssets = MediaAsset::query()->count();
        $activeCampaigns = AdCampaign::query()->eligibleAt()->count();
        $failedImports = WordpressImportRecord::query()->where('status', 'failed')->count();

        return [
            Stat::make('Published articles', number_format($publishedArticles))
                ->description(number_format($draftArticles).' draft, review, or scheduled')
                ->color('success')
                ->url(ArticleResource::getUrl()),
            auth()->user()?->isAdmin()
                ? Stat::make('Тим / Crew', number_format(TeamMember::query()->where('status', 'active')->count()))
                    ->description('Активни членови на тимот')
                    ->color('info')
                    ->url(TeamMemberResource::getUrl())
                : Stat::make('Media assets', number_format($mediaAssets))
                    ->description('Available images and files')
                    ->color('info')
                    ->url(MediaAssetResource::getUrl()),
            Stat::make('Eligible ad campaigns', number_format($activeCampaigns))
                ->description('Active campaigns inside their date window')
                ->color($activeCampaigns > 0 ? 'success' : 'gray')
                ->url(AdCampaignResource::getUrl()),
            Stat::make('Failed imports', number_format($failedImports))
                ->description('WordPress records needing review')
                ->color($failedImports > 0 ? 'danger' : 'success')
                ->url(WordpressImportRecordResource::getUrl()),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
