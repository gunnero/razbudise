<?php

namespace App\Filament\Resources\Authors\Pages;

use App\Filament\Resources\Authors\AuthorResource;
use App\Services\Authors\AuthorProfileFormDataService;
use App\Services\Operations\AdminAuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAuthor extends EditRecord
{
    protected static string $resource = AuthorResource::class;

    /** @var array<string, mixed> */
    private array $profileAuditBefore = [];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->record->loadMissing('avatar');
        $this->profileAuditBefore = $this->auditSnapshot();

        return app(AuthorProfileFormDataService::class)->prepare($data, $this->record);
    }

    protected function afterSave(): void
    {
        $after = $this->auditSnapshot();

        if ($after === $this->profileAuditBefore) {
            return;
        }

        app(AdminAuditLogger::class)->record(
            'author.profile_updated',
            $this->record,
            before: $this->profileAuditBefore,
            after: $after,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(): array
    {
        return [
            'name' => $this->record->name,
            'sex' => $this->record->sex,
            'avatar_id' => $this->record->avatar_id,
            'short_bio' => $this->record->short_bio,
            'job_title' => $this->record->job_title,
            'is_active' => $this->record->is_active,
        ];
    }
}
