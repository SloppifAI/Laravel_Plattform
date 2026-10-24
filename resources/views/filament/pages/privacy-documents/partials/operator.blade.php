{{-- The operator as processor, from the imprint facts. --}}
<address class="not-italic">
    <strong>{{ $operator['name'] }}</strong><br>
    {{ $operator['person'] }}<br>
    {{ $operator['street'] }}, {{ $operator['city'] }}<br>
    <a href="mailto:{{ $operator['email'] }}">{{ $operator['email'] }}</a>
</address>
