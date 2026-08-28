# laravel-imagekit

Media-library glue that stores Spatie Media Library files on ImageKit and serves them through ImageKit URLs. Talks to ImageKit only through the Client.

## Language

**Client**:
The Laravel-native ImageKit API client (`thecyrilcril/imagekit-laravel-client`) that owns credentials, HTTP, DTOs and URL building.
_Avoid_: SDK, wrapper, adapter

**Profile**:
A named set of store-time rules applied before upload: compression, max edge, quality, format, and whether to await the upload.
_Avoid_: upload options, conversion

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
