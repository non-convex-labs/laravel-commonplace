@extends('commonplace::layouts.app', [
    'title' => 'Journal — Commonplace',
    'description' => 'Browse journal entries by date.',
])

@section('content')
<section class="cp-journal" aria-labelledby="journal-heading">
    <div class="cp-section-header">
        <div>
            <nav class="cp-breadcrumbs" aria-label="Vault path">
                <a href="{{ route('commonplace.index') }}">Notes</a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('commonplace.show', ['path' => 'journal']) }}">journal</a>
            </nav>
            <h1 id="journal-heading">Journal</h1>
        </div>
        <a href="{{ route('commonplace.create') }}" class="cp-action-primary">New Note</a>
    </div>

    <nav class="cp-calendar-nav" aria-label="Month navigation">
        <a href="{{ route('commonplace.show', ['path' => 'journal']) }}?year={{ $prevYear }}&month={{ $prevMonthNum }}" aria-label="Previous month">&larr; Prev</a>
        <h2>{{ $monthName }} {{ $year }}</h2>
        <a href="{{ route('commonplace.show', ['path' => 'journal']) }}?year={{ $nextYear }}&month={{ $nextMonthNum }}" aria-label="Next month">Next &rarr;</a>
    </nav>

    <table class="cp-calendar" role="grid" aria-label="{{ $monthName }} {{ $year }}">
        <thead>
            <tr>
                <th scope="col" abbr="Sunday">Sun</th>
                <th scope="col" abbr="Monday">Mon</th>
                <th scope="col" abbr="Tuesday">Tue</th>
                <th scope="col" abbr="Wednesday">Wed</th>
                <th scope="col" abbr="Thursday">Thu</th>
                <th scope="col" abbr="Friday">Fri</th>
                <th scope="col" abbr="Saturday">Sat</th>
            </tr>
        </thead>
        <tbody>
            @foreach (array_chunk($calendarDays, 7) as $week)
                <tr>
                    @foreach ($week as $cell)
                        @if ($cell === null)
                            <td class="cp-calendar-day cp-calendar-day-empty"></td>
                        @else
                            <td @class([
                                'cp-calendar-day',
                                'cp-calendar-day-has-notes' => $cell['hasNotes'],
                                'cp-calendar-day-selected' => $cell['isSelected'],
                                'cp-calendar-day-today' => $cell['isToday'],
                            ])>
                                @if ($cell['hasNotes'])
                                    <a href="{{ route('commonplace.show', ['path' => 'journal']) }}?year={{ $year }}&month={{ $month }}&date={{ $cell['date'] }}">
                                        {{ $cell['day'] }}
                                        <span class="cp-calendar-dot" aria-label="{{ $cell['noteCount'] }} {{ \Illuminate\Support\Str::plural('note', $cell['noteCount']) }}"></span>
                                    </a>
                                @else
                                    <span>{{ $cell['day'] }}</span>
                                @endif
                            </td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($selectedDate)
        <section class="cp-journal-notes" aria-labelledby="journal-day-heading">
            <h3 id="journal-day-heading">
                {{ $selectedDateLabel }}
            </h3>

            @if ($selectedNotes->isNotEmpty())
                <ul class="cp-file-list" role="list">
                    @foreach ($selectedNotes as $note)
                        <li class="cp-file-item">
                            <a href="{{ route('commonplace.show', ['path' => $note->path]) }}" class="cp-file-link">
                                <span class="cp-file-title">{{ $note->title }}</span>
                                @if ($note->tags->isNotEmpty())
                                    <span class="cp-file-tags">
                                        @foreach ($note->tags as $tag)
                                            <span class="cp-tag">{{ $tag->name }}</span>
                                        @endforeach
                                    </span>
                                @endif
                                @if ($note->updated_at)
                                    <time datetime="{{ $note->updated_at->toIso8601String() }}">{{ $note->updated_at->diffForHumans() }}</time>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="cp-empty">No entries for this day.</p>
            @endif
        </section>
    @endif
</section>
@endsection
