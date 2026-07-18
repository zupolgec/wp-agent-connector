# Agent Connector

A small, **modular WP-CLI toolkit** for agent-driven site operations. It turns
the fiddly, repeated jobs (translating posts in TranslatePress, publishing with
real back-dates, regenerating Breakdance caches, editing WPCodeBox snippets)
into single, verifiable commands.

It is **not a backdoor**: there is no HTTP endpoint, no token, no auth bypass.
Everything runs through the WP-CLI channel you already use over SSH, as a
trusted local process with full access to the WordPress API.

## Design

- `wp agent <module> <command>` — one namespace, modules underneath.
- The core discovers modules under `src/Modules/*` and boots **only the ones
  whose dependency is present** on the current site. So the plugin is
  site-agnostic: drop it anywhere, each capability lights up when relevant.
- Adding a feature = adding a `src/Modules/<Name>/Module.php` (+ `Commands.php`).
  No core changes.
- Commands emit **JSON** so an agent can parse results.

```
wp agent modules            # list active modules on this site
```

## Modules

| Module | Slug | Depends on | Status |
|--------|------|-----------|--------|
| TranslatePress | `tp` | TranslatePress | working |
| Content | `content` | core WP | working |
| Breakdance | `bd` | Breakdance | working (regen fn auto-detected) |
| WPCodeBox | `snippet` | WPCodeBox | working (list/get/status/pull/push, lint + locking) |
| Database | `db` | core WP | working (guarded read/write SQL) |
| Self-update | `self` | core WP | working (checksum-verified) |
| Cache | `cache` | SpinupWP (auto-detected) | working (purge post/url/site) |

### `tp` — TranslatePress

Automates the post-translation workflow (register strings → fill dictionary →
translate slug → verify), server-side, reusing TP's own tables.

```
wp agent tp languages
wp agent tp strings <post_id> --lang=en_US            # dump untranslated strings (JSON)
cat en.json | wp agent tp apply <post_id> --lang=en_US --map=- --slug=<slug>
wp agent tp status <post_id>                          # coverage per language
wp agent tp verify <post_id>
```

`map` JSON keys are the row ids from `strings` (or exact original strings);
values are translations. `--dry-run` on `apply` reports without writing.

Note: the TranslatePress dictionary is **global** — an edited string can appear
on any page. So when `apply` writes at least one string it purges the whole
site cache (not just the post URL), otherwise other pages could keep serving
the old translation. `strings` and `status` report a `registration` field per
language: anything other than `"ok"` means the remote fetch that triggers TP's
string registration failed, so the string list may be incomplete.

### `content` — posts, media, dates

```
cat post.html | wp agent content bundle --title="..." --content=- \
  --status=publish --date="2025-10-11 11:00:00" --cat=116 --tag=arte-e-cultura \
  --featured-url="https://.../img.jpg" --alt="..."
wp agent content publish <post_id> --date="2025-11-05 11:00:00"   # date-safe publish
wp agent content featured <post_id> --url="https://.../img.jpg" --alt="..."
```

Handles the WordPress quirk where publishing a former draft resets its date to
"now": the real `--date` is re-asserted after the status change. `--date` is
validated strictly (`YYYY-MM-DD HH:MM:SS`, impossible dates refused) and
`bundle --id` fails if the post does not exist, instead of silently creating a
new one.

### `bd` — Breakdance

```
wp agent bd list [--type=breakdance_template]          # posts/templates using Breakdance
wp agent bd validate <post_id>                         # check _nextNodeId + status:exported
cat data.json | wp agent bd set <post_id> --data=-     # validates, writes, regenerates CSS cache
wp agent bd regen <post_id>
wp agent bd get <post_id>
```

`set` refuses to write data missing the fields the builder needs (`--force` to
override), and regenerates the CSS cache automatically.

### `snippet` — WPCodeBox

Read and edit WPCodeBox snippets. WPCodeBox runs snippets straight from its DB
(no file cache), so a code update applies on the next request. `set` lints PHP
before writing and refuses on a syntax error, and keeps a one-level backup so
`restore` can undo the last edit.

```
wp agent snippet list [--type=php] [--enabled] [--folder=<id>]
wp agent snippet get <id> [--field=code]
cat snippet.php | wp agent snippet set <id> --code=- [--dry-run]
cat snippet.php | wp agent snippet status <id> --code=-
wp agent snippet pull <id> > snippet.php
cat snippet.php | wp agent snippet push <id> --code=- --if-match=<remote-sha256>
wp agent snippet restore <id>                          # undo the last set
cat new.php | wp agent snippet create --title="WAY - X" --code=- [--enable]
wp agent snippet toggle <id> --on|--off
wp agent snippet tables            # schema discovery
```

