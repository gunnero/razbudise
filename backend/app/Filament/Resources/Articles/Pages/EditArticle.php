<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Actions\RequestArticleChangesAction;
use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Services\Articles\ArticleChangeRequestService;
use App\Services\Articles\ArticleEditorialWorkflow;
use App\Services\Articles\ArticleFormDataService;
use App\Services\Articles\ArticleRevisionService;
use App\Services\Newsroom\NewsroomWorkspaceService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    public static bool $formActionsAreSticky = true;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['featured_image_upload'] = $this->record->featuredImage?->path;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return app(ArticleFormDataService::class)->prepare($data, $this->record);
    }

    protected function afterSave(): void
    {
        app(ArticleRevisionService::class)->capture($this->record, auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('admin.actions.preview'))
                ->url(fn (): string => $this->record->previewUrl(), shouldOpenInNewTab: true),
            ViewAction::make(),
            Action::make('send_to_review')
                ->label(fn (): string => $this->record->status === 'changes_requested'
                    ? 'Испрати повторно на проверка'
                    : __('admin.actions.review'))
                ->visible(fn (): bool => $this->isSubmissionOnlyUser() && $this->canRunTransition('submit', 'submit'))
                ->action(function (): void {
                    $this->syncWorkflowRecord(
                        app(ArticleChangeRequestService::class)->submitForReview($this->record, auth()->user()),
                    );
                }),
            Action::make('start_review')
                ->label('Започни преглед')
                ->visible(fn (): bool => $this->canRunTransition('review', 'startReview'))
                ->action(function (): void {
                    $this->syncWorkflowRecord(
                        app(ArticleEditorialWorkflow::class)->startReview($this->record, auth()->user()),
                    );
                }),
            RequestArticleChangesAction::make(),
            Action::make('approve')
                ->label('Одобри')
                ->color('success')
                ->visible(fn (): bool => $this->canRunTransition('approve', 'approve'))
                ->action(function (): void {
                    $this->syncWorkflowRecord(
                        app(ArticleEditorialWorkflow::class)->approve($this->record, auth()->user()),
                    );
                }),
            Action::make('publish')
                ->label(__('admin.actions.publish'))
                ->visible(fn (): bool => $this->canPublish())
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->syncWorkflowRecord(
                        app(ArticleEditorialWorkflow::class)->publish($this->record, auth()->user()),
                    );
                }),
            Action::make('schedule')
                ->label(__('admin.actions.schedule'))
                ->visible(fn (): bool => $this->canRunTransition('schedule', 'schedule'))
                ->form([
                    DateTimePicker::make('scheduled_at')
                        ->required()
                        ->after(now()),
                ])
                ->action(function (array $data): void {
                    $this->syncWorkflowRecord(
                        app(ArticleEditorialWorkflow::class)->schedule($this->record, $data['scheduled_at'], auth()->user()),
                    );
                }),
            Action::make('archive')
                ->label(__('admin.actions.archive'))
                ->visible(fn (): bool => $this->canRunTransition('publish', 'archive'))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->syncWorkflowRecord(
                        app(ArticleEditorialWorkflow::class)->archive($this->record, auth()->user()),
                    );
                }),
            DeleteAction::make()
                ->visible(fn (): bool => ! $this->isSubmissionOnlyUser()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make([
                'default' => 1,
                'xl' => 12,
            ])->schema([
                $this->getFormContentComponent()
                    ->columnSpan([
                        'default' => 1,
                        'xl' => 8,
                    ]),
                View::make('filament.resources.articles.review-sidebar')
                    ->viewData(fn (): array => $this->reviewSidebarData())
                    ->columnSpan([
                        'default' => 1,
                        'xl' => 4,
                    ]),
            ]),
            $this->getRelationManagersContentComponent(),
        ]);
    }

    /** @return array<string, mixed> */
    private function reviewSidebarData(): array
    {
        $record = $this->record->fresh([
            'author',
            'supervisingEditor',
            'assignedEditor',
            'featuredImage',
            'seoMetadata',
        ]) ?? $this->record;
        $checklist = app(NewsroomWorkspaceService::class)->checklist($record, auth()->user());
        $items = collect($checklist);

        return [
            'record' => $record,
            'checklist' => $checklist,
            'checklistSummary' => [
                'complete' => $items->where('state', 'complete')->count(),
                'warning' => $items->where('state', 'warning')->count(),
                'blocking' => $items->where('state', 'blocking')->count(),
                'total' => $items->count(),
            ],
            'unresolvedComments' => $record->editorialComments()
                ->where('is_resolved', false)
                ->when(
                    auth()->user()?->hasRole('author') || auth()->user()?->hasRole('contributor'),
                    fn ($query) => $query->where('visibility', ArticleEditorialComment::VISIBILITY_AUTHOR),
                )
                ->count(),
            'revisionCount' => $record->revisions()->count(),
        ];
    }

    private function syncWorkflowRecord(Article $record): void
    {
        $this->record = $record->refresh();

        // Keep hidden workflow fields in sync so a later form save cannot
        // accidentally restore the state that was loaded before the action.
        $this->refreshFormData(['status', 'scheduled_at']);
    }

    private function canRunTransition(string $ability, string $transition): bool
    {
        return (auth()->user()?->can($ability, $this->record) ?? false)
            && app(ArticleEditorialWorkflow::class)->canTransition($this->record, $transition);
    }

    private function canPublish(): bool
    {
        $user = auth()->user();

        return ($user?->can('publish', $this->record) ?? false)
            && app(ArticleEditorialWorkflow::class)->canPublish($this->record, $user);
    }

    private function isSubmissionOnlyUser(): bool
    {
        $user = auth()->user();
        $isDirectPublisher = $user !== null
            && ($user->isAdmin() || $user->hasRole('editor_in_chief') || $user->hasRole('editor'))
            && $user->hasPermission('articles.publish');

        return ! $isDirectPublisher
            && (bool) ($user?->hasRole('author') || $user?->hasRole('contributor'));
    }
}
