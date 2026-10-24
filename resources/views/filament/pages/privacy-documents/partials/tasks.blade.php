{{-- A checklist: box, task, explanation, what the platform knows (print and screen), a link (screen only) and a line for "done on / by". Needs $tasks. --}}
<ul class="pd-checklist">
    @foreach ($tasks as $task)
        <li>
            <span class="pd-check" aria-hidden="true"></span>
            <div>
                <p><strong>{{ $task['title'] }}</strong></p>
                <p>{{ $task['text'] }}</p>
                @if ($task['status'])
                    <p><strong>Stand in der Plattform:</strong> {{ $task['status'] }}</p>
                @endif
                @if ($task['link'])
                    <p class="print:hidden"><a href="{{ $task['link'][1] }}">{{ $task['link'][0] }}</a></p>
                @endif
                <p class="pd-done">Erledigt am {{ $blank }} von {{ $blank }}</p>
            </div>
        </li>
    @endforeach
</ul>
