# AGENTS.md — Newspack Live

Developer guide for people and AI coding agents (Claude Code, Codex, Cursor…) working on this plugin. `CLAUDE.md` only points here.

It says what is true, what is forbidden, which commands to run and where things live. When something non-obvious bites you, add it to §8 "Known pitfalls" in the same change as the fix.

Internal planning docs are not part of this repository.

---

## 1. What this project is

Newspack Live is a WordPress plugin for the Newspack platform. It turns a regular post into a **live blog**: a page that newsrooms update with short entries and that refreshes itself for readers.

- Current release: **1.2.0**. See `CHANGELOG.md`.
- Requirements: WordPress 6.7+, PHP 8.1+, the Newspack plugin (header `Requires Plugins: newspack-plugin`).
- Development happens against a local WordPress site (for example Local by Flywheel) with the Newspack plugins installed.

---

## 2. Hard rules (non-negotiable)

### 2.1 Language

English everywhere: code, comments, UI strings, docs, commit messages, demo content, test names.

### 2.2 Local site safety

- Write only in the plugin folder. Never modify WordPress core, other plugins or themes.
- **Never delete or alter content the plugin's tooling didn't create.** Only content marked `_newspack_live_demo = 1` (demo seed) or `_newspack_live_e2e = 1` / written by an e2e user (`nl-e2e-*`) may be modified or deleted.
- E2E cleanup selects by **marker or e2e author only**, never by post type or status.
- Take a database backup before any migration, data cleanup or plugin install/uninstall on your local site.
- A theme switched for tests is always restored, even on failure.
- Temporary plugins (e.g. Yoast for `@yoast` specs) are installed, tested, then deactivated, deleted and cleaned (tables, options, user meta, cron).
- `NEWSPACK_LIVE_E2E` must be absent from `wp-config.php` at the end of every run.

### 2.3 Security (VIP blocker level)

- Sanitize early, validate against allowlists, escape late, in context.
- Every REST route has an explicit `permission_callback`. Writes check the **live-scoped meta capability with the object ID** (`current_user_can( 'newspack_live_edit_entry', $entry_id )`). A user authorized on live A gets 403 on live B.
- Private routes: cookie auth + `X-WP-Nonce`. Admin links: `wp_nonce_url()` + `check_admin_referer()`.
- Entry content from users without `unfiltered_html` never keeps `<script>`, `on*` handlers or `javascript:` URLs, on every write path (console, core REST, CLI `--user`). Decorative SVG only, from an allowlist.
- Client side: server HTML is parsed with `DOMParser` only and inserted as nodes. Reader strings go through `textContent`. No `eval`, no inline handlers, no string-built HTML.
- No secrets in code. Local credentials live in gitignored files (`.env.e2e`, `tests/wp-tests-config.php`, `bin/*.local.sh`).

### 2.4 Privacy (GDPR)

- Store the minimum (display name, question text, `wp_hash()` of the IP, user ID when signed in). Honour retention periods.
- Every new piece of personal data gets a personal data exporter and eraser in the same change.
- No reader data leaves the site, except to reCAPTCHA or Akismet when the publisher configured them.

### 2.5 Performance (WordPress VIP)

- **No database writes during GET** (page views and REST GET): no options, meta, transients or counters.
- Anonymous responses are cacheable: no nonce, user data or randomness in page HTML or public REST responses. Public lives poll with `credentials: 'omit'`.
- REST GET TTLs never go below VIP's 60 s floor (`/status` anonymous: 60 s). Gated lives and authenticated requests: `private, no-store`.
- Queries are bounded (`posts_per_page` ≤ 100, never `-1`), scoped by `post_type` + `post_parent` + `post_status`, with `no_found_rows`. No queries in loops, no `post__not_in` on large sets (PHPCS flags it: use `per_page + 1` to compute `has_more`).
- Assets load only where used. A page without a live loads **no** plugin script or stylesheet (badge styles are inline). Budgets (`npm run test:budgets`): view module ≤ 30 KB gzip, front CSS ≤ 12 KB gzip, and no growth beyond `BASELINE` in `bin/check-budgets.mjs` without saying so.
- Cron jobs are idempotent and batch-limited (≤ 100 items per run, then reschedule).

### 2.6 WordPress VIP compliance

The plugin must be deployable as is on WordPress VIP. Apply the VIP developer documentation, starting with:
- https://docs.wpvip.com/guidebooks/develop-on-wpvip/wordpress-apps/
- the VIP coding standards: `automattic/vipwpcs`, the `WordPress-VIP-Go` ruleset run by `composer lint`.

Treat VIP Code Analysis findings (the PHPCS VIP-Go sniffs) as blocking. On top of §2.3 and §2.5:
- **Code-only deployment.**
  - The plugin ships as decompressed code in a Git repo (`/plugins/newspack-live`) with its built assets and the `--no-dev` Composer autoloader.
  - No install, update or configuration step through wp-admin or FTP.
  - No Git submodules.
- **Read-only filesystem.**
  - Never write files at runtime (no caches, logs or generated files on disk); `wp-content/uploads` goes through the media APIs only.
  - If a temp file is unavoidable, use `get_temp_dir()`.
