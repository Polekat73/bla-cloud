<?php if (!empty($newLink)): ?>
  <div class="card linkbox">
    <div class="card__head">
      <span class="card__icon is-ok"><?= icon('key') ?></span>
      <div><h2><?= e($newLink['label']) ?></h2>
        <p class="muted">Send this to the person privately (email, message…). Anyone with the link can use it, and it's only shown once.</p></div>
    </div>
    <div class="copyrow">
      <input class="mono" readonly value="<?= e($newLink['url']) ?>" data-select-on-focus aria-label="Link">
      <button type="button" class="btn btn--secondary" data-copy="<?= e($newLink['url']) ?>"><?= icon('copy') ?> Copy</button>
    </div>
  </div>
<?php endif; ?>
