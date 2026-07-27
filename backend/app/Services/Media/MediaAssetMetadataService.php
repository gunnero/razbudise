<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Operations\AdminAuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MediaAssetMetadataService
{
    private const EDITABLE_COLUMNS = [
        'display_name',
        'title',
        'alt_text',
        'caption',
        'description',
        'photographer',
        'source_name',
        'license',
        'copyright_notice',
        'seo_filename_suggestion',
        'usage_notes',
    ];

    public function __construct(
        private readonly MediaAssetRenameService $renamer,
        private readonly MediaSeoFilenameSuggester $filenameSuggester,
        private readonly AdminAuditLogger $auditLogger,
    ) {}

    /**
     * Update editorial metadata without replacing operational/private metadata.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(MediaAsset $asset, array $attributes, ?User $actor = null): MediaAsset
    {
        $validated = Validator::make($attributes, [
            'filename' => ['sometimes', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alt_text' => ['sometimes', 'nullable', 'string'],
            'caption' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'photographer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'license' => ['sometimes', 'nullable', 'string', 'max:255'],
            'copyright_notice' => ['sometimes', 'nullable', 'string'],
            'seo_filename_suggestion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'usage_notes' => ['sometimes', 'nullable', 'string'],
            'credit' => ['sometimes', 'nullable', 'string', 'max:255'],
            'photo_credit' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'focal_point_x' => ['sometimes', 'nullable', 'integer', 'between:0,100', 'required_with:focal_point_y'],
            'focal_point_y' => ['sometimes', 'nullable', 'integer', 'between:0,100', 'required_with:focal_point_x'],
        ])->validate();

        $filename = Arr::pull($validated, 'filename');

        if (is_string($filename) && trim($filename) !== '') {
            $asset = $this->renamer->rename($asset, $filename, $actor);
        }

        if ($validated === []) {
            return $asset->refresh();
        }

        return DB::transaction(function () use ($asset, $validated, $actor): MediaAsset {
            /** @var MediaAsset $locked */
            $locked = MediaAsset::query()->lockForUpdate()->findOrFail($asset->getKey());
            $before = $this->auditSnapshot($locked);
            $columns = [];
            $suggestionSourceChanged = collect([
                'display_name',
                'title',
                'alt_text',
                'caption',
                'description',
            ])->contains(fn (string $field): bool => array_key_exists($field, $validated));
            $suggestionWasSubmitted = array_key_exists('seo_filename_suggestion', $validated);
            $submittedSuggestion = $suggestionWasSubmitted
                ? $this->nullableTrimmedString($validated['seo_filename_suggestion'])
                : null;
            $storedSuggestion = $this->nullableTrimmedString($locked->seo_filename_suggestion);

            foreach (self::EDITABLE_COLUMNS as $column) {
                if (array_key_exists($column, $validated)) {
                    $columns[$column] = $this->nullableTrimmedString($validated[$column]);
                }
            }

            if (array_key_exists('source', $validated) && ! array_key_exists('source_name', $columns)) {
                $columns['source_name'] = $this->nullableTrimmedString($validated['source']);
            }

            $metadata = $locked->metadata ?? [];
            $suggestionWasGenerated = ($metadata['seo_filename_suggestion_generated'] ?? false) === true;

            if (array_key_exists('photo_credit', $validated) || array_key_exists('credit', $validated)) {
                $credit = $validated['photo_credit'] ?? $validated['credit'] ?? null;
                $this->setMetadataValue($metadata, 'credit', $this->nullableTrimmedString($credit));
            }

            if (array_key_exists('source_url', $validated)) {
                $this->setMetadataValue($metadata, 'source_url', $this->nullableTrimmedString($validated['source_url']));
            }

            if (array_key_exists('focal_point_x', $validated) || array_key_exists('focal_point_y', $validated)) {
                if (($validated['focal_point_x'] ?? null) === null && ($validated['focal_point_y'] ?? null) === null) {
                    unset($metadata['focal_point']);
                } else {
                    $metadata['focal_point'] = [
                        'x' => (int) $validated['focal_point_x'],
                        'y' => (int) $validated['focal_point_y'],
                    ];
                }
            }

            $locked->forceFill([
                ...$columns,
                'metadata' => $metadata,
            ]);

            $automaticSuggestion = $this->filenameSuggester->suggest($locked);
            $submittedSuggestionMatchesAutomatic = $suggestionWasSubmitted
                && $submittedSuggestion !== null
                && $submittedSuggestion === $automaticSuggestion;
            $suggestionWasManuallyChanged = $suggestionWasSubmitted
                && $submittedSuggestion !== $storedSuggestion
                && ! $submittedSuggestionMatchesAutomatic;

            if (! $suggestionWasManuallyChanged
                && (blank($storedSuggestion) || ($suggestionSourceChanged && $suggestionWasGenerated))) {
                $locked->seo_filename_suggestion = $automaticSuggestion;

                if (filled($locked->seo_filename_suggestion)) {
                    $metadata['seo_filename_suggestion_generated'] = true;
                } else {
                    unset($metadata['seo_filename_suggestion_generated']);
                }
            } elseif ($suggestionWasManuallyChanged) {
                unset($metadata['seo_filename_suggestion_generated']);
            }

            $locked->metadata = $metadata;

            $after = $this->auditSnapshot($locked);

            if ($before === $after) {
                return $locked;
            }

            $locked->save();
            $changedFields = collect(array_keys($after))
                ->filter(fn (string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null))
                ->values()
                ->all();

            $this->auditLogger->record(
                action: 'media_asset.metadata_updated',
                auditable: $locked,
                actor: $actor,
                before: $before,
                after: $after,
                metadata: ['changed_fields' => $changedFields],
            );

            return $locked->refresh();
        }, 3);
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function setMetadataValue(array &$metadata, string $key, ?string $value): void
    {
        if ($value === null) {
            unset($metadata[$key]);

            return;
        }

        $metadata[$key] = $value;
    }

    /**
     * Deliberately excludes operational metadata such as upload tokens and hashes.
     *
     * @return array<string, mixed>
     */
    private function auditSnapshot(MediaAsset $asset): array
    {
        return [
            'display_name' => $asset->getRawOriginal('display_name'),
            'title' => $asset->title,
            'alt_text' => $asset->alt_text,
            'caption' => $asset->caption,
            'description' => $asset->description,
            'credit' => $asset->metadata['credit'] ?? null,
            'photographer' => $asset->photographer,
            'source_name' => $asset->source_name,
            'source_url' => $asset->metadata['source_url'] ?? null,
            'license' => $asset->license,
            'copyright_notice' => $asset->copyright_notice,
            'focal_point' => $asset->metadata['focal_point'] ?? null,
            'seo_filename_suggestion' => $asset->seo_filename_suggestion,
            'usage_notes' => $asset->usage_notes,
        ];
    }
}
