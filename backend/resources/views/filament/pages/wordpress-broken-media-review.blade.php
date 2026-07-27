<x-filament-panels::page>
    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Broken old media sources</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Grouped old WordPress upload references that still need repair, replacement, or editorial removal.</p>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                php artisan wordpress:old-link-export --grouped
            </div>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-4">
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-200">Status</span>
                <select class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900" wire:model.live="statusFilter">
                    <option value="all">All statuses</option>
                    <option value="old_link_repair_failed">Failed repair</option>
                    <option value="pending">Pending</option>
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-200">Source year/month</span>
                <select class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900" wire:model.live="sourcePeriodFilter">
                    <option value="all">All periods</option>
                    @foreach ($this->sourcePeriodOptions() as $period)
                        <option value="{{ $period }}">{{ $period }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-end gap-2 text-sm">
                <input class="rounded border-gray-300 dark:border-gray-700" type="checkbox" wire:model.live="onlyMultipleReferences">
                <span class="pb-2 font-medium text-gray-700 dark:text-gray-200">Multiple refs only</span>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-200">Search slug, WordPress ID, article ID, old URL, or source URL</span>
                <input class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900" type="search" wire:model.live.debounce.300ms="search">
            </label>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Grouped sources</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format(count($this->filteredGroupedRows())) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Article rows</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format(count($this->filteredDetailRows())) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Workflow</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Open article, choose existing media, replace URL, re-run readiness.</p>
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Grouped sources</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2 pr-4">Source URL</th>
                        <th class="py-2 pr-4">Articles</th>
                        <th class="py-2 pr-4">Occurrences</th>
                        <th class="py-2 pr-4">WP IDs</th>
                        <th class="py-2">Literal URLs</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->filteredGroupedRows() as $row)
                        <tr>
                            <td class="py-3 pr-4">
                                <p class="font-medium text-gray-950 dark:text-white">{{ str_replace('_', ' ', strtoupper($row['status'] ?? 'pending')) }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $row['reason'] ?? '-' }}</p>
                            </td>
                            <td class="max-w-xl break-all py-3 pr-4">{{ $row['source_url'] ?? '-' }}</td>
                            <td class="py-3 pr-4">{{ number_format($row['article_count'] ?? 0) }}</td>
                            <td class="py-3 pr-4">{{ number_format($row['occurrence_count'] ?? 0) }}</td>
                            <td class="py-3 pr-4">{{ $this->displayValue($row['wordpress_id'] ?? null) }}</td>
                            <td class="max-w-xl break-all py-3">{{ $this->displayValue($row['literal_url'] ?? null) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-3 text-gray-500" colspan="6">No broken media sources match the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Replace an old media reference</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Use this only after confirming the old source is broken and choosing an existing replacement asset.</p>

        <form class="mt-4 grid gap-3 md:grid-cols-3" wire:submit="replaceReference">
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-200">Article ID</span>
                <input class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900" min="1" required type="number" wire:model="articleId">
            </label>
            <label class="grid gap-1 text-sm md:col-span-2">
                <span class="font-medium text-gray-700 dark:text-gray-200">Old source URL or literal URL</span>
                <input class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900" required type="url" wire:model="sourceUrl">
            </label>
            <label class="grid gap-1 text-sm md:col-span-2">
                <span class="font-medium text-gray-700 dark:text-gray-200">Replacement media asset</span>
                <select class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900" required wire:model="mediaAssetId">
                    <option value="">Choose existing media</option>
                    @foreach ($this->mediaOptions() as $asset)
                        <option value="{{ $asset->id }}">#{{ $asset->id }} {{ $asset->display_name }} — {{ $asset->url ?: $asset->path }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-end">
                <button class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-500" type="submit">
                    Replace reference
                </button>
            </div>
        </form>

        @if ($this->selectedMediaPreviewUrl())
            <div class="mt-4 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Selected replacement preview</p>
                <img alt="Selected replacement preview" class="mt-2 max-h-48 max-w-full rounded-lg border border-gray-200 object-contain dark:border-gray-700" src="{{ $this->selectedMediaPreviewUrl() }}">
            </div>
        @endif

        @if ($lastReplacement)
            <div class="mt-4 rounded-lg border border-success-200 bg-success-50 p-3 text-sm text-success-800 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300">
                Replaced {{ $lastReplacement['replaced_references'] }} reference(s). Remaining old upload refs: {{ $lastReplacement['remaining_old_upload_references'] ? 'yes' : 'no' }}.
            </div>
        @endif

        <div class="mt-5 rounded-xl border border-danger-200 bg-danger-50 p-4 dark:border-danger-500/20 dark:bg-danger-500/10">
            <h3 class="text-sm font-semibold text-danger-800 dark:text-danger-300">Remove instead of replace</h3>
            <p class="mt-1 text-sm text-danger-700 dark:text-danger-300">Use this when the broken media is decorative or no suitable replacement exists. It removes only the selected old media reference from the article body.</p>
            <button class="mt-3 rounded-lg bg-danger-600 px-4 py-2 text-sm font-semibold text-white hover:bg-danger-500" type="button" wire:click="removeReference">
                Remove selected reference
            </button>
        </div>

        @if ($lastRemoval)
            <div class="mt-4 rounded-lg border border-success-200 bg-success-50 p-3 text-sm text-success-800 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300">
                Removed {{ $lastRemoval['removed_references'] }} reference(s). Remaining old upload refs: {{ $lastRemoval['remaining_old_upload_references'] ? 'yes' : 'no' }}.
            </div>
        @endif
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Article rows</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Article</th>
                        <th class="py-2 pr-4">WP ID</th>
                        <th class="py-2 pr-4">Old URL</th>
                        <th class="py-2 pr-4">Broken media</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->filteredDetailRows() as $row)
                        <tr>
                            <td class="py-3 pr-4">
                                <a class="font-medium text-primary-600 hover:underline dark:text-primary-400" href="{{ url('/backoffice/articles/'.($row['article_id'] ?? '').'/edit') }}">{{ $row['title'] ?? $row['slug'] ?? '-' }}</a>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">#{{ $row['article_id'] ?? '-' }} / {{ $row['slug'] ?? '-' }}</p>
                                @if ($this->publicUrlForRow($row))
                                    <a class="mt-1 inline-block text-xs text-primary-600 hover:underline dark:text-primary-400" href="{{ $this->publicUrlForRow($row) }}" target="_blank" rel="noreferrer">Open public article</a>
                                @endif
                            </td>
                            <td class="py-3 pr-4">{{ $row['wordpress_id'] ?? '-' }}</td>
                            <td class="max-w-xs break-all py-3 pr-4">{{ $row['old_url'] ?? '-' }}</td>
                            <td class="max-w-lg break-all py-3 pr-4">
                                <p>{{ $row['source_url'] ?? '-' }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Literal: {{ $row['literal'] ?? '-' }}</p>
                            </td>
                            <td class="py-3">
                                <p class="font-medium text-gray-950 dark:text-white">{{ str_replace('_', ' ', strtoupper($row['status'] ?? 'pending')) }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $row['reason'] ?? '-' }}</p>
                            </td>
                            <td class="py-3">
                                @if (! empty($row['article_id']) && ! empty($row['source_url']))
                                    <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" type="button" wire:click="selectReference({{ (int) $row['article_id'] }}, @js($row['source_url']))">
                                        Use this article/source
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-3 text-gray-500" colspan="6">No article rows match the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-filament-panels::page>
