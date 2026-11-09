# AltMonitor

Free image alt text auditing and management for Craft CMS 5. Alt text suggestions are described
from the image itself by a free, open-source image model that runs in your browser: no API key,
no account, no server to set up, nothing to pay.

AltMonitor scans your image assets, flags missing and weak alt text with rule-based checks, shows
where each image is used, describes images to suggest alt text, and lets you review and save alt
text from one place in the control panel. Nothing is written to an asset until a person saves or
approves it.

AltMonitor is free and open source under the MIT license.

## Features

- **Dashboard** — image totals, missing / weak / needs-review / good counts, coverage, suggestions
  waiting for review, images updated through AltMonitor, content usage counts, scan progress and
  the last scans. Every number is counted from the database when the page loads; each card links
  to the matching filtered list.
- **Scanning in the queue** — full scans, incremental scans (only images changed since the last
  scan), and content usage scans, a batch per job, with progress, retries and one scan at a time.
  Images are also re-audited as soon as they are saved.
- **Rule-based checks** — empty alt text, the file name used as alt text, file extensions, camera
  names, hashes and other identifiers, lone generic words (“image”, “photo”…), and repeated words
  are flagged. Short text is not treated as a problem by itself. Images can be **marked
  decorative**, so their empty alt text counts as intended; images whose file name suggests
  decoration are left for review rather than reported as missing.
- **Content usage** — where images are referenced through Craft relations (Assets fields in
  entries, Matrix and other nested entries, global sets, categories, users…) and as reference tags
  embedded in field content (how rich text editors store images). Drafts, revisions and trashed
  content are ignored.
