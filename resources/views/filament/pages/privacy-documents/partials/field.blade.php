{{-- A labelled box the school writes into by hand. Optional: $hint, $tall. --}}
<div class="pd-field">
    <p><strong>{{ $label }}</strong>@isset($hint)<span> – {{ $hint }}</span>@endisset</p>
    <div @class(['pd-field-box', 'pd-tall' => $tall ?? false])></div>
</div>
