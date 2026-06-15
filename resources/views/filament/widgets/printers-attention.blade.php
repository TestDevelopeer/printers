@php
    $pollingInterval = $this->getPollingInterval();
@endphp

<x-filament-widgets::widget
    :attributes="
        (new \Illuminate\View\ComponentAttributeBag)
            ->merge([
                'wire:poll.' . $pollingInterval => $pollingInterval ? true : null,
            ], escape: false)
    "
>
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">Детализация</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                Принтеры и слоты, по которым нужно принять меры.
            </div>
        </div>

        @if ($items->isEmpty())
            <div class="p-8 text-sm text-gray-500 dark:text-gray-400">
                Все принтеры в норме — проблем, требующих внимания, не найдено.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Проблема</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Принтер</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">ID принтера</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Слот</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">ID картриджа</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Картридж</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Уровень</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                        @foreach ($items as $item)
                            <tr>
                                <td class="px-4 py-3">
                                    <x-filament::badge :color="$item->type->color()">
                                        {{ $item->type->label() }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                                    {{ $item->printer->display_name }}
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                                    #{{ $item->printer->getKey() }}
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                                    {{ $item->slotKey ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                                    {{ $item->cartridgeId() ? '#'.$item->cartridgeId() : '—' }}
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                                    {{ $item->cartridgeLabel() ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                                    {{ $item->tonerLevel() ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <x-filament::link :href="$item->printerViewUrl()" color="primary" size="sm">
                                        Открыть
                                    </x-filament::link>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
