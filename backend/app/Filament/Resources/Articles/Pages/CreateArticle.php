<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use App\Services\Articles\ArticleEditorialWorkflow;
use App\Services\Articles\ArticleFormDataService;
use App\Services\Articles\ArticleRevisionService;
use App\Services\Newsroom\NewsroomWorkspaceService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Resources\Pages\CreateRecord;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    protected static bool $canCreateAnother = false;

    private ?string $postCreateTransition = null;

    private mixed $postCreateScheduledAt = null;

    protected function afterFill(): void
    {
        $authorId = request()->integer('author_id');
        $user = auth()->user();

        if (! $authorId || ! $user) {
            return;
        }

        $author = app(NewsroomWorkspaceService::class)
            ->managedAuthors($user)
            ->firstWhere('id', $authorId);

        if (! $author?->is_active) {
            return;
        }

        // Only prefill authors already inside the current user's server-side
        // newsroom scope. A forged query parameter cannot widen access.
        $this->form->fillPartially(['author_id' => $authorId], ['author_id']);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(ArticleFormDataService::class)->prepare($data);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('saveWorkingVersion')
                ->label(__('admin.writer.save_working_version'))
                ->action(fn () => $this->createWithStatus('draft'))
                ->keyBindings(['mod+s']),
            Action::make('submitReview')
                ->label(__('admin.writer.submit_review'))
                ->color('warning')
                ->visible(fn (): bool => $this->isSubmissionOnlyUser())
                ->action(fn () => $this->createWithTransition('submit')),
            Action::make('publish')
                ->label(__('admin.actions.publish'))
                ->color('success')
                ->visible(fn (): bool => $this->canPublishDirectly())
                ->requiresConfirmation()
                ->action(fn () => $this->createWithTransition('publish')),
            Action::make('schedule')
                ->label(__('admin.actions.schedule'))
                ->visible(fn (): bool => $this->canPublishDirectly())
                ->form([
                    DateTimePicker::make('scheduled_at')
                        ->required()
                        ->after(now()),
                ])
                ->action(fn (array $data) => $this->createWithTransition('schedule', $data['scheduled_at'])),
            $this->getCancelFormAction(),
        ];
    }

    private function createWithStatus(string $status): void
    {
        $this->data['status'] = $status;
        $this->create();
    }

    private function createWithTransition(string $transition, mixed $scheduledAt = null): void
    {
        $this->postCreateTransition = $transition;
        $this->postCreateScheduledAt = $scheduledAt;
        $this->data['status'] = 'draft';
        $this->create();
    }

    protected function afterCreate(): void
    {
        app(ArticleRevisionService::class)->capture($this->record, auth()->user(), 'created');

        if (! $this->postCreateTransition) {
            return;
        }

        $workflow = app(ArticleEditorialWorkflow::class);

        $this->record = match ($this->postCreateTransition) {
            'submit' => $workflow->submit($this->record, auth()->user()),
            'publish' => $workflow->publish($this->record, auth()->user()),
            'schedule' => $workflow->schedule($this->record, $this->postCreateScheduledAt, auth()->user()),
            default => $this->record,
        };
    }

    private function isSubmissionOnlyUser(): bool
    {
        $user = auth()->user();

        return ! $this->canPublishDirectly()
            && (bool) ($user?->hasRole('author') || $user?->hasRole('contributor'));
    }

    private function canPublishDirectly(): bool
    {
        $user = auth()->user();

        return $user !== null
            && ($user->isAdmin() || $user->hasRole('editor_in_chief') || $user->hasRole('editor'))
            && $user->hasPermission('articles.publish');
    }
}
