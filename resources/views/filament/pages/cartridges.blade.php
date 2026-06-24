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
        .toner-report__footer {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            border-top: 1px solid var(--tr-border);
            overflow-x: auto;
        }

        .toner-report__pagination {
            min-width: max-content;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .toner-report__page {
            appearance: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 0.875rem;
            font-size: 0.8125rem;
            font-weight: 500;
            line-height: 1;
            color: inherit;
            background: color-mix(in oklab, currentColor 6%, transparent);
            border: 1px solid color-mix(in oklab, currentColor 15%, transparent);
            border-radius: 0.5rem;
            cursor: pointer;
            transition: background-color 120ms ease, border-color 120ms ease;
        }

        .toner-report__page:hover {
            background: color-mix(in oklab, currentColor 10%, transparent);
            border-color: color-mix(in oklab, currentColor 25%, transparent);
        }

        .toner-report__page--disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .toner-report__page--disabled:hover {
            background: color-mix(in oklab, currentColor 6%, transparent);
            border-color: color-mix(in oklab, currentColor 15%, transparent);
        }

        .toner-report__page-info {
            font-size: 0.8125rem;
            opacity: 0.75;
            padding: 0 0.25rem;
        }
    </style>

    <div class="toner-report space-y-6">
        @if ($this->supplies->isEmpty())
            <div class="toner-report__empty">
                Свободных картриджей нет. Отправьте активный картридж на обслуживание со страницы принтера, чтобы он появился здесь.
            </div>
        @else
            <div class="toner-report__card">
                <div class="toner-report__header">
                    <div>
                        <div class="toner-report__title">Картриджи на обслуживании</div>
                        <div class="toner-report__hint">
                            Выберите позиции для включения в PDF-отчет. В отчете добавляются колонки «Подпись получателя» и «Подпись владельца».
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
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
                                <th>Цвет</th>
                                <th>Статус</th>
                                <th>Комментарий</th>
                                <th class="col-toner">% тонера</th>
                                <th>Отправлен</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->supplies as $supply)
                                <tr wire:key="cartridge-supply-{{ $supply->id }}">
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
                                    <td>{{ $supply->color_label }}</td>
                                    <td>{{ $supply->service_status_label }}</td>
                                    <td>{{ $supply->comment_display }}</td>
                                    <td class="col-toner">{{ $supply->percentage_display }}</td>
                                    <td>{{ optional($supply->removed_at)->format('d.m.Y H:i') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="toner-report__footer">
                    @php
                        $paginator = $this->supplies;
                    @endphp
                    @if ($paginator->hasPages())
                        <nav class="toner-report__pagination" role="navigation" aria-label="Pagination">
                            @if ($paginator->onFirstPage())
                                <span class="toner-report__page toner-report__page--disabled" aria-disabled="true">
                                    « Назад
                                </span>
                            @else
                                <button type="button" wire:click="previousPage" class="toner-report__page">
                                    « Назад
                                </button>
                            @endif

                            <span class="toner-report__page-info">
                                Стр. {{ $paginator->currentPage() }} из {{ $paginator->lastPage() }}
                            </span>

                            @if ($paginator->hasMorePages())
                                <button type="button" wire:click="nextPage" class="toner-report__page">
                                    Вперёд »
                                </button>
                            @else
                                <span class="toner-report__page toner-report__page--disabled" aria-disabled="true">
                                    Вперёд »
                                </span>
                            @endif
                        </nav>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
