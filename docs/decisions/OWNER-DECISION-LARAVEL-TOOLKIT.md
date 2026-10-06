# Install route on the host: **Laravel Toolkit**, document root `<APP_ROOT>/api/public`

> **OWNER DECIDED, 2026-10-06.** Artisan (`key:generate`, `migrate`, `ethr:create-admin`)
> runs from Plesk's **Laravel Toolkit**. The site's document root is **`<APP_ROOT>/api/public`**.
> This **overrides** [`SHARED-HOSTING-CONTRACT.md`](../deployment/SHARED-HOSTING-CONTRACT.md)'s
> rule that no procedure may require a Plesk extension, for this one purpose. The owner
> made that call with the alternatives in front of them.

## What forced a decision

The repository's install route since 2026-09-18 was Plesk Git's *additional deployment
actions*: present in the panel, never executed. The first host deploy (2026-10-06) executed
it, and the log answered:

```
deploy/post-deploy.sh: line 30: dirname: command not found
deploy/post-deploy.sh: line 39: cut: command not found
STOPPED: no php binary found; set ETHR_PHP
```

The action runs in a **chrooted shell** with no PHP, and neither `php` on the `PATH` nor
`/opt/plesk/php/8.3/bin/php` is visible to it. So no deployment action can run artisan on
this account. The route was not degraded; it was gone.

## The options the owner was shown

| Option | Cost |
|---|---|
| **Laravel Toolkit** (chosen) | The document root moves to `api/public`, the standard Laravel layout. No new code surface. Depends on a Plesk extension |
| Token-guarded HTTP install endpoint | Keeps the layout, but adds a remotely triggerable endpoint that runs migrations. The deployment docs had already said that would need a deliberate design of its own |
| Ask Ethio Telecom first | No change, and go-live waits on the provider adding PHP to the chroot or granting SSH |

The Toolkit was found installed on 2026-10-06: a **Laravel** entry in the panel sidebar, and
a page saying applications are discovered when *"their `public` directory [is] set as the
website document root and the `artisan` file [is] located in the parent directory."*

## What changed because of it

- **Layout.** The release puts the static export, the rendered `.htaccess` and `.user.ini`
  into `api/public`, next to Laravel's **unmodified** `index.php`. The repointed `index.php`
  copy in `httpdocs/` is gone, and so is the layout it existed for.
- **No deployment action.** `post-deploy.sh` is removed. Plesk Git deploys `production` into
  `<APP_ROOT>`, and that alone publishes the release.
- **Artisan.** It runs from the Toolkit: `key:generate --force` **on the first deploy only**,
  then `migrate --force` on every release. `rehearse-release.sh` rehearses that sequence in
  CI on every release, and serves `api/public` to prove it boots.

## What it does not change

- **Security.** `.env`, `vendor/`, `storage/` and `.git` are still outside the document
  root: `api/public` is a child of `api/`, never its parent. That is the property
  DEPLOYMENT.md §0 cares about. The "never deploy into the served directory" rule is
  about `<APP_ROOT>` itself, which stays unserved.
- **Gates.** M1, M2, M3 and M6 are unchanged. Whether the Toolkit runs artisan on this
  account is **unverified** until the owner runs `key:generate` there.

## The trap to avoid

**Document root** takes a path relative to the webspace root. The value is `ethr/api/public`.
`ethr` or `ethr/api` would serve `.env`. Read it back character by character before saving.
