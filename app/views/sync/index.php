<?php
$webdav  = "$scheme://$host$base/dav/files/" . rawurlencode($user['username']) . '/';
$caldav  = "$scheme://$host$base/dav/calendars/" . rawurlencode($user['username']) . '/';
$carddav = "$scheme://$host$base/dav/addressbooks/" . rawurlencode($user['username']) . '/';
?>
<div class="page">
  <p class="eyebrow">Your account</p>
  <h1>Sync</h1>
  <p class="lead">Connect your files, calendar and contacts to your phone, computer, or apps like Thunderbird,
    Outlook or Apple Calendar &amp; Contacts — using the addresses below. A built-in calendar and contacts
    app is coming in a future update; until then, any CalDAV/CardDAV app works.</p>

  <?php if ($newPassword): ?>
  <section class="card">
    <div class="card__head">
      <span class="card__icon is-ok"><?= icon('key') ?></span>
      <div><h2>New app password</h2><p class="muted">Copy it now — you won't be able to see it again.</p></div>
    </div>
    <div class="codes" data-codes><code><?= e(\BlaCloud\AppPasswords::format($newPassword['secret'])) ?></code></div>
    <p>Username: <strong><?= e($newPassword['username']) ?></strong></p>
    <div class="actions actions--left">
      <button type="button" class="btn btn--ghost" data-copy="<?= e($newPassword['secret']) ?>"><?= icon('copy') ?> Copy password</button>
    </div>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="card__head">
      <span class="card__icon"><?= icon('shield') ?></span>
      <div><h2>App passwords</h2><p class="muted">One per device. They can't answer a two-step verification
        prompt, so each gets its own password instead of your account one — and you can revoke any of them
        without changing your main password.</p></div>
    </div>

    <?php if ($passwords): ?>
    <div class="table-wrap">
      <table class="file-table">
        <thead><tr><th>Label</th><th>Created</th><th>Last used</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($passwords as $p): ?>
          <tr>
            <td><?= e($p['label']) ?></td>
            <td class="nowrap"><?= e(human_time((int) strtotime($p['created_at'] . ' UTC'))) ?></td>
            <td class="nowrap"><?= $p['last_used_at'] ? e(human_time((int) strtotime($p['last_used_at'] . ' UTC')) . ' · ' . $p['last_used_ip']) : '<span class="muted">Never</span>' ?></td>
            <td class="nowrap">
              <form method="post" action="<?= e(url('sync.apppasswords.delete')) ?>" data-confirm="Revoke “<?= e($p['label']) ?>”? Any device using it will stop syncing.">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn--ghost btn--sm btn--danger" type="submit"><?= icon('trash') ?> Revoke</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <p class="muted">No app passwords yet.</p>
    <?php endif; ?>

    <div class="actions actions--left">
      <button type="button" class="btn btn--primary" data-open="dlg-newapppw"><?= icon('plus') ?> New app password</button>
    </div>
  </section>

  <section class="card">
    <div class="card__head">
      <span class="card__icon"><?= icon('link') ?></span>
      <div><h2>Addresses</h2><p class="muted">When an app asks for a server, username and password: use your
        username (<?= e($user['username']) ?>) and an app password from above — not your account password.</p></div>
    </div>
    <label class="field"><span>Files (WebDAV)</span>
      <input type="text" readonly value="<?= e($webdav) ?>" onclick="this.select()"></label>
    <label class="field"><span>Calendar (CalDAV)</span>
      <input type="text" readonly value="<?= e($caldav) ?>" onclick="this.select()"></label>
    <label class="field"><span>Contacts (CardDAV)</span>
      <input type="text" readonly value="<?= e($carddav) ?>" onclick="this.select()"></label>
    <p class="hint">Apple, Thunderbird and most CalDAV/CardDAV apps can also auto-discover the calendar and
      contacts addresses from just <code><?= e("$scheme://$host") ?></code> and your username/app password.</p>
  </section>
</div>

<dialog id="dlg-newapppw" class="dialog">
  <form method="post" action="<?= e(url('sync.apppasswords.create')) ?>" class="form">
    <?= csrf_field() ?>
    <h2>New app password</h2>
    <label class="field"><span>What device is this for?</span>
      <input name="label" maxlength="128" placeholder="e.g. My iPhone, Work laptop" autofocus></label>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--primary" type="submit">Create</button></div>
  </form>
</dialog>
