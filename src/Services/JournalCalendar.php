<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use NonConvexLabs\Commonplace\Models\Note;

class JournalCalendar
{
    public function buildMonth(
        ?Authenticatable $user,
        int $year,
        int $month,
        ?string $selectedDate,
    ): array {
        $year = max(2000, min(2100, $year));
        $month = max(1, min(12, $month));

        $monthStart = Carbon::createFromDate($year, $month, 1);

        $notesByDay = $this->groupNotesByDay($user, $year, $month);

        $selectedNotes = isset($notesByDay[$selectedDate])
            ? collect($notesByDay[$selectedDate])
            : collect();

        $prevMonth = $monthStart->copy()->subMonth();
        $nextMonth = $monthStart->copy()->addMonth();

        return [
            'year' => $year,
            'month' => $month,
            'monthName' => $monthStart->format('F'),
            'calendarDays' => $this->buildCalendarDays($year, $month, $monthStart, $notesByDay, $selectedDate),
            'selectedDate' => $selectedDate,
            'selectedDateLabel' => $selectedDate !== null
                ? Carbon::parse($selectedDate)->format('l, F j, Y')
                : null,
            'selectedNotes' => $selectedNotes,
            'prevYear' => $prevMonth->year,
            'prevMonthNum' => $prevMonth->month,
            'nextYear' => $nextMonth->year,
            'nextMonthNum' => $nextMonth->month,
        ];
    }

    private function groupNotesByDay(?Authenticatable $user, int $year, int $month): array
    {
        $prefix = sprintf('journal/%04d-%02d-', $year, $month);

        $journalNotes = Note::accessibleBy($user)
            ->where('path', 'like', $prefix.'%')
            ->with('tags')
            ->orderBy('path')
            ->get();

        $notesByDay = [];
        foreach ($journalNotes as $note) {
            $relative = Str::after($note->path, 'journal/');
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $relative, $matches)) {
                $notesByDay[$matches[1]][] = $note;
            }
        }

        return $notesByDay;
    }

    private function buildCalendarDays(
        int $year,
        int $month,
        Carbon $monthStart,
        array $notesByDay,
        ?string $selectedDate,
    ): array {
        $daysInMonth = $monthStart->daysInMonth;
        $startDayOfWeek = $monthStart->dayOfWeek;
        $totalCells = (int) ceil(($startDayOfWeek + $daysInMonth) / 7) * 7;
        $today = date('Y-m-d');

        $days = [];
        $dayCounter = 1;

        for ($cell = 0; $cell < $totalCells; $cell++) {
            $isValidDay = $cell >= $startDayOfWeek && $dayCounter <= $daysInMonth;

            if (! $isValidDay) {
                $days[] = null;

                continue;
            }

            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $dayCounter);
            $noteCount = isset($notesByDay[$dateStr]) ? count($notesByDay[$dateStr]) : 0;

            $days[] = [
                'day' => $dayCounter,
                'date' => $dateStr,
                'hasNotes' => $noteCount > 0,
                'noteCount' => $noteCount,
                'isSelected' => $dateStr === $selectedDate,
                'isToday' => $dateStr === $today,
            ];

            $dayCounter++;
        }

        return $days;
    }
}
