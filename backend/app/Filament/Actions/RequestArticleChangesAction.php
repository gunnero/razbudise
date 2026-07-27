<?php

namespace App\Filament\Actions;

use App\Models\Article;
use App\Models\User;
use App\Services\Articles\ArticleChangeRequestService;
use App\Services\Articles\ArticleEditorialWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;

class RequestArticleChangesAction
{
    public static function make(): Action
    {
        return Action::make('request_changes')
            ->label('Побарај измени')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->visible(fn (Article $record): bool => (auth()->user()?->can('requestChanges', $record) ?? false)
                && app(ArticleEditorialWorkflow::class)->canTransition($record, 'requestChanges'))
            ->modalHeading('Побарај конкретна доработка')
            ->modalDescription('Авторот ќе го види резимето, рокот и сите забелешки. Внесете јасни чекори што може да ги примени.')
            ->modalSubmitActionLabel('Испрати за доработка')
            ->modalCancelActionLabel('Откажи')
            ->schema([
                Textarea::make('summary')
                    ->label('Резиме')
                    ->helperText('Кратко објаснете зошто објавата се враќа и што е најважно да се поправи.')
                    ->required()
                    ->maxLength(2000)
                    ->rows(3)
                    ->autofocus(),
                Repeater::make('comments')
                    ->label('Конкретни забелешки')
                    ->helperText('Секоја забелешка нека содржи една јасна и применлива насока.')
                    ->schema([
                        Textarea::make('body')
                            ->label('Што треба да се поправи')
                            ->required()
                            ->maxLength(5000)
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('is_blocking')
                            ->label('Блокира одобрување')
                            ->helperText('Вклучете кога оваа ставка мора да биде решена пред одобрување.'),
                    ])
                    ->defaultItems(0)
                    ->minItems(1)
                    ->maxItems(30)
                    ->addActionLabel('Додај задолжителна забелешка')
                    ->columnSpanFull(),
                DatePicker::make('deadline_at')
                    ->label('Рок (опционално)')
                    ->helperText('Изберете реален рок што авторот ќе го види.')
                    ->minDate(now()->startOfDay()),
            ])
            ->action(function (array $data, Article $record): void {
                /** @var User $actor */
                $actor = auth()->user();

                app(ArticleChangeRequestService::class)->request($record, $actor, $data);
            });
    }
}
