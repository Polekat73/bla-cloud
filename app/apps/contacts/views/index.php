<div class="page" data-contacts>
  <div class="page-head">
    <div><p class="eyebrow">Your account</p><h1>Contacts</h1></div>
    <div class="files__actions">
      <form class="search" method="get" action="<?= e(url('contacts')) ?>">
        <input type="hidden" name="r" value="contacts">
        <?= icon('search') ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search contacts" aria-label="Search contacts">
      </form>
      <button type="button" class="btn btn--primary" data-open="dlg-contact" data-new-contact><?= icon('plus') ?> Add contact</button>
    </div>
  </div>

  <?php if (!$contacts): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('contact') ?></div>
      <h2><?= $q !== '' ? 'No matches' : 'No contacts yet' ?></h2>
      <p class="muted"><?= $q !== '' ? 'Try a different search.' : 'Add people here, or sync them in from your phone (Settings > Sync).' ?></p>
    </div>
  <?php else: ?>
    <section class="card card--flush">
      <div class="table-wrap">
        <table class="file-table">
          <thead><tr><th></th><th>Name</th><th>Phone</th><th>Email</th></tr></thead>
          <tbody>
          <?php foreach ($contacts as $c): ?>
            <tr class="contact-row" data-contact
                data-id="<?= (int) $c['id'] ?>"
                data-given="<?= e($c['given']) ?>"
                data-family="<?= e($c['family']) ?>"
                data-note="<?= e($c['note']) ?>"
                data-photo="<?= e((string) $c['photo']) ?>"
                data-phones='<?= e(json_encode($c['phones'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                data-emails='<?= e(json_encode($c['emails'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                data-street="<?= e($c['address']['street']) ?>" data-city="<?= e($c['address']['city']) ?>"
                data-region="<?= e($c['address']['region']) ?>" data-postal="<?= e($c['address']['postal']) ?>"
                data-country="<?= e($c['address']['country']) ?>">
              <td class="col-check">
                <?php if ($c['photo']): ?><img class="avatar avatar--sm" src="<?= e($c['photo']) ?>" alt="">
                <?php else: ?><span class="avatar avatar--sm" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($c['fn'] ?: '?', 0, 1))) ?></span><?php endif; ?>
              </td>
              <td><span class="fname"><?= e($c['fn'] ?: '(no name)') ?></span></td>
              <td><?= e($c['phones'][0]['value'] ?? '') ?></td>
              <td><?= e($c['emails'][0]['value'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
</div>

<dialog id="dlg-contact" class="dialog dialog--wide">
  <form method="post" action="<?= e(url('contacts.save')) ?>" class="form" enctype="multipart/form-data" data-contact-form>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="" data-f="id">
    <h2 data-contact-title>New contact</h2>
    <div class="contact-photo-row">
      <img data-photo-preview class="avatar avatar--md" hidden>
      <span data-photo-placeholder class="avatar avatar--md" aria-hidden="true"><?= icon('contact') ?></span>
      <label class="field"><span>Photo</span><input type="file" name="photo" accept="image/*" data-photo-input></label>
      <label class="check-row" data-remove-photo-row hidden><input type="checkbox" name="remove_photo" value="1"><span>Remove</span></label>
    </div>
    <div class="grid-2">
      <label class="field"><span>First name</span><input name="given" maxlength="128" data-f="given" autofocus></label>
      <label class="field"><span>Last name</span><input name="family" maxlength="128" data-f="family"></label>
    </div>
    <div class="field-legend">Phone numbers</div>
    <div data-phone-rows>
      <?php for ($i = 0; $i < 3; $i++): ?>
        <div class="grid-2">
          <select name="phone_type[]"><option value="cell">Mobile</option><option value="home">Home</option><option value="work">Work</option><option value="fax">Fax</option><option value="other">Other</option></select>
          <input name="phone_value[]" type="tel" maxlength="64" placeholder="Phone number">
        </div>
      <?php endfor; ?>
    </div>
    <div class="field-legend">Emails</div>
    <div data-email-rows>
      <?php for ($i = 0; $i < 2; $i++): ?>
        <div class="grid-2">
          <select name="email_type[]"><option value="home">Home</option><option value="work">Work</option><option value="other">Other</option></select>
          <input name="email_value[]" type="email" maxlength="255" placeholder="Email address">
        </div>
      <?php endfor; ?>
    </div>
    <div class="field-legend">Address</div>
    <label class="field"><span>Street</span><input name="street" maxlength="255" data-f="street"></label>
    <div class="grid-2">
      <label class="field"><span>City</span><input name="city" maxlength="128" data-f="city"></label>
      <label class="field"><span>State/Region</span><input name="region" maxlength="128" data-f="region"></label>
    </div>
    <div class="grid-2">
      <label class="field"><span>Postal code</span><input name="postal" maxlength="32" data-f="postal"></label>
      <label class="field"><span>Country</span><input name="country" maxlength="128" data-f="country"></label>
    </div>
    <label class="field"><span>Notes</span><textarea name="note" rows="2" data-f="note"></textarea></label>
    <div class="actions">
      <button type="button" class="btn btn--danger" data-delete-contact hidden><?= icon('trash') ?> Delete</button>
      <span class="actions-spacer"></span>
      <button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--primary" type="submit">Save</button>
    </div>
  </form>
</dialog>
<form method="post" action="<?= e(url('contacts.delete')) ?>" id="form-delete-contact" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="id" data-df="id">
</form>
