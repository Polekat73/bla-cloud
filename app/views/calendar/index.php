<?php
/** @var \DateTimeImmutable $date */
$fmtDate = static fn (\DateTimeImmutable $d) => $d->format('Y-m-d');
$fmtDay = static fn (\DateTimeImmutable $d) => (int) $d->format('j');
$isToday = static fn (\DateTimeImmutable $d) => $fmtDate($d) === $fmtDate($today);
$isCurMonth = static fn (\DateTimeImmutable $d) => $d->format('Y-m') === $date->format('Y-m');
$reminders = ['' => 'No reminder', '5' => '5 minutes before', '15' => '15 minutes before', '30' => '30 minutes before',
    '60' => '1 hour before', '1440' => '1 day before', '2880' => '2 days before'];

// Group this view's events by day (Y-m-d) for quick lookup while rendering the grid/columns.
$byDay = [];
foreach ($events as $ev) {
    $cursor = $ev['start_dt'];
    $last = $ev['all_day'] ? $ev['end_dt']->modify('-1 second') : $ev['end_dt'];
    do {
        $byDay[$cursor->format('Y-m-d')][] = $ev;
        $cursor = $cursor->modify('+1 day')->setTime(0, 0);
    } while ($cursor <= $last && count($byDay[$cursor->format('Y-m-d')] ?? []) < 60);
}

