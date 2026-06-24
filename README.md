# Quick Poll for Craft CMS

Lightweight, self-hosted polls & votings for Craft CMS 5 — star ratings,
A/B/C choices, mood pickers and Doodle-style yes/maybe/no grids. Polls are a
first-class, localizable element you author in the control panel and drop into
any template, entry or Matrix block with a single field.

Written for teams who want *engagement-grade* voting on their own site —
no third-party embed, no tracking pixels, no data leaving the server — and who
need the same poll to read and vote correctly in every language the site runs.

## Features

- **Four poll types**: `rating` (1–5 stars), `choice` (radio / multi-checkbox),
  `mood` (emoji options) and `grid` (yes/maybe/no per option). The grid is the
  Doodle-style table — one mechanic for **date-finding, task assignment and
  design voting**, scored with `yes` = 1, `maybe` = ½.
- **Polls are a real element**: plugin-managed, localizable, with their own CP
  section. The question and options translate **per site** through Craft's
  native site switcher — no field layout or section setup required on the host.
- **One field to embed**: the **Quick Poll** relation field drops into any field
  layout — an entry, a Matrix/content block, a global set — to let editors pick
  a poll. Render it with one include.
- **Entity-attached polls**: reuse a single poll definition across many entries
  with votes scoped **per target** (e.g. one "Rate this" poll attached to every
  product, with independent results each).
- **Works without JavaScript**: the widget votes via a native POST→redirect and
  progressively enhances to inline voting + live results when JS is available.
  The JS ships from the plugin, outside the host's build pipeline.
- **Access & deduplication**:
  - `public` → one vote per `sha256(ip + cookie + pepper)` (engagement-grade).
  - `members` → one vote per user id (hard).
  - A `UNIQUE(pollId, targetId, voterHash, optionKey)` index is the whole dedup
    story — enforced in the database, not just the app.
- **Result visibility** per poll: `afterVote`, `always`, or `afterClose`.
- **Scheduling**: an optional *Open until* date closes voting automatically.
- **Control-panel overview**: every poll with its type, open/closed status and
  distinct-voter count, plus a streamed **CSV export** of aggregated results
  (UTF-8 BOM, so Excel reads umlauts).
- **Localized votes**: each vote records the site it came from, so results can be
  read globally or per language.
- **Themable base CSS**: ships a *structure-only* stylesheet (no card chrome) so
  the widget flows inside your content. Tune via CSS custom properties, swap in
  your own SCSS partial, or turn the base off entirely.
- **i18n**: control panel and widget translated in German, French, Italian,
  English and Spanish.

## Screenshots

| | |
| --- | --- |
| ![Polls overview](src/resources/screenshots/quick-poll-overview.png) | ![Poll editor](src/resources/screenshots/quick-poll-edit.png) |
| CP overview: type, status and voter count per poll, with CSV export. | Poll editor with the native per-language site switcher. |

## Installation

Via Craft's plugin store:

```
./craft plugin/install quick-poll
```

Or via Composer:

```
composer require pixelwerft/quick-poll
./craft plugin/install quick-poll
```

Installing creates the plugin's tables and registers the **Polls** CP section,
the **Poll** element and the **Quick Poll** field. There is no scaffold step and
nothing is written to your project config — polls are runtime data, not content
YAML.

By default the section is **admin-only**; grant other control-panel users the
`accessPlugin-quick-poll` permission to let them manage polls.

## Poll types

| Type     | Voting UI                | Storage (`optionKey` / `value`)         |
|----------|--------------------------|-----------------------------------------|
| `rating` | 1–5 stars                | `"1"`..`"5"` / —                         |
| `choice` | radio or checkbox        | option index / —  (multi-select capable)|
| `mood`   | emoji options            | option index / —                        |
| `grid`   | yes/maybe/no per option  | option index / `yes`\|`maybe`\|`no`      |

`grid` is the Doodle-style table: every option gets a yes/maybe/no answer. The
result view shows a per-option breakdown and highlights the best-scoring option
(`yes` counts full, `maybe` counts half).

## Embedding a poll

The widget ships with the plugin as the template `quick-poll/widget` — it
registers its own JS/CSS, renders all four poll types and works without
JavaScript. Add a **Quick Poll** field to any field layout, pick a poll, then
render it:

```twig
{% set poll = entry.myPollField.one() %}
{% if poll %}
    {% include 'quick-poll/widget' with { poll: poll } %}
{% endif %}
```

### Entity-attached polls

Reuse one poll across many entries, with votes scoped per target:

```twig
{% include 'quick-poll/widget' with {
    poll: craft.quickPoll.poll(123),   {# resolve by id #}
    target: entry                       {# the entry being rated #}
} %}
```

Votes carry `targetId = target.id`, so each target aggregates independently.
Omit `target` to embed standalone (poll-level votes, `targetId = 0`). The dedup
guarantee is per `(poll, target, voter)`.

## Access & deduplication

