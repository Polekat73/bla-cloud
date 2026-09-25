<div class="page page--narrow">
  <p class="eyebrow">Security</p>
  <h1>Save your recovery codes</h1>
  <p class="lead">If you ever lose your phone, each of these codes lets you sign in <strong>once</strong>.
    Store them somewhere safe — a password manager, or printed and kept with important papers.
    <strong>You won't be able to see them again.</strong></p>

  <div class="codes" data-codes>
    <?php foreach ($codes as $c): ?><code><?= e($c) ?></code><?php endforeach; ?>
  </div>

  <div class="actions actions--left">
    <button type="button" class="btn btn--secondary" data-download-codes
            data-filename="<?= e(\BlaCloud\Config::get('instance_name', 'BLA-Cloud')) ?>-recovery-codes.txt"><?= icon('download') ?> Download</button>
    <button type="button" class="btn btn--ghost" data-copy="<?= e(implode("\n", $codes)) ?>"><?= icon('copy') ?> Copy</button>
    <button type="button" class="btn btn--ghost" data-print><?= icon('file') ?> Print</button>
  </div>

  <form method="post" action="<?= e(url('recovery-codes')) ?>" class="form confirm-save">
    <?= csrf_field() ?>
    <label class="check-row">
      <input type="checkbox" required data-enable-next>
      <span>I've saved my recovery codes somewhere safe</span>
    </label>
    <button class="btn btn--primary" type="submit" disabled data-next>Continue to my files <?= icon('chevron') ?></button>
  </form>
</div>