- **Don't depend on activation hooks.** Setup runs on version change (§5.1).
- **WordPress core is never modified**, and the plugin works on the WordPress and PHP versions of its header (`Requires at least`, `Requires PHP`), also listed in README.md.
- **VIP helpers first.** Use `wpcom_vip_*` functions when they exist (e.g. `wpcom_vip_add_role_caps()`, the cached `url_to_postid` equivalents), behind `function_exists()`.
- **VIP MU plugins.**
  - Don't duplicate features that VIP's MU plugins already provide (page cache purge, Cron Control, object cache).
  - Integrate with them through their hooks, e.g. `wpcom_vip_rest_read_response_ttl` for REST TTLs.
- **Remote calls.** `wp_safe_remote_*` with a timeout of 3 s or less, and `WP_Error` handled.
- **Cron.** Jobs must run under Cron Control: idempotent, batch-limited, intervals ≥ 15 minutes.
- **Third-party code.** Any plugin or library is evaluated, scanned and tested on a non-production environment first; there is no VIP "approved list".

When a VIP rule and a Newspack or WordPress convention disagree, VIP wins on security and performance.

### 2.7 Dependencies

- No runtime dependencies other than `@wordpress/*` packages and core APIs. Dev dependencies are allowed when justified.
- No custom tables, no custom roles, no schema changes.

---

## 3. Environment

### 3.1 Local site

| Item | Value |
|---|---|
| Site | A local WordPress site, e.g. `http://newspack.local` (Local by Flywheel on macOS) |
| WordPress root | `<wordpress-root>` (for Local: `~/Local Sites/<site>/app/public`) |
| Plugin | `<wordpress-root>/wp-content/plugins/newspack-live` |
| Logs | `wp-content/debug.log` (turn `WP_DEBUG_LOG` on) |
| Versions | WordPress 6.7+ (tested up to 7.1), PHP 8.1+ (Local: 8.2) |
| Themes tested | `newspack-theme` (default), `newspack-joseph`, `-katharine`, `-nelson`, `-sacha`, `-scott`, `newspack-block-theme` |
| Newspack plugins | `newspack-plugin`, `newspack-blocks`, `newspack-newsletters`, `newspack-popups` (Campaigns), `newspack-ads`, `newspack-sponsors`, `super-cool-ad-inserter`, `republication-tracker-tool`, plus `co-authors-plus` |

### 3.2 Health check

```sh
curl -s -o /dev/null -w '%{http_code}' http://newspack.local   # must print 200
bin/wp.local.sh db check   # the wrapper adds --socket itself
```

### 3.3 PHP and WP-CLI

- On a Mac with Local, plain `wp` and `php` may run another PHP (e.g. Homebrew) that can't reach the site's MySQL socket.
- Use the wrappers (gitignored, machine-specific). Copy the committed templates and set your values (`LOCAL_SITE_ID`, `LOCAL_PHP`, `WP_PATH`):
  - `cp bin/wp.example.sh bin/wp.local.sh && cp bin/php.example.sh bin/php.local.sh`
  - `bin/wp.local.sh <command>`: WP-CLI on Local's PHP against the site's MySQL socket. It adds `--socket` to the MySQL client commands (`db check`, `db export`, `db query`, …) itself. If `wp db query` fails with "access denied", use `wp eval` with `$wpdb`, or `wp db tables`, for read-only checks.
  - `bin/php.local.sh`: Local's PHP. PHPUnit, PHPCS and the git hooks run through it.
  - `bin/composer.sh`: Composer through `bin/php.local.sh`.
  - `bin/debug-log.sh mark|check`: new `debug.log` lines since the mark; fails on `newspack-live` entries.
- PHPUnit: copy `tests/wp-tests-config.example.php` to `tests/wp-tests-config.php` and set the test database and socket.
- E2E: copy `.env.e2e.example` to `.env.e2e` and set the site URL and the e2e user passwords.
- Node: `source ~/.nvm/nvm.sh && nvm use` (`.nvmrc` = 22).

### 3.4 Demo content (seed)

- `bin/wp.local.sh eval-file "$PWD/bin/seed-demo-content.php" --user=<admin login>` (absolute path: `eval-file` resolves paths against the current directory) is idempotent. Everything it creates is flagged `_newspack_live_demo = 1`. It resets the demo:
  - "Election night 2026": **Live**, with a description and a Context, auto-end off (re-runs reopen it);
  - "Storm Watch": **Upcoming** (start moved to the next day);
  - "Riverside Bridge reopens": **Ended**, so the three listing badges can be seen (homepage section "Live coverage");
  - a static Home page with Homepage Posts blocks, and an About page.
- Demo copy avoids clock times (the site clock may be UTC).
- Fictional newsroom "The Riverton Ledger", with fictional staff users (editor, authors, contributor).
- E2E users: `nl-e2e-admin`, `-editor`, `-author`, `-contributor`, `-subscriber`. Passwords are in `.env.e2e` and are never printed.

---

## 4. Product

### 4.1 Newsroom side (wp-admin)

