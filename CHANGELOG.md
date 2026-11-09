# Release Notes for AltMonitor

## Unreleased

### Added
- Dashboard with image, alt text status, coverage, suggestion, update and content usage counts,
  scan progress and scan history, each linking to the matching filtered list.
- Full, incremental and content usage scans in Craft's queue, in batches, with progress, retries,
  one scan at a time, and abandoned scans released after 30 minutes without progress.
- Rule-based alt text analysis (missing, weak, good, needs review) with decorative images and
  configurable generic terms.
- Images are re-audited when saved, restored or marked decorative, and forgotten when deleted.
- Content usage detection through Craft relations (including nested entries and global sets) and
  asset reference tags in field content, ignoring drafts, revisions and trashed content.
- Alt text suggestions described from the image itself by Florence-2, running in the browser of
  whoever generates them, with no API key, account or server setup: describe one image, a
  selection, or every image with missing or weak alt text, with download and progress reporting.
  Images reach the browser through a permission-checked endpoint, so private volumes work too.
- Image model page (AltMonitor → Image model), where each person agrees to the one-time model
  download first. It lists what is downloaded, from where and how big, what those services see,
  where the files are kept and how to remove them. Describing sends people there when their
  browser doesn't have the model yet, and picks up where they left off afterwards. It also shows
  download progress, cancelling, and the storage used, and warns about metered connections, low
  storage, plain HTTP and browsers that can't keep the files.
- Optional server-side suggestions from an Ollama vision model, configured in
  `config/alt-monitor.php` only, with endpoint allowlisting, address checks and connection
  pinning, a connection test, and an optional fallback to suggestions from the caption, title or
  file name.
- Suggestions page: review, edit, approve, reject, regenerate and bulk-approve suggestions.
- Image list with search, filters, pagination, inline editing and bulk actions, and a page per
  image showing its audit, suggestion and usage.
- Settings page and permissions for viewing, scanning, generating, editing, approving and managing
  settings. AI cannot be switched on or pointed anywhere from the control panel.
- Simple, guided control panel: the dashboard shows three steps (find images, describe them, check
  and approve) with one button each; the image list has status tabs with counts, the suggestion
  under each alt text field with a one-click “Use”, and filters that apply on selection; the
  suggestions page lets you edit, approve or reject each suggestion in place; an image's page puts
  the suggestion right above its alt text. Lists stack on phones.
- Describing never shows a download when the model is already in the browser, skips descriptions
  that repeat the image's alt text, and updates a row's status in place after “Use”.
- Describing an image again gives a different description: the browser avoids the image's
  current alt text, its suggestion and what it wrote before, trying a fuller caption and other
  wordings. Describing shows a popup with the image, its new description, progress and an
  immediate Stop.
- Descriptions read as alt text: the model's narration (“In this image we can see…”, “there
  is…”, “An image of…”) is removed, places are kept (“A flag in the background”), and sentences
  about the background colour or how the photo was taken are dropped.
- Released under the MIT license.
