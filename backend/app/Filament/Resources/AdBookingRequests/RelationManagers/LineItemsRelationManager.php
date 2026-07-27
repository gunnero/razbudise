<?php

namespace App\Filament\Resources\AdBookingRequests\RelationManagers;

use App\Models\AdBookingLineItem;
use App\Services\Ads\AdBookingPublishingService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LineItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'lineItems';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ad_zone_id')
                    ->label('Zone')
                    ->relationship('zone', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('duration_weeks')->required()->integer()->minValue(1),
                DateTimePicker::make('start_at')->required()->seconds(false),
                DateTimePicker::make('end_at')->required()->seconds(false),
                Select::make('creative_type')
                    ->required()
                    ->options([
                        'image' => 'Image',
                        'html' => 'HTML',
                        'script' => 'Script',
                        'native' => 'Native',
                    ])
                    ->default('image'),
                TextInput::make('width')->required()->integer()->minValue(1),
                TextInput::make('height')->required()->integer()->minValue(1),
                TextInput::make('click_url')->url()->maxLength(255),
                Select::make('status')
                    ->required()
                    ->options([
                        'pending_review' => 'Pending review',
                        'needs_technical_review' => 'Needs technical review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'published' => 'Published',
                    ])
                    ->default('pending_review')
                    ->disabledOn('edit'),
                Toggle::make('technical_review_required')->default(false)->disabledOn('edit'),
                Toggle::make('is_political_or_sensitive')->default(false),
                TextInput::make('price_denars')->required()->integer()->minValue(0),
                Textarea::make('html')->rows(6)->columnSpanFull(),
                Textarea::make('rejection_reason')->rows(4)->columnSpanFull()->disabledOn('edit'),
                Textarea::make('review_notes')->rows(4)->columnSpanFull(),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('zone.name')->label('Zone'),
                TextEntry::make('duration_weeks')->label('Duration'),
                TextEntry::make('start_at')->dateTime(),
                TextEntry::make('end_at')->dateTime(),
                TextEntry::make('creative_type')->badge(),
                TextEntry::make('width')->numeric(),
                TextEntry::make('height')->numeric(),
                IconEntry::make('technical_review_required')->boolean(),
                IconEntry::make('is_political_or_sensitive')->boolean(),
                TextEntry::make('status')->badge(),
                TextEntry::make('rejection_reason')->placeholder('-')->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('zone.name')->label('Zone')->searchable(),
                TextColumn::make('duration_weeks')->label('Duration')->numeric()->sortable(),
                TextColumn::make('start_at')->label('Starts')->dateTime()->sortable(),
                TextColumn::make('end_at')->label('Ends')->dateTime()->sortable(),
                TextColumn::make('creative_type')->badge(),
                TextColumn::make('width')->numeric(),
                TextColumn::make('height')->numeric(),
                IconColumn::make('technical_review_required')->label('Technical review')->boolean(),
                IconColumn::make('is_political_or_sensitive')->label('Political/sensitive')->boolean(),
                TextColumn::make('status')->badge(),
                TextColumn::make('rejection_reason')->limit(40)->placeholder('-'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve line item')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->authorize(fn (AdBookingLineItem $record): bool => (auth()->user()?->hasPermission('ads.manage') ?? false)
                        && app(AdBookingPublishingService::class)->canReviewLineItem($record))
                    ->visible(fn (AdBookingLineItem $record): bool => app(AdBookingPublishingService::class)->canReviewLineItem($record))
                    ->action(fn (AdBookingLineItem $record): bool => $record->forceFill([
                        'status' => 'approved',
                        'rejection_reason' => null,
                        'technical_review_required' => false,
                    ])->save()),
                Action::make('reject')
                    ->label('Reject line item')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Customer-visible reason')
                            ->required()
                            ->rows(4),
                    ])
                    ->authorize(fn (AdBookingLineItem $record): bool => (auth()->user()?->hasPermission('ads.manage') ?? false)
                        && app(AdBookingPublishingService::class)->canRejectLineItem($record))
                    ->visible(fn (AdBookingLineItem $record): bool => app(AdBookingPublishingService::class)->canRejectLineItem($record))
                    ->action(function (array $data, AdBookingLineItem $record): void {
                        app(AdBookingPublishingService::class)->rejectLineItem($record, $data['reason'], auth()->user());
                    }),
                Action::make('needsTechnicalReview')
                    ->label('Needs technical review')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->authorize(fn (AdBookingLineItem $record): bool => (auth()->user()?->hasPermission('ads.manage') ?? false)
                        && app(AdBookingPublishingService::class)->canReviewLineItem($record))
                    ->visible(fn (AdBookingLineItem $record): bool => app(AdBookingPublishingService::class)->canReviewLineItem($record))
                    ->action(fn (AdBookingLineItem $record): bool => $record->forceFill([
                        'status' => 'needs_technical_review',
                        'technical_review_required' => true,
                    ])->save()),
            ]);
    }
}
