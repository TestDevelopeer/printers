@php
    $percentage = $getRecord()->percentage;
    $statusLabel = $getRecord()->status_label;
@endphp

<div class="space-y-2">
    <div class="flex items-center justify-between text-sm">
        <span>{{ $percentage === null ? 'Unknown' : $percentage . '%' }}</span>
        <span class="text-gray-500">{{ $statusLabel }}</span>
    </div>
    <div class="h-2.5 rounded-full bg-gray-200">
        <div
            class="h-2.5 rounded-full {{ $percentage !== null && $percentage <= config('printers.low_toner_threshold', 15) ? 'bg-red-500' : 'bg-emerald-500' }}"
            style="width: {{ $percentage ?? 0 }}%;"
        ></div>
    </div>
</div>
