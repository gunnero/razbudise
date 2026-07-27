@php
    /** @var \App\Models\MediaAsset $record */
    $record = $getRecord();
    $filename = basename((string) $record->path);
    $title = $record->title ?: $record->display_name ?: $filename;
    $credit = data_get($record->metadata, 'credit');

    $usageCount = (int) ($record->getAttributes()['usage_count'] ?? 0);
    $warnings = collect([
        blank($record->title) ? __('media.warnings.title') : null,
        blank($record->alt_text) ? __('media.warnings.alt') : null,
        blank($record->caption) ? __('media.warnings.caption') : null,
        blank($credit) ? __('media.warnings.credit') : null,
    ])->filter()->values();
    $size = match (true) {
        blank($record->size_bytes) => '—',
        $record->size_bytes >= 1048576 => number_format($record->size_bytes / 1048576, 1).' MB',
        $record->size_bytes >= 1024 => number_format($record->size_bytes / 1024, 0).' KB',
        default => $record->size_bytes.' B',
    };
@endphp

<article class="razbudise-media-card" aria-label="{{ $title }}">
    <div class="razbudise-media-card__preview">
        @if ($record->admin_preview_url)
            <img
                src="{{ $record->admin_preview_url }}"
                alt="{{ $record->alt_text ?: $title }}"
                loading="lazy"
                decoding="async"
            >
        @else
            <div class="razbudise-media-card__missing" aria-hidden="true">
                <x-filament::icon icon="heroicon-o-photo" />
            </div>
        @endif

        <span class="razbudise-media-card__usage">
            {{ trans_choice('media.usage_count', $usageCount, ['count' => $usageCount]) }}
        </span>

        @if ($record->archived_at)
            <span class="razbudise-media-card__archived">{{ __('media.archived') }}</span>
        @endif
    </div>

    <div class="razbudise-media-card__body">
        <h3 title="{{ $title }}">{{ $title }}</h3>
        <p class="razbudise-media-card__filename" title="{{ $filename }}">{{ $filename }}</p>

        <dl class="razbudise-media-card__facts">
            <div>
                <dt class="sr-only">{{ __('media.fields.dimensions') }}</dt>
                <dd>{{ $record->width && $record->height ? $record->width.'×'.$record->height : '—' }}</dd>
            </div>
            <div>
                <dt class="sr-only">{{ __('media.fields.size') }}</dt>
                <dd>{{ $size }}</dd>
            </div>
            <div>
                <dt class="sr-only">{{ __('media.fields.upload_date') }}</dt>
                <dd>{{ $record->created_at?->translatedFormat('j M Y') ?: '—' }}</dd>
            </div>
        </dl>

        <p class="razbudise-media-card__uploader">
            {{ $record->uploader?->name ?: __('media.unknown_uploader') }}
        </p>

        @if ($warnings->isNotEmpty())
            <p class="razbudise-media-card__warning" title="{{ $warnings->join(', ') }}">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
                {{ trans_choice('media.warning_count', $warnings->count(), ['count' => $warnings->count()]) }}
            </p>
        @else
            <p class="razbudise-media-card__complete">
                <x-filament::icon icon="heroicon-o-check-circle" aria-hidden="true" />
                {{ __('media.metadata_complete') }}
            </p>
        @endif
    </div>
</article>
