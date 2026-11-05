<?php
/** leg-schedule.php — public, read-only. Feeds the leg picker on the College & University team form. */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
require __DIR__ . '/includes/leg-schedule.php';
$up = upcomingLeg();
echo json_encode([
    'success'  => true,
    'upcoming' => $up ? ['leg' => $up['leg'], 'label' => $up['label']] : null,
    'legs'     => array_values(array_filter(legSchedulePublic(), function ($l) { return !$l['past']; })),
]);
