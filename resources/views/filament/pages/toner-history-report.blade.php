<x-filament-panels::page>
    <div class="space-y-6">
        @if ($this->historySupplies->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-sm text-gray-500">
                В истории пока нет картриджей. После замены или удаления слота записи появятся здесь.
            </div>
        @else
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                    <div>
                        <div class="text-sm font-medium text-gray-900">Картриджи в истории</div>
                        <div class="text-xs text-gray-500">
                            Выберите позиции для включения в PDF-отчет. В отчете добавляются колонки «Подпись получателя» и «Подпись владельца».
                        </div>
                    </div>
                    <x-filament::button wire:click="generateReport" color="primary">
                        Сформировать отчет
                    </x-filament::button>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Выбрать</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">ID</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Название</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Слот</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Принтер</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Цвет</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Комментарий</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">% тонера</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($this->historySupplies as $supply)
                                <tr wire:key="history-supply-{{ $supply->id }}">
                                    <td class="px-4 py-3">
                                        <input
                                            type="checkbox"
                                            wire:model.live="selectedSupplies"
                                            value="{{ $supply->id }}"
                                        >
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ $supply->id }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $supply->display_name }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $supply->display_slot }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $supply->printer?->display_name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $supply->color_label }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $supply->comment_display }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $supply->percentage_display }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
