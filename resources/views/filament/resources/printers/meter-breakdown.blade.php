@php
    use Carbon\Carbon;
@endphp
<div class="fi-in-repeatable space-y-2">
    @if (empty($breakdown))
        <div class="text-sm text-gray-500 dark:text-gray-400">
            Нет данных. Показания появятся после первого успешного опроса принтера.
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-700 dark:bg-white/5 dark:text-gray-200">
                    <tr>
                        <th scope="col" class="px-3 py-2">Дата</th>
                        <th scope="col" class="px-3 py-2 text-right">Страниц за день</th>
                        <th scope="col" class="px-3 py-2 text-right">Всего на конец дня</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($breakdown as $row)
                        @php
                            $carbon = Carbon::parse($row['date']);
                            $totalPages = $row['total_pages'];
                            $delta = $row['delta'];
                            $isToday = $row['is_today'];
                            $reset = $row['reset_detected'];
                        @endphp
                        <tr class="border-t border-gray-200 dark:border-white/10 {{ $isToday ? 'bg-primary-50/40 dark:bg-primary-400/10' : '' }}">
                            <td class="px-3 py-2 align-top">
                                <div class="font-medium">{{ $carbon->format('d.m.Y') }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $carbon->translatedFormat('D') }}
                                    @if ($isToday)
                                        <span class="ml-1 inline-flex items-center rounded-md bg-primary-100 px-1.5 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">
                                            Сегодня
                                        </span>
                                    @endif
                                    @if ($reset)
                                        <span class="ml-1 inline-flex items-center rounded-md bg-warning-100 px-1.5 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/20 dark:text-warning-300">
                                            Сброс
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right align-top tabular-nums">
                                @if ($delta === null)
                                    <span class="text-gray-400">—</span>
                                @else
                                    <span class="font-semibold {{ $reset ? 'text-warning-600 dark:text-warning-400' : '' }}">
                                        {{ number_format($delta, 0, '.', ' ') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right align-top tabular-nums text-gray-600 dark:text-gray-300">
                                @if ($totalPages === null)
                                    <span class="text-gray-400">—</span>
                                @else
                                    {{ number_format($totalPages, 0, '.', ' ') }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Значения за день считаются как разница с предыдущим днём. При уменьшении счётчика
            (сервисный сброс) дельта за день считается от нуля и строка помечается.
        </p>
    @endif
</div>