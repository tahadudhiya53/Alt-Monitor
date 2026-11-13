# Release Notes for AltMonitor

## Unreleased

### Added
- Added a dashboard with alt text coverage, counts by status and usage, and three steps: find images, describe them, check and approve.
- Added alt text checks per image and site: Missing, Weak, Needs review and Good, with the reason for each.
- Added decorative images: marked images need no alt text, and file names that look decorative are flagged for review.
- Added full, changes-only and usage scans in Craft's queue, with progress, retries and the last scan's result on the dashboard.
- Added usage detection for Assets fields (including Matrix, nested content and global sets) and images in rich text.
- Added alt text suggestions written from the image itself by Florence-2, running free in the browser.
- Added the Image model page, where each person agrees to the one-time download (about 304 MB) and can remove it.
- Added suggestions in the site's language: optional translation models for 20 languages, downloaded one language at a time.
- Added **Translate into {language}** for English suggestions on non-English sites, which translates just the text.
- Added a suggestion language per site (Settings → Languages), by default the site's own language.
- Added the Images list: filters, search, inline editing, **Save changes**, and bulk describe, approve and mark as decorative.
- Added **Export CSV** on the Images list: every image the filters show (all pages) with its status, issues, current and suggested alt text, usage, links and an empty **New alt text** column, for content teams working outside Craft.
- Added an image page with the suggestion, describing, decorative toggle and where the image is used.
- Added the Suggestions page: edit, approve, reject, **Approve selected**, **Approve all on this page**, and keyboard shortcuts.
- Added live hints while typing alt text, using the same checks as scans.
- Added extra generic words (Settings → Scanning & rules); the next full scan re-checks every image with them.
- Added a warning before leaving a page with unsaved alt text.
- Added an **Alt text check** box in Craft's asset editor sidebar, with **Use** and **Reject**.
- Added an **Alt text status** column and filter to Craft's Assets index.
- Added the **Alt text coverage** dashboard widget.
- Added the `alt-monitor/scan` console command (`--full`, `--usage`, `--wait`).
- Added optional server-side suggestions with Ollama, set in `config/alt-monitor.php` only.
- Added settings in two tabs, **Scanning & rules** (with how images are checked) and **Languages**, overridable from `config/alt-monitor.php`.
- Added the “View AltMonitor”, “Run scans”, “Get suggestions”, “Edit alt text”, “Approve suggestions” and “Manage settings” permissions.

### Security
- Nothing is downloaded to a browser without the person's consent on the Image model page.
- Images go only from the Craft site to the person's browser; they are never sent to an outside service.
- Alt text is written only through Craft's element saving, after checking the user may save the asset.
- Every action checks its permission; changes need a POST request with Craft's CSRF token.
- Images are served to the browser only to users who may view them.
- The CSV export has only the images the user may see in the list, and cells that start like a spreadsheet formula are written as plain text.
- Ollama is only contacted on allowed hosts, never on metadata or other blocked addresses, without redirects, and its answers aren't logged.
- `allowedAiHosts` is read from the config file only and never saved to project config.
- The Image model page only links back to AltMonitor pages or Craft's asset editor, also where the control panel is reached through `index.php?p=`, and never through `..` or encoded slashes.