`pollAccess = public | members`.

- `members` → voter hash is `u:<userId>`; one vote per logged-in user (hard).
- `public`  → voter hash is `sha256(ip + cookieId + pepper)`; one vote per
  device/network (engagement-grade, not ballot-grade).

Set a server-side `voterPepper` (below) so a stored hash can never be reversed
to an IP.

## Result visibility

Per poll, `resultsVisibility` decides when a visitor sees the tally:

| Value        | Results shown…                                  |
|--------------|-------------------------------------------------|
| `afterVote`  | once the visitor has voted (or the poll closed) |
| `always`     | immediately, before voting                      |
| `afterClose` | only after the *Open until* date passes         |

## Settings

Available under *Settings → Plugins → Quick Poll*, or in code via
`config/quick-poll.php`:

| Setting                    | Default      | Effect                                                                 |
|----------------------------|--------------|------------------------------------------------------------------------|
| `loadBaseCss`              | `true`       | Auto-load the plugin's base stylesheet with the widget                 |
| `voterPepper`              | `''`         | Server secret mixed into the anonymous voter hash. Set per environment |
| `defaultResultsVisibility` | `afterVote`  | Fallback visibility when a poll sets none (`afterVote`/`always`/`afterClose`) |
| `resultsCacheDuration`     | `15`         | Seconds the public results endpoint may be cached (`0` disables)       |

```php
<?php
// config/quick-poll.php
return [
    'voterPepper'             => getenv('QUICK_POLL_PEPPER') ?: '',
    'defaultResultsVisibility' => 'afterVote',
    'resultsCacheDuration'     => 15,
    'loadBaseCss'             => true,
];
```

Keep the real `voterPepper` in an environment variable rather than committing it.

## Endpoints

The widget talks to two plugin actions (both allow anonymous access):

```
POST actions/quick-poll/vote/submit
     pollId      (required)
     targetId    (default 0)
     options[]   selected option keys
     values[]    yes|maybe|no per option (grid only)

GET  actions/quick-poll/results/show?pollId=123[&targetId=456]
     → aggregated, template-ready JSON
```

## Templating API

The `craft.quickPoll` variable exposes read-only helpers:

```twig
{{ craft.quickPoll.poll(123) }}              {# resolve a Poll element by id #}
{{ craft.quickPoll.forPoll(poll) }}          {# aggregated results array #}
{{ craft.quickPoll.isOpen(poll) }}           {# voting still open? #}
{{ craft.quickPoll.hasVoted(poll) }}         {# current visitor voted? #}
{{ craft.quickPoll.canSee(poll) }}           {# may see results now? #}
{{ craft.quickPoll.myBallot(poll) }}         {# current visitor's own ballot #}
{{ craft.quickPoll.byCategory('slug') }}     {# polls in a category, front-end #}
{{ craft.quickPoll.baseCssUrl }}             {# URL of the base stylesheet #}
```

## Styling

The base stylesheet (`src/resources/poll.css`) is **structure only**: layout,
the bar/pill/grid skeleton, neutral defaults — no card decoration, so the widget
flows inside your content area like any other block.

- **Tune** via CSS custom properties on `.qp-poll`: `--qp-accent`, `--qp-track`,
  `--qp-radius`, `--qp-gap`, `--qp-max` (default `none` → fills the content area).
- **SCSS users**: `src/resources/poll.scss` is the same base as a source partial
  with `$qp-*` variables — `@use` it in your build and extend.
- **Ship your own CSS**: turn off `loadBaseCss`. The base file URL is still
  available: `<link rel="stylesheet" href="{{ craft.quickPoll.baseCssUrl }}">`.
- **Override the template**: copy the plugin's `templates/widget.twig` (and
  `templates/results.twig`) into your own templates and include that copy
  instead; the endpoints, `data-*` hooks and CSS classes stay the same, so
  `poll.js`/`poll.css` keep working.

## Database

Three plugin-owned tables, all under the `quickpoll_` prefix:

| Table                   | Holds                                                       |
|-------------------------|-------------------------------------------------------------|
| `quickpoll_polls`       | one row per poll — non-localized settings (type, access, …) |
| `quickpoll_polls_sites` | one row per `(poll, site)` — the localized question + options |
| `quickpoll_votes`       | one flat row per vote, with the `UNIQUE` dedup guard         |

Votes are deliberately **not** project config, so they never travel through CI.
Foreign keys cascade from `{{%elements}}`, so deleting a poll removes its votes.

## Releases

Versions are cut from git tags (`vX.Y.Z`). The Craft plugin store reads the
changelog from [`CHANGELOG.md`](CHANGELOG.md) via the raw URL declared in
[`composer.json`](composer.json) → `extra.changelogUrl`.

## License

Commercial — © pixelwerft. Licensed, not sold; see [LICENSE.md](LICENSE.md).
Available through the [Craft Plugin Store](https://plugins.craftcms.com).
