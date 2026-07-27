<x-filament-panels::page>
    <form wire:submit="runDryRun" class="grid gap-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900 md:grid-cols-4">
        <label class="grid gap-2 md:col-span-2">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">WordPress base URL</span>
            <input wire:model="baseUrl" type="url" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950" placeholder="https://razbudise.mk" required>
        </label>
        <label class="grid gap-2">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Per page</span>
            <input wire:model="perPage" type="number" min="1" max="100" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950" required>
        </label>
        <label class="grid gap-2">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Page</span>
            <input wire:model="page" type="number" min="1" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950" required>
        </label>
        <div class="md:col-span-4">
            <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-500">
                Preview mappings
            </button>
        </div>
    </form>

    @if ($migrationReport)
        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Migration health</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Current import records and content needing manual review.</p>
                </div>
            </div>

            <div class="mt-4 grid gap-4 md:grid-cols-6">
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Posts imported</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($migrationReport['posts']['imported'] ?? 0) }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Failed posts</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($migrationReport['posts']['failed'] ?? 0) }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Missing images</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($migrationReport['issues']['missing_featured_images'] ?? 0) }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Broken embeds</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($migrationReport['issues']['broken_embeds'] ?? 0) }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Manual review</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($migrationReport['issues']['manual_review'] ?? 0) }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Review queue</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($migrationReport['issues']['review_queue'] ?? 0) }}</p>
                </div>
            </div>
        </section>
    @endif

    @if ($preview)
        <div class="grid gap-4 md:grid-cols-5">
            @foreach (($preview['summary'] ?? []) as $label => $value)
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ str_replace('_', ' ', $label) }}</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($value) }}</p>
                </div>
            @endforeach
        </div>

        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Preview rows</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">WP ID</th>
                            <th class="py-2 pr-4">Slug</th>
                            <th class="py-2 pr-4">Author</th>
                            <th class="py-2 pr-4">Category</th>
                            <th class="py-2">Warnings</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse (($preview['posts'] ?? []) as $post)
                            <tr>
                                <td class="py-3 pr-4">{{ $post['wordpress_id'] }}</td>
                                <td class="py-3 pr-4">{{ $post['slug'] }}</td>
                                <td class="py-3 pr-4">{{ $post['author']['status'] ?? 'unknown' }} -> {{ $post['author']['target_slug'] ?? '-' }}</td>
                                <td class="py-3 pr-4">{{ $post['category']['status'] ?? 'unknown' }} -> {{ $post['category']['target_slug'] ?? '-' }}</td>
                                <td class="py-3">{{ implode(', ', $post['warnings'] ?? []) ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="py-3 text-gray-500" colspan="5">No posts returned by the WordPress API.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-filament-panels::page>
