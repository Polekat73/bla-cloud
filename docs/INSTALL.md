# Installing Haven

Pick the path that matches where you're hosting:

- **A. Shared hosting** (cPanel, Plesk, Hostinger, SiteGround, Namecheap…). The easiest option.
- **B. Your own server / VPS** (Ubuntu with Nginx or Caddy)
- **C. Behind a reverse proxy** (Cloudflare Tunnel, Traefik, Nginx Proxy Manager, a home router setup)

---

## A. Shared hosting

1. **Turn on HTTPS** for your domain or subdomain. Most panels have a free "Let's Encrypt" / "SSL" button.
2. **Choose PHP 8.2 or newer** for the site. Look for "Select PHP version" or "MultiPHP Manager" in your panel.
3. **Upload the files.** Unzip Haven on your computer, then upload the folder using the panel's
   File Manager or an FTP app (FileZilla) — rename it to `haven` if you like. Common places:
   - `public_html/cloud` → your cloud is at `https://yourdomain.com/cloud/`
   - or point a subdomain like `cloud.yourdomain.com` at the folder.
4. **Visit the address** in your browser. The setup wizard opens.
5. **Storage step.** If the wizard suggests a folder *outside* `public_html` (for example
   `/home/youruser/haven-data`), keep it: that's the safest place. If your host doesn't allow it,
   the built-in `data` folder is used and locked down automatically.
6. Create your account, sign in, and scan the QR code with your authenticator app.

> **Do setup right after uploading.** Until setup is finished, anyone who finds the address could run the wizard.

**Upload size:** Haven sends big files in pieces, so hosting upload limits don't matter.
The included `.htaccess` / `.user.ini` raise the limit where the host allows, which just makes uploads faster.

**After setup (optional, recommended):** make `config/config.php` read-only (permissions `440`) in the File Manager.

---

## B. VPS with Nginx or Caddy

Install PHP (Ubuntu 24.04 example):

```bash
sudo apt install php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-gd php8.3-zip php8.3-mysql
sudo mkdir -p /var/www/haven /var/lib/haven-data
# copy the Haven files into /var/www/haven, then:
sudo chown -R www-data:www-data /var/www/haven/config /var/lib/haven-data
```