| Feature | Notes |
|---|---|
| Top-level **Live** menu | Submenus Lives and New live, each behind its own capability. Placed right after Posts |
| Lives list | `edit.php?newspack_live=1`, with a state column and an "Open console" row action |
| New live | Opens the block editor on a draft with Live enabled and default settings |
| Post editor | "Live coverage" sidebar panel (settings) · **post content = live description** · separate **Context** box below the editor: a meta box hosting a small block editor where each paragraph is shown as a bullet (fixed toolbar at the top: bold, italic, link), bound to `_newspack_live_context` (stored as one `<ul>`) with `useEntityProp`. Core sends its meta-box request with each save |
| Live console | `admin.php?page=newspack-live-console&live={id}`, hidden from the menu. Composer (embedded block editor), label, pin, backdating, drafts and review, edit, unpublish, trash, copy link, local autosave, state actions (Go live, End, Reopen, Keep live) |
| Entry labels | Composer "Label" select: none, Breaking, Photos, Video, Key point, the live's custom labels, "+ Create custom label…" (inline field, 24 characters, unique in the live ignoring case). "Delete label" for unused custom labels. Locked to "Your questions" on answers |
| Questions inbox | Statuses `nl_new`, `nl_shortlisted`, `nl_answered`, `nl_rejected`, `nl_spam`. Deterministic grouping of similar questions (site-language stop words, English fallback), ungroup, one Q&A entry answers a group |
| Who's online | Heartbeat-based presence list in the console. Transient or object cache only, never post or user meta. Long suspension only on the console screen |
| Console paging | 50 items, then "Load more", for the Feed, Drafts and Questions tabs |
| Team and roles | Team = co-authors ∪ `_newspack_live_team`. Contributors propose, editors publish |

### 4.2 Reader side (live page)

Order from top to bottom:

1. **Header**:
   - state badge;
   - "Updated 8:57 am" (today, no "today"), "Updated yesterday at …", "Updated Sep 27 at …" (plus the year when it differs);
   - title, standfirst, "Live hosted by …", text **Share** button, header media.
2. **Description**: post content, through the plugin pipeline. Hidden if empty.
3. **Context card**: the Context meta as a bulleted list, updated live on change.
4. **Ask a question** form.
5. "↑ N new updates" button (sticky below the theme header), the pinned entry, then the feed, **newest first only**.

The page has a light grey background across the viewport (`--nl-surface`); the Context card, the question form and the entries are white cards on it.

The Context and question titles are disclosure buttons with a chevron at the end edge: open by default on desktop, closed by default on mobile ≤ 781 px (the server renders them open, the view module closes them).

Rules for the feed and the page:
- **15 entries** on first render and per "Load older updates" page (filter `newspack_live_per_page`). The pinned entry doesn't count.
- **Editorial entries only** in the feed: no ad slot, no Campaigns prompt.
- Entry card:
  - top row: the time label on the left (`10:48 am` / `Yesterday, 10:48 am` / `Sep 27, 10:48 am`, year if different), then the **entry label** (bold, uppercase through CSS; Breaking and Key point in `--newspack-live-label-accent`, others in the text color; "Your questions" derived on answers), then the "Pinned" marker; **share icon** on the right;
  - Key point cards take the Context card's start border (`--nl-accent-border`);
  - the icon is a `<details>` disclosure, works without JS, accessible name "Share: {title}", target ≥ 24×24 px;
  - "✓ Link copied" shows for 2 s.
- Time labels use the site timezone and site time format, and are corrected client-side after midnight.
- Feed modules: standard, Q&A, media, quote, article card if present, pinned.
- **Sticky aside** (≥ 1024 px):
  - related coverage: primary category, tag, manual or Collection, max 4;
  - then the ad.
  - If the aside is taller than the viewport, it doesn't stick.
- Real-time:
  - the view module polls `/status` every 30 s while live (filter clamped 30–120 s), 60 s while upcoming, and stops when ended;
  - it fetches `/entries?since&until` deltas, applied idempotently by `rev`: edits, removals and pins in place; **new entries always wait behind the button**, whatever the scroll position; the tab title shows "(n) "; above 20 the button reloads the page, which catches up without the button (`sessionStorage` key `newspack-live-catch-up-{id}`);
  - it pauses when the tab is hidden.
- Entry permalinks `?live-entry={id}` and `?live-before={id}`.
- Listings (Homepage Posts, archives, search, related lists, Query Loop titles on block themes): badge before the title, "● Live", "Upcoming live" or "Live ended". **Never filter `the_title` globally.**

---

## 5. Architecture map

### 5.1 Data model

