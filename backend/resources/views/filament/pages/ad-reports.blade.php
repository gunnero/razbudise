<x-filament-panels::page>
    <div class="grid gap-6 md:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Impressions</p>
            <p class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['impressions'] ?? 0) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Clicks</p>
            <p class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['totals']['clicks'] ?? 0) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">CTR</p>
            <p class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $report['totals']['ctr_percent'] ?? 0 }}%</p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Campaigns</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">Campaign</th>
                            <th class="py-2 pr-4">Impressions</th>
                            <th class="py-2 pr-4">Clicks</th>
                            <th class="py-2">CTR</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse (($report['campaigns'] ?? []) as $campaign)
                            <tr>
                                <td class="py-3 pr-4">{{ $campaign['name'] }}</td>
                                <td class="py-3 pr-4">{{ number_format($campaign['impressions']) }}</td>
                                <td class="py-3 pr-4">{{ number_format($campaign['clicks']) }}</td>
                                <td class="py-3">{{ $campaign['ctr_percent'] }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="py-3 text-gray-500" colspan="4">No campaign activity in the last 30 days.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Zones</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">Zone</th>
                            <th class="py-2 pr-4">Impressions</th>
                            <th class="py-2 pr-4">Clicks</th>
                            <th class="py-2">CTR</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse (($report['zones'] ?? []) as $zone)
                            <tr>
                                <td class="py-3 pr-4">{{ $zone['key'] }}</td>
                                <td class="py-3 pr-4">{{ number_format($zone['impressions']) }}</td>
                                <td class="py-3 pr-4">{{ number_format($zone['clicks']) }}</td>
                                <td class="py-3">{{ $zone['ctr_percent'] }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="py-3 text-gray-500" colspan="4">No zone activity in the last 30 days.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
