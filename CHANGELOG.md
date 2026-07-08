# Release Notes for Quick Poll

## 1.1.1 - 2026-07-08

### Changed
- `LICENSE.md` now contains the official Craft License text, as required for
  commercial plugins in the Craft Plugin Store.

## 1.1.0 - 2026-06-24

### Added
- Front-end widget now ships with the plugin as the templates `quick-poll/widget`
  and `quick-poll/results`. Embed with `{% include 'quick-poll/widget' with
  { poll: poll } %}` — it registers its own JS/CSS, renders all four poll types,
  works without JavaScript and is overridable by copying into your own templates.
- Optional *Allow changing the answer* (`allowRevote`, off by default) — when a
  poll is open, voters can reopen the form via a **Change answer** button and
  update their vote (the existing replace-on-revote backend). The reopened form
  is pre-selected with the voter's previous choice. JS-only enhancement; the
  button stays hidden without JavaScript.
- `craft.quickPoll.myBallot(poll, targetId)` — the current visitor's own ballot,
  shaped for pre-selecting the re-vote form.

### Changed
- Translations: added the front-end widget strings in all five languages and
  removed leftover keys from the pre-1.0 scaffold workflow.

## 1.0.0 - 2026-06-03

Initial release.

### Added
- Four poll types: `rating` (1–5 stars), `choice` (radio / multi-checkbox),
  `mood` (emoji options) and `grid` (Doodle-style yes/maybe/no per option).
- **Poll** element — a plugin-managed, localizable element with its own control
  panel section. The question and options translate per site via Craft's native
  site switcher; no field layout or section setup is required on the host.
- **Quick Poll** field (`pixelwerft\quickpoll\fields\PollField`) to embed a poll
  into any field layout, Matrix or content block.
- Entity-attached polls: one poll definition reused across many entries with
  votes scoped per `targetId`.
- No-JS-first widget (native POST→redirect) with progressive enhancement to
  inline voting and live results.
- Access modes `public` (dedup by `sha256(ip + cookie + pepper)`) and `members`
  (dedup by user id), enforced by a `UNIQUE(pollId, targetId, voterHash,
  optionKey)` index.
- Result visibility modes: `afterVote`, `always`, `afterClose`, plus an optional
  *Open until* close date.
- Optional per-poll share button (`showShare`, off by default) shown alongside
  the results.
- Optional *Hide after it closes* (`hideAfterClose`, off by default) — removes the
  widget entirely once the poll is closed instead of showing the closed state.
- Per-site *answer* text (`resultText`) revealed above the results — e.g. the
  correct answer or a comment.
- Control-panel overview with type, status and distinct-voter count per poll,
  and a streamed CSV export of aggregated results (UTF-8 BOM for Excel).
- Live result panel in the control-panel poll editor (right-hand details pane),
  including a per-user voter list for members-only polls.
- Optional poll categories: pick a category group in the settings, assign one or
  more categories per poll, filter the CP overview by category, and list polls by
  category on the front end via `craft.quickPoll.byCategory()`.
- `craft.quickPoll` Twig API: `poll()`, `forPoll()`, `isOpen()`, `hasVoted()`,
  `canSee()`, `baseCssUrl`.
- Structure-only base stylesheet (CSS + SCSS source) themable via `--qp-*`
  custom properties, with a `loadBaseCss` opt-out.
- Plugin settings: `loadBaseCss`, `voterPepper`, `defaultResultsVisibility`,
  `resultsCacheDuration`.
- Translations for German, French, Italian, English and Spanish.
