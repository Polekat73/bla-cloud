<?php
/** @var array $ev one row from CalendarController::eventsBetween(), plus $today/$fmtDate in scope */
$parsed = \BlaCloud\Dav\Ical::parseEvent($ev['data']) ?? [];
$summary = $parsed['summary'] !== '' ? $parsed['summary'] : '(untitled)';
$remindMinutes = '';
if (!empty($ev['remind_at'])) {
    $remindMinutes = (string) max(0, (int) round((strtotime($ev['start_at'] . ' UTC') - strtotime($ev['remind_at'] . ' UTC')) / 60));
}
$timeLabel = $ev['all_day'] ? 'All day' : $ev['start_dt']->format('g:ia');
?>
<button type="button" class="cal-chip-event" data-chip-color="<?= e($ev['cal_color']) ?>"
  data-event
  data-object-id="<?= (int) $ev['id'] ?>"
  data-calendar-id="<?= (int) $ev['calendar_id'] ?>"
  data-title="<?= e($parsed['summary'] ?? '') ?>"
  data-all-day="<?= $ev['all_day'] ? '1' : '0' ?>"
  data-date="<?= e($ev['start_dt']->format('Y-m-d')) ?>"
  data-start-time="<?= e($ev['start_dt']->format('H:i')) ?>"
  data-end-date="<?= e(($ev['all_day'] ? $ev['end_dt']->modify('-1 day') : $ev['end_dt'])->format('Y-m-d')) ?>"
  data-end-time="<?= e($ev['end_dt']->format('H:i')) ?>"
  data-location="<?= e($parsed['location'] ?? '') ?>"
  data-description="<?= e($parsed['description'] ?? '') ?>"
  data-remind="<?= e($remindMinutes) ?>"
><span class="cal-chip-event__time"><?= e($timeLabel) ?></span> <?= e($summary) ?></button>
