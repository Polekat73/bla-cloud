<?php
/**
 * BLA-Cloud unit tests. Run from the command line:  php tests/run.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
restore_exception_handler();

use BlaCloud\AiTokens;
use BlaCloud\AppPasswords;
use BlaCloud\Apps;
use BlaCloud\Apps\Projects\ProjectsData;
use BlaCloud\Backup;
use BlaCloud\Mcp\Tools as McpTools;
use BlaCloud\Database;
use BlaCloud\Encryption;
use BlaCloud\FileCrypto;
use BlaCloud\Dav\CalendarBackend;
use BlaCloud\Dav\ContactsBackend;
use BlaCloud\Dav\Ical;
use BlaCloud\Dav\Provisioning;
use BlaCloud\Dav\Vcard;
use BlaCloud\Dav\Vobject;
use BlaCloud\Dav\Xml;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Storage;
use BlaCloud\StorageException;
use BlaCloud\Totp;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  \033[32m✓\033[0m " : "  \033[31m✗\033[0m ") . $name . "\n";
}
function throws(callable $fn): bool
{
    try {
        $fn();
    } catch (\Throwable) {
        return true;
    }
    return false;
}

echo "TOTP (RFC 6238 test vectors)\n";
$secret = Totp::base32Encode('12345678901234567890');
check('base32 round-trip', Totp::base32Decode($secret) === '12345678901234567890');
foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471',
          1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $t => $expected) {
    check("t=$t -> $expected", Totp::codeAt($secret, intdiv($t, 30), 'sha1', 8) === $expected);
}
$now = 1_700_000_000;
$code = Totp::codeAt($secret, Totp::currentStep($now));
check('verify current code', Totp::verify($secret, $code, 0, $now) === Totp::currentStep($now));
check('accept 1 step clock drift', Totp::verify($secret, Totp::codeAt($secret, Totp::currentStep($now) - 1), 0, $now) !== null);
check('reject 2 steps drift', Totp::verify($secret, Totp::codeAt($secret, Totp::currentStep($now) - 2), 0, $now) === null);
check('block replay of used step', Totp::verify($secret, $code, Totp::currentStep($now), $now) === null);
check('reject wrong code', Totp::verify($secret, '000000', 0, $now) === null || $code === '000000');
check('accept spaces "123 456" style', Totp::verify($secret, substr($code, 0, 3) . ' ' . substr($code, 3), 0, $now) !== null);
check('otpauth URI', str_starts_with(Totp::uri($secret, 'me', 'BLA-Cloud'), 'otpauth://totp/BLA-Cloud:me?secret='));

echo "Path safety\n";
check('normalize simple', Storage::normalize('a/b/c') === '/a/b/c');
check('normalize slashes', Storage::normalize('//a///b/') === '/a/b');
check('root is empty string', Storage::normalize('/') === '');
check('unicode names ok', Storage::normalize('/Fotos/Été 2026') === '/Fotos/Été 2026');
foreach (['../etc/passwd', 'a/../../b', '..', "a\0b", 'a\\..\\b', 'x/.htaccess', '.user.ini', " lead", "tab\tname"] as $bad) {
    check('rejects ' . json_encode($bad), throws(fn () => Storage::normalize($bad)));
}
check('single dot segments dropped', Storage::normalize('./a/./b') === '/a/b');

echo "Real filesystem sandbox\n";
$tmp = sys_get_temp_dir() . '/bla-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/users/1/files', 0700, true);
mkdir($tmp . '/outside', 0700);
file_put_contents($tmp . '/outside/secret.txt', 'secret');
symlink($tmp . '/outside', $tmp . '/users/1/files/link');
$ref = new ReflectionClass(\BlaCloud\Config::class);
$prop = $ref->getProperty('data');
$testConfig = [
    'data_dir' => $tmp, 'app_key' => base64_encode(random_bytes(32)),
    'db' => ['driver' => 'sqlite', 'path' => $tmp . '/test.sqlite'], 'versions_keep' => 3,
];
$prop->setValue(null, $testConfig);
$pdo = \BlaCloud\Database::connectWith($testConfig['db']);
\BlaCloud\Schema::create($pdo, 'sqlite');
check('schema at latest version', \BlaCloud\Schema::currentVersion($pdo) === \BlaCloud\Schema::VERSION);
$pdo->exec("INSERT INTO bla_users (id, username, password_hash, created_at) VALUES (1, 'u', 'x', '2026-01-01 00:00:00')");
$F = fn (string $rel) => $tmp . '/users/1/files' . $rel;

$fs = new Storage(1);
check('symlink hidden from listing', !in_array('link', array_column($fs->list(''), 'name'), true));
check('symlink cannot be opened', throws(fn () => $fs->abs('/link/secret.txt')));
$fs->mkdir('', 'Photos');
check('mkdir works', is_dir($F('/Photos')));
check('mkdir duplicate rejected', throws(fn () => $fs->mkdir('', 'Photos')));
check('rename works', $fs->rename('/Photos', 'Pictures') === '/Pictures' && is_dir($F('/Pictures')));
check('rename to reserved rejected', throws(fn () => $fs->rename('/Pictures', '.htaccess')));
check('cannot delete root', throws(fn () => $fs->delete('')));
file_put_contents($F('/a.txt'), 'x');
check('unique name', $fs->uniqueName('', 'a.txt') === 'a (2).txt');
check('unique name for dotfile', $fs->uniqueName('', '.env') === '.env');

echo "Versions\n";
$v = new \BlaCloud\Versions($fs);
$up = function (string $content) use ($fs, $tmp) {
    $t = tempnam($tmp, 'up');
    file_put_contents($t, $content);
    return $fs->receiveChunk(bin2hex(random_bytes(10)), 0, $t, true, '/Pictures', 'note.txt', strlen($content));
};
$r1 = $up('one');
$r2 = $up('two');
check('first upload is new', $r1['replaced'] === false && $r1['path'] === '/Pictures/note.txt');
check('same name replaces and keeps version', $r2['replaced'] === true && file_get_contents($F('/Pictures/note.txt')) === 'two');
check('one version listed', count($v->list('/Pictures/note.txt')) === 1);
$up('three'); $up('four'); $up('five');
check('pruned to versions_keep (3)', count($v->list('/Pictures/note.txt')) === 3);
[, $blob] = $v->blobFor((int) $v->list('/Pictures/note.txt')[0]['id']);
check('newest version holds previous content', file_get_contents($blob) === 'four');
$v->restore((int) $v->list('/Pictures/note.txt')[0]['id']);
check('restore brings old content back', file_get_contents($F('/Pictures/note.txt')) === 'four');
check('replaced content kept as version', file_get_contents($v->blobFor((int) $v->list('/Pictures/note.txt')[0]['id'])[1]) === 'five');
$fs->rename('/Pictures', 'Album');
check('versions follow folder rename', count($v->list('/Album/note.txt')) === 3 && count($v->list('/Pictures/note.txt')) === 0);

echo "Move & copy\n";
$fs->mkdir('', 'Archive');
$moved = $fs->move('/Album', '/Archive');
check('move folder', $moved === '/Archive/Album' && is_file($F('/Archive/Album/note.txt')) && !is_dir($F('/Album')));
check('versions follow move', count($v->list('/Archive/Album/note.txt')) === 3);
check('cannot move folder into itself', throws(fn () => $fs->move('/Archive', '/Archive/Album')));
check('cannot copy folder into itself', throws(fn () => $fs->copy('/Archive', '/Archive')));
$copy = $fs->copy('/Archive/Album', '');
check('copy folder', $copy === '/Album' && file_get_contents($F('/Album/note.txt')) === 'four');
check('copy again gets unique name', $fs->copy('/Archive/Album', '') === '/Album (2)');
check('copy does not duplicate versions', count($v->list('/Album/note.txt')) === 0);
check('move to missing folder fails', throws(fn () => $fs->move('/a.txt', '/Nope')));
check('move rejects traversal', throws(fn () => $fs->move('/a.txt', '/../outside')));

echo "Trash\n";
$trash = new \BlaCloud\Trash($fs);
$fs->delete('/Archive');
check('delete moves to trash', !is_dir($F('/Archive')) && count($trash->list()) === 1);
check('trashed versions hidden', count($v->list('/Archive/Album/note.txt')) === 0);
$fs->mkdir('', 'Archive'); // something new takes the old name
$item = $trash->list()[0];
$back = $trash->restore((int) $item['id']);
check('restore with name clash gets unique name', $back === '/Archive (2)' && is_file($F('/Archive (2)/Album/note.txt')));
check('versions come back with restore', count($v->list('/Archive (2)/Album/note.txt')) === 3);
file_put_contents($F('/Album/deep.txt'), 'deep');
$fs->delete('/Album/deep.txt');
$fs->delete('/Album');
$deep = array_values(array_filter($trash->list(), fn ($r) => $r['name'] === 'deep.txt'))[0];
$trash->restore((int) $deep['id']);
check('restore recreates missing parent folder', is_file($F('/Album/deep.txt')));
$fs->delete('/Archive (2)');
$blobs = count(glob($tmp . '/users/1/versions/*'));
$n = $trash->empty();
check('empty trash removes items', $n === 2 && count($trash->list()) === 0);
check('empty trash removes their versions', count(glob($tmp . '/users/1/versions/*')) === $blobs - 3);
check('outside file untouched', is_file($tmp . '/outside/secret.txt'));

file_put_contents($F('/old.txt'), 'old');
$fs->delete('/old.txt');
\BlaCloud\Database::run("UPDATE bla_trash SET deleted_at = '2000-01-01 00:00:00'");
check('expired trash purged automatically', $trash->purgeExpired() === 1 && count($trash->list()) === 0);

echo "Search, zip, previews\n";
file_put_contents($F('/Album/Holiday Photo.TXT'), 'x');
$names = array_column($fs->search('photo'), 'name');
check('search is case-insensitive and recursive', in_array('Holiday Photo.TXT', $names, true));
check('search skips symlinked folders', !in_array('secret.txt', array_column($fs->search('secret'), 'name'), true));
if (\BlaCloud\Zipper::available()) {
    [$zipFile, $zipName] = \BlaCloud\Zipper::build($fs, ['/Album']);
    $z = new ZipArchive(); $z->open($zipFile);
    check('zip contains folder files', $z->locateName('Album/deep.txt') !== false && $zipName === 'Album.zip');
    $z->close(); unlink($zipFile);
}
check('viewer: image', \BlaCloud\FileSender::viewer('a.JPG') === 'image');
check('viewer: pdf', \BlaCloud\FileSender::viewer('a.pdf') === 'pdf');
check('viewer: html shown as text only', \BlaCloud\FileSender::viewer('page.html') === 'text' && \BlaCloud\FileSender::inlineType('x.html') === 'text/plain');
check('viewer: exe none', \BlaCloud\FileSender::viewer('setup.exe') === null);
if (extension_loaded('gd')) {
    $img = imagecreatetruecolor(800, 400);
    imagefill($img, 0, 0, imagecolorallocate($img, 186, 141, 53));
    imagejpeg($img, $F('/Album/pic.jpg'));
    [$thumb, $mime] = (new \BlaCloud\Thumbnails($fs))->get('/Album/pic.jpg', 256);
    $info = getimagesize($thumb);
    check('thumbnail generated at 256px', $info[0] === 256 && $info[1] === 128);
    check('thumbnail not inside user files', !str_starts_with($thumb, $fs->root()));
    file_put_contents($F('/Album/fake.jpg'), '<?php echo 1;');
    check('broken image gives clean error', throws(fn () => (new \BlaCloud\Thumbnails($fs))->get('/Album/fake.jpg', 256)));
}
echo "Sharing & scopes\n";
$pdo2 = \BlaCloud\Database::pdo();
$pdo2->exec("INSERT INTO bla_users (id, username, display_name, password_hash, created_at) VALUES (2, 'anna', 'Anna', 'x', '2026-01-01 00:00:00')");
$fs->mkdir('', 'Family');
file_put_contents($F('/Family/plan.txt'), 'plan');
file_put_contents($F('/secret.txt'), 'top secret');
check('cannot share with yourself', throws(fn () => \BlaCloud\Shares::shareWithUser($fs, '/Family', 1, 'view')));
check('cannot share whole root', throws(fn () => \BlaCloud\Shares::shareWithUser($fs, '', 2, 'view')));
[$sid] = \BlaCloud\Shares::shareWithUser($fs, '/Family', 2, 'view');
$share = \BlaCloud\Shares::findForRecipient($sid, 2);
check('recipient can find share', $share !== null && \BlaCloud\Shares::findForRecipient($sid, 1) === null);
$sc = \BlaCloud\Scope::forShare($share, ['id' => 2]);
check('scope maps sub-path to owner path', $sc->toOwner('/plan.txt') === '/Family/plan.txt');
check('scope blocks ../ escape', throws(fn () => $sc->toOwner('/../secret.txt')));
check('scope lists only shared folder', array_column($sc->list(''), 'path') === ['/plan.txt']);
check('view share cannot edit', !$sc->can('edit') && !$sc->can('upload') && $sc->can('view'));
check('search stays inside share', array_column($sc->search('secret'), 'name') === []);
$fs->rename('/Family', 'Household');
check('share follows rename', \BlaCloud\Shares::findForRecipient($sid, 2)['path'] === '/Household');
[$fid] = \BlaCloud\Shares::shareWithUser($fs, '/secret.txt', 2, 'edit');
$fsc = \BlaCloud\Scope::forShare(\BlaCloud\Shares::findForRecipient($fid, 2), ['id' => 2]);
check('file share: only the file itself', $fsc->toOwner('') === '/secret.txt' && throws(fn () => $fsc->toOwner('/other.txt')));
check('file share lists one item', count($fsc->list('')) === 1 && $fsc->list('')[0]['name'] === 'secret.txt');

echo "Public links\n";
[$lid, $tok] = \BlaCloud\Shares::createLink($fs, '/Household', 'upload', 'hunter22', null, 'Test');
$link = \BlaCloud\Shares::findLink($tok);
check('link found by token', $link !== null && (int) $link['id'] === $lid);
check('link token stored hashed', !str_contains(json_encode($pdo2->query('SELECT token_hash, password_hash FROM bla_shares')->fetchAll()), $tok));
check('link password hashed', password_verify('hunter22', $link['password_hash']));
check('wrong token finds nothing', \BlaCloud\Shares::findLink(strrev($tok)) === null);
check('single-file links are view-only', throws(fn () => \BlaCloud\Shares::createLink($fs, '/secret.txt', 'edit', null, null, '')));
check('short link password rejected', throws(fn () => \BlaCloud\Shares::createLink($fs, '/Household', 'view', '123', null, '')));
check('past expiry rejected', throws(fn () => \BlaCloud\Shares::parseExpiry('2001-01-01')));
\BlaCloud\Database::run("UPDATE bla_shares SET expires_at = '2001-01-01 00:00:00' WHERE id = ?", [$lid]);
check('expired link stops working', \BlaCloud\Shares::findLink($tok) === null);
\BlaCloud\Database::run('UPDATE bla_shares SET expires_at = NULL WHERE id = ?', [$lid]);
$drop = \BlaCloud\Scope::forLink(\BlaCloud\Shares::findLink($tok), $tok);
check('upload link can upload + view, not edit', $drop->can('upload') && $drop->can('list') && !$drop->can('edit'));
check('drop perms: upload only', \BlaCloud\Shares::can('drop', 'upload') && !\BlaCloud\Shares::can('drop', 'list'));
\BlaCloud\Settings::save(['links_max_days' => 7]);
check('max link lifetime enforced', throws(fn () => \BlaCloud\Shares::parseExpiry(gmdate('Y-m-d', time() + 30 * 86400))) && throws(fn () => \BlaCloud\Shares::parseExpiry('')));
\BlaCloud\Settings::save(['links_max_days' => 0, 'links_enabled' => false]);
check('links off: token stops working', \BlaCloud\Shares::findLink($tok) === null);
\BlaCloud\Settings::save(['links_enabled' => true]);
$fs->delete('/Household');
check('deleting item removes its shares', \BlaCloud\Shares::findLink($tok) === null && \BlaCloud\Shares::findForRecipient($sid, 2) === null);

echo "Accounts & tokens\n";
$t1 = \BlaCloud\Tokens::create(2, 'reset', 1);
check('token valid', \BlaCloud\Tokens::find($t1, 'reset') !== null);
check('token kind must match', \BlaCloud\Tokens::find($t1, 'invite') === null);
$t2 = \BlaCloud\Tokens::create(2, 'reset', 1);
check('new token cancels old one', \BlaCloud\Tokens::find($t1, 'reset') === null && \BlaCloud\Tokens::find($t2, 'reset') !== null);
$row = \BlaCloud\Tokens::find($t2, 'reset');
check('token single use', \BlaCloud\Tokens::consume((int) $row['id']) && !\BlaCloud\Tokens::consume((int) $row['id']) && \BlaCloud\Tokens::find($t2, 'reset') === null);
$t3 = \BlaCloud\Tokens::create(2, 'invite', 1);
\BlaCloud\Database::run("UPDATE bla_tokens SET expires_at = '2001-01-01 00:00:00'");
check('expired token rejected', \BlaCloud\Tokens::find($t3, 'invite') === null);
$pdo2->exec("UPDATE bla_users SET is_admin = 1 WHERE id = 1");
$me = \BlaCloud\Users::find(1);
check('last admin cannot be deleted', throws(fn () => \BlaCloud\Users::delete($me, 99)));
check('cannot demote yourself', throws(fn () => \BlaCloud\Users::update($me, ['is_admin' => false, 'is_active' => true], 1)));
check('duplicate username rejected', throws(fn () => \BlaCloud\Users::create('ANNA', '', '', false, 0, null)));
$newId = \BlaCloud\Users::create('ben', 'Ben', 'ben@example.com', false, 0, null);
check('invited user has no usable password', \BlaCloud\Users::status(\BlaCloud\Users::find($newId)) === 'invited');
check('duplicate email rejected', throws(fn () => \BlaCloud\Users::create('ben2', '', 'BEN@example.com', false, 0, null)));
mkdir($tmp . '/users/' . $newId . '/files', 0700, true);
file_put_contents($tmp . '/users/' . $newId . '/files/x.txt', 'x');
\BlaCloud\Users::delete(\BlaCloud\Users::find($newId), 1);
check('deleting a user removes their data', !is_dir($tmp . '/users/' . $newId) && \BlaCloud\Users::find($newId) === null);
[$mt, $mh] = \BlaCloud\Mailer::render('Hi <b>', ['Line & <script>'], 'Go', 'https://x.test/?a=1&b=2');
check('email HTML is escaped', str_contains($mh, 'Line &amp; &lt;script&gt;') && str_contains($mh, 'a=1&amp;b=2') && !str_contains($mh, '<script>'));

echo "Sync (WebDAV/CalDAV/CardDAV)\n";
Provisioning::seedDefaults(1); // user 1 was inserted with raw SQL above, so it has none yet
check('seeding gives a default calendar', \BlaCloud\Database::one(
    "SELECT id FROM bla_calendars WHERE user_id = 1 AND uri = 'personal'") !== null);
check('seeding gives a default address book', \BlaCloud\Database::one(
    "SELECT id FROM bla_addressbooks WHERE user_id = 1 AND uri = 'contacts'") !== null);
Provisioning::seedDefaults(1); // must not duplicate on a second call
check('seeding defaults twice does not duplicate', (int) \BlaCloud\Database::one(
    "SELECT COUNT(*) AS n FROM bla_calendars WHERE user_id = 1")['n'] === 1);

[$apId, $apSecret] = AppPasswords::create(1, 'Test phone');
check('app password verifies with the right secret', AppPasswords::verify('ben-admin-does-not-exist', $apSecret) === null);
$_SERVER['REMOTE_ADDR'] = '198.51.100.1'; // fresh IP so earlier throttling in this run doesn't interfere
$adminRow = \BlaCloud\Users::find(1);
check('app password verifies for the right user', (AppPasswords::verify($adminRow['username'], $apSecret)['id'] ?? null) === 1);
check('app password rejects the wrong secret', AppPasswords::verify($adminRow['username'], 'not-the-secret') === null);
check('account password does not work as an app password', AppPasswords::verify($adminRow['username'], 'quiet river morning bread') === null);
AppPasswords::revoke(1, $apId);
check('revoked app password stops working', AppPasswords::verify($adminRow['username'], $apSecret) === null);
check('format() groups into dashes', AppPasswords::format('abcdefgh') === 'abcd-efgh');

check('UID extracted', Vobject::extractUid("BEGIN:VEVENT\nUID:abc-123\nSUMMARY:Hi\nEND:VEVENT") === 'abc-123');
check('UID extraction handles folded lines', Vobject::extractUid("BEGIN:VEVENT\nUID:abc-\n 123\nEND:VEVENT") === 'abc-123');
check('missing UID returns null', Vobject::extractUid("BEGIN:VEVENT\nSUMMARY:Hi\nEND:VEVENT") === null);

check('propfind with no body means "everything"', Xml::propfindProps(null) === null);
$doc = new DOMDocument();
$doc->loadXML('<d:propfind xmlns:d="DAV:"><d:prop><d:displayname/><d:getetag/></d:prop></d:propfind>');
check('propfind parses requested prop names', Xml::propfindProps($doc) === ['{DAV:}displayname', '{DAV:}getetag']);
$allpropDoc = new DOMDocument();
$allpropDoc->loadXML('<d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>');
check('allprop means "everything" too', Xml::propfindProps($allpropDoc) === null);

echo "Calendar app (iCalendar read/write)\n";
$start = new DateTimeImmutable('2026-10-01 09:00:00', new DateTimeZone('UTC'));
$end = new DateTimeImmutable('2026-10-01 10:00:00', new DateTimeZone('UTC'));
$ics = Ical::buildEvent(['uid' => 'evt-1@test', 'summary' => 'Team sync', 'description' => "Line one\nLine two",
    'location' => 'Room 5, "The Lounge"', 'allDay' => false, 'start' => $start, 'end' => $end, 'remindMinutesBefore' => 30]);
check('built ICS has the right shape', str_contains($ics, 'BEGIN:VEVENT') && str_contains($ics, 'BEGIN:VALARM'));
$parsed = Ical::parseEvent($ics);
check('parsed summary round-trips', $parsed['summary'] === 'Team sync');
check('parsed multi-line description round-trips', $parsed['description'] === "Line one\nLine two");
check('parsed location with punctuation round-trips', $parsed['location'] === 'Room 5, "The Lounge"');
check('parsed start round-trips', $parsed['start']->format('Y-m-d H:i:s') === '2026-10-01 09:00:00');
check('parsed end round-trips', $parsed['end']->format('Y-m-d H:i:s') === '2026-10-01 10:00:00');
check('reminder fires 30 minutes before start', $parsed['remindAt']->format('Y-m-d H:i:s') === '2026-10-01 08:30:00');
check('event with no DTEND defaults to +1 hour', Ical::parseEvent(
    "BEGIN:VEVENT\nUID:x\nDTSTART:20261001T090000Z\nSUMMARY:No end\nEND:VEVENT")['end']->format('H:i') === '10:00');

$allDayIcs = Ical::buildEvent(['uid' => 'evt-2@test', 'summary' => 'Holiday', 'description' => '', 'location' => '',
    'allDay' => true, 'start' => new DateTimeImmutable('2026-12-25', new DateTimeZone('UTC')),
    'end' => new DateTimeImmutable('2026-12-26', new DateTimeZone('UTC')), 'remindMinutesBefore' => null]);
$allDayParsed = Ical::parseEvent($allDayIcs);
check('all-day event parses as all-day', $allDayParsed['allDay'] === true);
check('all-day event has no reminder when none set', $allDayParsed['remindAt'] === null);
check('not-iCalendar text has no VEVENT to parse', Ical::parseEvent('not an event') === null);

$cal = \BlaCloud\Database::one("SELECT id FROM bla_calendars WHERE user_id = 1 AND uri = 'personal'");
CalendarBackend::writeObject((int) $cal['id'], 'evt-1.ics', $ics);
$row = \BlaCloud\Database::one('SELECT * FROM bla_calendar_objects WHERE calendar_id = ? AND uri = ?', [$cal['id'], 'evt-1.ics']);
check('writeObject denormalises start/end/reminder', $row['start_at'] === '2026-10-01 09:00:00' && $row['remind_at'] === '2026-10-01 08:30:00');
check('writeObject bumped the calendar ctag', (int) \BlaCloud\Database::one('SELECT ctag FROM bla_calendars WHERE id = ?', [$cal['id']])['ctag'] === 2);
check('a due, unsent, future reminder is found by the reminder query', (bool) \BlaCloud\Database::one(
    "SELECT id FROM bla_calendar_objects WHERE remind_at <= ? AND reminder_sent_at IS NULL AND start_at > ?",
    ['2026-10-01 09:00:00', '2026-01-01 00:00:00']));
check('deleteObjectByUri removes it and bumps ctag again', CalendarBackend::deleteObjectByUri((int) $cal['id'], 'evt-1.ics')
    && (int) \BlaCloud\Database::one('SELECT ctag FROM bla_calendars WHERE id = ?', [$cal['id']])['ctag'] === 3);
check('deleting a missing object returns false', CalendarBackend::deleteObjectByUri((int) $cal['id'], 'nope.ics') === false);

echo "Contacts app (vCard read/write)\n";
$vcf = Vcard::buildContact(['uid' => 'c-1@test', 'given' => 'Ada', 'family' => 'Lovelace',
    'phones' => [['type' => 'cell', 'value' => '+1 555-0100'], ['type' => 'home', 'value' => '']],
    'emails' => [['type' => 'work', 'value' => 'ada@example.com']],
    'address' => ['street' => '1 Analytical Engine Way', 'city' => 'London', 'region' => '', 'postal' => 'SW1', 'country' => 'UK'],
    'note' => "VIP\nCall first"]);
check('built vCard has the right shape', str_contains($vcf, 'BEGIN:VCARD') && str_contains($vcf, 'FN:Ada Lovelace'));
$c = Vcard::parseContact($vcf);
check('parsed full name round-trips', $c['fn'] === 'Ada Lovelace');
check('blank phone value is dropped, real one kept', count($c['phones']) === 1 && $c['phones'][0]['value'] === '+1 555-0100');
check('email round-trips', $c['emails'][0]['value'] === 'ada@example.com');
check('address round-trips', $c['address']['city'] === 'London' && $c['address']['country'] === 'UK');
check('multi-line note round-trips', $c['note'] === "VIP\nCall first");
check('no photo means null, not a broken data URI', $c['photo'] === null);

$photoVcf = Vcard::buildContact(['given' => 'Grace', 'family' => 'Hopper', 'photoBase64' => base64_encode('not-really-a-jpeg'), 'photoType' => 'JPEG']);
check('long PHOTO line gets folded under 76 octets per line', max(array_map('strlen', explode("\r\n", $photoVcf))) < 76);
check('folded PHOTO still parses back out', str_starts_with(Vcard::parseContact($photoVcf)['photo'] ?? '', 'data:image/jpeg;base64,'));

$book = \BlaCloud\Database::one("SELECT id FROM bla_addressbooks WHERE user_id = 1 AND uri = 'contacts'");
ContactsBackend::writeObject((int) $book['id'], 'c-1.vcf', $vcf);
check('writeObject denormalises fn for listing/search', \BlaCloud\Database::one(
    'SELECT fn FROM bla_contacts WHERE addressbook_id = ? AND uri = ?', [$book['id'], 'c-1.vcf'])['fn'] === 'Ada Lovelace');
check('deleteObjectByUri removes the contact', ContactsBackend::deleteObjectByUri((int) $book['id'], 'c-1.vcf')
    && \BlaCloud\Database::one('SELECT id FROM bla_contacts WHERE addressbook_id = ? AND uri = ?', [$book['id'], 'c-1.vcf']) === null);

// Same LIKE query ContactsController::index() runs, exercised directly against SQLite (the default
// driver) — SQLite's LIKE has no default escape character, so a query without ESCAPE silently makes
// the \_ / \% escaping below into a no-op and '_'/'%' in a name behave as wildcards.
ContactsBackend::writeObject((int) $book['id'], 'ob.vcf', Vcard::buildContact(['given' => "O_Brien", 'family' => '']));
ContactsBackend::writeObject((int) $book['id'], 'os.vcf', Vcard::buildContact(['given' => 'OxBrien', 'family' => '']));
$likeSearch = fn (string $q) => \BlaCloud\Database::all(
    "SELECT fn FROM bla_contacts WHERE addressbook_id = ? AND fn LIKE ? ESCAPE '\\' ORDER BY fn",
    [$book['id'], '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%']
);
check('literal underscore in search matches only the literal name', array_column($likeSearch('O_Brien'), 'fn') === ['O_Brien']);
ContactsBackend::deleteObjectByUri((int) $book['id'], 'ob.vcf');
ContactsBackend::deleteObjectByUri((int) $book['id'], 'os.vcf');

echo "Backups\n";
$encTmp = sys_get_temp_dir() . '/bla-enc-test-' . bin2hex(random_bytes(4));
mkdir($encTmp);
file_put_contents($encTmp . '/plain.txt', str_repeat('The quick brown fox jumps over the lazy dog. ', 100_000)); // ~4.6MB, spans several chunks
Backup::encryptFile($encTmp . '/plain.txt', $encTmp . '/enc.bin', 'correct horse battery staple');
Backup::decryptFile($encTmp . '/enc.bin', $encTmp . '/roundtrip.txt', 'correct horse battery staple');
check('encrypt/decrypt round-trips a multi-chunk file exactly', hash_file('sha256', $encTmp . '/plain.txt') === hash_file('sha256', $encTmp . '/roundtrip.txt'));
check('wrong passphrase is rejected', throws(fn () => Backup::decryptFile($encTmp . '/enc.bin', $encTmp . '/bad.txt', 'wrong passphrase entirely')));
file_put_contents($encTmp . '/notabackup.bin', random_bytes(64));
check('a random file is rejected as not a backup', throws(fn () => Backup::decryptFile($encTmp . '/notabackup.bin', $encTmp . '/x.txt', 'anything')));
exec('rm -rf ' . escapeshellarg($encTmp));

// Backup::run() zips config/config.php by its real on-disk path (Config::path() isn't affected by the
// test's injected config array), so give it a throwaway one here and put back whatever was there before.
$realConfigPath = dirname(__DIR__) . '/config/config.php';
$hadRealConfig = is_file($realConfigPath);
$savedConfig = $hadRealConfig ? file_get_contents($realConfigPath) : null;
if (!$hadRealConfig) {
    file_put_contents($realConfigPath, "<?php\nreturn [];\n");
}
try {
    $backupDir = $tmp . '/backups';
    check('enable() rejects a short passphrase', throws(fn () => Backup::enable($backupDir, 'daily', 7, 'short')));
    Backup::enable($backupDir, 'daily', 3, 'a proper backup passphrase');
    check('enable() creates the folder', is_dir($backupDir));
    check('backups report enabled once configured', Backup::enabled());

    $r1 = Backup::run('manual');
    check('run() reports ok and a filename', $r1['ok'] === true && $r1['filename'] !== '');
    check('run() writes the encrypted file to disk', is_file($backupDir . '/' . $r1['filename']));
    check('run() records a row', count(Backup::list()) === 1);

    $v = Backup::verify((int) Backup::list()[0]['id'], 'a proper backup passphrase');
    check('verify() succeeds with the right passphrase', $v['ok'] === true);
    check('verify() fails with the wrong passphrase', throws(fn () => Backup::verify((int) Backup::list()[0]['id'], 'not it')));

    Backup::run('manual');
    Backup::run('manual');
    Backup::run('manual');
    check('prune keeps only the newest N backups', count(Backup::list()) === 3);

    // Restore round-trip: change a row, restore from an earlier backup, confirm the change is undone.
    Database::run("UPDATE bla_users SET display_name = 'Changed after backup' WHERE id = 1");
    $toRestore = (int) Backup::list()[array_key_last(Backup::list())]['id']; // oldest of the kept ones
    Backup::restore($toRestore, 'a proper backup passphrase');
    $after = Database::one('SELECT display_name FROM bla_users WHERE id = 1');
    check('restore() puts the database back', $after !== null && $after['display_name'] !== 'Changed after backup');
    check('restore() makes its own safety backup first', (bool) Database::one("SELECT id FROM bla_backups WHERE kind = 'safety'"));
    check('restore() leaves no stray .pre-restore folder behind', glob($tmp . '/users.pre-restore-*') === []);
    check('restore() brings the files back too', file_get_contents($F('/a.txt')) === 'x');

    check('rotatePassphrase() rejects a short one', throws(fn () => Backup::rotatePassphrase('short')));
    Backup::rotatePassphrase('a brand new passphrase');
    check('old backups need the old passphrase after rotating', throws(fn () => Backup::verify($toRestore, 'a brand new passphrase')));

    $countBefore = count(Backup::list());
    $victim = Database::one('SELECT filename FROM bla_backups WHERE id = ?', [$toRestore]);
    Backup::deleteFile($toRestore);
    check('deleteFile() removes the row and the file', count(Backup::list()) === $countBefore - 1
        && $victim !== null && !is_file($backupDir . '/' . $victim['filename']));
} finally {
    if ($hadRealConfig) {
        file_put_contents($realConfigPath, $savedConfig);
    } else {
        @unlink($realConfigPath);
    }
}

echo "Encryption at rest\n";
check('encryption starts off', !Encryption::enabled() && !Encryption::hasKey());
check('pause()/resume() without a key first is rejected', throws(fn () => Encryption::resume()));
check('enable() rejects a short passphrase', throws(fn () => Encryption::enable('short')));
Encryption::enable('a proper file encryption passphrase');
check('enable() turns it on', Encryption::enabled() && Encryption::hasKey());

// FileCrypto: the size header lets a caller learn the plaintext size without decrypting.
$cryptTmp = sys_get_temp_dir() . '/bla-crypt-test-' . bin2hex(random_bytes(4));
mkdir($cryptTmp);
$plain = str_repeat('x', 12345);
file_put_contents($cryptTmp . '/p.txt', $plain);
FileCrypto::encryptFile($cryptTmp . '/p.txt', $cryptTmp . '/p.enc', 'pw', 'MYMAGIC1');
check('hasMagic finds the marker', FileCrypto::hasMagic($cryptTmp . '/p.enc', 'MYMAGIC1'));
check('hasMagic is false for a plain file', !FileCrypto::hasMagic($cryptTmp . '/p.txt', 'MYMAGIC1'));
check('plaintextSize reads the size without a passphrase', FileCrypto::plaintextSize($cryptTmp . '/p.enc', 'MYMAGIC1') === 12345);
check('plaintextSize is null for a plain file', FileCrypto::plaintextSize($cryptTmp . '/p.txt', 'MYMAGIC1') === null);
exec('rm -rf ' . escapeshellarg($cryptTmp));

// Uploading through the normal Storage path while encryption is on: the file on disk must be
// unreadable as plain bytes, but resolvePlaintext() must hand back exactly what was uploaded.
$secretUp = function (string $content) use ($fs, $tmp) {
    $t = tempnam($tmp, 'up');
    file_put_contents($t, $content);
    return $fs->receiveChunk(bin2hex(random_bytes(10)), 0, $t, true, '', 'secret.bin', strlen($content));
};
$secretUp('secret family photos data, not plain on disk');
$encAbs = $F('/secret.bin');
check('the file on disk is not the plaintext', file_get_contents($encAbs) !== 'secret family photos data, not plain on disk');
check('Encryption recognises it as encrypted', Encryption::isEncryptedFile($encAbs));
check('contentSize() reports the real (plaintext) size', Encryption::contentSize($encAbs) === strlen('secret family photos data, not plain on disk'));
$resolved = Encryption::resolvePlaintext($encAbs);
check('resolvePlaintext() decrypts back to the original bytes', file_get_contents($resolved) === 'secret family photos data, not plain on disk');
check('resolvePlaintext() of a plain file returns the same path unchanged', Encryption::resolvePlaintext($F('/a.txt')) === $F('/a.txt'));

// Pausing stops new files from being encrypted, but doesn't touch what's already there.
Encryption::pause();
check('pause() turns enabled() off but keeps the key', !Encryption::enabled() && Encryption::hasKey());
$secretUp('now plain again, encryption is paused');
check('a file saved while paused is plain on disk', file_get_contents($F('/secret.bin')) === 'now plain again, encryption is paused');
Encryption::resume();
check('resume() turns it back on without needing the passphrase again', Encryption::enabled());

// Bulk migration over whatever's on disk for every account right now.
$r = Encryption::encryptExistingFiles();
check('encryptExistingFiles() converts the plain ones and skips the rest', $r['converted'] >= 1 && $r['errors'] === []);
check('files folder is now fully encrypted', Encryption::isEncryptedFile($F('/secret.bin')) && Encryption::isEncryptedFile($F('/a.txt')));
$r2 = Encryption::encryptExistingFiles();
check('running it again converts nothing new', $r2['converted'] === 0 && $r2['skipped'] > 0);
$r3 = Encryption::decryptExistingFiles();
check('decryptExistingFiles() puts everything back to plain', $r3['converted'] > 0
    && !Encryption::isEncryptedFile($F('/secret.bin')) && file_get_contents($F('/a.txt')) === 'x');

echo "Apps (plugin framework)\n";
Apps::resetCache();
$allApps = Apps::all();
check('discovers the calendar app', isset($allApps['calendar']) && $allApps['calendar']['name'] === 'Calendar');
check('discovers the contacts app', isset($allApps['contacts']) && $allApps['contacts']['name'] === 'Contacts');
check('both enabled by default', Apps::isEnabled('calendar') && Apps::isEnabled('contacts'));
check('unknown app is not enabled', !Apps::isEnabled('nope'));
check('routes() includes both apps', isset(Apps::routes()['calendar']) && isset(Apps::routes()['contacts.save']));
$navRoutes = array_column(Apps::navItems(), 'route');
check('navItems() includes both apps', in_array('calendar', $navRoutes, true) && in_array('contacts', $navRoutes, true));

Apps::setEnabled('calendar', false);
Apps::resetCache();
check('disabling persists', !Apps::isEnabled('calendar') && Apps::isEnabled('contacts'));
check('disabled app drops out of routes()', !isset(Apps::routes()['calendar']) && isset(Apps::routes()['contacts']));
check('disabled app drops out of navItems()', !in_array('calendar', array_column(Apps::navItems(), 'route'), true));

Apps::setEnabled('calendar', true);
Apps::resetCache();
check('re-enabling persists', Apps::isEnabled('calendar'));
check('setEnabled() on an unknown app throws', throws(fn () => Apps::setEnabled('nope', true)));

$badAppDir = dirname(__DIR__) . '/app/apps/__test_bad__';
mkdir($badAppDir);
file_put_contents($badAppDir . '/manifest.php', "<?php\nreturn ['id' => '__test_bad__', 'name' => 'Bad'];\n"); // missing 'routes'
Apps::resetCache();
try {
    check('a manifest missing required keys is skipped, not fatal', !isset(Apps::all()['__test_bad__']));
} finally {
    unlink($badAppDir . '/manifest.php');
    rmdir($badAppDir);
    Apps::resetCache();
}

echo "Projects app (Kanban boards)\n";
$pid = ProjectsData::create(1, 'Website Relaunch', 'Redo the marketing site');
check('create() seeds 3 default columns', count(ProjectsData::columns($pid)) === 3);
check('owner sees the project in forUser()', in_array($pid, array_column(ProjectsData::forUser(1), 'id'), true));
check('non-member does not see it', !in_array($pid, array_column(ProjectsData::forUser(2), 'id'), true));
check('requireMember() rejects a non-member', throws(fn () => ProjectsData::requireMember(2, $pid)));
check('non-owner cannot add members', throws(fn () => ProjectsData::addMember(2, $pid, 'anna')));
ProjectsData::addMember(1, $pid, 'anna');
check('member is added', count(ProjectsData::members($pid)) === 2);
check('adding the same member twice is rejected', throws(fn () => ProjectsData::addMember(1, $pid, 'anna')));
check('member (not owner) can now see the project', in_array($pid, array_column(ProjectsData::forUser(2), 'id'), true));

$cols = ProjectsData::columns($pid);
$todoCol = $cols[0]['id'];
$doneCol = $cols[2]['id'];
$extraCol = ProjectsData::createColumn(2, $pid, 'Blocked'); // a non-owner member can manage columns
check('member can create a column', count(ProjectsData::columns($pid)) === 4);

$taskId = ProjectsData::createTask(2, $pid, $todoCol, 'Write the copy', 'Homepage + pricing', 1, '2026-12-01');
check('task assignee must be a project member', throws(fn () => ProjectsData::createTask(1, $pid, $todoCol, 'x', '', 999)));
check('task created with the right column and assignee', ProjectsData::task($taskId)['assignee_id'] === 1);
ProjectsData::updateTask(1, $taskId, 'Write the homepage copy', 'Homepage only', 2, null);
check('updateTask() changes title/description/assignee', ProjectsData::task($taskId)['title'] === 'Write the homepage copy' && ProjectsData::task($taskId)['assignee_id'] === 2);
ProjectsData::moveTask(2, $taskId, $doneCol);
check('moveTask() changes the column', (int) ProjectsData::task($taskId)['column_id'] === $doneCol);
check('cannot delete the column now holding a task', throws(fn () => ProjectsData::deleteColumn(1, $doneCol)));
ProjectsData::deleteColumn(1, $extraCol); // empty, so this one is fine
check('an empty column can be deleted', count(ProjectsData::columns($pid)) === 3);

$commentId = ProjectsData::addComment(2, $taskId, 'First draft is up for review.');
check('addComment() works and lists back out', count(ProjectsData::comments($taskId)) === 1 && ProjectsData::comments($taskId)[0]['id'] === $commentId);
check('cannot remove the owner from their own project', throws(fn () => ProjectsData::removeMember(1, $pid, 1)));
ProjectsData::removeMember(1, $pid, 2);
check('removeMember() clears that member as an assignee too', ProjectsData::task($taskId)['assignee_id'] === null);
check('removed member no longer sees the project', !in_array($pid, array_column(ProjectsData::forUser(2), 'id'), true));
ProjectsData::deleteTask(1, $taskId);
check('deleteTask() removes it', ProjectsData::task($taskId) === null);
check('a non-member (former member) cannot delete the project', throws(fn () => ProjectsData::delete(2, $pid)));
ProjectsData::delete(1, $pid);
check('delete() cascades columns/tasks', Database::one('SELECT id FROM bla_projects WHERE id = ?', [$pid]) === null
    && Database::one('SELECT id FROM bla_project_columns WHERE project_id = ?', [$pid]) === null);

echo "AI access tokens\n";
[$aiId, $aiToken] = AiTokens::create(1, 'Test assistant');
check('token verifies to the right user', AiTokens::verify($aiToken)['id'] === 1);
check('a wrong token does not verify', AiTokens::verify($aiToken . 'x') === null);
check('an unrelated random string does not verify', AiTokens::verify('not-a-real-token') === null);
check('forUser() lists it', count(AiTokens::forUser(1)) === 1);
AiTokens::revoke(1, $aiId);
check('revoked token no longer verifies', AiTokens::verify($aiToken) === null);

echo "MCP tools (AI integration)\n";
$u1 = Database::one('SELECT * FROM bla_users WHERE id = 1');
$u2 = Database::one('SELECT * FROM bla_users WHERE id = 2');

$defs = McpTools::definitions();
check('exposes a full set of tools', count($defs) >= 20);
check('every tool has a description and a schema', array_reduce(array_keys($defs), fn ($ok, $n) => $ok && is_string($defs[$n][0]) && is_array($defs[$n][1]), true));
check('unknown tool name is rejected', throws(fn () => McpTools::call('not_a_tool', [], $u1)));

McpTools::call('files_write', ['path' => '/mcp-test.txt', 'content' => 'hello from the AI'], $u1);
check('files_write then files_read round-trips', McpTools::call('files_read', ['path' => '/mcp-test.txt'], $u1)['content'] === 'hello from the AI');
check('files_write auto-creates missing parent folders', (function () use ($u1) {
    McpTools::call('files_write', ['path' => '/mcp/nested/note.txt', 'content' => 'x'], $u1);
    return McpTools::call('files_read', ['path' => '/mcp/nested/note.txt'], $u1)['content'] === 'x';
})());
check('files_list sees the new file', in_array('mcp-test.txt', array_column(McpTools::call('files_list', ['path' => ''], $u1)['items'], 'name'), true));
McpTools::call('files_delete', ['path' => '/mcp-test.txt'], $u1);
check('files_delete then files_read fails', throws(fn () => McpTools::call('files_read', ['path' => '/mcp-test.txt'], $u1)));

$ev = McpTools::call('calendar_create_event', ['title' => 'AI-scheduled sync', 'start' => '2026-06-01 09:00', 'end' => '2026-06-01 10:00'], $u1);
check('calendar_create_event returns an object_id', $ev['object_id'] > 0);
check('calendar_list_events finds it', in_array('AI-scheduled sync', array_column(McpTools::call('calendar_list_events', ['start' => '2026-05-01', 'end' => '2026-07-01'], $u1)['events'], 'title'), true));
McpTools::call('calendar_update_event', ['object_id' => $ev['object_id'], 'calendar_id' => $ev['calendar_id'], 'title' => 'Renamed sync', 'start' => '2026-06-01 09:00', 'end' => '2026-06-01 10:00'], $u1);
check('calendar_update_event renames it', array_column(McpTools::call('calendar_list_events', ['start' => '2026-05-01', 'end' => '2026-07-01'], $u1)['events'], 'title') === ['Renamed sync']);
McpTools::call('calendar_delete_event', ['object_id' => $ev['object_id'], 'calendar_id' => $ev['calendar_id']], $u1);
check('calendar_delete_event removes it', McpTools::call('calendar_list_events', ['start' => '2026-05-01', 'end' => '2026-07-01'], $u1)['events'] === []);

$ct = McpTools::call('contacts_create', ['given' => 'Grace', 'family' => 'Hopper', 'emails' => [['type' => 'work', 'value' => 'grace@navy.mil']]], $u1);
check('contacts_create returns an id', $ct['id'] > 0);
check('contacts_list finds it by name', count(McpTools::call('contacts_list', ['query' => 'Hopper'], $u1)['contacts']) === 1);
McpTools::call('contacts_update', ['id' => $ct['id'], 'given' => 'Grace', 'family' => 'Murray Hopper'], $u1);
check('contacts_update changes the name', McpTools::call('contacts_list', ['query' => 'Murray'], $u1)['contacts'][0]['fn'] === 'Grace Murray Hopper');
McpTools::call('contacts_delete', ['id' => $ct['id']], $u1);
check('contacts_delete removes it', McpTools::call('contacts_list', ['query' => 'Hopper'], $u1)['contacts'] === []);

$mp = McpTools::call('projects_create', ['name' => 'AI-run project'], $u1);
check('projects_create returns an id', $mp['id'] > 0);
McpTools::call('projects_add_member', ['project_id' => $mp['id'], 'username' => 'anna'], $u1);
$colId = McpTools::call('projects_get', ['project_id' => $mp['id']], $u1)['columns'][0]['id'];
$mt = McpTools::call('projects_create_task', ['project_id' => $mp['id'], 'column_id' => $colId, 'title' => 'Draft the plan', 'assignee_username' => 'anna'], $u1);
check('projects_create_task assigned it via username', ProjectsData::task($mt['task_id'])['assignee_username'] === 'anna');
$doneColId = McpTools::call('projects_get', ['project_id' => $mp['id']], $u1)['columns'][2]['id'];
McpTools::call('projects_move_task', ['task_id' => $mt['task_id'], 'column_id' => $doneColId], $u1);
check('projects_move_task moved it', (int) ProjectsData::task($mt['task_id'])['column_id'] === $doneColId);
McpTools::call('projects_add_comment', ['task_id' => $mt['task_id'], 'body' => 'Looks good.'], $u1);
check('projects_add_comment recorded it', count(ProjectsData::comments($mt['task_id'])) === 1);
check('assigning to a non-member username is rejected', throws(fn () => McpTools::call('projects_update_task', ['task_id' => $mt['task_id'], 'title' => 'x', 'assignee_username' => 'nope'], $u1)));

$pdo->exec("INSERT INTO bla_users (id, username, password_hash, created_at) VALUES (3, 'outsider', 'x', '2026-01-01 00:00:00')");
$outsider = Database::one('SELECT * FROM bla_users WHERE id = 3');
check('a non-member cannot probe another project\'s membership via assignee resolution',
    throws(fn () => McpTools::call('projects_update_task', ['task_id' => $mt['task_id'], 'title' => 'x', 'assignee_username' => 'anna'], $outsider)));
check('a user token can only reach their own data: an unrelated user cannot read the project via MCP',
    throws(fn () => McpTools::call('projects_get', ['project_id' => $mp['id']], $outsider)));
check('an unrelated user cannot read another user\'s file via MCP',
    throws(fn () => McpTools::call('files_read', ['path' => '/mcp/nested/note.txt'], $outsider)));

$pdo = null;
exec('rm -rf ' . escapeshellarg($tmp));

echo "Network / proxy\n";
check('ipv4 in range', Request::ipInRange('172.18.0.5', '172.18.0.0/16'));
check('ipv4 out of range', !Request::ipInRange('172.19.0.5', '172.18.0.0/16'));
check('exact ip', Request::ipInRange('127.0.0.1', '127.0.0.1'));
check('ipv6 range', Request::ipInRange('fd00::1', 'fd00::/8'));
check('odd prefix /20', Request::ipInRange('10.0.15.1', '10.0.0.0/20') && !Request::ipInRange('10.0.16.1', '10.0.0.0/20'));
$prop->setValue(null, ['trusted_proxies' => ['127.0.0.1']]);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
check('untrusted peer cannot spoof IP', Request::clientIp() === '203.0.113.9');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 198.51.100.7';
check('trusted proxy: rightmost client IP used', Request::clientIp() === '198.51.100.7');

echo "Passwords\n";
check('too short rejected', Security::passwordProblem('short') !== null);
check('contains username rejected', Security::passwordProblem('damian-is-great-2026', 'damian') !== null);
check('common word short rejected', Security::passwordProblem('password1234') !== null);
check('passphrase accepted', Security::passwordProblem('quiet river morning bread') === null);

echo "\n" . ($fail ? "\033[31m$fail failed\033[0m, " : '') . "$pass passed\n";
exit($fail ? 1 : 0);
