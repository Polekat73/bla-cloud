<?php
$form = $setup['db_form'] ?? [];
$driver = $setup['db']['driver'] ?? (!extension_loaded('pdo_sqlite') ? 'mysql' : ($form ? 'mysql' : 'sqlite'));
$hasSqlite = extension_loaded('pdo_sqlite');
$hasMysql = extension_loaded('pdo_mysql');
?>
<h2>Where should BLA-Cloud keep its records?</h2>
<p class="lead">This database stores accounts and settings (your files are stored separately as normal files).</p>

<form method="post" class="form" data-db-form>
  <?= csrf_field() ?>
  <fieldset class="choice-group">
    <legend class="sr-only">Database type</legend>
    <label class="choice <?= !$hasSqlite ? 'is-disabled' : '' ?>">
      <input type="radio" name="driver" value="sqlite" <?= $driver === 'sqlite' ? 'checked' : '' ?> <?= !$hasSqlite ? 'disabled' : '' ?>>
      <span class="choice__body">
        <strong>Simple (SQLite) <span class="badge">Recommended</span></strong>
        <span>Nothing to set up. Perfect for you and your family, or a small team.</span>
      </span>
    </label>
    <label class="choice <?= !$hasMysql ? 'is-disabled' : '' ?>">
      <input type="radio" name="driver" value="mysql" <?= $driver === 'mysql' ? 'checked' : '' ?> <?= !$hasMysql ? 'disabled' : '' ?>>
      <span class="choice__body">
        <strong>MySQL / MariaDB</strong>
        <span>For larger groups. Create an empty database in your hosting panel first.</span>
      </span>
    </label>
  </fieldset>

  <div class="mysql-fields" data-mysql-fields <?= $driver === 'mysql' ? '' : 'hidden' ?>>
    <div class="grid-2">
      <label class="field"><span>Database host</span>
        <input name="host" value="<?= e($form['host'] ?? 'localhost') ?>" autocomplete="off"></label>
      <label class="field"><span>Port</span>
        <input name="port" inputmode="numeric" value="<?= e((string) ($form['port'] ?? 3306)) ?>"></label>
    </div>
    <label class="field"><span>Database name</span>
      <input name="name" value="<?= e($form['name'] ?? '') ?>" autocomplete="off"></label>
    <div class="grid-2">
      <label class="field"><span>Database user</span>
        <input name="user" value="<?= e($form['user'] ?? '') ?>" autocomplete="off"></label>
      <label class="field"><span>Database password</span>
        <input name="password" type="password" autocomplete="new-password"></label>
    </div>
    <p class="hint">We'll test the connection before moving on.</p>
  </div>

  <div class="actions">
    <a class="btn btn--ghost" href="?step=welcome">Back</a>
    <button class="btn btn--primary" type="submit">Continue <?= icon('chevron') ?></button>
  </div>
</form>
