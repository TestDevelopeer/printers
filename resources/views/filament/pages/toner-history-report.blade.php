<x-filament-panels::page>
    <style>
        .toner-report {
            --tr-border: color-mix(in oklab, currentColor 20%, transparent);
            --tr-header-bg: color-mix(in oklab, currentColor 8%, transparent);
            --tr-row-alt-bg: color-mix(in oklab, currentColor 4%, transparent);
        }

        .toner-report__card {
            overflow: hidden;
            border-radius: 0.75rem;
            border: 1px solid var(--tr-border);
        }

        .toner-report__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.875rem 1rem;
            border-bottom: 1px solid var(--tr-border);
        }

        .toner-report__title {
            font-size: 0.875rem;
            font-weight: 600;
        }

        .toner-report__hint {
            margin-top: 0.25rem;
            font-size: 0.75rem;
            opacity: 0.7;
        }

        .toner-report__table-wrap {
            overflow-x: auto;
        }

        .toner-report__table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 0.875rem;
            line-height: 1.35;
        }

        .toner-report__table th,
        .toner-report__table td {
            border: 1px solid var(--tr-border);
            padding: 0.625rem 0.75rem;
            text-align: left;
            vertical-align: middle;
            word-break: break-word;
        }

        .toner-report__table thead th {
            background: var(--tr-header-bg);
            font-weight: 600;
        }

        .toner-report__table tbody tr:nth-child(even) {
            background: var(--tr-row-alt-bg);
        }

        .toner-report__table .col-select {
            width: 4.5rem;
            text-align: center;
        }

        .toner-report__table .col-id {
            width: 4rem;
        }

        .toner-report__table .col-slot {
            width: 4rem;
        }

        .toner-report__table .col-toner {
            width: 6rem;
        }

        .toner-report__empty {
            border-radius: 0.75rem;
            border: 1px dashed var(--tr-border);
            padding: 2rem;
            font-size: 0.875rem;
            opacity: 0.75;
        }
    </style>

    <div class="toner-report space-y-6">
        @if ($this->historySupplies->isEmpty())
            <div class="toner-report__empty">
                В истории пока нет картриджей. После замены или удаления слота записи появятся здесь.
            </div>
        @else
            <div class="toner-report__card">
                <div class="toner-report__header">
                    <div>
                        <div class="toner-report__title">Картриджи в истории</div>
                        <div class="toner-report__hint">
                            Выберите позиции для включения в PDF-отчет. В отчете добавляются колонки «Подпись получателя» и «Подпись владельца».
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                wire:model.live="serviceOnly"
                            >
                            <span>На обслуживании</span>
                        </label>
                        <x-filament::button wire:click="generateReport" color="primary">
                            Сформировать отчет
                        </x-filament::button>
                    </div>
                </div>

                <div class="toner-report__table-wrap">
                    <table class="toner-report__table">
                        <thead>
                            <tr>
                                <th class="col-select">Выбрать</th>
                                <th class="col-id">ID</th>
                                <th>Название</th>
                                <th class="col-slot">Слот</th>
                                <th>Принтер</th>
                                <th>Цвет</th>
                                <th>Статус</th>
                                <th>Комментарий</th>
                                <th class="col-toner">% тонера</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->historySupplies as $supply)
                                <tr wire:key="history-supply-{{ $supply->id }}">
                                    <td class="col-select">
                                        <input
                                            type="checkbox"
                                            wire:model.live="selectedSupplies"
                                            value="{{ $supply->id }}"
                                        >
                                    </td>
                                    <td class="col-id">{{ $supply->id }}</td>
                                    <td>{{ $supply->display_name }}</td>
                                    <td class="col-slot">{{ $supply->display_slot }}</td>
                                    <td>{{ $supply->printer?->display_name ?? '—' }}</td>
                                    <td>{{ $supply->color_label }}</td>
                                    <td>{{ $supply->removed_at === null ? 'Активный' : $supply->service_status_label }}</td>
                                    <td>{{ $supply->comment_display }}</td>
                                    <td class="col-toner">{{ $supply->percentage_display }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
