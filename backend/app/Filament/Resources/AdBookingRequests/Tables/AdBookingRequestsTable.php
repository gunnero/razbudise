<?php

namespace App\Filament\Resources\AdBookingRequests\Tables;

use App\Models\AdBookingRequest;
use App\Services\Ads\AdBookingPublishingService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AdBookingRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),
                TextColumn::make('billing_company_name')->label('Billing company')->searchable()->sortable(),
                TextColumn::make('contact_email')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('payment_status')->badge()->sortable(),
                TextColumn::make('total_denars')->numeric()->sortable(),
                TextColumn::make('hold_expires_at')->dateTime()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending_review' => 'Pending review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'published' => 'Published',
                        'cancelled' => 'Cancelled',
                    ]),
                SelectFilter::make('payment_status')
                    ->options([
                        'unpaid' => 'Unpaid',
                        'pending' => 'Pending',
                        'paid' => 'Paid',
                        'refunded' => 'Refunded',
                    ]),
                TernaryFilter::make('political_or_sensitive_line_item')
                    ->label('Political/sensitive line item')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('lineItems', fn (Builder $query): Builder => $query->where('is_political_or_sensitive', true)),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('lineItems', fn (Builder $query): Builder => $query->where('is_political_or_sensitive', true)),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('success')
                    ->authorize(fn (AdBookingRequest $record): bool => auth()->user()?->hasPermission('ads.manage') ?? false)
                    ->visible(fn (AdBookingRequest $record): bool => app(AdBookingPublishingService::class)->canMarkPaid($record))
                    ->schema([
                        Hidden::make('payment_method')
                            ->default('manual_bank_transfer'),
                        TextInput::make('payment_reference')
                            ->label('Bank statement reference')
                            ->required()
                            ->maxLength(255),
                        DateTimePicker::make('paid_at')
                            ->required()
                            ->default(now())
                            ->seconds(false),
                    ])
                    ->action(function (array $data, AdBookingRequest $record): void {
                        app(AdBookingPublishingService::class)->markPaid($record, [
                            'payment_method' => $data['payment_method'] ?? 'manual_bank_transfer',
                            'payment_reference' => $data['payment_reference'],
                            'paid_at' => $data['paid_at'],
                        ], auth()->user());
                    }),
                Action::make('approveAndPublish')
                    ->label('Approve and publish')
                    ->icon(Heroicon::OutlinedRocketLaunch)
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (AdBookingRequest $record): bool => auth()->user()?->hasPermission('ads.manage') ?? false)
                    ->visible(fn (AdBookingRequest $record): bool => app(AdBookingPublishingService::class)->canPublish($record))
                    ->action(function (AdBookingRequest $record): void {
                        app(AdBookingPublishingService::class)->publish($record, auth()->user());
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Customer-visible reason')
                            ->required()
                            ->rows(4),
                    ])
                    ->authorize(fn (AdBookingRequest $record): bool => (auth()->user()?->hasPermission('ads.manage') ?? false)
                        && app(AdBookingPublishingService::class)->canRejectBooking($record))
                    ->visible(fn (AdBookingRequest $record): bool => app(AdBookingPublishingService::class)->canRejectBooking($record))
                    ->action(function (array $data, AdBookingRequest $record): void {
                        app(AdBookingPublishingService::class)->rejectBooking($record, $data['reason'], auth()->user());
                    }),
            ])
            ->toolbarActions([]);
    }
}
