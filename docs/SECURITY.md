# How Haven keeps your data safe

## Built in (Stage 1)

| Area | Protection |
|---|---|
| **Sign-in** | Argon2id password hashing (bcrypt fallback). Minimum 12 characters, with checks for common and repeated passwords. |
| **Two-step verification** | TOTP (RFC 6238), **mandatory for admins**. Allows ±30s clock drift, and **a code can't be used twice**. The secret is encrypted in the database with libsodium. |
| **Recovery codes** | 10 single-use codes, shown once and stored only as keyed hashes (HMAC-SHA256). |
| **Brute force** | Max 8 failed attempts per account and 20 per IP address in any 15 minutes, with a random delay on failure. Unknown usernames take the same time as real ones. |
| **Sessions** | HttpOnly, SameSite, `__Host-` + Secure cookies on HTTPS. New session id at every privilege change, 8h idle / 7-day max, and all other sessions end when the password changes. |
| **Forms** | Every change requires a CSRF token. |
| **Browser hardening** | Strict Content-Security-Policy (no inline scripts, no third-party resources), no framing, `nosniff`, `no-referrer`, HSTS on HTTPS. |
| **Files** | Every path is normalized and checked against the user's own folder. `..`, symlinks, control characters and server config names (`.htaccess`, `.user.ini`…) are rejected. Downloads are always sent as attachments inside a sandbox CSP, so an uploaded HTML file can't run in your session. |
| **Storage location** | The data folder lives outside the website folder when possible. Otherwise it's locked with `.htaccess`/`web.config` and PHP execution is disabled inside it. |
| **Database** | Only prepared statements with bound parameters. |
| **Errors** | Never shown to visitors. They go to the server log. |
| **Audit log** | Sign-ins (and failures), 2FA changes, password changes, uploads, downloads, renames and deletes, with IP address. |
| **Proxies** | `X-Forwarded-*` headers are only believed from proxies you explicitly trust. |
| **Previews** | Only a fixed list of safe types (images, PDF, audio/video, plain text) is shown in the browser. Web pages, SVGs and scripts are served as plain text inside a locked sandbox, so a malicious upload can't run code in your account. Everything else is download-only. |
| **Thumbnails** | Made server-side from your own files. Oversized images (over 50 megapixels) are skipped to protect memory. Stored outside your files folder. |
| **Trash & versions** | Stored in private folders next to your files and tied to your account. Each request checks that the item belongs to you. |
| **Sharing** | Every request inside a share is translated to a path *inside* the shared item, so `../` can never reach the owner's other files. Permissions are checked on the server for every action, not only hidden in the page. Visitors who can upload but not edit can never overwrite existing files. |
| **Public links** | 192-bit random tokens, stored only as a hash (plus an encrypted copy so you can copy the link again). Optional passwords are Argon2id-hashed, with max 10 wrong tries per link and 20 per IP in 15 minutes. Links stop working when they expire, when their owner is disabled, or when an admin turns links off. |
| **Invitations & password resets** | Single-use links, stored hashed. Invitations last 7 days and resets last 1 hour, and a newer link cancels older ones. A reset signs the account out everywhere, and two-step verification is still required afterwards. "Forgot password" gives the same answer whether or not the account exists, is rate-limited, and only sends email when a fixed public address is configured, which stops spoofed-Host attacks. |
| **Email** | SMTP over STARTTLS or SSL with certificate checks. The password is stored encrypted, and it won't be sent over an unencrypted connection (except to localhost). Email content is HTML-escaped. |
| **Admin safety** | You can't remove the last administrator, demote or disable yourself, or delete your own account. Deleting a person requires typing their username. |
| **Zip downloads** | Symlinks and server config files are skipped. Size and file-count limits prevent the server from filling up. |
| **Backups** | Encrypted (libsodium) under a passphrase separate from your account password and the app's own key. A "verify" restore drill checks a backup without touching anything live. |
| **Encryption at rest** | Optional: file *contents* encrypted on disk (libsodium `secretstream`) under their own passphrase, separate from your account password and the backup passphrase. Off by default — see below before deciding. |
| **Apps** | Installed apps run in-process with no special privileges — a controller in an app still has to authenticate and authorize the request itself, the same as core code. Only install apps you trust; there's no sandboxing. |
| **Projects** | Any project member can manage tasks/columns/comments; only the owner can rename/delete the project or change membership. Every check runs server-side, not just hidden in the page. |
| **Project chat** | Channel membership is separate from project membership — joining a project doesn't put you in every channel. Only the channel creator or the project owner can remove someone, delete the channel, or delete another person's message; the owner can do this even for a channel they haven't personally joined, since that check is against project ownership, not channel membership. |
| **AI access (MCP)** | Bearer tokens (256 bits of random entropy, indexed SHA-256 lookup), revocable any time. A token is scoped to exactly one person's own data — files, calendar, contacts, projects, chat — and can never reach another user's data or any admin function, enforced by the same authorization checks the web UI itself uses. There's no sandboxing of what a connected AI does *within* that scope, so a token deserves the same care as a password. |