| Object | Storage | Key facts |
|---|---|---|
| Live | Regular `post` (filter `newspack_live_post_types`) + meta `_newspack_live_*` | Editor-REST meta (`show_in_rest` + `auth_callback`): settings (`Live_Meta::definitions()`) and the Context (`edit` context only: gated like the feed). **Server-managed meta is never exposed to core REST** (`_start`, `_end`, `_activity_at`, `_rev`, `_last_update`, `_removed`, `_questions_open`, …): the block editor would write stale values back |
| Description | Live `post_content` | Rendered through `Content::render()`, never `the_content` |
| Context | Live post meta `_newspack_live_context`: one `<ul>` of `<li>` (br, strong/b, em/i, a inside), normalized by `Context::to_list()` from anything `Context::ALLOWED_HTML` accepts | Reader-visible: a change bumps the revision and records it in `_newspack_live_context_rev`; the delta carries `context` only when that revision is in (since, until] |
| Entry | CPT `newspack_live_entry`, `post_parent` = live | Statuses `publish` / `draft` / `pending` / `trash`. Meta: pinned (at most one per live), question IDs (Q&A), `_rev`, `_published_at`, label `_newspack_live_entry_label` (preset slug or `custom:<id>`; "Your questions" is derived from the question IDs, never stored). Core REST controller requires `newspack_live_post_entries`, even for reads |
| Custom labels | Live meta `_newspack_live_labels`: list of `{id, text}` (8-character IDs, ≤ 50 per live) | Not in core REST; written by `Labels::create()` / `delete()` under the live's lock, no revision bump. A label in use (any entry status, trash included) can't be deleted |
| Question | CPT `newspack_live_q` (post type names are limited to 20 characters), private | Plain text ≤ 500 characters, display name ≤ 50, IP hash purged after 30 days, unanswered questions deleted 30 days after the live ends |
| Revision | `_newspack_live_rev` on the live and on each entry | Every reader-visible write runs under the MySQL named lock `newspack_live_{id}` (`send_reads_to_masters()` first): read rev from DB → +1 → write and stamp the entry → write the live rev last → clear the meta cache. A busy lock returns 503 `newspack_live_try_again`; a Context bump makes 3 short attempts, then schedules a one-off cron event |
| Removal log | `_newspack_live_removed` `[{id, rev, t}]` | Pruned after 24 h by the hourly cron |
| State | Computed at read time | none / draft / upcoming / live / ended. Virtual auto-end is persisted only by cron or the next write, **never during GET** |

Capabilities:
- Primitives: `newspack_live_manage`, `_post_entries`, `_publish_entries`, `_edit_others_entries`, `_moderate`.
- Meta capabilities: `newspack_live_manage_live`, `_post_to_live`, `_publish_to_live`, `_edit_entry`, `_moderate_live`, mapped in `map_meta_cap`.
- Editors and admins bypass team checks, so test team scoping with authors and contributors.

Setup routine:
- Keyed on the option `newspack_live_db_version`.
- Runs on activation, `admin_init`, `cli_init` and the hourly cron, never on front-end or REST requests.
- Capabilities are added with `wpcom_vip_add_role_caps()` when it exists.

### 5.2 REST (`newspack-live/v1`)

| Route | Notes |
|---|---|
| `GET /lives/{id}/status` | Public. 60 s cache when anonymous, `no-store` when authenticated |
| `GET /lives/{id}/entries?since=&until=` | Public delta: an immutable pair, 300 s cache |
| `GET /lives/{id}/entries?before=` | Public page of 15 (`per_page` ≤ 50), 60 s cache |
| `POST /lives/{id}/labels`, `DELETE /lives/{id}/labels/{label_id}` | Private, `newspack_live_publish_to_live` with the live ID. Custom entry labels |
| `POST /lives/{id}/questions` | Pipeline in this exact order: open? → registered? → honeypot → signed timestamp ≥ 3 s → lengths → rate limit → reCAPTCHA → Akismet → store |
| Private routes | Console bootstrap/refresh and paging (`/console`, `/console/entries`), team candidates (`/team-candidates`), entries CRUD, pin (`/entries/{id}/flags`), status, state actions, settings, questions list/bulk/ungroup, who's online leave (`/presence/leave`, the `sendBeacon` call). `no-store` |

- Gated lives: `/entries*` always answers `private, no-store`, and 403 to readers without access. On gated lives the view module fetches with `credentials: 'same-origin'` and a nonce.
- `wp_enqueue_scripts` runs during public REST entry rendering so inserted entries get their inline data and translations. The kill switch is the filter `newspack_live_rest_enqueue_assets`.
- Rendered entry HTML is cached in the object cache, group `newspack_live`. Keys are versioned by entry rev and markup version.
- Each revision bump fires `newspack_live_updated( $live_id, $rev )`.

### 5.3 Rendering

- Templates:
  - Newspack classic themes: plugin `templates/single-live.php`;
  - block themes: block template `newspack-live//single-live` containing the internal `newspack-live/live` block;
  - other classic themes: `the_content` replacement.
- The private content pipeline `Content::render()` covers entries and the description; the Context goes through `Context::render()` (its allowlist). **Never `apply_filters( 'the_content' )` on them**: it keeps content inserters such as Super Cool Ad Inserter out.
- Front JS: one Interactivity API view module (store `newspack-live`). Injected HTML contains no directives; interactions inside entries use event delegation.
- The console and the editor panel are React apps built from `@wordpress/*` packages, with a `@wordpress/data` store.
- Blocks: the internal `newspack-live/live` (`inserter: false`) and `newspack-live/article-card` (used in entries).

### 5.4 Newspack integrations (always guarded; the plugin works when they're absent)

| Integration | Extension point (re-check in the installed code before relying on it) |
|---|---|
| Newspack Ads | Aside placement only: `\Newspack_Ads\Placements::register_placement()`, `newspack_ads_suppress_ads` |
| Reader Activation | `openAuthModal`, `reader` event (sign-in without reload) |
| reCAPTCHA | `\Newspack\Recaptcha::can_use_captcha()` / `verify_captcha()` |
| Co-Authors Plus | `get_coauthors()`, "Live hosted by". Entries and questions are excluded from CAP |
| Content Gate | `newspack_is_post_restricted`, `newspack_post_has_restrictions` |
| Homepage Posts | `newspack_blocks_content_loop_entry_wrapper_start` (badge on its own line, 0.5em below) |
| Listing badges | `newspack_theme_categories` (classic Newspack themes; hidden on archives where the theme hides categories), `render_block_core/post-title` outside the post's own page (block themes); `Listings` |
| Collections | Related-coverage source. Listed by date, not editorial order. Falls back to the primary category when the module is off |
| Primary category | `\Newspack\Primary_Category::get()` |
| SEO | `LiveBlogPosting` JSON-LD, Open Graph per entry, Yoast filters (`wpseo_schema_*`, `wpseo_canonical`) |

