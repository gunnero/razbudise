<x-filament-panels::page>
    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Editorial follow-up</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Failed imports, missing article ownership, missing categories, broken embeds, and manual review warnings.</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-5">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Review queue</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['issues']['review_queue'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Failed posts</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['posts']['failed'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Missing images</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['issues']['imported_articles_without_featured_images'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Broken embeds</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['issues']['broken_embeds'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Manual review</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['issues']['manual_review'] ?? 0) }}</p>
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Queue rows</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Issue</th>
                        <th class="py-2 pr-4">WP ID</th>
                        <th class="py-2 pr-4">Article</th>
                        <th class="py-2 pr-4">Old URL</th>
                        <th class="py-2">Error / warnings</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse (($report['review_items'] ?? []) as $item)
                        <tr>
                            <td class="py-3 pr-4 font-medium text-gray-950 dark:text-white">{{ str_replace('_', ' ', $item['issue'] ?? '-') }}</td>
                            <td class="py-3 pr-4">{{ $item['old_wordpress_id'] ?? '-' }}</td>
                            <td class="py-3 pr-4">
                                @if (! empty($item['slug']))
                                    <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ url('/backoffice/articles/'.$item['id'].'/edit') }}">{{ $item['title'] ?? $item['slug'] }}</a>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">Not imported</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">{{ $item['old_url'] ?? '-' }}</td>
                            <td class="py-3">
                                {{ $item['error_message'] ?? (implode(', ', $item['warnings'] ?? []) ?: '-') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-3 text-gray-500" colspan="5">No WordPress import review items.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-filament-panels::page>