## What you should do after installing

1. **Use HTTPS.** Haven warns you on the status page if you're not.
2. **Save your recovery codes** somewhere safe that isn't your phone.
3. **Back up `config/config.php`.** It holds the key that encrypts your 2FA secrets, backup passphrase and encryption-at-rest passphrase. Without it, all three have to be reset/reconfigured.
4. Optionally make `config/config.php` read-only (`chmod 440`).
5. Keep PHP updated through your hosting panel.
6. **Turn on Backups before you put anything you'd miss on it.** Pick a destination outside both the
   website folder and the data folder (docs/INSTALL.md has the details), and write the passphrase down
   somewhere safe — it's the only way to restore, and Haven never stores it in the clear.
7. **Decide on Encryption at rest now, not later.** It only protects files saved *after* it's turned on
   (there's a separate "Encrypt existing files now" migration for what's already there, which needs
   backups configured first). Turning it on after months of real data means an extra bulk-migration
   step; turning it on from day one doesn't. There's no passphrase recovery and no rotation — write it
   down like the backup passphrase, and expect a real (if usually small) slowdown on large files
   while it's on, since there's no seekable cipher.
8. **Only create an AI access token for an assistant you actually trust**, and revoke it the moment
   you stop using that integration — it grants full read/write over your own data, with no sandbox.

## Not built yet (planned)

- **Safe in-app updates** with automatic rollback (Stage 7) — for now, upgrading is manual: see
  "Upgrading" in README.md. There's no one-click update and no automatic rollback if a new version
  has a problem, so read the changelog before upgrading and keep a backup handy.

## Independent review

An independent security review (an AI pass covering auth, sessions, CSRF, path safety, WebDAV/CalDAV
auth boundaries, share-link tokens and secret-at-rest handling) found no critical or high-severity
issues as of Stage 9 (v0.8.0). Every stage since (Projects, the MCP server and AI access tokens,
project chat) went through the same code-review process before release, which has caught and fixed
real bugs each time — most recently, in Stage 12: the project owner being unable to moderate a
channel they hadn't personally joined, and the chat page not checking that a requested channel
actually belonged to the requested project. That's still one reviewer, not a substitute for a second
set of human eyes — get one before this holds data you'd genuinely miss, and especially before
relying on the MCP server with a real AI assistant.

## Emergency: admin locked out of 2FA and recovery codes are lost

On the server, with shell or database access, run this to turn off 2FA for the account. The user will be asked to set it up again on next sign-in:

```sql
UPDATE bla_users SET totp_enabled = 0, totp_secret = NULL WHERE username = 'your-username';
DELETE FROM bla_recovery_codes WHERE user_id = (SELECT id FROM bla_users WHERE username = 'your-username');
```

## Reporting a problem

Found a security issue? Please contact Best Life Apps privately rather than opening a public issue.
