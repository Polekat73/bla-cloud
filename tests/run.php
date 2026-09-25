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
