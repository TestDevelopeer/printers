@php
    $percentage = $getRecord()->percentage;
@endphp

<div class="fi-in-entry">
    <div class="fi-in-entry-label-col">
        <div class="fi-in-entry-label-ctn">
            <dt class="fi-in-entry-label">
                Тонер </dt>
        </div>
    </div>
    <div class="fi-in-entry-content-col">
        <dd class="fi-in-entry-content-ctn">
            <div class="fi-in-entry-content">
                <div class="fi-size-sm  fi-in-text-item  fi-wrapped  fi-in-text">
                    {{ $percentage === null ? 'Неизвестно' : $percentage . '%' }}
                </div>
            </div>
        </dd>
    </div>
</div>
