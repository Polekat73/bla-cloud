<?php
$labels = [
    'install.completed' => 'BLA-Cloud installed', 'login.success' => 'Signed in', 'login.failed' => 'Failed sign-in',
    'login.throttled' => 'Sign-in blocked (too many attempts)', 'login.password_ok_enroll_required' => 'Password OK, 2FA setup required',
    '2fa.failed' => 'Wrong verification code', '2fa.enabled' => 'Two-step verification turned on',
    '2fa.disabled' => 'Two-step verification turned off', '2fa.recovery_codes_generated' => 'New recovery codes created',
    'password.changed' => 'Password changed', 'logout' => 'Signed out', 'file.upload' => 'Uploaded',
    'file.download' => 'Downloaded', 'file.delete' => 'Deleted', 'file.rename' => 'Renamed', 'folder.create' => 'Created folder',
    'file.trash' => 'Moved to trash', 'file.replace' => 'Replaced (old version kept)', 'file.move' => 'Moved', 'file.copy' => 'Copied',
    'file.zip' => 'Downloaded as zip', 'trash.restore' => 'Restored from trash', 'trash.purge' => 'Deleted forever',
    'trash.empty' => 'Emptied trash', 'version.restore' => 'Restored a version', 'version.delete' => 'Deleted a version',
    'upgrade.database' => 'Database upgraded',
    'user.create' => 'Added a person', 'user.update' => 'Updated a person', 'user.delete' => 'Deleted a person',
    'user.invite' => 'Sent an invitation', 'user.reset_link' => 'Created a password reset link', 'user.reset_2fa' => 'Reset two-step verification',
    'invite.accepted' => 'Accepted invitation', 'password.reset' => 'Reset password by email link', 'password.reset_requested' => 'Requested a password reset',
    'settings.update' => 'Changed settings', 'share.user' => 'Shared with a person', 'share.link' => 'Created a public link',
    'share.delete' => 'Stopped sharing', 'share.email' => 'Emailed a link', 'share.leave' => 'Left a share', 'share.link_unlocked' => 'Link password entered', 'upgrade.code' => 'BLA-Cloud updated', 'maintenance' => 'Automatic cleanup',
    'apppassword.created' => 'New app password', 'apppassword.revoked' => 'App password revoked',
    'backup.created' => 'Backup created', 'backup.failed' => 'Backup failed', 'backup.verified' => 'Backup verified',
    'backup.restored' => 'Restored from backup', 'backup.enabled' => 'Backups turned on', 'backup.disabled' => 'Backups turned off',
    'backup.deleted' => 'Backup deleted', 'backup.passphrase_rotated' => 'New backup passphrase set',
    'encryption.enabled' => 'Encryption turned on', 'encryption.paused' => 'Encryption turned off',
    'encryption.migrated_encrypt' => 'Encrypted existing files', 'encryption.migrated_decrypt' => 'Decrypted existing files',
];
$bad = ['login.failed', 'login.throttled', '2fa.failed', '2fa.disabled', 'user.delete', 'user.reset_2fa', 'apppassword.revoked',
    'backup.failed', 'backup.disabled', 'encryption.paused'];
?>
<?php if (!$activity): ?>
  <p class="muted">No activity yet.</p>
<?php else: ?>
<div class="table-wrap">
<table class="log-table">
  <thead><tr><th>When (UTC)</th><?php if (!empty($showUser)): ?><th>User</th><?php endif; ?><th>What</th><th>Details</th><th>IP address</th></tr></thead>
  <tbody>
  <?php foreach ($activity as $a): ?>
    <tr class="<?= in_array($a['action'], $bad, true) ? 'is-bad' : '' ?>">
      <td class="nowrap"><?= e(substr($a['created_at'], 0, 16)) ?></td>
      <?php if (!empty($showUser)): ?><td><?= e($a['username'] ?? '—') ?></td><?php endif; ?>
      <td><?= e($labels[$a['action']] ?? $a['action']) ?></td>
      <td class="log-detail"><?= e($a['detail']) ?></td>
      <td class="mono nowrap"><?= e($a['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
