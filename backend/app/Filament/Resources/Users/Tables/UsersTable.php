<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->label('Email address')->searchable()->sortable(),
                TextColumn::make('locale')
                    ->label(__('admin.profile.language'))
                    ->formatStateUsing(fn (string $state): string => __('admin.languages.'.$state))
                    ->badge(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('roles.label')->label('Roles')->badge()->placeholder('No access'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Filter::make('editorial_access')
                    ->label('Пристап на уредници и автори')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'roles',
                        fn (Builder $roles): Builder => $roles->whereIn('name', UserResource::EDITORIAL_ACCESS_ROLE_NAMES),
                    )),
                SelectFilter::make('role')
                    ->relationship('roles', 'label')
                    ->preload()
                    ->searchable(),
                SelectFilter::make('status')
                    ->options([
                        'invited' => 'Invited',
                        'active' => 'Active',
                        'disabled' => 'Disabled',
                        'suspended' => 'Suspended',
                        'archived' => 'Archived',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