New snippets are created **disabled** by default, so you can review before they run.
`push` updates both `code` and `original_code`, lints PHP, keeps a one-level
backup, and can use `--if-match` to refuse overwriting an online edit.

For safe local synchronization, use the bundled helper (it never stores a
working script on the WordPress server):

```
bin/wp-agent-snippet-sync status @waytest 17 wpcodebox/way-portfolio.php
bin/wp-agent-snippet-sync pull   @waytest 17 wpcodebox/way-portfolio.php
bin/wp-agent-snippet-sync push   @waytest 17 wpcodebox/way-portfolio.php
```

`pull` writes atomically through a local temporary file and PHP-lints before
replacing the local copy. `push` reads the remote hash first and uses
optimistic locking.

### `db` — guarded SQL

```
wp agent db info
wp agent db query --sql='SELECT ID, post_title FROM {{prefix}}posts LIMIT 10'
printf 'SELECT ID, post_title FROM {{prefix}}posts WHERE post_status = %%s' | \
  wp agent db query --sql=- --params='["publish"]'
cat update.sql | wp agent db exec --sql=- --dry-run
cat update.sql | wp agent db exec --sql=- --yes
```

`query` only accepts read statements (`SELECT`, `SHOW`, `DESCRIBE`, `EXPLAIN`;
`WITH` only when the statement after the CTE definitions is a `SELECT` —
MySQL 8.0 also allows `WITH ... UPDATE`/`WITH ... DELETE`, which are refused
on the read side and on the write side alike). `exec` only accepts data writes
(`INSERT/UPDATE/DELETE/REPLACE`), rejects multiple statements (a `;` inside a
quoted string literal is fine), and requires
either a non-executing `--dry-run` or explicit `--yes`. Use `{{prefix}}` and
`{{base_prefix}}` instead of hard-coding a site's table prefix.

### `self` — checksum-verified update

Updates the plugin in place from a GitHub release, without SSH or `git pull`.
By design there is **no "install latest"**: every update names an explicit tag
**and** the expected SHA-256 of the release asset. The command downloads the
asset, verifies the hash, and only then installs it — on any mismatch it aborts
and changes nothing. The install is a clean directory swap (current version
aside → new version into place → old copy removed), so files dropped by a
release disappear and a failed swap restores the previous version. This keeps
every production code swap explicit and tamper-evident.

```
wp agent self version
wp agent self update --tag=v0.2.0 --sha256=<hash> --dry-run
wp agent self update --tag=v0.2.0 --sha256=<hash>
```

The verified artifact is the release asset `wp-agent-connector.zip` (built and
attached to each release), not GitHub's auto-generated source archive.

### `cache` — purge the page cache

The other modules purge automatically after a direct write; this exposes the
same purge on its own, for when you need to clear stale HTML by hand.

```
wp agent cache status                     # which purge providers are detected
wp agent cache purge --post=<id>          # purge one post's URL(s)
wp agent cache purge --url=<url>          # purge a specific URL
wp agent cache purge --site               # purge the whole site
```

Currently wired for SpinupWP (auto-detected); `status` reports an empty
provider list when none is present, and a purge is then a no-op.

## Install / deploy

This is a normal plugin directory. Deploy it into `wp-content/plugins/` on the
target site (folder name `wp-agent-connector`) and activate:

```
wp @site plugin activate wp-agent-connector
```

Because the plugin only registers WP-CLI commands, activating it adds no
front-end surface. After the first install, use `wp agent self update` for
subsequent versions.

## Releasing

Each release ships a fixed-bytes asset so its checksum is stable:

```
zip -r wp-agent-connector.zip agent-connector.php src bin README.md   # from a clean checkout
sha256sum wp-agent-connector.zip
gh release create vX.Y.Z wp-agent-connector.zip --title vX.Y.Z --notes "..."
```

Then update with the printed hash: `wp agent self update --tag=vX.Y.Z --sha256=<hash>`.

## Tests

Standalone (no WordPress loaded): each file runs in its own process and uses
the shared doubles in `tests/lib.php`.

```
for t in tests/*-commands.php tests/cache-core.php; do php "$t"; done
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
bash -n bin/wp-agent-snippet-sync
```

## Roadmap

- `content` media dedupe; taxonomy helpers.
- `tp` bulk mode across a list of posts.
