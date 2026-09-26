<div class="page">
  <p class="eyebrow">Administration</p>
  <h1>Apps</h1>
  <p class="muted">Built-in and installed apps. Disabling one hides it from the sidebar and its pages —
    it doesn't touch its data (a disabled Calendar or Contacts app keeps syncing over CalDAV/CardDAV).
    To add a new app, place its folder in <code class="mono">app/apps/</code> — see
    <code class="mono">app/apps/README.md</code>.</p>

  <?php if (!$apps): ?>
  <section class="card"><p class="muted">No apps found in <code class="mono">app/apps/</code>.</p></section>
  <?php else: ?>
  <?php foreach ($apps as $m): $on = \BlaCloud\Apps::isEnabled($m['id']); ?>
  <section class="card">
    <div class="card__head">
      <span class="card__icon <?= $on ? 'is-ok' : '' ?>"><?= icon($m['icon']) ?></span>
      <div>
        <h2><?= e($m['name']) ?> <?php if ($m['version'] !== ''): ?><small class="muted">v<?= e($m['version']) ?></small><?php endif; ?></h2>
        <p class="muted"><?= e($m['description']) ?></p>
      </div>
    </div>
    <div class="actions actions--left">
      <span class="<?= $on ? 'ok-text' : 'muted' ?>"><?= icon($on ? 'check' : 'x') ?> <?= $on ? 'Enabled' : 'Disabled' ?></span>
      <form method="post" action="<?= e(url('admin.apps.toggle')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($m['id']) ?>">
        <input type="hidden" name="enabled" value="<?= $on ? '0' : '1' ?>">
        <button class="btn btn--ghost" type="submit"><?= $on ? 'Disable' : 'Enable' ?></button>
      </form>
    </div>
  </section>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