$nav = static function (string $view, \DateTimeImmutable $d) {
    return url('calendar', ['view' => $view, 'date' => $d->format('Y-m-d')]);
};
[$prevDate, $nextDate, $heading] = match ($view) {
    'week'   => [$date->modify('-7 days'), $date->modify('+7 days'), 'Week of ' . $date->modify('monday this week')->format('M j, Y')],
    'day'    => [$date->modify('-1 day'), $date->modify('+1 day'), $date->format('l, F j, Y')],
    'agenda' => [$date->modify('-30 days'), $date->modify('+30 days'), 'Agenda'],
    default  => [$date->modify('-1 month'), $date->modify('+1 month'), $date->format('F Y')],
};
?>
<div class="page" data-calendar>
  <div class="page-head">
    <div><p class="eyebrow">Your account</p><h1>Calendar</h1></div>
    <div class="files__actions">
      <div class="seg">
        <?php foreach (['month' => 'Month', 'week' => 'Week', 'day' => 'Day', 'agenda' => 'Agenda'] as $v => $label): ?>
          <a class="icon-btn cal-view-tab <?= $view === $v ? 'is-on' : '' ?>" href="<?= e($nav($v, $date)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn--primary" data-open="dlg-event" data-new-event data-default-date="<?= e($fmtDate($today)) ?>"><?= icon('plus') ?> New event</button>
    </div>
  </div>

  <div class="cal-toolbar">
    <div class="cal-nav">
      <a class="icon-btn" href="<?= e($nav($view, $prevDate)) ?>" aria-label="Previous"><?= icon('chevron-left') ?></a>
      <a class="icon-btn" href="<?= e($nav($view, $today)) ?>" aria-label="Today"><?= icon('calendar') ?></a>
      <a class="icon-btn" href="<?= e($nav($view, $nextDate)) ?>" aria-label="Next"><?= icon('chevron') ?></a>
      <h2 class="cal-heading"><?= e($heading) ?></h2>
    </div>
    <div class="cal-legend">
      <?php foreach ($calendars as $c): ?>
        <span class="cal-chip"><span class="cal-dot" data-dot-color="<?= e($c['color']) ?>"></span><?= e($c['display_name']) ?></span>
      <?php endforeach; ?>
      <button type="button" class="btn btn--ghost btn--sm" data-open="dlg-new-cal"><?= icon('plus') ?> Calendar</button>
    </div>
  </div>

  <?php if ($view === 'month'): ?>
    <div class="cal-grid">
      <div class="cal-grid__head">
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?><div><?= $d ?></div><?php endforeach; ?>
      </div>
      <?php foreach ($grid as $week): ?>
        <div class="cal-grid__row">
          <?php foreach ($week as $d): $items = $byDay[$fmtDate($d)] ?? []; ?>
            <div class="cal-day <?= $isCurMonth($d) ? '' : 'is-outside' ?> <?= $isToday($d) ? 'is-today' : '' ?>"
                 data-day data-date="<?= e($fmtDate($d)) ?>">
              <button type="button" class="cal-day__num" data-add-here><?= $fmtDay($d) ?></button>
              <div class="cal-day__events">
                <?php foreach (array_slice($items, 0, 3) as $ev): ?>
                  <?php include __DIR__ . '/_chip.php'; ?>
                <?php endforeach; ?>
                <?php if (count($items) > 3): ?><a class="cal-more" href="<?= e($nav('day', $d)) ?>">+<?= count($items) - 3 ?> more</a><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>

  <?php elseif ($view === 'week'): ?>
    <div class="cal-week">
      <?php $wd = $date->modify('monday this week'); for ($i = 0; $i < 7; $i++, $wd = $wd->modify('+1 day')): $items = $byDay[$fmtDate($wd)] ?? []; ?>
        <div class="cal-col <?= $isToday($wd) ? 'is-today' : '' ?>" data-day data-date="<?= e($fmtDate($wd)) ?>">
          <button type="button" class="cal-col__head" data-add-here><?= e($wd->format('D j')) ?></button>
          <div class="cal-col__events">
            <?php foreach ($items as $ev): ?><?php include __DIR__ . '/_chip.php'; ?><?php endforeach; ?>
            <?php if (!$items): ?><p class="muted cal-col__empty">—</p><?php endif; ?>
          </div>
        </div>
      <?php endfor; ?>
    </div>

  <?php elseif ($view === 'day'): ?>
    <div class="card" data-day data-date="<?= e($fmtDate($date)) ?>">
      <?php $items = $byDay[$fmtDate($date)] ?? []; ?>
      <?php if (!$items): ?>
        <p class="muted">No events. <button type="button" class="btn btn--ghost btn--sm" data-add-here><?= icon('plus') ?> Add one</button></p>
      <?php else: ?>
        <ul class="cal-agenda-list">
          <?php foreach ($items as $ev): ?><li><?php include __DIR__ . '/_chip.php'; ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

  <?php else: /* agenda */ ?>
    <div class="card">
      <?php if (!$byDay): ?>
        <p class="muted">Nothing in the next 60 days.</p>
      <?php else: ?>
        <?php ksort($byDay); foreach ($byDay as $ymd => $items): ?>
          <div class="cal-agenda-day">
            <h3><?= e((new DateTimeImmutable($ymd))->format('l, F j')) ?></h3>
            <ul class="cal-agenda-list">
              <?php foreach ($items as $ev): ?><li><?php include __DIR__ . '/_chip.php'; ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<dialog id="dlg-event" class="dialog">
  <form method="post" action="<?= e(url('calendar.event.save')) ?>" class="form" data-event-form>
    <?= csrf_field() ?>
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <input type="hidden" name="date_view" value="<?= e($fmtDate($date)) ?>">
    <input type="hidden" name="object_id" value="" data-f="object_id">
    <h2 data-event-title>New event</h2>
    <label class="field"><span>Title</span><input name="title" required maxlength="255" data-f="title" autofocus></label>
    <?php if (count($calendars) > 1): ?>
      <label class="field"><span>Calendar</span>
        <select name="calendar_id" data-f="calendar_id">
          <?php foreach ($calendars as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['display_name']) ?></option><?php endforeach; ?>
        </select></label>
    <?php else: ?>
      <input type="hidden" name="calendar_id" value="<?= (int) ($calendars[0]['id'] ?? 0) ?>" data-f="calendar_id">
    <?php endif; ?>
    <label class="check-row"><input type="checkbox" name="all_day" value="1" data-f="all_day"><span>All day</span></label>
    <div class="grid-2">
      <label class="field"><span>Start</span><input type="date" name="date" required data-f="date"></label>
      <label class="field" data-time-field><span>Time</span><input type="time" name="start_time" value="09:00" data-f="start_time"></label>
    </div>
    <div class="grid-2">
      <label class="field"><span>End</span><input type="date" name="end_date" data-f="end_date"></label>
      <label class="field" data-time-field><span>Time</span><input type="time" name="end_time" value="10:00" data-f="end_time"></label>
    </div>
    <label class="field"><span>Location</span><input name="location" maxlength="255" data-f="location"></label>
    <label class="field"><span>Description</span><textarea name="description" rows="3" data-f="description"></textarea></label>
    <label class="field"><span>Reminder</span>
      <select name="remind" data-f="remind">
        <?php foreach ($reminders as $val => $label): ?><option value="<?= e($val) ?>"><?= e($label) ?></option><?php endforeach; ?>
      </select></label>
    <div class="actions">
      <button type="button" class="btn btn--danger" data-delete-event hidden><?= icon('trash') ?> Delete</button>
      <span class="actions-spacer"></span>
      <button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--primary" type="submit">Save</button>
    </div>
  </form>
</dialog>
<form method="post" action="<?= e(url('calendar.event.delete')) ?>" id="form-delete-event" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <input type="hidden" name="date_view" value="<?= e($fmtDate($date)) ?>">
  <input type="hidden" name="calendar_id" data-df="calendar_id">
  <input type="hidden" name="object_id" data-df="object_id">
</form>

<dialog id="dlg-new-cal" class="dialog">
  <form method="post" action="<?= e(url('calendar.new')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <input type="hidden" name="date_view" value="<?= e($fmtDate($date)) ?>">
    <h2>New calendar</h2>
    <label class="field"><span>Name</span><input name="name" required maxlength="128" autofocus></label>
    <label class="field"><span>Color</span><input type="color" name="color" value="#c9a227"></label>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--primary" type="submit">Create</button></div>
  </form>
</dialog>
