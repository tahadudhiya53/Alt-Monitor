# Release Notes for AltMonitor

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 5.0.0 - 2026-11-28

### Added
- Alt text checks for every image, per site: Missing, Weak, Needs review and Good, with the reason. Images can be marked decorative.
- Full, changes-only and usage scans in Craft's queue, from the dashboard or `php craft alt-monitor/scan`.
- Alt text suggestions written from the image by Florence-2, free in the browser, after a one-time download each person agrees to on the Image model page.
- Suggestions in the site's language: optional translation models for 20 languages, and a suggestion language per site.
- A dashboard, an Images list (filters, search, inline editing, bulk actions, CSV export), an image page, a Pages list ranking content by images to fix, and a Suggestions page to approve or reject.
- Checks for images in rich text without alt text there, and for the same alt text on different images.
- An alt text box in Craft's entry and asset editors, a status column and filter in the Assets index, and a dashboard widget.
- An option to stop entries being published while an image in them has no alt text.
- The `altText()` Twig function, and a dev mode check that warns in the browser console about images without `alt`.
- Optional server-side suggestions with Ollama, and an event for supplying another AI provider.
- Permissions to view, scan, get suggestions, edit alt text, approve suggestions and manage settings.

### Security
- Nothing is downloaded to a browser without the person's consent.
- Images go only from the site to the person's browser, or to an allowed Ollama host.
- Alt text is saved only for people allowed to save the asset, and only from images they may see.
- The CSV export has only the images the user may see, and cells that start like a spreadsheet formula are written as text.
