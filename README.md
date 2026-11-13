# AltMonitor

Find images in Craft CMS with missing or weak alt text, and fix them. AltMonitor checks every
image, shows what needs work, and suggests alt text written from the image itself, by a free AI
that runs in your browser. Nothing is saved until a person approves it.

## What it does

- **Checks every image** for missing, weak or doubtful alt text, per site.
- **Suggests alt text from the image itself**, never from the file name. A free AI
  ([Florence-2](https://huggingface.co/microsoft/Florence-2-base), MIT) runs in your browser.
  No account, API key or cost.
- **Suggestions in your sites' languages.** The AI writes English; for 20 languages the browser
  can translate it, with a free model you download only if you want it.
- **Review in one place.** Approve, edit or reject suggestions, one by one or many at once.
- **Shows where images are used** in entries, Matrix and other nested content, global sets and
  rich text.
- **Works in Craft's own screens.** A box in the asset editor, a column and a filter in the
  Assets index, and a dashboard widget.

## Requirements

- Craft CMS 5.9 or later, PHP 8.2 or later.
- To describe images: an up-to-date desktop browser with WebAssembly (tested in Chrome; Edge,
  Firefox and Safari are not tested yet), about 1 GB of free memory, and the control panel opened
  with `https://` (browsers only keep the downloaded model on secure sites).

## Installation

```bash
composer require tahadudhiya53/craft-alt-monitor
php craft plugin/install alt-monitor
```

Then give people the permissions they need (see [Permissions](#permissions)).

## Quick start

1. **Scan.** Open **AltMonitor → Dashboard** and click **Scan all images**. The scan runs in
   Craft's queue; you can leave the page. It only reads alt text and changes nothing.
2. **Describe.** Click **Describe them for me**. The first time, the browser asks you to
   download the image model (about 304 MB, once). Then each image takes about 10 seconds.
3. **Review.** Open **Suggestions**. Edit the text if needed, then **Approve** (it becomes the
   image's alt text) or **Reject**.

Prefer to write alt text yourself? Use **Images**: type it in the list and click **Save changes**.

## The screens

| Screen | What you do there |
|---|---|
| **Dashboard** | See how many images need alt text, start scans, start describing. |
| **Images** | Every checked image with its status. Filter by status, volume, site, usage or suggestion; search by file name or title. Type alt text in the list, or select images to describe, approve or mark as decorative. **Export CSV** downloads every image the filters show, to share with a content team. |
| **Image page** (click an image) | The image, its alt text and status, the suggestion (**Use this** / **Reject**), **Describe the image**, **Mark as decorative**, and where the image is used. |
| **Suggestions** | Everything waiting for review. Edit, **Approve** or **Reject**; **Approve selected** or **Approve all on this page**. Keyboard: Ctrl/⌘ + Enter approves, Esc rejects. |
| **Image model** | Download or remove the image model and the translation models in this browser. |
| **Settings** | Two tabs: **Scanning & rules** (scan options, how images are checked, extra generic words) and **Languages**. |

In Craft's own screens:

- **Asset editor:** an **Alt text check** box in the sidebar shows the status and the suggestion.
  **Use** puts the suggestion in the Alt Text field; save the asset to keep it.
- **Assets index:** an **Alt text status** column, and a filter by status.
- **Dashboard widget:** **Alt text coverage**.

## What the statuses mean

| Status | Meaning |
|---|---|
| **Missing** | No alt text. |
| **Weak** | The alt text is the file name, a file extension, a code (like `IMG_1234`), a generic word (like “image” or “photo”), or the same words repeated. |
| **Needs review** | Probably fine, but check it: it starts with “image of”, is very short (under 3 characters) or long (over 250), or has no alt text but the file name looks decorative. |
| **Good** | No problem found, or marked as decorative. |

Mark an image as **decorative** (a divider, a background) when it needs no alt text: screen
readers skip it.

These are automatic checks. They point out likely problems but can't prove alt text is right or
that a page meets WCAG.

## Describing images

- **Download once, with your consent.** The first time someone clicks **Describe**, they are sent
  to the **Image model** page. It explains what is downloaded, from where and what those services
  see. Nothing is downloaded until they tick the box and click **Download**. When it's done,
  **Continue describing** takes them back and describing starts.

  | Download | Size | From |
  |---|---|---|
  | The image model (Florence-2) | about 277 MB | huggingface.co |
  | The AI engine (ONNX Runtime) | about 27 MB | cdn.jsdelivr.net |

- **Speed** (measured in Chrome): about 35 seconds to download on a fast connection, about
  1 second to start after that, about 10 seconds per image.
- **Describing again** gives a different description: it avoids the current alt text and earlier
  suggestions.
- **Your images stay with you.** They go from your Craft site to your browser only. Nothing is
  sent to an AI company.
- **What it can get wrong.** It writes short, plain descriptions. It doesn't know people, brands
  or places by name, and can be wrong. That's why every suggestion needs approval.

Keep the page open while describing. **Stop** ends it at once.

## Other languages

The image model writes English. Each site can get suggestions in its own language.

- **Settings → Languages:** choose the suggestion language for each site. The default is the
  site's own language. Choose English, for example, for a French site that uses English alt text.
- **Translation models** are separate, optional downloads on the **Image model** page, one per
  language (about 110–135 MB each): Afrikaans, Arabic, Chinese (Simplified), Czech, Danish,
  Dutch, Finnish, French, German, Hindi, Hungarian, Indonesian, Italian, Romanian, Russian,
  Spanish, Swedish, Ukrainian, Vietnamese and Xhosa.
- **When describing**, the suggestion is translated if this browser has that language's model.
  If not, it stays in English.
- **English suggestions** for a non-English site are marked “This suggestion is in English.
  Translate it before using it.” with a **Translate into {language}** button. It translates
  just the text, in about a second once the model is loaded. If the model isn't downloaded yet,
  it takes you to that language on the Image model page first; **Continue translating** brings
  you back.

Languages without a model (for example Portuguese, Polish, Japanese, Norwegian, Traditional
Chinese) get English suggestions, marked, without a Translate button. Translations are machine
made: check them when you review.

## Where images are used

A **usage scan** finds images in Assets fields (including Matrix, nested content and global sets)
and in rich text. Drafts, revisions and trashed content are ignored. A full scan runs it
afterwards (Settings → Scanning).

It can't see templates, custom code or other systems, so “Not found in content” doesn't prove an
image is unused.

## Settings

**AltMonitor → Settings** has two tabs. Settings are saved to project config, so they can only
be changed where admin changes are allowed.

| Tab | Setting | Default |
|---|---|---|
| Scanning & rules | Check images when they are saved | On |
| Scanning & rules | Find where images are used after a full scan | On |
| Scanning & rules | Images per scan job | 100 |
| Scanning & rules | Extra generic words: alt text that is only one of them (also with “a”, “the” or a number) counts as weak | None |
| Languages | Suggestion language per site | The site's language |

After changing the extra generic words, run a full scan: it re-checks every image with the new
list. The words also apply right away to hints while typing, and an AI description that is only
one of them is thrown away.

**Scanning & rules** also explains how each status is given and lists the 49 built-in generic
words (like “image”, “logo”, “placeholder”). With Ollama, it has the **Test connection** button.

Everything can also be set in `config/alt-monitor.php`, which overrides the control panel:

| Setting | Default | |
|---|---|---|
| `siteLanguages` | `[]` | Suggestion language by site UID, e.g. `['<site-uid>' => 'en']` |
| `scanBatchSize` | `100` | Images per scan job (10–1000) |
| `auditOnSave` | `true` | Check images when they are saved |
| `scanUsageAfterFullScan` | `true` | Run a usage scan after a full scan |
| `additionalGenericTerms` | `[]` | Extra generic words |
| `browserModel` | `onnx-community/Florence-2-base-ft` | Config file only. The image model |
| `browserModelHost` | Hugging Face | Config file only. Where to download the models from |
| `browserWasmPath` | jsDelivr | Config file only. Where to download the AI engine from |
| `aiEnabled` | `false` | Config file only. Describe on the server with Ollama instead |
| `ollamaEndpoint` | `http://localhost:11434` | Config file only. Can be an environment variable |
| `ollamaModel` | `qwen2.5vl:3b` | Config file only |
| `aiTimeout` | `120` | Config file only. Seconds per image with Ollama |
| `allowedAiHosts` | `localhost`, `127.0.0.1`, `::1`, `host.docker.internal`, `ollama` | Config file only |
| `suggestAfterScan` | `false` | Config file only. With Ollama, get suggestions after each scan |
| `fallbackToRules` | `false` | Config file only. With Ollama, suggest from caption, title or file name when it fails |
| `contextFieldHandles` | `['caption', 'description']` | Config file only. Fields that fallback may use |

## Permissions

| Permission | Allows |
|---|---|
| View AltMonitor | The dashboard, images and suggestions, in volumes the user can see |
| ↳ Run scans | Starting scans |
| ↳ Get suggestions | Describing and translating images |
| ↳ Edit alt text | Saving alt text and marking images as decorative (the user must also be allowed to save the asset) |
| ↳ Approve suggestions | Approving and rejecting suggestions (the user must also be allowed to save the asset) |
| Manage settings | The Settings page |

## Command line

```bash
php craft alt-monitor/scan                # images changed since the last scan (all of them the first time)
php craft alt-monitor/scan --full         # every image
php craft alt-monitor/scan --usage        # where images are used
php craft alt-monitor/scan --full --wait  # run it now and print the result
```

With `--wait`, the command runs Craft's queue until the scan is done. Exit codes: `0` done or
queued, `1` the scan failed, `64` `--full` and `--usage` together, `75` another scan is already
running (try again later). Nightly example:

```
0 3 * * * cd /path/to/project && php craft alt-monitor/scan --wait >/dev/null
```

## Privacy (GDPR)

- Images never leave your site and the person's browser.
- The model files are downloaded from huggingface.co and cdn.jsdelivr.net. Like any download,
  they see the IP address, browser type and time, and may handle data outside the EU. They get no
  cookies, account details, images or alt text. You may want to mention this in your privacy
  notice.
- The files are kept in that browser only. **Remove from this browser** on the Image model page
  deletes them.
- AltMonitor doesn't record who downloaded the model.

This describes what the plugin does; it isn't legal advice.

## Advanced

### Hosting the model files yourself

To avoid Hugging Face and jsDelivr (for a content security policy or data rules), host the files
and set in `config/alt-monitor.php`:

```php
return [
    'browserModelHost' => 'https://files.example.com/models/',      // serves onnx-community/Florence-2-base-ft/… and Xenova/opus-mt-en-…/…
    'browserWasmPath' => 'https://files.example.com/onnxruntime/',  // onnxruntime-web dist files
];
```

The Image model page then says no other service is used.

### Describing on your own server with Ollama

With [Ollama](https://ollama.com) and a vision model, images are described in Craft's queue on
the server, without anyone keeping a page open. Set in `config/alt-monitor.php` only:

```php
return [
    'aiEnabled' => true,
    'ollamaEndpoint' => 'http://localhost:11434',
    'ollamaModel' => 'qwen2.5vl:3b',
    // Hosts the endpoint may use. Add yours if Ollama isn't local.
    'allowedAiHosts' => ['localhost', '127.0.0.1', '::1', 'host.docker.internal', 'ollama'],
];
```

**Settings** then shows a **Test connection** button. **Describe them for me** on the dashboard
and **Get a suggestion** on an image queue the work instead of describing in the browser, so the
image model isn't downloaded. Ollama writes English too; English suggestions still get **Translate** (in the
browser). Ollama needs several GB of memory and is slow without a GPU. AltMonitor only connects
to allowed hosts, refuses URLs with logins or paths, and never connects to addresses such as cloud
metadata services. Check the licence of the model you choose.

## Known limitations

- Describing needs a person on the page (unless you use Ollama).
- Usage scans can't see templates or custom code.
- The image model doesn't know names of people, brands or places.
- Statuses are automatic checks, not a WCAG audit.

## Development

From `plugins/Alt-Monitor`:

```bash
composer install
composer test           # unit tests (no Craft app needed)
composer phpstan
composer check-cs
```

Integration and security tests need the host Craft project and database (DDEV). They run inside
a rolled-back transaction and mock Ollama:

```bash
ddev exec -d /var/www/html/plugins/Alt-Monitor composer test-integration
```

The browser test runs the real image and translation models in headless Chrome on real photos
(needs Chrome and network access to Hugging Face and jsDelivr):

```bash
composer test-browser
```

## License

MIT. See `LICENSE.md`, which also lists the bundled Transformers.js library (Apache 2.0). The
models are downloaded by each browser, not bundled: Florence-2 (MIT) and Opus-MT by Helsinki-NLP
(Apache 2.0; English→German CC BY 4.0).