In the wizard, set the data folder to `/var/lib/haven-data`.

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name cloud.example.com;
    root /var/www/haven;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/cloud.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cloud.example.com/privkey.pem;

    client_max_body_size 64M;

    # Never serve internal folders or hidden files
    location ~ ^/(app|config|data|tests|docs|tools)(/|$) { deny all; return 404; }
    location ~ /\.(?!well-known) { deny all; return 404; }
    location ~ \.(sqlite|md|part|log)$ { deny all; return 404; }

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 300;
    }
    # Only index.php may run as PHP
    location ~ \.php$ { return 404; }

    location /assets/ { expires 30d; add_header Cache-Control "public"; }
}
server { listen 80; server_name cloud.example.com; return 301 https://$host$request_uri; }
```

### Caddy (automatic HTTPS)

```caddy
cloud.example.com {
    root * /var/www/haven
    @blocked path /app/* /config/* /data/* /tests/* /docs/* /tools/* /.* *.sqlite *.md
    respond @blocked 404
    request_body { max_size 64MB }
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
}
```

Set `upload_max_filesize = 64M` and `post_max_size = 70M` in `/etc/php/8.3/fpm/php.ini`, then restart PHP-FPM.

---

## C. Behind a reverse proxy

When a proxy forwards traffic to Haven, Haven needs to know which proxy to trust, so it can
see visitors' real IP addresses (used for rate limiting and the activity log) and know the connection is HTTPS.

1. In the setup wizard's **Storage** step, open **Advanced: reverse proxy**, tick the box and enter the proxy's
   IP address as Haven sees it (the wizard pre-fills it when it detects one). Examples:
   `127.0.0.1` (same machine), `172.18.0.0/16` (Docker network), `10.0.0.5` (another server).
2. Your proxy must send `X-Forwarded-For` and `X-Forwarded-Proto` headers (all common proxies do).
3. To change it later, edit `trusted_proxies` in `config/config.php`:

```php
'trusted_proxies' => ['127.0.0.1', '172.18.0.0/16'],
```

Only list your own proxies. Anything listed is trusted to report visitor IPs.

**Serving under a sub-path** (like `https://example.com/cloud/`) works automatically.

---

## Setting up email (recommended)

Email is used for invitations, password resets and share notifications. As an administrator, go to **Settings → Email**:

- **SMTP server (recommended):** use the "SMTP settings" from your email provider or host. Common ones:

| Provider | Server | Port / security |
|---|---|---|
| Gmail / Google Workspace | `smtp.gmail.com` | 587 STARTTLS (use an *App password*) |
| Outlook / Microsoft 365 | `smtp.office365.com` | 587 STARTTLS |
| Most cPanel hosts | `mail.yourdomain.com` | 465 SSL or 587 STARTTLS |

- **Server default (mail()):** works on many shared hosts without any settings, but messages are more likely to land in spam.

Then use **Send a test email** on the same page. Also set **Web address** (e.g. `https://cloud.yourdomain.com`) so links in emails point to the right place.

## Sync (WebDAV/CalDAV/CardDAV)

Every account has an address to mount their files as a network drive, plus a calendar and address
book, under **Sync** in the sidebar. Since sync apps can't answer a two-step verification prompt,
each device signs in with its own **app password** instead of the account password — create one
per device on that page, and revoke it any time without touching your main password.

- **Files (WebDAV)** — `https://your-domain.com/cloud/dav/files/USERNAME/`. Mount it in Windows
  ("Map network drive" → "Connect to a website..."), macOS Finder (**Go → Connect to Server**), or a
  file app on your phone (e.g. FE File Explorer, Files by Readdle).
- **Calendar (CalDAV)** and **Contacts (CardDAV)** — Apple Calendar/Contacts, Thunderbird, and DAVx5
  (Android) can usually auto-discover both from just the server address (`https://your-domain.com/cloud/`)
  plus the username and app password. Apps that need the full address: see the Sync page for the
  exact URLs.

**Server config:** the Nginx and Caddy examples above already route every HTTP method (PROPFIND, PUT,
MKCOL...) to `index.php`, so nothing extra is needed. Apache's `.htaccess` (included) adds two rules
for `/dav/` and `/.well-known/caldav`/`carddav` — if you copied an older `.htaccess`, replace it with
the current one.

There's no built-in calendar or contacts app yet (see the roadmap) — until then, use any CalDAV/CardDAV
app to see and edit them.

## Backups

Turn them on under **Backups** in the admin sidebar: pick a folder (ideally outside both the
website folder and the data folder — a sibling folder, or a mounted network drive), how often
(daily/weekly) and how many to keep, and set a **passphrase**. That passphrase is separate from
your account password and from the app's own encryption key: write it down somewhere safe, because
it's the only way to restore a backup, and Haven never stores it in a readable form.

**Reliable scheduling with real cron.** By default, a scheduled backup runs as a side effect of
someone visiting the site (like the rest of the housekeeping) — fine for an active site, less
reliable for one nobody visits for a day or two. If your host allows cron jobs, wire one up instead:

```bash
crontab -e
# runs every 15 minutes; each job checks whether a backup is actually due and exits quickly if not
0,15,30,45 * * * * php /path/to/haven/tools/cron.php
```

**Verify** decrypts a backup and checks it's intact (including opening a SQLite snapshot and
counting accounts) without touching anything live — a good habit after first setting backups up,
and occasionally after.

**Restore** (in place, on this same server) replaces the database and everyone's files with a
backup's contents. It saves whatever was there first as its own "pre-restore safety" backup, so a
restore can itself be undone. It expects the backup to come from an install using the same database
type (SQLite or MySQL) as this one.

**Moving to a brand-new server** (this one is gone entirely) is a manual process, since there's no
running app yet to click "Restore" in:

1. Install Haven fresh on the new server (through the setup wizard) — or skip the wizard,
   see step 3.
2. Get a copy of the backup file onto the new server.
3. From a terminal on the new server, decrypt and extract it:
   ```bash
   php -r '
   require "/path/to/haven/app/bootstrap.php";
   BlaCloud\Backup::decryptFile("/path/to/the/backup/file.bcbackup", "/tmp/restored.zip", "your passphrase");
   (new ZipArchive())->open("/tmp/restored.zip") && (new ZipArchive())->extractTo("/tmp/restored");
   '
   ```
   This gives you `/tmp/restored/config.php`, `/tmp/restored/database/` and `/tmp/restored/users/`.
4. Put `config.php` in place at `config/config.php` (it has the original `app_key`, so 2FA secrets
   and stored SMTP passwords keep working), the database file/dump where your `db` config in it
   expects (SQLite: copy the `.sqlite` file over; MySQL: `mysql yourdb < database/database.sql`),
   and `users/` inside your data folder.
5. Visit the site. If you did skip the wizard in step 1, it now finds an existing install and just
   signs you in.

## Encryption at rest

Turn it on under **Encryption** in the admin sidebar and set a passphrase (or let one be
generated). This is separate from your account password and from the app's own encryption key —
write it down somewhere safe, since there's no way to recover it if it's lost, and no way to
change it later short of decrypting everything and re-encrypting with a new one.

It only affects files saved from that point on. Existing files stay exactly as they are until you
run **Encrypt existing files now**, which needs backups set up first (a safety backup is taken
automatically before it starts). The reverse, **Decrypt existing files**, works the same way.

A few things worth knowing:
- Only file *contents* are encrypted — file and folder names are not.
- It only covers the main files area for now, not trash or version history.
- Because there's no seekable cipher, a large encrypted video's Range requests (skipping ahead
  while playing) and thumbnail generation decrypt a temporary full copy first — noticeably slower
  than for a plain file of the same size. Turn it off if that matters more than the protection.

## Apps

Calendar and Contacts, plus anything else you add, are apps under **Administration → Apps**, where
you can turn each one on or off. Turning one off just hides its pages and sidebar link — it doesn't
touch its data, and (for Calendar/Contacts specifically) doesn't stop it syncing over CalDAV/CardDAV,
since that sync is handled by the core, not the app.

To install a new app, place its folder inside `app/apps/` on your server (the same way you'd upload
Haven itself — FTP, File Manager, or unzip-and-upload), then enable it on that page. There's no
in-browser "upload an app" button by design: an app is arbitrary PHP that runs in-process alongside
the rest of Haven, so only install ones you trust — see `app/apps/README.md` if you're writing
your own.

## Project chat

Every project has a chat, under the project's **Chat** tab: a default "General" channel plus as many
topic channels as you want. Messages update every few seconds by checking the server (not a
persistent connection), so it works on any shared host, including the one this app is designed to
deploy to.

Joining a project does **not** automatically add you to its channels — an existing channel member
invites you into a specific one under that channel's **Members** dialog. The channel's creator or the
project owner can remove someone, delete the channel, or delete any message; a project owner can do
all of that even for a channel they haven't personally joined, since moderation belongs to whoever's
accountable for the project.

## AI access (MCP)

**Settings → Sync → AI access** connects an MCP-compatible AI assistant (Claude and others) directly
to your account. Create a token there — it's shown once, like a backup or encryption passphrase —
and add Haven as a remote MCP server in your AI assistant using the address shown on that page
(`https://your-domain.com/cloud/mcp`) with the token as the Bearer credential.

A token gives that AI **full read/write access to your own data**: files (reading and writing plain
text files up to 256 KB; creating, moving and deleting anything), calendar events, contacts,
projects, and project chat. It can never see another person's data on your cloud, and it can never
reach admin functions — no user management, no settings, no backups, no turning apps on or off.

This is what makes "AI, read the #General channel on Project A and turn it into tasks" or "draft a
scope-of-work document from that discussion" work: the AI reads the channel with `chat_get_messages`,
then creates the tasks/stages and writes the document itself using the same tools listed above —
there's no separate "summarize" button or automatic behavior built into Haven, and no AI API key
for you to configure or pay for on this end.

There's no sandboxing beyond that scoping: whatever the AI decides to do with its access, it can do,
the same as if you'd done it yourself. Treat a token like a password — only issue one to an assistant
you trust, and revoke it the moment you stop using that integration or suspect it's been exposed.

## Troubleshooting

| Problem | Fix |
|---|---|
| Blank page or "500 error" | Check the PHP version is 8.2+. Look in your host's error log. |
| "Config folder writable" is red | Set the `config` folder's permissions to `750` (or `770`). |
| Can't create the data folder | Create it yourself in the File Manager, then enter its full path. |
| Authenticator codes rejected | Your phone's clock is off. Turn on automatic date & time. |
| Lost your phone | Sign in with one of your recovery codes, then set up 2FA again in **Security**. An administrator can also reset it under **People**. |
| Forgot password | Use **Forgot your password?** on the sign-in page (needs email set up), or ask an administrator for a reset link under **People**. |
| Test email fails | Check server, port and security match your provider. Gmail needs an App password. Some hosts block outgoing port 587, so try 465 with SSL. |
| Locked out after many attempts | Wait 15 minutes. The block lifts automatically. |
| Uploads stop partway | Check free disk space on the **System status** page. |
| WebDAV/CalDAV/CardDAV app rejects the password | Use an **app password** from the Sync page, not your account password. |
| "Wrong passphrase, or this backup file is corrupted" | Double-check the backup passphrase (Backups page) — it's separate from your account password, and rotating it doesn't change what older backups need. |
| A photo/video is slow to open, or "Encrypt/Decrypt existing files" is greyed out | The first is expected for large files with encryption on (see Encryption at rest above). The second needs backups set up first (Backups page) — it takes a safety backup automatically before the bulk change. |

## Moving or re-installing

Everything lives in two places: **`config/config.php`** (settings and the encryption key) and **your data folder**
(files and the SQLite database). Back up both together. To start over, delete `config/config.php` and use a new
empty data folder.
