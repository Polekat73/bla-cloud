# Installing BLA-Cloud

Pick the path that matches where you're hosting:

- **A. Shared hosting** (cPanel, Plesk, Hostinger, SiteGround, Namecheap…). The easiest option.
- **B. Your own server / VPS** (Ubuntu with Nginx or Caddy)
- **C. Behind a reverse proxy** (Cloudflare Tunnel, Traefik, Nginx Proxy Manager, a home router setup)

---

## A. Shared hosting

1. **Turn on HTTPS** for your domain or subdomain. Most panels have a free "Let's Encrypt" / "SSL" button.
2. **Choose PHP 8.2 or newer** for the site. Look for "Select PHP version" or "MultiPHP Manager" in your panel.
3. **Upload the files.** Unzip BLA-Cloud on your computer, then upload the `bla-cloud` folder using the panel's
   File Manager or an FTP app (FileZilla). Common places:
   - `public_html/cloud` → your cloud is at `https://yourdomain.com/cloud/`
   - or point a subdomain like `cloud.yourdomain.com` at the folder.
4. **Visit the address** in your browser. The setup wizard opens.
5. **Storage step.** If the wizard suggests a folder *outside* `public_html` (for example
   `/home/youruser/bla-cloud-data`), keep it: that's the safest place. If your host doesn't allow it,
   the built-in `data` folder is used and locked down automatically.
6. Create your account, sign in, and scan the QR code with your authenticator app.

> **Do setup right after uploading.** Until setup is finished, anyone who finds the address could run the wizard.

**Upload size:** BLA-Cloud sends big files in pieces, so hosting upload limits don't matter.
The included `.htaccess` / `.user.ini` raise the limit where the host allows, which just makes uploads faster.

**After setup (optional, recommended):** make `config/config.php` read-only (permissions `440`) in the File Manager.

---

## B. VPS with Nginx or Caddy

Install PHP (Ubuntu 24.04 example):

```bash
sudo apt install php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-gd php8.3-zip php8.3-mysql
sudo mkdir -p /var/www/bla-cloud /var/lib/bla-cloud-data
# copy the BLA-Cloud files into /var/www/bla-cloud, then:
sudo chown -R www-data:www-data /var/www/bla-cloud/config /var/lib/bla-cloud-data
```

In the wizard, set the data folder to `/var/lib/bla-cloud-data`.

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name cloud.example.com;
    root /var/www/bla-cloud;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/cloud.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cloud.example.com/privkey.pem;

    client_max_body_size 64M;

    # Never serve internal folders or hidden files
    location ~ ^/(app|config|data|tests|docs)(/|$) { deny all; return 404; }
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
    root * /var/www/bla-cloud
    @blocked path /app/* /config/* /data/* /tests/* /docs/* /.* *.sqlite *.md
    respond @blocked 404
    request_body { max_size 64MB }
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
}
```

Set `upload_max_filesize = 64M` and `post_max_size = 70M` in `/etc/php/8.3/fpm/php.ini`, then restart PHP-FPM.

---

## C. Behind a reverse proxy

When a proxy forwards traffic to BLA-Cloud, BLA-Cloud needs to know which proxy to trust, so it can
see visitors' real IP addresses (used for rate limiting and the activity log) and know the connection is HTTPS.

1. In the setup wizard's **Storage** step, open **Advanced: reverse proxy**, tick the box and enter the proxy's
   IP address as BLA-Cloud sees it (the wizard pre-fills it when it detects one). Examples:
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

## Moving or re-installing

Everything lives in two places: **`config/config.php`** (settings and the encryption key) and **your data folder**
(files and the SQLite database). Back up both together. To start over, delete `config/config.php` and use a new
empty data folder.
