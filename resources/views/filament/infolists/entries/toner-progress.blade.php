@php
    $percentage = $getRecord()->percentage;
@endphp

<div class="space-y-2">
    <div class="text-sm font-medium">
        {{ $percentage === null ? 'Неизвестно' : $percentage . '% тонера' }}
    </div>
    <div class="h-2.5 rounded-full bg-gray-200">
        <div
            class="h-2.5 rounded-full {{ $percentage !== null && $percentage <= config('printers.low_toner_threshold', 15) ? 'bg-red-500' : 'bg-emerald-500' }}"
            style="width: {{ $percentage ?? 0 }}%;"
        ></div>
    </div>
</div>