- **Suggestions from the image itself** — [Florence-2](https://huggingface.co/microsoft/Florence-2-base)
  (Microsoft, MIT license) runs in the browser of whoever clicks **Describe** and writes a short
  description of what it sees, e.g. “Two cats laying on a pink blanket on a couch.” Describe one
  image, a selection, or every image with missing or weak alt text. Suggestions are never made up
  from file names.
- **Review and editing** — approve (optionally after editing), reject or regenerate suggestions;
  bulk-approve a selection; edit alt text inline in the image list or on each image's page.
  Saving always goes through Craft's own element saving.
- **Multi-site and multi-volume** — alt text that is translatable is audited and edited per site;
  alt text shared by every site is audited once. Lists only show images in volumes the user may
  view.

## Requirements

- Craft CMS 5.9 or later
- PHP 8.2 or later
- To describe images: a current desktop browser (Chrome, Edge, Firefox or Safari) with JavaScript.

AltMonitor has no dependency beyond Craft and uses no paid or hosted API. It works on any
hosting, including shared hosting: the image model runs in the browser, not on the server.

## Installation

From your Craft project:

```bash
composer require tahadudhiya53/craft-alt-monitor
php craft plugin/install alt-monitor
```

Then grant the permissions below to anyone other than admins who should use it, and run a full
scan from **AltMonitor → Dashboard**. Scans run in Craft's queue, so a queue runner must be
working.

## How image descriptions work

There is nothing to set up or turn on.

1. Someone with the **Generate suggestions** permission clicks **Describe** (on the dashboard, in
   the image list, or on an image's page).
2. If their browser doesn't have the model yet, they are taken to **AltMonitor → Image model**
   first. That page explains, before anything is downloaded, what will be downloaded (the
   Florence-2 model, about 277 MB from Hugging Face, and the ONNX runtime, about 27 MB from
   jsDelivr), what those services see (the IP address, browser type and time, as with any
   download; never images, alt text, cookies or account details), where the files are kept (this
   browser only) and how to remove them. Nothing is downloaded until they tick the agreement box
   and press **Download**. Once it's done, **Continue describing** takes them back and what they
   asked for starts.
   The browser keeps the files, so this happens once per browser: later, the model loads from the
   browser's cache in a second or two (measured in Chrome: 33 s the first time, 1.3 s after).
   Describing an image again gives a different description: the model avoids the image's current
   alt text and earlier suggestions, and tries a fuller caption or another wording. A popup shows
   the image, the new description and progress, with a Stop button.
3. For each image, the browser fetches a resized copy through Craft (so images in private volumes
   work too), describes it, and sends the description back to be stored as a suggestion.
4. Someone approves, edits or rejects each suggestion.

Images are never sent to an outside service: they go from your Craft site to your own browser.
On an ordinary computer each image takes around 10 seconds, and the page must stay open while
images are being described.

The model writes short, literal descriptions. It can be wrong (and doesn't know names, brands or
why the image is on the page), which is why every suggestion needs approval.

### Privacy (GDPR)

AltMonitor itself stores nothing about the download and sets no cookies for it; the files are kept
in each person's browser (Cache Storage for your control panel's site) and can be removed with
**Remove from this browser** on the Image model page. The only personal data involved is what any
download exposes to Hugging Face and jsDelivr: the downloader's IP address, browser type and the
time. Those services may process data outside the EU. If that matters to your organisation,
mention it in your privacy notice or self-host the files (below), in which case the page says no
other service is contacted. This is a description of what the plugin does, not legal advice.

The page needs the control panel to be served over HTTPS: browsers only keep files for secure
sites. Over plain HTTP the page explains that the model would be downloaded every time.

### Self-hosting the model (optional)

To avoid downloading from Hugging Face and jsDelivr (for example because of a content security
policy or data protection rules), host the files yourself and set in `config/alt-monitor.php`:

```php
return [
    'browserModelHost' => 'https://files.example.com/models/',      // serves onnx-community/Florence-2-base-ft/…
    'browserWasmPath' => 'https://files.example.com/onnxruntime/',  // onnxruntime-web dist files
];
```

### Using Ollama on your own server instead (advanced)

If you run [Ollama](https://ollama.com) with a vision model, AltMonitor can describe images on the
server, in the queue, without anyone keeping a page open. This is set in `config/alt-monitor.php`
only:

```php
return [
    'aiEnabled' => true,
    'ollamaEndpoint' => 'http://localhost:11434',
    'ollamaModel' => 'qwen2.5vl:3b',
    // Hosts the endpoint may name. Add yours if Ollama isn't local.
    'allowedAiHosts' => ['localhost', '127.0.0.1', '::1', 'host.docker.internal', 'ollama'],
];
```

Ollama and its models are free, but need a machine with enough memory (several GB) and are slow
without a GPU. **Settings** then shows the configuration and a **Test connection** button.
AltMonitor refuses endpoints with credentials, paths or other schemes, hosts not on the
allowlist, and addresses such as cloud metadata services, and pins each connection to the
addresses it checked. Check the license of the model you choose if you use it commercially.

## Configuration

**AltMonitor → Settings** has the scanning and rule settings (saved to project config, so only
where admin changes are allowed). Anything can be set in `config/alt-monitor.php`, which
overrides the control panel; the AI settings can only be set there.

| Setting | Default | |
|---|---|---|
| `scanBatchSize` | `100` | Images per queue job |
| `auditOnSave` | `true` | Re-audit images when they are saved |
| `scanUsageAfterFullScan` | `true` | Run a content usage scan after each full scan |
| `additionalGenericTerms` | `[]` | Extra words that count as generic alt text |
| `browserModel` | `onnx-community/Florence-2-base-ft` | Config file only. A Florence-2 ONNX model |
| `browserModelHost` | Hugging Face | Config file only. Where the browser downloads the model |
| `browserWasmPath` | jsDelivr | Config file only. Where the browser downloads the ONNX runtime |
| `aiEnabled` | `false` | Config file only. Describe on the server with Ollama instead |
| `ollamaEndpoint` | `http://localhost:11434` | Config file only. May be an environment variable |
| `ollamaModel` | `qwen2.5vl:3b` | Config file only |
| `aiTimeout` | `120` | Config file only. Seconds per image with Ollama |
| `allowedAiHosts` | `localhost`, `127.0.0.1`, `::1`, `host.docker.internal`, `ollama` | Config file only |
| `suggestAfterScan` | `false` | Config file only. With Ollama, queue suggestions after each scan |
| `fallbackToRules` | `false` | Config file only. With Ollama, suggest from caption, title or file name when it fails |
| `contextFieldHandles` | `['caption', 'description']` | Config file only. Fields that fallback may use |

## Permissions

| Permission | Allows |
|---|---|
| Access AltMonitor | The control panel section (Craft's own) |
| View AltMonitor | Dashboard, image list, suggestions, usage |
| ↳ Run scans | Starting scans |
| ↳ Generate suggestions | Describing images |
| ↳ Edit alt text | Saving alt text and marking images decorative |
| ↳ Approve suggestions | Approving and rejecting suggestions |
| Manage settings | The settings page and connection test |

Editing or approving also needs Craft's own permission to save the asset (and to edit its site).
Lists only show images in volumes the user may view.

## Usage

1. **Dashboard → Full scan.** The scan runs in the queue; the dashboard shows progress.
2. Click **Describe images with missing or weak alt text** on the dashboard and keep the page
   open while it works. Or describe only some: select rows in **Images** and click **Describe
   images**, or open an image and click **Describe the image**.
3. Open **Images** and filter by status, usage, suggestion, site or volume. Type alt text into a
   row and press **Save**, or select rows and choose **Save alt text** or **Approve suggestions**.
   Failures are listed per image. Open an image to see why it was flagged, edit its alt text,
   mark it decorative, see where it is used, and approve or reject its suggestion.
4. **Suggestions** lists suggestions waiting for review (filter by status or source). Edit any,
   select them and **Approve selected**.
5. Use **Scan changes** for quick incremental scans and **Scan usage** after content changes.

## What the results mean

- Statuses come from heuristics. “Good” means no rule found a problem; it does not prove the alt
  text is accurate or that a page meets WCAG. Review what matters by hand.
- Content usage only covers references Craft records. Images used in Twig templates, custom code,
  CSS or external systems are not found, so “No references found” does not prove an image is
  unused. Reference tags are matched by asset ID.
- Image descriptions can be wrong. Every suggestion needs approval before it reaches an asset.

## Testing

From `plugins/Alt-Monitor`:

```bash
composer install
composer test                 # unit tests (no Craft app or database needed)
composer phpstan
composer check-cs
```

The integration and security suites need the host Craft project and its database (in DDEV):

```bash
ddev exec -d /var/www/html/plugins/Alt-Monitor composer test-integration
```

They create their volumes, fields, entries and images inside a transaction that is rolled back,
and mock Ollama, so no AI server is needed.

The browser test runs the image model the control panel uses in headless Chrome on real photos
(it needs Chrome and network access to Hugging Face and jsDelivr):

```bash
composer test-browser
```

## License

MIT. See `LICENSE.md`, which also lists the bundled Transformers.js library (Apache 2.0).
