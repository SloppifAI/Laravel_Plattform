{{-- Place, date and signature lines. $lines is a list of captions. --}}
<div class="pd-signature">
    @foreach ($lines as $line)
        <div>{{ $line }}</div>
    @endforeach
</div>
