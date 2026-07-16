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
| WPCodeBox | `snippet` | WPCodeBox | working (list/get/set/toggle, PHP linted) |
| Self-update | `self` | core WP | working (checksum-verified) |

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

### `content` — posts, media, dates

```
cat post.html | wp agent content bundle --title="..." --content=- \
  --status=publish --date="2025-10-11 11:00:00" --cat=116 --tag=arte-e-cultura \
  --featured-url="https://.../img.jpg" --alt="..."
wp agent content publish <post_id> --date="2025-11-05 11:00:00"   # date-safe publish
wp agent content featured <post_id> --url="https://.../img.jpg" --alt="..."
```

Handles the WordPress quirk where publishing a former draft resets its date to
"now": the real `--date` is re-asserted after the status change.

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
wp agent snippet restore <id>                          # undo the last set
cat new.php | wp agent snippet create --title="WAY - X" --code=- [--enable]
wp agent snippet toggle <id> --on|--off
wp agent snippet tables            # schema discovery
```

New snippets are created **disabled** by default, so you can review before they run.

### `self` — checksum-verified update

Updates the plugin in place from a GitHub release, without SSH or `git pull`.
By design there is **no "install latest"**: every update names an explicit tag
**and** the expected SHA-256 of the release asset. The command downloads the
asset, verifies the hash, and only then extracts — on any mismatch it aborts
and changes nothing. This keeps every production code swap explicit and
tamper-evident.

```
wp agent self version
wp agent self update --tag=v0.2.0 --sha256=<hash> --dry-run
wp agent self update --tag=v0.2.0 --sha256=<hash>
```

The verified artifact is the release asset `wp-agent-connector.zip` (built and
attached to each release), not GitHub's auto-generated source archive.

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
zip -r wp-agent-connector.zip agent-connector.php src README.md   # from a clean checkout
sha256sum wp-agent-connector.zip
gh release create vX.Y.Z wp-agent-connector.zip --title vX.Y.Z --notes "..."
```

Then update with the printed hash: `wp agent self update --tag=vX.Y.Z --sha256=<hash>`.

## Roadmap

- `snippet` read/write once the schema is confirmed on-site.
- `content` media dedupe; taxonomy helpers.
- `tp` bulk mode across a list of posts.