### 5.5 Code map

- Model and services (`includes/`): `Live` (state, settings, team memoized per request, `with_lock()` / `write()`, removal log) · `Context` (meta, meta box) · `Labels` (entry labels: presets, custom labels, validation, "in use") · `Entries` (`save()`, `set_flags()` (pin), `transition()`; core edits caught on `wp_after_insert_post`) · `Questions`, `Question_Groups` (stop words in `includes/stopwords/`), `Question_Submission` · `Capabilities` (`map_meta_cap`) · `Setup` · `Cron` · `Uninstall` · `Presence` (who's online: Heartbeat payload `newspack_live_presence`, per-user transients, `sendBeacon` leave) · `Privacy`.
- Rendering: `Renderer` (layout, entry frame cached per rev, aside printed only when not empty) · `Feed` (15 per page, cursors, window; the pinned entry excluded in PHP) · `Content` (also `sanitize_svg()`) · `Dates` · `Related` · `Listings` · `Templates` (`templates/single-live.php`) · `Blocks` (`build/blocks/{live,article-card}`) · `Assets` (records the handles entries enqueue) · `SEO` · `E2E` (fake ad while `NEWSPACK_LIVE_E2E` is on).
- REST (`includes/rest/`): `REST_Public`, `REST_Private`, `REST_Cache` (Cache-Control), `Entries_Posts_Controller`. CLI: `includes/cli/class-command.php`. Integrations (`includes/integrations/`): Anti_Spam, Co_Authors_Plus, Collections, Homepage_Posts, Newspack_Ads, Reader_Activation.
- JS: view module `src/blocks/live/view.ts` (`LiveController`) with `feed.ts` (pure delta application), `times.ts` (time labels), `plural.ts`, `copy-feedback.ts`, `assets.ts`; console `src/console/` (store `newspack-live/console`, pure reducer; labels in `labels.ts` and `components/label-control.tsx`); editor panel `src/editor-panel/` (`panel.tsx`, `context-field.tsx`); shared admin API `src/shared/api.ts`. Initial front state: `Renderer::interactivity_state()`.
- E2E helpers: `tests/e2e/utils/api.ts` (`apiContext( role )`), `tests/e2e/utils/live.ts` (`createLive` with `content` = description and `context`, `addEntry`, `addQuestion`, `setMeta`, `setState`, `addFeaturedImage`, `permalink`, `cleanE2E`).

---

## 6. Commands

| Task | Command |
|---|---|
| Install | `npm ci` · `bin/composer.sh install` |
| Build / watch | `npm run build` · `npm run watch` |
| PHP lint | `bin/composer.sh lint` · `bin/composer.sh lint:fix` (`phpcs.xml.dist`: VIP-Go + WordPress-Extra + Docs + PHPCompatibilityWP, Newspack exclusions) |
| JS / CSS lint | `npm run lint` · `npm run fix:js` · `npm run format:js` |
| Types | `npm run typescript:check` |
| PHPUnit | `bin/composer.sh test` (separate test database, core from `vendor/roots/wordpress-no-content`; never the site database) |
| Jest | `npm test` (full suite only; colocated `src/**/*.test.ts`) |
| E2E | `npm run test:e2e` · `npm run test:e2e -- --grep @smoke` · `npm run test:e2e:clean` |
| Yoast e2e | `npm run test:e2e:yoast` (needs Yoast installed temporarily, see §2.2) |
| All checks | `bin/dod.sh` (lint, types, build, PHPUnit, Jest, e2e, `debug.log`) · `bin/dod.sh --no-e2e` |
| Budgets | `npm run test:budgets` (`bin/check-budgets.mjs`, on a real page) |
| Load check | `npm run test:load` (10 clients publish 100 entries) |
| Screenshots | `node bin/screenshots.mjs <version> [name=target#mode …]` → `artifacts/{version}/` (gitignored, excluded by `.distignore`). Themes: newspack-theme, newspack-joseph, newspack-block-theme; 1280 and 375 px. Modes `#top`, `#scrolled`, `#card`, `#live-coverage`, and `edit:<id>` (signed in with the e2e admin state). Run it outside the e2e suite, so the e2e fake ad doesn't show |
| Seed reset | `bin/wp.local.sh eval-file "$PWD/bin/seed-demo-content.php" --user=<admin login>` |
| Plugin CLI | `bin/wp.local.sh newspack-live …`: `doctor <live>` · `create-live [--title] [--state=draft\|upcoming\|live\|ended] [--content=<description>] [--context-html=<html>] [--excerpt] [--author] [--category=<slug>] [--e2e] [--porcelain]` · `add-entry <live> [--title] [--content] [--pinned] [--time="-30 minutes"] [--status] [--author] [--question-ids=1,2] [--label=<slug>\|custom:<id>] [--porcelain]` · `add-question <live> <text> [--name] [--status] [--user]` · `set-state <live> <go_live\|end\|reopen\|keep_live>` · `set-meta <post> <key> [<value>] [--time] [--delete]` · `run-cron hourly\|daily` · `reset-rate-limits` · `clean-e2e`. Modifying commands only touch `_newspack_live_e2e` / `_newspack_live_demo` content |
| Changelog / POT / version | `npm run changelog` · `npm run i18n:pot` · `npm run check:version` (release only) |
| Release zip | `npm run release:archive` → `release/newspack-live-{version}.zip` (refuses uncommitted or untracked files unless `ALLOW_DIRTY=1`; fails if the zip holds a file that is neither tracked nor build output or the no-dev autoloader; reproducible; never replaces an existing zip) |

`package.json` and `composer.json` are the source of truth for command names; fix this table in the same change if one differs.

---

## 7. How to work

### 7.1 Workflow

1. Branch from `main` (`feat/…`, `fix/…`).
2. Write tests first where practical, then make small Conventional Commits.
3. Run the checks (§7.2) before merging.
4. Review the diff against the guides of this file (security, performance, VIP, accessibility).
5. Releases: `chore(release): x.y.z` (version in plugin header + `NEWSPACK_LIVE_VERSION` + `package.json`, generated CHANGELOG and POT), verified zip, tag `vX.Y.Z`.

### 7.2 Definition of Done

1. `bin/composer.sh lint`: 0 errors, 0 warnings (only sniff-specific ignores, each with a reason).
2. `npm run lint`: 0 errors (Stylelint: 0 warnings).
3. `npm run typescript:check`: 0 errors.
4. `npm run build`: success.
5. `bin/composer.sh test`: green.
6. `npm test`: green.
7. `npm run test:e2e`: every active spec green. No `test.skip`; `test.fixme` only with a tracked reason. Fix flaky tests at the root.
8. `debug.log`: no new entry caused by `newspack-live` (`bin/debug-log.sh mark` before the e2e run, `check` after). `bin/dod.sh` runs items 1–8.
9. Docs updated: README, hooks, and **this file** if a command or pitfall changed.

Never bypass the git hooks (`--no-verify`).

### 7.3 Commits

- Format `<type>(<scope>): <subject>`: one line, ≤ 72 characters, imperative, lower-case, no trailing period, no body. Trailers come after a blank line.
- `feat` and `fix` only for changes newsrooms or readers can see.
- Allowed scopes are listed in `commitlint.config.js`.
- `CHANGELOG.md` and the POT are generated at release time and never edited by hand.

### 7.4 Coding conventions

- PHP 8.1+:
  - namespace `Newspack_Live` (`\REST`, `\CLI`, `\Integrations`);
  - one `final` class per file, `class-{name}.php`;
  - a static `init()` that only adds hooks, wired from `Plugin::init()`;
  - Composer classmap (run `bin/composer.sh dump-autoload` after adding a class).
- Short arrays `[]`, no Yoda conditions, strict comparisons, no short ternary `?:`, no closures as hook callbacks, docblocks on every symbol and hook.
- Prefixes:
  - PHP hooks and options: `newspack_live_`;
  - meta: `_newspack_live_`;
  - blocks: `newspack-live/`;
  - handles: `newspack-live-`;
  - CSS BEM: `.newspack-live…`;
  - constants: `NEWSPACK_LIVE_`;
  - CLI: `wp newspack-live`;
  - text domain: literal `'newspack-live'`.
- Every constant read with `defined()` has a docblock: what it does, its type and its default.
- i18n: `/* translators: */` comments, numbered placeholders, `_n()` for plurals (the client receives plural rules), `_x()` for ambiguous words. Dates come from `wp_date()` with the site formats; `<time datetime>` uses ISO 8601.
- JS:
  - TypeScript for new console code;
  - imports grouped External / WordPress / Internal;
  - `@wordpress/*` packages stay externals;
  - notices follow Newspack's politeness rules;
  - announcements go through `speak()`.
- CSS:
  - SCSS, logical properties, RTL output;
  - theme tokens on the front end, WordPress admin colours in the back office;
  - no `!important` without a comment.
- Accessibility: WCAG 2.2 AA (keyboard, labels, focus, live regions, contrast, reduced motion, forced colours). Role-based locators in Playwright.
- Every integration is guarded (`class_exists`, `function_exists`, `defined`), and its "absent" branch is tested.
- Never print passwords, tokens or `.env*` contents.

---

## 8. Known pitfalls

| Symptom | Cause / rule |
|---|---|
| `wp` or `php` fails with "Access denied" or the wrong version | Another PHP (e.g. Homebrew). Use `bin/wp.local.sh` and `bin/php.local.sh` |
| `wp db query` fails through the wrapper (access denied) | Use `wp eval` with `$wpdb`, or `wp db tables`, for read-only checks |
| First e2e tests fail right after setup | Local's PHP cache sees `wp-config.php` changes seconds late. Global setup waits for the `X-Newspack-Live-E2E` response header |
| Login click ignored in e2e | Wait until the login page is stable before clicking |
| `NEWSPACK_LIVE_E2E` left in `wp-config.php` | An interrupted run. Remove it, check the response headers, never ship with it |
| Content disappeared after an e2e run | E2E cleanup must select by `_newspack_live_e2e` or e2e author only, never by type or status. Mark debug-script content too |
| `navigator.share` / `navigator.clipboard` missing | A local site over http. Test the fallbacks, stub Web Share with `page.addInitScript` |
| Context change not visible on a quiet live | The named lock was busy. The bump must retry, then schedule a one-off event; it must never depend on another write |
| "Today/Yesterday" wrong after midnight | Labels are computed in the site timezone and corrected client-side on each poll and every minute. A wrong device clock is a known limitation |
| PHPCS flags `post__not_in` | Use `per_page + 1` to compute `has_more`, or a (date, ID) cursor |
| Presence drops after 10 minutes | Core Heartbeat suspends idle screens. The longer suspension applies only on the console screen, never across wp-admin |
| Blocks with their own inline `<script>` don't run inside a live | A consequence of the script rule (§2.3). Known limitation |
| Inserted entries miss inline data or translations | `wp_enqueue_scripts` runs during public REST rendering. Check the `newspack_live_rest_enqueue_assets` filter |
| Screenshots ended up in the zip | `artifacts/` must stay in `.distignore` and `.gitignore` |
| Release zip differs between builds | The release script refuses untracked files; zips must be reproducible |
| A local file ended up in the zip | Local agent files (`AGENTS.*.md`, `CLAUDE.*.md`) and `/docs` are in `.distignore`; the release script also fails on any shipped file that isn't tracked, build output or the no-dev autoloader |
| A badge leaks into feeds, admin or SEO titles | Never filter `the_title` globally. Use listing-specific hooks, or skip the listing |
| Ad inserters inject into entries | Entries, description and Context never go through `the_content` |
| Stale meta written by the block editor | Server-managed meta must not be `show_in_rest` |
| Collections menu moved the Live menu | Keep Live right after Posts. Check the menu position when Newspack menus change |
| Demo copy looks wrong on demo day | Avoid clock times in seed copy |
| Client store defaults override `wp_interactivity_state()` values | Declare only getters in the client `store()` |
| A 403/404 on an admin page comes too late | Admin page callbacks run after the header: refuse access on `load-{hook}` |
| Translated strings lose their spaces | ESLint's `@wordpress/i18n-no-flanking-whitespace` autofix strips them: use placeholders (`'%1$s, %2$s'`) |
| TS errors on `@wordpress/block-editor` / `block-library` imports | They ship no types: see `src/types/wordpress.d.ts`. The rich-text store is typed as a plain object: cast it to `StoreDescriptor` |
| `@wordpress/ui` import drops the script | `wp-ui` isn't registered on WordPress 7.1: use core components |
| `SelectControl` type errors | It infers literal unions from inline options: build options with a `string`-typed helper |
| Auto-end doesn't trigger in a test | The reference is `max( _activity_at, start )`: backdate `_start` too |
| PHPUnit REST tests miss cache headers | `rest_do_request()` skips `rest_post_dispatch`: apply it in the test |
| A question made only of a `<script>` is refused | `sanitize_textarea_field()` removes the script with its content |
| A stub leaks between PHPUnit tests (Akismet) | Use `@runInSeparateProcess @preserveGlobalState disabled` |
| A plugin taxonomy survives between test classes | The test library only resets core taxonomies: unregister in `tear_down()` |
| Tests and setup state | `tests/bootstrap.php` runs `Setup::run()` outside the per-test transactions. Stubs in `tests/mocks/`: Co-Authors Plus (`$GLOBALS['newspack_live_test_coauthors']`), Collections (`$GLOBALS['newspack_live_test_collections']`), Akismet |
| Co-authors set through the stub don't apply | `Live::get_team()` is memoized per request: call `Live::flush_team()` |
| Busy-lock tests | Hold the live's named lock from a second `mysqli` session (`hold_lock()` in the context-bump tests): named locks are per session and ignore test transactions |
| `post_status => 'any'` misses questions | It skips statuses excluded from search: list the question statuses explicitly. Newspack Sponsors' post type is excluded from `post_type => 'any'` too |
| Live block doesn't fill the page | Newspack's `main.site-main` is a flex container: the block wrapper needs `width: 100%` |
| Paragraph markup differs from fixtures | WordPress 7 adds `class="wp-block-paragraph"` to rendered paragraphs |
| Setup didn't run after activation | Activation runs after `init`, without the plugin's post types: `activate()` only adds caps and resets the version; the next `admin_init`/`cli_init` completes it |
| `parent` ignored on the entries REST route | Core ignores it for non-hierarchical types: the entries controller handles it |
| Co-hosts aren't CAP guest authors | Newspack disables them: co-hosts who aren't staff are "Guest Contributor" users, and `$coauthors_plus->guest_authors` is null |
| Budget misses a stylesheet | WordPress inlines small block stylesheets (`<style id="{handle}-inline-css">`): measure them from the page |
| Axe flags core's RichText | `aria-allowed-attr` on `role="document"`: excepted for that rule and node only |
| Strings missing from the POT | WP-CLI's `make-pot` doesn't parse `.ts/.tsx`: the POT is built from `build/`; keep `/* translators: */` inline with `__()` |
| Reader Activation's `reader` event | Callbacks get a `CustomEvent`: read the reader with `ra.getReader()` |
| `<summary>` has no button role in Playwright | Select share controls by class and check `toHaveAccessibleName()` |
| e2e tests the old front end | Specs use `build/`: run `npm run build` after changing `src/` |
| Feed pages skip or repeat entries | Pages and `count_older()` use an exact `(post_date_gmt, ID)` keyset (`Feed::KEYSET_VAR`); console lists use the same keyset, drafts on the ID alone (`Feed::get_status_page()`, `Feed::THROUGH_LIMIT`) |
| Entry assets in REST responses | `Assets::snapshot()` fires `wp_enqueue_scripts` once outside page renders for its registrations only, then restores the queues |
| Context box not mounted | It is a meta box moved into the editor after render: `context-field.tsx` waits for `#newspack-live-context-root` and portals a `BlockEditorProvider` with its own registry (block editor store + a rich-text store with bold, italic, link only); blocks are rebuilt from the list with `contextItems()` (one paragraph per bullet) |
| Screenshot shows a half-turned chevron | The chevron rotates in 0.2 s: wait before shooting a section just folded |
| `create-live --context` says "Unknown context" | WP-CLI has a global `--context` flag: the plugin option is `--context-html` |
| Context box hidden in e2e or screenshots | The editor's "Meta Boxes" pane keeps its collapsed state per user; open it by keyboard (the resize handle covers the toggle) |
| Badge missing on classic archives | It opens the category line: hidden when the site hides categories on archives (e.g. Jetpack Content Options). Known limitation: don't add a hook or move the badge |
| A failed meta-box request after an editor save | The Context box is the post's only meta box, so core sends its meta-box request on every save; nothing of ours is saved there (the Context goes through REST). Known limitation |
| Query Loop blocks have no `queryId` | The post template passes only `postId`/`postType` to its blocks: the block-theme badge applies to every post title except on the post's own page |
| Context meta missing in PHPUnit | The test library unregisters every meta key after each test and the posts controller caches its schema: call `Context::register()` and reset the controller's schema in `set_up()` (see `Test_Context`) |
| Live page background cut at the column, or horizontal scroll | It is a `border-image` outset on `.newspack-live`: ink overflow, never a wide box, negative margins or `overflow`/`clip-path` |
| Sticky aside doesn't stick | An ancestor with `overflow: hidden` breaks `position: sticky` |
| Count strings in the wrong plural form | They use `state.plurals` (`Renderer::plurals()`, counts 0–199) through `src/blocks/live/plural.ts` |
| PHPUnit can't run | `tests/wp-tests-config.php` is gitignored (copy the `.example.php`); `WP_PHP_BINARY` is `bin/php.local.sh` (Local's PHP path has a space); `wp-phpunit` must match the core version |
| Jest flags ignored | `newspack-scripts test` can't forward them |
| Blocks build uses the wrong config | wp-scripts picks up `webpack.config.js`: the blocks build passes `--config webpack.blocks.config.js` |
| e2e users | `nl-e2e-*` carry the user meta `_newspack_live_e2e = 1` and are never deleted. Their passwords pass on the `wp user` command line in global setup (local sites only) |
| Contributors see no "Live" flyout | Core drops a submenu with a single item; such a menu gets `current`, not `wp-has-current-submenu` |
| Times show "pm", not "PM" | Labels follow the site time format (e.g. `g:i a`) |
| Integrations miss Newspack classes at load | Plugins load alphabetically (newspack-live first): add hooks unconditionally, check `class_exists`/`function_exists` when called |
| INFO lines in `debug.log` | Newspack Newsletters' email editor logs them on every request: not ours |
| Heartbeat tests | Core's `heartbeat_received` callbacks take three arguments (pass a screen ID). Playwright's `page.close()` doesn't fire `pagehide`: navigate away to test the leave beacon |
| Scripts in entries | Content saved without `unfiltered_html` goes through `Entries::filter_untrusted_markup()`; rendering keeps only external scripts with an empty body (`Content::strip_inline_scripts()`) |
| Real-time e2e specs are slow | They wait for real 30 s polls (180 s timeout): the full suite takes several minutes |
| A new entry never appears in a real-time spec | New entries wait behind the "N new updates" button whatever the scroll position: click it (`getByRole( 'button', { name: '1 new update' } )`) |
| Scripts blocked on a page served by `route.fulfill()` | Chrome treats the fulfilled document as public and its Local/Private Network Access checks block same-host scripts: disable those features with a top-level `test.use( { launchOptions } )` in that spec only (`new-updates-page-cache.spec.ts`) |
| Jest can't import `@wordpress/components` | Its ESM dependencies (`@wordpress/ui`) don't load under `newspack-scripts test`: component tests stub it with native stand-ins (`label-control.test.tsx`); check the real ARIA wiring in e2e |
| Keyboard `End`/arrows don't change a closed `<select>` in e2e on macOS | Chromium on macOS opens the popup instead: use type-ahead (`page.keyboard.type( '+' )`) to pick an option by keyboard |
| A label set in the full editor is lost, or a label isn't in core REST | By design: labels go through `Entries::save()` (console routes, CLI, seed) so the "Your questions" lock and the live's custom labels are enforced; core REST leaves them untouched |
| `add-entry --content` shows raw tags | The CLI stores `--content` as text in a paragraph: publish markup through REST as an editor (`apiContext( 'editor' )`), as the console does |
