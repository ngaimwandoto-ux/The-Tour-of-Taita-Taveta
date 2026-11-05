<?php
/**
 * includes/leg-schedule.php
 * The ONE place the server knows each leg's dates. Used to work out the
 * "next upcoming leg" (the default for College & University team entries).
 *
 * When dates change, edit them here. null = date still to be set.
 * (The dates shown on the pages themselves are separate text in the HTML.)
 */
function legSchedule(): array {
    return [
        ['leg' => 'Sagalla',       'start' => '2026-10-10', 'end' => '2026-10-12'],
        ['leg' => 'Vuria/Wusi',    'start' => '2026-12-12', 'end' => '2026-12-14'],
        ['leg' => 'Bura',          'start' => '2027-03-26', 'end' => '2027-03-29'],
        ['leg' => 'Kasighau',      'start' => '2027-05-01', 'end' => '2027-05-03'],
        ['leg' => 'Mbololo/Ronge', 'start' => null,         'end' => null],
        ['leg' => 'Mbale',         'start' => null,         'end' => null],
        ['leg' => 'Funju',         'start' => null,         'end' => null],
    ];
}

/** Legs with their number, a readable label, and whether they are already over. */
function legSchedulePublic(): array {
    $today = (new DateTime('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');
    $out = [];
    foreach (legSchedule() as $i => $l) {
        $n = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
        if ($l['start'] && $l['end']) {
            $s = new DateTime($l['start']); $e = new DateTime($l['end']);
            $when = ($s->format('M Y') === $e->format('M Y'))
                ? $s->format('j') . '–' . $e->format('j M Y')
                : $s->format('j M') . '–' . $e->format('j M Y');
            $past = $l['end'] < $today;
        } else {
            $when = 'date TBD';
            $past = false;
        }
        $out[] = [
            'leg' => $l['leg'], 'number' => $n,
            'label' => "Leg $n · {$l['leg']} · $when",
            'start' => $l['start'], 'end' => $l['end'], 'past' => $past,
        ];
    }
    return $out;
}

/** First leg that has not finished yet (a dated leg, or the next one still to be dated). */
function upcomingLeg(): ?array {
    foreach (legSchedulePublic() as $l) {
        if (!$l['past']) return $l;
    }
    return null;
}
