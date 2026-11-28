# AltMonitor

Find images in Craft CMS with missing or weak alt text, and fix them. AltMonitor checks every
image, shows what needs work, and suggests alt text written from the image itself by a free AI
that runs in your browser. Nothing is saved until a person approves it.

- **Free.** No account, API key or cost.
- **Describes the image, not the file name**, with
  [Florence-2-base-ft](https://huggingface.co/onnx-community/Florence-2-base-ft) (MIT) running in
  the browser.
- **Your images stay on your site.** They go only from Craft to the person's browser.
- **Works where people edit:** the entry editor, the asset editor and the Assets index.

## Contents

- [Requirements](#requirements) · [Installation](#installation) · [Quick start](#quick-start)
- For editors: [Screens](#screens) · [Statuses](#statuses) · [Describing images](#describing-images) · [Other languages](#other-languages) · [Where images are used](#where-images-are-used)
- For admins: [Settings](#settings) · [Permissions](#permissions) · [Command line](#command-line) · [Privacy](#privacy-gdpr)
- For developers: [Templates](#templates) · [Hosting the model files](#hosting-the-model-files-yourself) · [Ollama](#describing-on-your-server-with-ollama) · [Your own AI provider](#your-own-ai-provider)
- [Known limitations](#known-limitations) · [Development](#development) · [License](#license)

## Requirements

- Craft CMS 5.9 or later, PHP 8.2 or later.
- To describe images in the browser: a current desktop browser with WebAssembly, about 1 GB of
  free memory, and the control panel on `https://` (browsers only keep the downloaded model on
  secure sites). The automated test runs in Chrome.

Scanning, checks and editing work without any of this.

## Installation

```bash
composer require tahadudhiya53/craft-alt-monitor
php craft plugin/install alt-monitor
```

Then give people the [permissions](#permissions) they need.

## Quick start

1. **Scan.** Open **AltMonitor → Dashboard** and click **Scan all images**. The scan runs in
   Craft's queue, so you can leave the page. It reads alt text and changes nothing.
2. **Describe.** Click **Describe them for me**. The first time, you are asked to download the
   image model (about 304 MB, once per browser). Then each image takes about 10 seconds.
3. **Review.** Open **Suggestions**, edit the text if needed, then **Approve** (it becomes the
   image's alt text) or **Reject**.

Prefer to write alt text yourself? Open **Images**, type it in the list and click **Save changes**.

## Screens

| Screen | What you do there |
|---|---|
| **Dashboard** | See how many images need work and start describing. **Scan for changes** checks images changed since the last scan; **Full scan** and **Find where used** are under **More details**. |
| **Images** | Every checked image with its status. Filter by status, volume, site, usage, suggestion or **More checks**; search file names, titles and alt text. Type alt text in the list, or select images to describe, approve or mark as decorative. **Export CSV** downloads every image the filters show. |
| **Image page** (click an image) | The image, its alt text and status, the suggestion (**Use this** / **Reject**), **Describe the image**, **Mark as decorative**, **Edit asset**, and where the image is used. |
| **Pages** | Entries and other content (global sets, categories…) ranked by how many of their images need work. **Fix images** opens the Images list with just that page's images. One site at a time. |
| **Suggestions** | Everything waiting for review, filtered by status, source, site or text. **Approve**, **Reject**, **Approve selected** or **Approve all on this page**. Keyboard: Ctrl/⌘ + Enter approves, Esc rejects (unless you edited the text). |
| **Image model** | Download or remove the image model and translation models in this browser. |
| **Settings** | **Scanning & rules** and **Languages**. |

In Craft's own screens:

- **Entry editor:** an **Image alt text** box lists the entry's images that need work (missing,
  weak or to review), from Assets fields, Matrix and other nested entries, and rich text, as the
  entry is being edited. Each has **Describe**, **Use** (saves the suggestion to the image right
  away), **Reject** and **Edit image**. It updates after each autosave.
- **Asset editor:** an **Alt text check** box with the status, the suggestion, **Use** (fills the
  Alt Text field; save the asset to keep it), **Reject** and **Describe the image**.
- **Assets index:** an **Alt text status** column, and a filter by status (including
  **Not checked**).
- **Dashboard widget:** **Alt text coverage**.

## Statuses

| Status | Meaning |
|---|---|
| **Missing** | No alt text. |
| **Weak** | The alt text is the file name, contains a file extension, is a code (`IMG_1234`), is only punctuation (`---`), is a generic word (“image”, “photo”, “logo”…), or repeats the same words. |
| **Needs review** | Probably fine, but check it: it starts with “image of” / “photo of” or similar, is under 3 characters or over 250, or there is no alt text but the file name looks decorative (`divider.png`). |
| **Good** | No problem found, or marked as decorative. |

Mark an image as **decorative** (a divider, a background) when it needs no alt text: screen
readers skip it.

These are automatic checks. They point out likely problems; they can't prove alt text is right
or that a page meets WCAG.

## Describing images

- **Download once, with consent.** The first time someone clicks **Describe**, they go to the
  **Image model** page. It says what is downloaded and from where. Nothing is downloaded until
  they tick the box and click **Download**. **Continue describing** then takes them back.

  | Download | Size | From |
  |---|---|---|
  | Image model (Florence-2-base-ft) | about 277 MB | huggingface.co |
  | AI engine (ONNX Runtime) | about 27 MB | cdn.jsdelivr.net |

- **Speed:** about a minute to download on fast internet; after that the model starts in a
  second or two and each image takes about 10 seconds.
- **Describing again** gives a different description: it avoids the current alt text and
  earlier suggestions.
- **What it gets wrong:** it writes short, plain descriptions, doesn't know people, brands or
  places by name, and can be wrong. That's why every suggestion needs approval.

Keep the page open while describing. **Stop** ends it at once.

## Other languages

The image model writes English. Each site can get suggestions in its own language.

- **Settings → Languages:** the suggestion language per site. The default is the site's own
  language; choose English, for example, for a French site that uses English alt text. Changing
  it doesn't change existing suggestions.
- **Translation models** are optional downloads on the **Image model** page, listed for the
  languages your sites use (about 105–140 MB each, plus the 27 MB AI engine if it isn't
  downloaded yet). Available: Afrikaans, Arabic, Chinese (Simplified), Czech, Danish, Dutch,
  Finnish, French, German, Hindi, Hungarian, Indonesian, Italian, Romanian, Russian, Spanish,
  Swedish, Ukrainian, Vietnamese and Xhosa.
- **When describing**, the suggestion is translated if this browser has that language's model.
  Otherwise it stays in English, marked “This suggestion is in English”, with
  **Translate into {language}**.

Other languages (for example Portuguese, Polish, Japanese, Norwegian, Traditional Chinese) get
English suggestions. Translations are machine made: check them when you review.

## Where images are used

**Find where used** (or a full scan, if **Find where images are used after a full scan** is on)
finds images in Assets fields, Matrix and other nested content, global sets, and rich text.
Drafts, revisions and trashed content are ignored. It can't see templates, custom code or other
systems, so “Not found in content” doesn't prove an image is unused.

**Pages** and the rich text check below are built from the last usage scan; run one after big
content changes.

**More checks** on the Images list:

- **No alt text where embedded in text.** Rich text keeps the alt text an image had when it was
  inserted, so a page can show an image without alt text even when the asset has some. The image
  page's **Where it is used** shows what each piece of content says now: **None in the text**,
  **Different here: “…”** or **Same as the image**. Fix it in the entry. Decorative images are
  fine without.
- **Same alt text as other images**, for example 40 different photos all called “Team photo”.
  Compared per site, ignoring case and extra spaces, only among images you may see; decorative
  images and empty alt text are left out. The list is sorted so each group is together.

## Settings

**AltMonitor → Settings** is saved to project config, so it can only be changed where admin
changes are allowed. Anything set in `config/alt-monitor.php` overrides the page and shows there
as disabled.

| Setting | Default | Where | |
|---|---|---|---|
| `auditOnSave` | `true` | Settings, config | Check images when they are saved |
| `scanUsageAfterFullScan` | `true` | Settings, config | Find where images are used after a full scan |
| `scanBatchSize` | `100` | Settings, config | Images checked at a time (10–1000). Lower it if scans run out of time or memory |
| `requireAltToPublish` | `false` | Settings, config | Don't publish entries with images missing alt text (see below) |
| `additionalGenericTerms` | `[]` | Settings, config | Extra generic words: alt text that is only one of them (also with “a”, “an”, “the”, “my”, “our”, “your” or a number) is weak |
| `siteLanguages` | `[]` | Settings, config | Suggestion language by site UID, e.g. `['<site-uid>' => 'en']` |
| `templateCheck` | `true` | config | In dev mode, warn in the browser console about images without `alt` ([Templates](#templates)) |
| `browserModelHost` | Hugging Face | config | Where the browser downloads models from |
| `browserWasmPath` | jsDelivr | config | Where the browser downloads the AI engine from |
| `browserModel` | `onnx-community/Florence-2-base-ft` | config | Only another build of Florence-2 with the same files works |
| `aiEnabled` | `false` | config | Describe on the server with Ollama |
| `ollamaEndpoint` | `http://localhost:11434` | config | Can be an environment variable |
| `ollamaModel` | `qwen2.5vl:3b` | config | |
| `aiTimeout` | `120` | config | Seconds per image with Ollama (10–600) |
| `allowedAiHosts` | `localhost`, `127.0.0.1`, `::1`, `host.docker.internal`, `ollama` | config | Hosts Ollama may be on. Never saved to project config |
| `suggestAfterScan` | `false` | config | With Ollama, make suggestions after each scan |
| `fallbackToRules` | `false` | config | With Ollama, when it fails, suggest from caption, title or file name |
| `contextFieldHandles` | `['caption', 'description']` | config | Fields the fallback may read |

**Don't publish entries with images missing alt text:** saving an enabled entry in the control
panel stops with an error at each field holding an image with no alt text. Weak alt text, decorative
images and images in disabled Matrix blocks don't stop it. Drafts, disabled entries, imports and
console commands are never stopped.

After changing the extra generic words, run a **Full scan** to re-check every image.

## Permissions

| Permission | Allows |
|---|---|
| View AltMonitor | The dashboard, Images, Pages, Suggestions and CSV export, the editor boxes, the Assets column and filter, and the dashboard widget, for images in volumes the user can see |
| ↳ Run scans | Starting scans |
| ↳ Get suggestions | Describing and translating; the Image model page |
| ↳ Edit alt text | Saving alt text and marking images as decorative |
| ↳ Approve suggestions | Approving and rejecting suggestions |
| Manage settings | The Settings page |

Saving alt text, approving and **Use** also need Craft's permission to save the asset. On
multi-site installs, people see images in the sites they may edit.

## Command line

```bash
php craft alt-monitor/scan                # images changed since the last scan (all, the first time)
php craft alt-monitor/scan --full         # every image
php craft alt-monitor/scan --usage        # where images are used
php craft alt-monitor/scan --full --wait  # run it now, show progress and a summary
```

With `--wait` the command runs Craft's queue until the scan ends. Exit codes: `0` done or
queued, `1` the scan failed or stopped, `64` `--full` and `--usage` together, `75` another scan is
running. Nightly:

```
0 3 * * * cd /path/to/project && php craft alt-monitor/scan --wait >/dev/null
```

## Privacy (GDPR)

- Images go only from your site to the person's browser, or to your Ollama server if you set one
  up. They are never sent to the download services.
- The model files come from huggingface.co and cdn.jsdelivr.net (unless you
  [host them yourself](#hosting-the-model-files-yourself)). Like any download, these services see
  the IP address, browser type and time, and may handle data outside the EU. They get no cookies,
  account details, images or alt text. You may want to mention this in your privacy notice.
- The files are kept in that browser only. **Remove from this browser** on the Image model page
  deletes them. AltMonitor doesn't record who downloaded them.

This describes what the plugin does; it isn't legal advice.

## Templates

Alt text only helps if the template prints it. Use `altText()` on the `<img>` tag:

```twig
{% set image = entry.image.one() %}
<img src="{{ image.url }}" {{ altText(image) }}>
```

It prints `alt="…"` with the image's alt text (escaped, on one line), or `alt=""` when the image
is marked decorative. An image without alt text, or no image at all, also gets `alt=""`, so
screen readers don't read the file name.

**Template check (dev mode only).** With `devMode` on, front-end HTML pages are checked after they
render, and the browser console warns about `<img>` tags without an `alt` attribute and images
printed with `altText()` that have no alt text yet. Pages with no problem are not changed. The
warnings are an inline `<script>`, so a content security policy without `'unsafe-inline'` blocks
them. Turn it off with `'templateCheck' => false`.

## Hosting the model files yourself

To avoid Hugging Face and jsDelivr, host the files and set in `config/alt-monitor.php`:

```php
return [
    'browserModelHost' => 'https://files.example.com/models/',      // serves onnx-community/Florence-2-base-ft/… and Xenova/opus-mt-en-…/…
    'browserWasmPath' => 'https://files.example.com/onnxruntime/',  // onnxruntime-web dist files
];
```

The Image model page then says no other service is used.

## Describing on your server with Ollama

With [Ollama](https://ollama.com) and a vision model, images are described in Craft's queue,
without anyone keeping a page open. In `config/alt-monitor.php` only:

```php
return [
    'aiEnabled' => true,
    'ollamaEndpoint' => 'http://localhost:11434',
    'ollamaModel' => 'qwen2.5vl:3b',
    // Add your host if Ollama isn't local.
    'allowedAiHosts' => ['localhost', '127.0.0.1', '::1', 'host.docker.internal', 'ollama'],
];
```

Settings then shows **Test connection**. **Describe them for me** and **Get a suggestion** queue
the work instead of describing in the browser, so nobody downloads the image model. Ollama writes English too; **Translate** still works in the browser.
AltMonitor only connects to allowed hosts, without redirects, and refuses URLs with logins or
paths and addresses such as cloud metadata services. Ollama needs several GB of memory and is slow
without a GPU. Check the licence of the model you choose.

## Your own AI provider

A module or plugin can supply its own provider, implementing
`Tahadudhiya\AltMonitor\ai\AiProviderInterface`:

```php
use Tahadudhiya\AltMonitor\events\DefineAiProviderEvent;
use Tahadudhiya\AltMonitor\services\AiProviders;
use yii\base\Event;

Event::on(AiProviders::class, AiProviders::EVENT_DEFINE_PROVIDER, function(DefineAiProviderEvent $event) {
    $event->provider = new MyProvider();
});
```

AltMonitor then describes images on the server with it, as with Ollama. It prepares the image
(a resized JPEG) and the prompt, and cleans up the answer.

## Known limitations

- Describing in the browser needs a person on the page (Ollama doesn't).
- Usage scans can't see templates or custom code.
- The rich text check reads images inserted as Craft assets (`{asset:123:url}`); images added by
  URL aren't assets.
- The template check reads the rendered HTML; images added later by JavaScript aren't checked.

## Development

From `plugins/Alt-Monitor`:

```bash
composer install
composer test           # unit tests
composer phpstan
composer check-cs
ddev exec -d /var/www/html/plugins/Alt-Monitor composer test-integration   # needs the host project (DDEV)
composer test-browser   # the real models in headless Chrome; needs Chrome and network
```

## License

MIT. See `LICENSE.md`, which also covers the bundled Transformers.js (Apache 2.0). The models are
downloaded by each browser, not bundled: Florence-2 (MIT) and Opus-MT by Helsinki-NLP (Apache 2.0;
English→German CC BY 4.0).
