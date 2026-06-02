<x-filament-panels::page>
    <div class="space-y-6">
        @if ($this->lastScanOptions !== [])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-sm font-medium text-gray-900">Last scan</div>
                <div class="mt-2 text-sm text-gray-600">
                    CIDR: {{ $this->lastScanOptions['cidr'] ?? '-' }},
                    community: {{ $this->lastScanOptions['community'] ?? '-' }},
                    timeout: {{ $this->lastScanOptions['timeout'] ?? '-' }} ms
                </div>
            </div>
        @endif

        @if ($this->scanResults === [])
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-sm text-gray-500">
                Run a scan from the header action to discover SNMP printers in your local network.
            </div>
        @else
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                    <div>
                        <div class="text-sm font-medium text-gray-900">Discovered printers</div>
                        <div class="text-xs text-gray-500">Select the rows you want to import into the printer catalog.</div>
                    </div>
                    <x-filament::button wire:click="importSelected" color="primary">
                        Import selected
                    </x-filament::button>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Select</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">IP</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Name</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Manufacturer</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Model</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Serial</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Location</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($this->scanResults as $index => $result)
                                <tr>
                                    <td class="px-4 py-3">
                                        <input type="checkbox" wire:model.live="selectedResults" value="{{ $index }}">
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ $result['ip_address'] ?? '-' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $result['discovered_name'] ?? '-' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $result['manufacturer'] ?? '-' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $result['model'] ?? '-' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $result['serial_number'] ?? '-' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $result['location'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
