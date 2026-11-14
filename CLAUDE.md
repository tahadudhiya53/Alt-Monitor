# CLAUDE.md — AltMonitor

Development guide for the AltMonitor Craft CMS plugin. Read it before changing anything; the code
is the source of truth for what exists, this file for the rules behind it.

## Identity

| | |
|---|---|
| Handle | `alt-monitor` |
| Composer package | `tahadudhiya53/craft-alt-monitor` (path repository in the host project) |
| Namespace / tests | `Tahadudhiya\AltMonitor\` / `Tahadudhiya\AltMonitor\Tests\` |
| Plugin class | `Tahadudhiya\AltMonitor\AltMonitor` |
| Target | Craft CMS `^5.9.0` (host runs 5.11), PHP `>=8.2.0` (platform pinned to 8.2.0) |
| Tables / permissions | `altmonitor_*` / `altMonitor:*` |
| Version / schema version | `5.0.0` / `1.0.0` — never bump them during development; `PluginTest` fails if either moves |
| License | MIT (`PluginTest` checks). Bundled Transformers.js is Apache 2.0 |

**Product rule (decided by the user): AltMonitor is free for everyone, needs no budget, and has no
AI switch in the control panel. By default suggestions describe the real image, never the file
name.**

Conventions shared with the sibling plugins (Smart-Links is the closest reference): thin plugin
class, typed service components with getters, records with a `TABLE` constant used only for
schema, one `Install` migration until release, enums with `label()`, strict `fromParams()` query
criteria, and tests grouped by category rather than by class.

## Architecture

```
Controller (authorize → validate → delegate → respond)
   └─ Service (business logic, owns its table)
        └─ Craft element API / Query on its own table
