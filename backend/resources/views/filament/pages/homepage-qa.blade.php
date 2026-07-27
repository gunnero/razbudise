<x-filament-panels::page>
    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Homepage readiness</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-4">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Sections checked</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['sections_checked'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Issues</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['issues'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Missing articles</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['manual_article_missing'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Unknown ad zones</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['unknown_ad_zones'] ?? 0) }}</p>
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Issues</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Code</th>
                        <th class="py-2 pr-4">Section</th>
                        <th class="py-2">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse (($report['issues'] ?? []) as $issue)
                        <tr>
                            <td class="py-3 pr-4 font-medium">{{ str_replace('_', ' ', $issue['code']) }}</td>
                            <td class="py-3 pr-4">{{ $issue['section_key'] }}</td>
                            <td class="py-3">{{ $issue['message'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-3 text-gray-500" colspan="3">No homepage QA issues.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-filament-panels::page>
