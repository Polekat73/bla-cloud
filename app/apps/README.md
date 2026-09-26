# Writing a Haven app

An app is a folder in `app/apps/` with its own controller(s), views and a `manifest.php`. It
runs in-process (plain PHP, autoloaded like the rest of Haven) — there's no sandboxing, so
only install apps you trust, the same way you'd only install a WordPress plugin you trust.

## Installing one

Copy the app's folder into `app/apps/` (FTP, File Manager, unzip-and-upload — however you'd
upload Haven itself), then go to **Administration → Apps** and enable it. There's no
in-browser upload/install step by design: extracting and running arbitrary PHP from an admin
upload is a much bigger attack surface than a human deciding what goes on their own server.

## Folder layout

```
app/apps/notes/
  manifest.php          Required — see below
  NotesController.php   Namespace BlaCloud\Apps\Notes\...
  views/
    index.php           Rendered via View::renderApp(__DIR__, 'index', [...])
```

The folder name is the app's id and **must** be lowercase and match `manifest.php`'s `'id'`. The
PHP namespace segment (`BlaCloud\Apps\Notes\...` above) can be any case — the autoloader
lowercases it to find the folder, so `BlaCloud\Apps\Notes\NotesController` resolves to
`app/apps/notes/NotesController.php`.

## manifest.php

Returns an array:

```php
<?php
declare(strict_types=1);

use BlaCloud\Apps\Notes\NotesController;

return [
    'id'          => 'notes',                  // required, matches the folder name
    'name'        => 'Notes',                  // required, shown in Administration → Apps
    'description' => 'Quick personal notes.',  // shown under the name there
    'version'     => '1.0.0',
    'icon'        => 'doc',                    // one of the icons in app/views/partials/icons.php
    'nav'         => ['route' => 'notes', 'label' => 'Notes', 'order' => 40], // sidebar link, or null for none
    'default_enabled' => true,                 // state before an admin ever touches the toggle
    'routes'      => [                         // required — route => [ControllerClass::class, 'method']
        'notes' => [NotesController::class, 'index'],
    ],
];
```

- `routes` keys become `?r=notes` style routes, same as core pages. A route name that collides
  with a core route is ignored (core always wins); one that collides with another app's is
  whichever app happened to load — pick names that won't collide (prefixing with your app's id,
  as above, is the easiest way).
- Disabling an app removes its routes (they 404) and its nav item, but touches nothing else —
  if your app keeps data elsewhere (its own tables, files under the data folder), that data is
  untouched and comes back the moment it's re-enabled.
- A `manifest.php` that fails to parse, or is missing `id`/`name`/`routes`, or whose `id` doesn't
  match its folder name, is skipped (logged to the PHP error log) rather than breaking the site.

## Inside a controller

```php
<?php
declare(strict_types=1);

namespace BlaCloud\Apps\Notes;

use BlaCloud\Auth;
use BlaCloud\View;

final class NotesController
{
    public function index(): void
    {
        $u = Auth::requireUser();
        View::renderApp(__DIR__, 'index', ['title' => 'Notes', 'nav' => 'notes']);
    }
}
```

Everything else — `Auth`, `Database`, `Security::requireCsrf()`, `Session::flash()`,
`Audit::log()`, the `e()`/`url()`/`icon()`/`csrf_field()` view helpers — works exactly like in a
core controller; apps aren't a separate API, just code that lives in its own folder and is
loaded conditionally. If your app needs its own database table, create it with
`CREATE TABLE IF NOT EXISTS` the first time it runs (there's no shared migration system for
apps yet — keep your own schema versioning inside your table if you'll need to change it later).

See `app/apps/calendar/` and `app/apps/contacts/` for complete, working examples — both are
ordinary apps built on this same system, not special-cased by core.
