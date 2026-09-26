<?php
/** @var array $project @var array $channels @var ?array $channel @var array $messages @var array $members
 *  @var array $projectMembers @var bool $isOwner @var array $me */
$lastId = $messages ? (int) end($messages)['id'] : 0;
$isCreator = $channel && (int) $channel['created_by'] === (int) $me['id'];
?>
<div class="page" data-chat
     data-project-id="<?= (int) $project['id'] ?>"
     data-channel-id="<?= (int) ($channel['id'] ?? 0) ?>"
     data-last-id="<?= $lastId ?>"
     data-me-id="<?= (int) $me['id'] ?>"
     data-messages-url="<?= e(url('projects.chat.messages')) ?>"
     data-post-url="<?= e(url('projects.chat.post')) ?>">
  <div class="page-head">
    <div><p class="eyebrow"><a href="<?= e(url('projects.show', ['id' => $project['id']])) ?>"><?= e($project['name']) ?></a></p><h1>Chat</h1></div>
  </div>

  <div class="chat-layout">
    <aside class="chat-channels">
      <?php foreach ($channels as $c): ?>
        <a class="chat-channel-link <?= $channel && (int) $c['id'] === (int) $channel['id'] ? 'is-active' : '' ?>"
           href="<?= e(url('projects.chat', ['project_id' => $project['id'], 'channel_id' => $c['id']])) ?>">#<?= e($c['name']) ?></a>
      <?php endforeach; ?>
      <form method="post" action="<?= e(url('projects.chat.channel.create')) ?>" class="chat-new-channel">
        <?= csrf_field() ?>
        <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
        <input name="name" maxlength="64" placeholder="New channel">
        <button class="btn btn--ghost btn--sm" type="submit"><?= icon('plus') ?></button>
      </form>
    </aside>

    <?php if (!$channel): ?>
      <div class="empty"><div class="empty__icon"><?= icon('chat') ?></div><h2>No channels yet</h2>
        <p class="muted">Create one on the left to start talking with this project's team.</p></div>
    <?php else: ?>
    <div class="chat-main">
      <div class="chat-head">
        <h2>#<?= e($channel['name']) ?></h2>
        <div class="files__actions">
          <button type="button" class="btn btn--ghost btn--sm" data-open="dlg-channel-members"><?= icon('users') ?> Members</button>
          <?php if ($isCreator || $isOwner): ?>
          <form method="post" action="<?= e(url('projects.chat.channel.delete')) ?>" data-confirm="Delete #<?= e($channel['name']) ?>? Its messages will be gone for everyone.">
            <?= csrf_field() ?>
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <input type="hidden" name="channel_id" value="<?= (int) $channel['id'] ?>">
            <button class="btn btn--ghost btn--sm btn--danger" type="submit"><?= icon('trash') ?></button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <ul class="chat-messages" data-message-list>
        <?php foreach ($messages as $m): ?>
          <li class="chat-message <?= (int) $m['user_id'] === (int) $me['id'] ? 'is-own' : '' ?>" data-message data-message-id="<?= (int) $m['id'] ?>">
            <div class="chat-message__meta"><?= e($m['display_name'] ?: $m['username']) ?> · <?= e(human_time((int) strtotime($m['created_at'] . ' UTC'))) ?></div>
            <div class="chat-message__body"><?= e($m['body']) ?></div>
            <?php if ((int) $m['user_id'] === (int) $me['id'] || $isOwner): ?>
              <button type="button" class="chat-message__del" data-delete-message data-message-id="<?= (int) $m['id'] ?>" title="Delete"><?= icon('x') ?></button>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="post" action="<?= e(url('projects.chat.post')) ?>" class="chat-compose" data-chat-form>
        <?= csrf_field() ?>
        <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
        <input type="hidden" name="channel_id" value="<?= (int) $channel['id'] ?>">
        <textarea name="body" placeholder="Message #<?= e($channel['name']) ?>" required autofocus></textarea>
        <button class="btn btn--primary" type="submit">Send</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<form method="post" action="<?= e(url('projects.chat.message.delete')) ?>" id="form-delete-message" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
  <input type="hidden" name="channel_id" value="<?= (int) ($channel['id'] ?? 0) ?>">
  <input type="hidden" name="message_id" data-df="message_id">
</form>

<?php if ($channel): ?>
<dialog id="dlg-channel-members" class="dialog">
  <h2>#<?= e($channel['name']) ?> members</h2>
  <ul class="member-list">
    <?php foreach ($members as $m): ?>
      <li>
        <span class="avatar avatar--sm"><?= e(mb_strtoupper(mb_substr($m['display_name'] ?: $m['username'], 0, 1))) ?></span>
        <span><?= e($m['display_name'] ?: $m['username']) ?><?php if ((int) $m['id'] === (int) $channel['created_by']): ?> <small class="muted">(creator)</small><?php endif; ?></span>
        <?php if (($isCreator || $isOwner) && (int) $m['id'] !== (int) $channel['created_by']): ?>
          <form method="post" action="<?= e(url('projects.chat.member.remove')) ?>" data-confirm="Remove <?= e($m['username']) ?> from #<?= e($channel['name']) ?>?">
            <?= csrf_field() ?>
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <input type="hidden" name="channel_id" value="<?= (int) $channel['id'] ?>">
            <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
            <button class="icon-btn" type="submit" title="Remove"><?= icon('x') ?></button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <form method="post" action="<?= e(url('projects.chat.member.add')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
    <input type="hidden" name="channel_id" value="<?= (int) $channel['id'] ?>">
    <label class="field"><span>Invite a project member by username</span>
      <input name="username" list="dlg-channel-members-list" maxlength="64" required></label>
    <datalist id="dlg-channel-members-list">
      <?php foreach ($projectMembers as $m): ?><option value="<?= e($m['username']) ?>"><?php endforeach; ?>
    </datalist>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Close</button>
      <button class="btn btn--primary" type="submit">Invite</button></div>
  </form>
</dialog>
<?php endif; ?>
