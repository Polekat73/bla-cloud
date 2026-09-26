<?php
$suggest = $setup['data_dir'] ?? \BlaCloud\Installer::suggestedDataDir();
$inside = \BlaCloud\Installer::isInsideWebRoot($suggest) || str_starts_with($suggest, BLA_ROOT);
$fwd = !empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_FORWARDED_PROTO']);
$proxyOn = $setup['behind_proxy'] ?? $fwd;
$proxyIps = implode(', ', $setup['trusted_proxies'] ?? ($fwd ? [$_SERVER['REMOTE_ADDR'] ?? ''] : []));
?>
<h2>Where should your files live?</h2>
<p class="lead">Pick a folder on this server for everything you upload. It's safest <em>outside</em> the public website folder.</p>

<form method="post" class="form">
  <?= csrf_field() ?>
  <label class="field">
    <span>Name your cloud</span>
    <input name="instance_name" maxlength="64" value="<?= e($setup['instance_name'] ?? 'Haven') ?>">
    <small>Shown in your authenticator app and the browser tab.</small>
  </label>

  <label class="field">
    <span>Data folder</span>
    <input name="data_dir" value="<?= e($suggest) ?>" required spellcheck="false" autocomplete="off" class="mono">
    <small>We'll create it if it doesn't exist.</small>
  </label>
  <?php if ($inside): ?>
    <div class="note note--warn"><?= icon('alert') ?>
      <span>This folder is inside your website folder. Haven locks it down on Apache automatically. If you can, choose a folder one level up (outside <code>public_html</code>) — on Nginx this is strongly recommended.</span>
    </div>
  <?php endif; ?>

  <details class="advanced" <?= $proxyOn ? 'open' : '' ?>>
    <summary>Advanced: reverse proxy</summary>
    <label class="check-row">
      <input type="checkbox" name="behind_proxy" value="1" <?= $proxyOn ? 'checked' : '' ?>>
      <span>This site is behind a reverse proxy (Nginx, Caddy, Traefik, Cloudflare Tunnel…)</span>
    </label>
    <label class="field">
      <span>Proxy IP address(es)</span>
      <input name="proxy_ips" value="<?= e($proxyIps) ?>" placeholder="e.g. 127.0.0.1, 172.18.0.0/16" class="mono">
      <small>Only these addresses are trusted to report the visitor's real IP and HTTPS. <?= $fwd ? 'We detected a proxy at ' . e($_SERVER['REMOTE_ADDR'] ?? '') . '.' : '' ?></small>
    </label>
  </details>

  <div class="actions">
    <a class="btn btn--ghost" href="?step=database">Back</a>
    <button class="btn btn--primary" type="submit">Continue <?= icon('chevron') ?></button>
  </div>
</form>
