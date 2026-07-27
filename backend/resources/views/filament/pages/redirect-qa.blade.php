<x-filament-panels::page>
    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Redirect readiness</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-4">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Missing</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['missing'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Inactive</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['inactive'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Non-301</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['non_301'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Duplicate targets</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['duplicate_targets'] ?? 0) }}</p>
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Issue rows</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Issue</th>
                        <th class="py-2 pr-4">Source</th>
                        <th class="py-2 pr-4">Target</th>
                        <th class="py-2">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="py-3 pr-4 font-medium">{{ str_replace('_', ' ', $row['issue']) }}</td>
                            <td class="py-3 pr-4">{{ $row['source_url'] }}</td>
                            <td class="py-3 pr-4">{{ $row['target_url'] }}</td>
                            <td class="py-3">{{ $row['details'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-3 text-gray-500" colspan="4">No redirect QA issues.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-filament-panels::page>
