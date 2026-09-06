# laravel-imagekit

Media-library glue that stores Spatie Media Library files on ImageKit and serves them through ImageKit URLs. Talks to ImageKit only through the Client.

## Language

**Client**:
The Laravel-native ImageKit API client (`thecyrilcril/imagekit-laravel-client`) that owns credentials, HTTP, DTOs and URL building.
_Avoid_: SDK, wrapper, adapter

**Profile**:
A named set of store-time rules applied before upload: compression, max edge, quality, format, whether to await the upload, and whether to clean up the Source afterwards.
_Avoid_: upload options, conversion

**Await**:
Uploading to ImageKit before the call that stored the file returns, so the caller gets the CDN path at once. Set on a Profile, or on one call.
_Avoid_: synchronous upload, blocking upload

**Source**:
Everything media-library wrote to the media disk for one media row: the original file, its conversions and its responsive images.
_Avoid_: local file, local copy, original

**Cleanup**:
Deleting the Source once ImageKit holds the file, so ImageKit is the only place the file exists. Set on a Profile, on one call, or run in bulk over a collection.
_Avoid_: discard, purge, prune, delete local

**Preset**:
A named set of delivery-time transformations applied when building a URL.
_Avoid_: transformation profile, variant

**Transformation**:
One ImageKit URL instruction (`w-200`, `fo-face`), or a chain of them. A Preset is a named Transformation.

**Reconcile**:
Comparing files on ImageKit with media rows in the database to find orphans on either side.
_Avoid_: sync

**Orphan**:
A remote file with no media row, or a media row with no remote file.
