---
status: accepted
date: 2026-08-28
---

# Transformations go in the URL path, not the query string

The new client defaults to path-position transformations (`/tr:w-200/photo.jpg`), matching the old SDK, even though ImageKit's newer SDKs default to the query string (`?tr=w-200`). Both render the same image, but the URL text differs and ImageKit's CDN caches by URL, so switching would make every existing image a cache miss and change every URL this package has ever emitted. Query position stays available as an explicit option.