Queue job (orchestration: one bounded batch, then push the next)
```

| Component (getter) | Class | Owns / does |
|---|---|---|
| `analyzer` | `services/AltTextAnalyzer` | The only place alt text is classified. Pure rules, no DB |
| `suggestionGenerator` | `services/SuggestionGenerator` | Rule-based suggestions from an `ImageContext`. Pure |
| `assetScanner` | `services/AssetScanner` | Finds image assets with `Asset::find()`, audits batches / one asset |
| `audits` | `services/Audits` | Only writer of `altmonitor_audits`; image list, dashboard stats, volume visibility |
| `contentUsage` | `services/ContentUsageScanner` | Only writer of `altmonitor_usages`; reads relations + reference tags |
| `scans` | `services/Scans` | Only writer of `altmonitor_scans`; start (mutex, one at a time), progress, stale detection |
| `suggestions` | `services/Suggestions` | Only writer of `altmonitor_suggestions`; reserve/queue, generate, approve, reject |
| `altTextEditor` | `services/AltTextEditor` | The only code that writes alt text to an asset (`Elements::saveElement`) |
| `assetsUi` | `services/AssetsUi` | Craft's own asset screens: the editor sidebar box (`_sidebar.twig`) and the Assets index column. Never writes |
| `imageExport` | `services/ImageExport` | The Images list as CSV (`Audits::all()`: the list's query, every page, batches of 200). Never writes |
| `aiProviders` | `services/AiProviders` | Builds the (Ollama) provider from settings; `EVENT_DEFINE_PROVIDER` lets code replace it; `browserConfig()` for the browser; `describerConfig()` (Describe buttons, null with Ollama) and `translatorConfig()` (Translate, also with Ollama) |

### Describing images (default: in the browser)

`web/assets/cp/dist/describe-worker.js` (module worker) imports the bundled
`vendor/transformers.min.js` (Transformers.js 4.3.1, the self-contained build) and runs
Florence-2 (`onnx-community/Florence-2-base-ft`, `dtype: 'q8'`, `device: 'wasm'`). Each describe
message carries `avoid` (the image's alt text and current suggestion, sent by the image endpoint
as the URL-encoded JSON header `X-AltMonitor-Known`, plus the last 8 texts this browser wrote for
it, `localStorage altmonitor.history.<asset>-<site>`); the worker walks `PLAN` (`<CAPTION>`,
`<DETAILED_CAPTION>`, sampled variants, `<MORE_DETAILED_CAPTION>`), cleans each answer with
`shorten()`, and returns the first one not in `avoid` (`fresh: false` when all repeat), so
describing again gives a different description. Progress shows in the `<dialog>` from
`_describer.twig` (image preview, new text, per-image log, Stop that ends the worker at once,
Review / Close, and the time left: 10 s per image until measured; describing everything gets the
total from the first `suggestions/next` answer). Close never reloads: `refreshAfterDescribing()`
fetches the page and swaps only its `data-altmonitor-refresh` parts (image rows only for the
images described), keeping typed text and ticks, then runs `initLists()` on each part.
`cp.js` (`Describer`) creates the worker from `AiProviders::browserConfig()` (rendered as
`data-config` by `templates/_describer.twig`), then per image: GET
`alt-monitor/suggestions/image` (resized JPEG via `ImageProcessor::jpeg()`, needs view +
generate and `Elements::canView`) → worker → POST `alt-monitor/suggestions/store`
(`Suggestions::storeDescription()`: `PromptBuilder::parse()`, sanitize, weak check, stored as source
`ai`, basis “Florence-2-base-ft (in the browser)”, needs review). Walking every image wanting one
uses GET `alt-monitor/suggestions/next?after=` (10 at a time, viewable volumes only). The model
(~277 MB) comes from Hugging Face and the ONNX runtime (~27 MB) from jsDelivr unless
`browserModelHost` / `browserWasmPath` are set. Verified in headless Chrome:
`tests/browser/run.mjs` (≈33–48 s first load, ≈1.3 s from cache, ≈10 s per image and ≈20 s when
the first caption is already known, single-threaded WASM; it also checks re-describing differs,
and translates one description with `Xenova/opus-mt-en-de`, checking its files are cached).

**The download needs consent (product rule).** Nothing is downloaded without the person agreeing
on the Image model page (`ModelController`, route `alt-monitor/image-model`, needs view +
generate, `templates/model/_index.twig`, `ModelPage` in `cp.js`). `cp.js` `modelStatus()` decides
“downloaded” by finding the files `describe-worker.js` loads in Cache Storage
(`transformers-cache`, `MODEL_FILES`; keep both in sync if the dtype or model changes). Describe
buttons go through `describe()`: when the model is missing they save the request in
sessionStorage and redirect with `?return=` (accepted only for URLs under the CP's full
`alt-monitor` URL, including `?p=` on query-string CP URLs, and never with `..` or `%2e`/`%2f`/`%5c`,
`ModelController::returnUrl()`); when the download finishes and the model is kept, the page
shows **Continue describing** (focused) linking back to that URL, where `resumePending()` runs the
request once. If the browser
loaded the model but didn't keep it (private window, storage full) `localStorage
altmonitor.notKept` stops further redirects. Sizes shown on the page come from
`ModelController::actionIndex()`. The page's states use `data-model-state` (Craft's nav already
uses `data-state`).

Server-side generation (queue jobs, `regenerate`, `generate-all`, bulk `generate`) only runs when
Ollama is configured (`Suggestions::generatesOnServer()`); otherwise those actions refuse with
`SuggestionsController::inBrowserMessage()`, and templates show the Describe buttons instead.
Rule-based suggestions (`SuggestionGenerator`) are only used with `fallbackToRules`, default off.

**Other languages (product rule: translating is separate from describing, and optional).**
A site's suggestion language is `Translations::languageFor($site)`: `Settings::$siteLanguages`
(by site UID, set per site on the Settings page; only `Translations::choices()`, the site's own
language is stored as nothing, unknown UIDs dropped by `Settings::normalizeSiteLanguages()`), else
the site's language. Never read `$site->language` directly for suggestions.
Florence-2 writes English only. `ai/Translations` maps a site language's primary subtag to an
Opus-MT model (`Xenova/opus-mt-en-xx`, q8, single-target models only; zh except Traditional; ja
left out, Bible-only training) with its size; `browserConfig()['translations']` is that per site
ID. Each language is its own download: a row on the Image model page (`data-translation`, own
agreement `data-translation-agree`, `ModelPage.translationRow()`), never part of the image
model's 304 MB, never fetched by Describe. `modelStatus()` checks the image model only;
`translationStatus()` checks a model's `TRANSLATION_FILES` + the runtime (verified by the browser
test); `removeModel(config, model)` keeps the shared runtime while another model needs it.
Worker requests carry an `id` (`Describer.request()`): `load` (`image` bool + `translations`),
`describe` (`translate` = a model, used only if `downloadedTranslations()` has it; each `PLAN`
attempt is translated *before* comparing with `avoid`, which holds site-language texts), and
`translate` (text alone, never loads Florence-2). Translators load lazily (posts `translator`;
at most 2 kept). The store request sends `translated`; the server records
`AiProviders::browserModelLabel($model, $translationModel)` only when the site's language has a
model. No schema change: `Suggestion::needsTranslation()` (AI source, basis without “ + ”, site
not English) drives `_macros.translationNote(suggestion, describer)` on all four screens, with
**Translate into {language}** (`data-translate-suggestion`) where `Suggestion::translation()` has a
model and the page has `translator` (`AiProviders::translatorConfig()`: the browser config for
anyone who may generate, also with Ollama, whose suggestions are English too; `describer` stays
null with Ollama and only gates Describe). Pages include `_describer` with `{describer:
translator}`; with Ollama the Image model page hides the image model's status and consent
(`ModelPage` then only sets up translation rows). It runs `Describer.translateSuggestions()` (same
dialog, title swapped) → POST `suggestions/translate` (`Suggestions::storeTranslation()`: pending,
untranslated AI suggestion only; replaces the text, `AiProviders::translatedBasis()`). A missing
model sends the person to `alt-monitor/image-model?translation=xx&return=…` (that row is marked
`data-wanted`; `pending.translate` resumes once, also from the sidebar via `pending.sidebar`;
`notKept.<model>` stops redirects). Suggestion rows on the Suggestions page are
`data-altmonitor-refresh` parts so translated rows refresh in place.

`ai/`: `AiProviderInterface` (the one deliberate extension point), `OllamaProvider`,
`EndpointPolicy` (SSRF rules), `PromptBuilder` (prompt + answer parsing), `ImageProcessor`
(asset → resized base64 JPEG via `getCopyOfFile()` and Craft's Images service).

Jobs: `BaseScanJob` (mutex per scan, marks the scan failed on error and rethrows, resumable via
`Scans::mayContinue()`, 3 attempts) → `ScanAssetsJob` (site by site, keyset on asset ID; full
scans prune unscanned audits, incremental scans prune gone ones; may chain a usage scan and a
suggestion walk), `ScanContentUsageJob` (relations phase, then reference tags phase, then prune +
`Audits::applyUsageStatuses()`); `GenerateAltTextJob` (given targets, or a walk of audits wanting
suggestions, 5 per job, TTR scaled by `aiTimeout`).

Controllers: `Dashboard` (index, progress JSON), `Model` (the image model page), `Scan` (start),
`Assets` (index, export, view, check, save, decorative, bulk: save / approve / generate, sidebar: the
editor box's HTML again), `Suggestions` (index, approve, reject, bulk-approve, image, next, store,
translate, regenerate, generate-all), `Settings` (index, save, test-connection). Shared input reading in the
`RequestInput` trait. Plugin class registers permissions, CP URL rules (the Image model nav item
shows with the generate permission unless Ollama is on and no site needs a translation model),
asset element events,
the dashboard widget.

Console: `console/controllers/ScanController` (`craft alt-monitor/scan [--full|--usage]
[--wait]`; Craft sets the plugin's console controller namespace itself). It calls
`Scans::start()` like the dashboard; `--wait` runs Craft's queue (`runQueue()`, overridden in
tests, which must never run the host's real queue) until the scan completes or fails. Exit codes:
0, 1 failed, 64 conflicting options, 75 a scan is already active. Widget:
`widgets/CoverageWidget` (`templates/_widget.twig`), selectable and shown only with
`altMonitor:view`, counts over the viewer's viewable volumes.

## Folder structure

```
src/
  AltMonitor.php            bootstrap only
  ai/                       provider interface, Ollama, endpoint policy, prompt, image processing, Translations
  conditions/               AltStatusConditionRule (the Assets index filter)
  console/controllers/      ScanController (craft alt-monitor/scan)
  controllers/              CP controllers + RequestInput trait
  enums/                    AltStatus, AltIssue, UsageStatus, UsageKind, ScanType, ScanStatus, SuggestionSource, SuggestionStatus
  errors/                   AiProviderException, AltTextException, ScanInProgressException
  events/                   DefineAiProviderEvent
  jobs/                     BaseScanJob, ScanAssetsJob, ScanContentUsageJob, GenerateAltTextJob
  migrations/Install.php    the only migration
  models/                   Settings, ListCriteria, view models (AuditItem, Suggestion, Scan…)
  records/                  one per table, schema only
  services/                 see table above
  widgets/                  CoverageWidget (Craft's dashboard)
  templates/                _dashboard, _describer, _macros, _sidebar, _widget, images/{_index,_view}, model/_index, suggestions/_index, settings/_index
  translations/en/alt-monitor.php
  web/assets/cp/            CpAsset + dist/cp.css, cp.js, describe-worker.js, vendor/transformers.min.js (no build step)
tests/
  unit/                     PluginTest, RulesTest, AiTest (no Craft app)
  integration/              InstallationTest, ScanTest, SuggestionsTest
  security/                 ControllerSecurityTest
  browser/                  describe.html + run.mjs: the real model in headless Chrome
  _support/                 AltMonitorFixture, ProjectConfigSandbox, TestUser
```

## Data model

- `altmonitor_audits` — one row per (asset, site); unique `(assetId, siteId)`. `altStatus`,
  `issues` (comma-separated `AltIssue`s), `decorative` (set by people, kept by scans),
  `usageStatus`, `altHash` (sha1 of alt, filename, title, volume, decorative: unchanged rows are
  only touched), `dateScanned` (used to prune after full scans), `dateAltSaved`.
- `altmonitor_suggestions` — one current suggestion per (asset, site); regenerating replaces it.
  Status `queued` doubles as the duplicate-generation reservation (expires after an hour).
- `altmonitor_usages` — one row per (asset, source element, source site, field, kind), keyed by a
  hash; rows not refreshed by a usage scan are pruned by `dateUpdated`.
- `altmonitor_scans` — scan runs, progress and the totals at completion.
- FKs cascade from `elements`, `sites`, `volumes`, so hard deletes need no code. Alt text is never
  copied into these tables.

## Important decisions

- **Alt text is per site.** Volumes with `altTranslationMethod = none` are audited in the primary
  site only; others in every site (`AssetScanner::volumeIds()`).
- **Who may see or edit an audited image** is `AssetScanner::userMay()`: Craft's
  `canView`/`canSave`, except that for volumes with shared alt text (audited in the primary site)
  the asset in any site the user has `editSite:` for counts. Lists apply the same rules in SQL
  (`Audits::visibleCondition()`: `viewPeerAssets` by uploader, `editSite`) so totals and pages
  match what is shown.
- **Abandoned scans**: no progress for 30 min *and* Craft's queue has nothing waiting or
  reserved, or 6 h in any case (`Scans::active()`); a scan's next job may wait behind others.
- **Settings set in `config/alt-monitor.php`** are disabled on the Settings page and dropped from
  the posted values; invalid config-file values are named in the save error.
- **Dashboard step 1** is done only after a completed full scan, not when images were checked on
  save alone.
- **A failed regenerate keeps the last suggestion**: `reserve()` passes the earlier status in the
  job target (`[assetId, siteId, status]`), and `generate()` restores it with the reason.
- **The `X-AltMonitor-Known` header** leaves out texts over 250 characters and stays under
  3000 bytes (nginx's default buffers).
- **Scanning never writes assets.** Only `AltTextEditor::save()` does, after checking the asset
  exists, is an image (`kind`), and `Elements::canSave()` (which includes Craft's `editSite:`
  check on multi-site). Text is sanitized to one plain line, max 1000 chars.
- **Empty alt is not automatically an error.** Decorative flag → good; decorative-looking file
  name → review. The filename rule matches the raw name (or a separator-only form), not prose
  that happens to match it.
- **Usage scanning reads Craft's `relations`, `elements_sites`, `elements_owners` tables with
  read-only queries**, because `relatedTo()` per asset would be N queries. Drafts, revisions,
  trashed elements and nested elements whose direct owner is one are excluded. Nested elements
  owned by a nested element of a draft are not excluded (known limitation). Reference tags are
  matched by numeric ID only.
- **Incremental scans** use `dateUpdated >=` the start of the last completed asset scan, passed as
  ISO-8601 with offset (a DB-format string is read in the app timezone by element queries).
- **No AI settings in the control panel.** The browser model needs none; Ollama (`aiEnabled`,
  endpoint, model, timeout, `suggestAfterScan`, `fallbackToRules`, `contextFieldHandles`) and the
  browser model sources are config-file only. `SettingsController::EDITABLE` is just
  `scanBatchSize`, `auditOnSave`, `scanUsageAfterFullScan`, `additionalGenericTerms`,
  `siteLanguages` (a language choice, not an AI switch); the
  connection test uses the configured values, never posted ones. `allowedAiHosts` is also
  excluded from `Settings::fields()` so it is never saved to project config.
- **Why WASM only, q8:** it is the path verified in a real browser; WebGPU would be faster but is
  untested here. Ollama requests use a plain
  Guzzle client (no Craft proxy), no redirects, `CURLOPT_RESOLVE` pinning, and never log bodies.
  AI suggestions are always `needsReview`; a weak AI answer is discarded.
- **Rule-based suggestions (fallback only) never invent**: caption (not review) → non-auto title →
  humanized filename → title of content using it (all review); otherwise none, with the reason
  stored.
- **Flashes** are read only when a session exists (`RequestInput::failures()`), since the console
  app running tests has none.

### Craft's own asset screens

`AltMonitor::registerAssetScreens()`: `EVENT_DEFINE_SIDEBAR_HTML` appends the box from
`AssetsUi::sidebarHtml()` (images only, `altMonitor:view` only; failures are logged, never break
the editor). The box is a Craft sidebar `fieldset` + `h6` legend + `.meta` pane; every button is
`type="button"` because it sits inside the editor's form. `cp.js` `sidebarBox()` sets boxes up on
load and through a `MutationObserver` (slide-outs arrive later). **Use** fills the editor's
`[name="alt"]` field (`<namespace>[alt]` in slide-outs, never `fields[alt]`; no request); saving the asset then runs `Suggestions::markUsedIfSaved()`
from `EVENT_AFTER_SAVE_ELEMENT`. Only when the layout has no Alt Text field does it approve
directly (needs approve + `canSave`). After describing or dismissing, the box is fetched again
(`assets/sidebar`) instead of reloading, which would lose unsaved edits (`Describer.onFinish`).
Its dialog uses `data-altmonitor-sidebar-describer`, so `describers()`/`resumePending()` on
AltMonitor pages never take it; a model download started from the box returns to the asset's
edit page (`ModelController::returnUrl()` also accepts `assets/edit/…`) and the box resumes via
`pending.sidebar`. Audit lookups use `AssetScanner::auditSiteId()` (primary site for volumes
with shared alt text). The column (`altMonitorStatus`) reads a page's statuses in one query from
`elementQueryResult` (`Audits::statusesFor()`); `AltStatusConditionRule` correlates
`altmonitor_audits` with the element query's `elements` / `elements_sites` / `assets` aliases.
`cp.css` loads on Craft's asset pages too, so every rule is `.altmonitor-*`-prefixed; rules on
Craft's own elements (`#header`…) are scoped to `body.altmonitor-page`, which every AltMonitor
template sets as `bodyClass`.

## Craft-specific conventions

- Element queries for assets (`Asset::find()->kind('image')`; note `kind()` also matches by file
  extension), element saving for writes, `Craft::$app->getMutex()` for locks, `craft\helpers\Queue`
  for jobs, `Db::upsert`/`Db::update` for own tables.
- Every controller: `requireCpRequest()` + `requirePermission()` in `beforeAction`; state changes
  `requirePostRequest()`; Craft's CSRF validation applies.
- Templates escape by default; `getThumbHtml()` is Craft-generated and output `|raw`. Top-level
  `{% set %}`/`{% import %}` used by the content belong inside `{% block content %}` so tests can
  render the block alone.
- Every `Craft::t('alt-monitor', …)`, `|t('alt-monitor')`, `Translation::prep` and JS `Craft.t`
  string needs an entry in `src/translations/en/alt-monitor.php` (`PluginTest` checks).

## Commands

From `plugins/Alt-Monitor` (host PHP works for unit tests, static analysis and CS):

```bash
composer install
composer test                                   # unit
composer phpstan                                # level 5, src + tests
composer check-cs                               # ECS (craftcms/ecs)
composer fix-cs
composer validate "$PWD/composer.json"          # absolute path, as with the other plugins
```

Integration + security need the host database, so run them in DDEV:

```bash
ddev exec -d /var/www/html/plugins/Alt-Monitor php vendor/bin/phpunit -c phpunit.integration.xml.dist
```

The real model in headless Chrome (host; needs Chrome and network; exits 2 when it can't run):

```bash
composer test-browser
```

To update Transformers.js: install the exact version with npm, copy its `dist/transformers.min.js`
(the self-contained build, not `transformers.web*.js`, which has bare imports) and `LICENSE` to
`dist/vendor/`, update the version in `LICENSE.md`, write the `i` in “Generation” of
`Mistral3ForConditionalGeneration` as `\u0069` (all occurrences; otherwise GitHub push protection
reports it as a Mistral API key, see `LICENSE.md`), and run the browser test.

Host project: `ddev craft plugin/install alt-monitor`; after changing `composer.json` run
`ddev composer update tahadudhiya53/craft-alt-monitor` (this also refreshes other path packages'
lock references).

## Testing notes

- Integration tests use `AltMonitorFixture`: volumes, fields, a Matrix field, a section and a
  global set in one rolled-back transaction, a savepoint per test, project config never written
  to YAML, real PNGs created with GD in a temp dir. Pushed jobs are caught via
  `Queue::EVENT_AFTER_PUSH` and run with `runJobs()`.
- Controllers run through `runAction()` with a real CSRF token; pages are checked by rendering
  their `content` block (`html()`), because the CP layout needs a web session.
- Ollama is always mocked with Guzzle's `MockHandler` via `AiProviders::$httpHandler`.
- `InstallationTest` rolls the migration back and installs it again, skipping itself if any
  AltMonitor table holds data.
- Keep test files by category; add to an existing file before creating one.
- Browser checks against the host site (DDEV) see the user's real images and suggestions. Never
  click Use / Approve / Reject / Save / Describe there unless those requests are intercepted, and
  confirm the interception actually matched (Craft action URLs look like
  `/index.php?p=admin%2Factions%2Falt-monitor%2F…`): an unmatched pattern once wrote test text to
  real assets. Safest: fail every non-GET request with CDP `Fetch` and check the blocked list.
- List filters never use a `site` query parameter: Craft appends `site=` to every CP URL for its
  site switcher. The site filter is `ListCriteria::SITE_PARAM` (`inSite`).
- A browser description that only repeats the current alt text is not stored
  (`Suggestions::sameText()`), and lists hide pending suggestions that equal the alt text
  (`AuditItem::hasNewSuggestion()`). While the model loads from the browser cache, the status says
  “Starting the image model…”; megabytes are only shown on the Image model page's download.
- Spacing: every page uses the shared block at the end of `cp.css` (scale 8 · 12 · 16 · 24px;
  cards 16px 20px padding, 8px radius, hairline border; list rows 16px with separators). Add new
  cards to its selector lists instead of giving them their own padding, and keep that block last.
  Lists use one toolbar: status pills left, filter boxes right (applied on change, no Filter
  button), search below. The bulk bar (`.altmonitor-bulk`) is in the flow under the list and gets
  `is-pinned` (sticky to the screen's bottom, every width) from `selection()` only while
  something is selected, or typed where it has `data-save-changed`.
- Forms where alt text is typed carry `data-altmonitor-unsaved` (`cp.js` `unsavedChanges()`):
  fields `[name^="alt"]` compared with their `defaultValue`; `beforeunload` warns unless the
  submission in progress saves them. Code that saves a field's text without a page load (Use)
  sets its `defaultValue` and fires `input`. The Images list has one save path, **Save changes**
  (`data-save-changed`, `intent=save`): with JS it posts each changed row to `assets/save`
  (`saveField()`, one after another; Enter saves the row it is in), whose answer carries the
  row's new status, issues and `hasSuggestion`; without JS the form goes to `assets/bulk`, which
  saves only rows whose `alt[key]` differs from the hidden `original[key]`, ignoring the
  selection. Don't add per-row Save buttons back. Fields with
  `data-altmonitor-check="assetId-siteId"` get live hints (`liveCheck()`, 400 ms debounce, only
  the latest answer counts) from `assets/check`, which runs `AltTextAnalyzer` on the sanitized
  text and saves nothing; rules stay in PHP only, never copied to JS.
- **CSV export** (`alt-monitor/images/export` → `assets/export`, GET, view permission only): the
  Images list's filters (`ListCriteria::fromParams`) over `Audits::listQuery()`, the same query and
  `visibleCondition()` as the page, so it never has rows the list wouldn't show. Streamed to
  `php://temp` and sent with `sendStreamAsFile`; UTF-8 BOM for Excel; every cell through
  `ImageExport::cell()` (a leading `=` `+` `-` `@` tab or CR gets an apostrophe: formula
  injection). Usage is “Not found in content”, never “unused”. No import yet: the empty “New alt
  text” column is for the content team.
- Suggestions page: the content is wrapped in `[data-altmonitor-review]`; when its last row
  leaves, `loadNextSuggestions()` fetches the same URL, swaps the wrapper's HTML, runs
  `initLists(root)` (every list behaviour must be set up there, not on `document` alone) and
  Craft's `elementThumbLoader` (thumbnails load by script). Past the last page
  (`data-altmonitor-past-end` in `pastEnd`) it loads the first page. Shortcuts (`reviewKeys()`)
  act only inside a row: Ctrl/⌘ + Enter approves, Esc rejects.
- Text: short, plain words; no jargon (“audit”, “rule-based”, “runtime”, “metered”) in what people
  read. Settings use Craft's `tabs`: `altmonitor-tab-checks` (Scanning & rules: the Ollama box
  with Test connection when on, scan switches, the status rules from `SettingsController::rules()`
  built on `AltTextAnalyzer`'s constants, extra generic words, the built-in list) and
  `altmonitor-tab-languages`; `hidden` on the closed one, `error` on a tab with errors, which
  then opens first. Keep `rules()` in step with `AltTextAnalyzer` when a rule changes.
- UI rules: one obvious next action per screen; rare actions (full/usage scans, extra filters,
  scan history, disclaimers) go under a collapsed “More details”/“Filters”. Craft hides
  `input.checkbox` without a sibling label, so list checkboxes are plain inputs; Craft also sets
  inline `max-width` on table cells, which the phone layout overrides.

## Constraints for future work

- Don't add tables Craft already covers, copy alt text, or write to Craft's tables.
- Don't send image data anywhere but an allowlisted endpoint; never through a URL.
- Don't add paid APIs, API keys or an AI switch in the control panel. Describing must keep
  working on any hosting with nothing but a browser; scanning and checks must work without it.
- Don't claim heuristic results establish WCAG compliance, or that “no references found” means
  unused.
- Documentation stays in README.md, CHANGELOG.md (Keep a Changelog, `## Unreleased`) and this
  file. Don't create other docs.
- The user controls Git: there is no repository yet; don't initialise, commit or push.
