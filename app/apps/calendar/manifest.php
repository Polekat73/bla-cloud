<?php
declare(strict_types=1);

use BlaCloud\Apps\Calendar\CalendarController;

return [
    'id'          => 'calendar',
    'name'        => 'Calendar',
    'description' => 'Month, week, day and agenda views over your CalDAV calendars, with email reminders.',
    'version'     => '1.0.0',
    'icon'        => 'calendar',
    'nav'         => ['route' => 'calendar', 'label' => 'Calendar', 'order' => 20],
    'default_enabled' => true,
    'routes'      => [
        'calendar'               => [CalendarController::class, 'index'],
        'calendar.event.save'    => [CalendarController::class, 'saveEvent'],
        'calendar.event.delete'  => [CalendarController::class, 'deleteEvent'],
        'calendar.new'           => [CalendarController::class, 'newCalendar'],
    ],
];
