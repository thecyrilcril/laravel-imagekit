---
status: accepted
date: 2026-09-06
---

# Cleanup is a queued job that removes the whole Source, never an inline delete

Cleanup (deleting the Source once ImageKit holds the file) runs as its own queued job, dispatched after the media row is saved with `imagekit.file_id`, and removes the original, the conversions and the responsive images. It is never done inline in the upload path, because media-library fires `MediaHasBeenAddedEvent` (where this package uploads) *before* it generates conversions, so an inline delete after an awaited upload leaves every conversion silently ungenerated. The job re-checks that the row still exists and still carries `file_id` before it deletes anything; that check, not the dispatch site, is the safety rule.

## Considered options

- **Inline delete right after `save()`** (rejected): simplest, but breaks conversions on the awaited path for the ordering reason above.
- **Listener on `FileUploaded` dispatching the job** (rejected): one trigger site, but the per-call `->cleanup()` override is not on the event, so it would have to stay on the row in `custom_properties` until the job strips it. v0.7.0 decided package bookkeeping never stays in `custom_properties`. Both success sites (`ImageKitManager::performUpload()` and `PushFileToImageKit::push()`) dispatch the job directly instead, and the override travels as a constructor argument.
- **Original only, keep conversions and responsive images** (rejected): ImageKit is the source of truth once the row is ready, so the whole Source is dead weight.

## Consequences

- Responsive images are not routed to ImageKit by `ImageKitUrlBuilder`, so a row that uses `withResponsiveImages()` shows broken `srcset` entries after Cleanup. The job logs one warning when the row carries `responsive_images` data. Serving responsive images from ImageKit is a separate issue; until it lands, README says not to combine the two.
- Conversions and responsive images are generated on media-library's own queue, so the Cleanup job can run first. A conversion that finds no Source is skipped silently and ImageKit serves it anyway. A responsive-images job that finds no Source fails into `failed_jobs`. Accepted; no delay knob.
- `getPath()` still returns a path after Cleanup, but nothing is there. App code that reads the local file (for example an EXIF reader) must run before Cleanup or read with `cleanup` off.
- `ImageKitClient::uploadNow()` gains a third optional parameter for the per-call override. Custom implementations of the contract must add it.
