# ImageKit.io API (2026) vs `imagekit/imagekit` PHP SDK 4.0.2

Date: 2026-08-28
Scope: what the current ImageKit REST API and URL transformation spec offer, and where the vendored SDK (`vendor/imagekit/imagekit`, v4.0.2, last commit 2024-09-05) falls short.

Sources used (primary only):

- ImageKit docs, plain-Markdown mirrors listed in `https://imagekit.io/docs/llms.txt` (every page below has a `.md` twin, e.g. `https://imagekit.io/docs/transformations.md`).
- The official successor SDK on the `stainless/release` branch of `https://github.com/imagekit-developer/imagekit-php` (generated from ImageKit's OpenAPI spec; used for exact field lists and endpoint paths).
- Local SDK source under `/Users/user/Code/laravel-imagekit/vendor/imagekit/imagekit/src/ImageKit/`.

Note: the JS-rendered API reference pages (`/docs/api-reference/...`) do not expose parameter tables in their `.md` form. Field lists below come from the Stainless SDK models, which are generated from the same OpenAPI spec the reference pages render.

---

## 0. Auth and rate limits (all v1 management APIs)

Source: `https://imagekit.io/docs/api-overview.md` (sections "Authentication", "Rate limits").

- HTTP Basic auth. Username = private API key, password = empty string. Header form: `Authorization: Basic base64("private_key:")`.
- HTTPS only.
- Rate limits apply to every API except upload. On `429`, sleep for `X-RateLimit-Reset` milliseconds. Headers: `X-RateLimit-Limit`, `X-RateLimit-Interval`, `X-RateLimit-Reset`.
- Safe usage from the doc: reads about 40 req/s, writes 5-20 req/s; bulk tags and custom-metadata-field CRUD are capped at 5 req/s.
- Base URLs: `https://api.imagekit.io/v1` (management), `https://upload.imagekit.io/api/v1` and `.../api/v2` (upload).

SDK 4.0.2 does this right: `src/ImageKit/Utils/Authorization.php` passes `['auth' => [$privateKey, '']]` to Guzzle. It does not read or expose the rate-limit headers.

---

## 1. Upload API

### Endpoints

| Version | Endpoint | Auth | Source |
|---|---|---|---|
| V1 | `POST https://upload.imagekit.io/api/v1/files/upload` (multipart/form-data) | Server: Basic with private key. Client: `publicKey` + `token` + `signature` + `expire` fields (HMAC-SHA1 of `token+expire` with private key) | `upload-file.md` lines 10, 1288-1342; Stainless `src/Services/FilesRawService.php:301` |
| V2 | `POST https://upload.imagekit.io/api/v2/files/upload` | Server: Basic. Client: a `token` that is a JWT (HS256, `kid` = public key) whose payload must equal every upload param except `file` and `token` | `https://imagekit.io/docs/api-reference/upload-file/upload-file-v2.md` lines 9-70, 176; Stainless `src/Services/Beta/V2/FilesRawService.php:87` |

### Request fields (V1)

Source: Stainless `src/Files/FileUploadParams.php` (phpstan shape at lines 40-64; docblocks at lines 70-263).

| Field | Type | Notes |
|---|---|---|
| `file` | string or binary | Required. Binary multipart, public HTTP(S) URL, or base64 (data URI or plain). URL fetch must return headers within 8 s or you get 400. |
| `fileName` | string | Required. |
| `useUniqueFileName` | bool | Default true. |
| `folder` | string | |
| `tags` | list of string | Wire format is comma-separated. |
| `isPrivateFile` | bool | |
| `isPublished` | bool | `false` = draft, reachable only via the media library. |
| `customCoordinates` | string | `x,y,w,h`. |
| `customMetadata` | object | JSON. Keys must be existing custom metadata fields. |
| `responseFields` | list | Enum: `tags`, `customCoordinates`, `isPrivateFile`, `embeddedMetadata`, `isPublished`, `customMetadata`, `metadata`, `selectedFieldsSchema` (`src/Files/FileUploadParams/ResponseField.php`). |
| `extensions` | list of objects | Types: `google-auto-tagging`, `aws-auto-tagging`, `remove-bg`, `ai-auto-description`, `ai-tasks`, and a saved-extension reference (`src/ExtensionItem/*.php`). |
| `webhookUrl` | string | Called when extensions finish. |
| `overwriteFile` | bool | |
| `overwriteAITags` | bool | |
| `overwriteTags` | bool | |
| `overwriteCustomMetadata` | bool | |
| `transformation` | object | `{ pre: string, post: [{type: "transformation"|"gif-to-video"|"thumbnail"|"abs", value, protocol}] }` (`src/Files/FileUploadParams/Transformation.php`). |
| `checks` | string | Server-side guard expression, e.g. `"file.size" < "1MB"`; fields `request.folder`, `request.tags`, `request.customMetadata.*`, `file.size`, `file.mime`, `mediaMetadata.width/height/...` (`upload-file.md` lines 1673-1895). |
| `description` | string | New; not in SDK 4.0.2 docs. |
| `publicKey`, `token`, `signature`, `expire` | | Client-side auth only. |

### Response fields

Source: Stainless `src/Files/FileUploadResponse.php` lines 63-225 and sub-models.

`fileId`, `name`, `filePath`, `url`, `thumbnailUrl`, `fileType` (`image`/`non-image`), `size`, `width`, `height`, `tags`, `AITags` (`[{name, confidence, source}]`), `customCoordinates`, `customMetadata`, `description`, `isPrivateFile`, `isPublished`, `embeddedMetadata`, `metadata` (full media metadata, only when asked via `responseFields`), `selectedFieldsSchema`, `versionInfo` (`{id, name}`), `extensionStatus` (`{aiAutoDescription, aiTasks, awsAutoTagging, googleAutoTagging, removeBg}` each `success`/`pending`/`failed`), and for video `duration`, `bitRate`, `audioCodec`, `videoCodec`.

### Limits

Source: Stainless `FileUploadParams.php` header docblock (lines 17-24), mirrored from the API reference.

- Free: 25 MB images/audio/raw, 100 MB video. Lite: 40 MB / 300 MB. Pro: 50 MB / 2 GB. Enterprise: higher.
- Max 100 versions per file.

### SDK 4.0.2 gaps for upload

`src/ImageKit/Upload/Upload.php` forwards any key you pass, so unknown fields (`description`, `checks`, `isPublished`, `overwrite*`) reach the API. But:

- It only serialises `tags`, `customCoordinates`, `responseFields`, `extensions`, `customMetadata`, `transformation`. Nothing validates names or types.
- Booleans go through Guzzle multipart as PHP strings; `false` becomes `""`. Send `"false"`/`"true"` strings yourself.
- No V2 (JWT) upload. No JWT helper.
- Response is the raw decoded body; no typed object, no `extensionStatus` handling.
- `uploadFiles()` (bulk) is just a loop.

---

## 2. File and folder management

### Endpoint map (current API) vs SDK 4.0.2

Paths from Stainless `src/Services/*RawService.php` (file:line) and SDK `src/ImageKit/Constants/Endpoints.php`.

| Operation | Method and path | In SDK 4.0.2? |
|---|---|---|
| List / search assets | `GET /v1/files` (`AssetsRawService.php:64`) | Yes `listFiles()` |
| File details | `GET /v1/files/{id}/details` (`FilesRawService.php:166`) | Yes |
| Update file | `PATCH /v1/files/{id}/details` (`:78`) | Yes |
| Delete file | `DELETE /v1/files/{id}` (`:106`) | Yes |
| Copy file | `POST /v1/files/copy` (`:140`) | Yes |
| Move file | `POST /v1/files/move` (`:200`) | Yes |
| Rename file | `PUT /v1/files/rename` (`:235`) | Yes |
| Bulk delete | `POST /v1/files/batch/deleteByFileIds` (`Files/BulkRawService.php:60`) | Yes |
| Bulk add tags | `POST /v1/files/addTags` (`:93`) | Yes |
| Bulk remove tags | `POST /v1/files/removeTags` (`:159`) | Yes |
| Bulk remove AI tags | `POST /v1/files/removeAITags` (`:126`) | Yes |
| File metadata | `GET /v1/files/{id}/metadata` (`Files/MetadataRawService.php:47`) | Yes |
| Metadata from remote URL | `GET /v1/metadata?url=` (`:77`) | Yes |
| List versions | `GET /v1/files/{id}/versions` (`Files/VersionsRawService.php:49`) | Yes |
| Version details | `GET /v1/files/{id}/versions/{v}` (`:119`) | Yes |
| Delete version | `DELETE /v1/files/{id}/versions/{v}` (`:85`) | Yes |
| Restore version | `PUT /v1/files/{id}/versions/{v}/restore` (`:153`) | Yes |
| Create folder | `POST /v1/folder` (`FoldersRawService.php:60`) | Yes |
| Delete folder | `DELETE /v1/folder` (`:91`) | Yes |
| Copy folder | `POST /v1/bulkJobs/copyFolder` (`:124`) | Yes |
| Move folder | `POST /v1/bulkJobs/moveFolder` (`:157`) | Yes |
| **Rename folder** | `POST /v1/bulkJobs/renameFolder` (`:190`) | **No** |
| Bulk job status | `GET /v1/bulkJobs/{jobId}` (`Folders/JobRawService.php:44`) | Yes |
| Purge cache | `POST /v1/files/purge` (`Cache/InvalidationRawService.php:51`) | Yes |
| Purge status | `GET /v1/files/purge/{requestId}` (`:77`) | Yes |
| Custom metadata fields CRUD | `POST/GET /v1/customMetadataFields`, `PATCH/DELETE /v1/customMetadataFields/{id}` | Yes |
| **Saved extensions CRUD** | `POST/GET /v1/saved-extensions`, `GET/PATCH/DELETE /v1/saved-extensions/{id}` (`SavedExtensionsRawService.php`) | **No** |
| **Account: usage** | `GET /v1/accounts/usage` (`Accounts/UsageRawService.php:54`) | **No** |
| **Account: usage analytics** | `GET /v1/accounts/usage-analytics` (`Accounts/UsageAnalyticsRawService.php:56`) | **No** (added 2026-07-14) |
| **Account: origins CRUD** | `/v1/accounts/origins[/{id}]` (`Accounts/OriginsRawService.php`) | **No** |
| **Account: URL endpoints CRUD** | `/v1/accounts/url-endpoints[/{id}]` (`Accounts/URLEndpointsRawService.php`) | **No** |
| **Webhook signature verify** | Local, Standard Webhooks (`standard-webhooks/standard-webhooks` dependency) | **No** |
| V2 upload | see section 1 | **No** |

### List / search: query params

Source: Stainless `src/Assets/AssetListParams.php` lines 46-102 and `src/Assets/AssetListParams/Sort.php`; doc `list-and-search-assets.md`.

| Param | Values |
|---|---|
| `type` | `file` (default), `file-version`, `folder`, `all` |
| `sort` | `ASC_NAME`, `DESC_NAME`, `ASC_CREATED`, `DESC_CREATED`, `ASC_UPDATED`, `DESC_UPDATED`, `ASC_HEIGHT`, `DESC_HEIGHT`, `ASC_WIDTH`, `DESC_WIDTH`, `ASC_SIZE`, `DESC_SIZE`, `ASC_RELEVANCE`, `DESC_RELEVANCE` |
| `path` | Folder path; one level only (`list-and-search-assets.md` line 1255). Use `path:"/x/"` inside `searchQuery` to include sub-folders (line 330). |
| `searchQuery` | Lucene-like string. When present, `tags`, `type`, `name` params are ignored. |
| `fileType` | `all`, `image`, `non-image` |
| `limit`, `skip` | ints |
| `tags`, `name` | legacy filters, overridden by `searchQuery` |

`searchQuery` fields (doc lines 135-332): `id`, `name`, `tags`, `type`, `createdAt`, `updatedAt`, `height`, `width`, `size`, `format`, `private`, `published`, `transparency`, `createdBy`, `embeddedMetadata.LocationTaken`, `embeddedMetadata.Keywords`, `embeddedMetadata.DateTimeOriginal`, `customMetadata.<field>`, `path`. Operators: `=`, `NOT =`, `:` (prefix), `IN`, `NOT IN`, `HAS`, `EXISTS`, `NOT EXISTS`, `<`, `<=`, `>`, `>=`, `AND`, `OR`. Relative dates like `"7d"`, sizes like `"2mb"`.

### File object (list and details response)

Source: Stainless `src/Files/File.php` lines 64-240.

`fileId`, `type` (`file`/`file-version`), `name`, `filePath`, `url`, `thumbnail`, `fileType`, `mime`, `size`, `width`, `height`, `hasAlpha`, `tags`, `AITags`, `customCoordinates`, `customMetadata`, `description`, `embeddedMetadata`, `selectedFieldsSchema`, `isPrivateFile`, `isPublished`, `versionInfo`, `createdAt`, `updatedAt`, and video `duration`, `bitRate`, `audioCodec`, `videoCodec`. Folders in `type=all` results come back as `src/Files/Folder.php` (`folderId`, `name`, `folderPath`, `type: "folder"`, `createdAt`, `updatedAt`).

### Update file (PATCH) body

Source: `src/Files/FileUpdateParams.php` lines 47-101: `tags`, `removeAITags` (list or `"all"`), `customCoordinates`, `customMetadata`, `description`, `extensions`, `webhookUrl`, `publish: {isPublished, includeFileVersions}`.

SDK 4.0.2 gaps here: `updateFileDetails()` in `src/ImageKit/ImageKit.php:404` passes the array through, so new keys work, but nothing is typed. `listFiles()` (`src/ImageKit/Manage/File.php:23`) just forwards query params; fine. `renameFolder`, saved extensions, accounts endpoints, and webhook verification are absent.

---

## 3. URL transformations

### Placement and syntax

Source: `https://imagekit.io/docs/transformations.md` (sections "Basic example and URL structure", "Chained transformations", "Named transformations", "Overlay using Layers").

- Path form: `https://ik.imagekit.io/{id}/tr:w-400,h-300/path.jpg`. Query form: `.../path.jpg?tr=w-400,h-300`.
- Inside a step, params are joined with `,`. Key and value are joined with `-`. Chained steps are joined with `:` and run in order (`w-400,h-300:rt-90`).
- Named transformation: `n-<name>` (works for images and videos). Dashboard can force "named only".
- Layers: `l-image|l-text|l-video|l-subtitle ... l-end`, up to 3 levels deep. Layer input `i-<path>` or `ie-<url-safe base64>`. Position: `lx`, `ly`, `lxc`, `lyc`, `lap`, `lfo`; timing on video: `lso`, `ldu`, `leo`. Blend: `lm-multiply|displacement|cutout|cutter`. Paths inside layers use `@@` for `/`.
- Conditional: `if-<prop>_<op>_<value>,<tr>,if-else,<tr>,if-end` with props `h,w,ar,ih,iw,iar` and ops `eq,ne,gt,gte,lt,lte` (`conditional-transformations.md`).
- Arithmetic expressions in many values: `iw_div_2`, `bw_mul_0.4`, `bdu_sub_idu` (`arithmetic-expressions-in-transformations.md`).
- Special path suffixes: `/ik-thumbnail.jpg` (video or PDF/PSD thumbnail), `/ik-video.mp4` (force video processing), `/ik-master.m3u8` and `/ik-master.mpd` with `sr-240_360_...` (ABS), `/ik-gif-video.mp4` (GIF to video) (`create-video-thumbnails.md`, `transformations.md` line ~245, `adaptive-bitrate-streaming.md`, `vector-and-animated-images.md`).
- Other URL query switches: `ik-sanitizeSvg=true` (`media-delivery-basic-security.md`), `ik-attachment=true` and `ik-attachment-filename=<name>` (`core-delivery-features.md` lines 24-38).

### Parameter to short-code map (current)

Doc pages: `image-resize-and-crop.md`, `effects-and-enhancements.md`, `image-optimization.md`, `ai-transformations.md`, `video-optimization.md`, `trim-videos.md`, `add-overlays-on-images.md`, `content-credentials.md`, `vector-and-animated-images.md`. The SDK-style property names come from Stainless `src/Transformation.php` lines 132-605.

| Property (Stainless) | Code | Values / notes | SDK 4.0.2 key |
|---|---|---|---|
| width | `w` | px, 0-1 fraction, expr | `width` |
| height | `h` | | `height` |
| aspectRatio | `ar` | `4-3` | `aspectRatio` |
| crop | `c` | `force`, `at_max`, `at_max_enlarge`, `at_least`, `maintain_ratio`, `maintain_ratio_no_enlarge` | `crop` |
| cropMode | `cm` | `pad_resize`, `pad_resize_no_enlarge`, `extract`, `pad_extract`, `pad_extract_no_shrink` | `cropMode` |
| focus | `fo` | `auto`, `auto-custom_override`, `face`, `custom`, `center|top|left|...`, `<object>` or `<obj1>_<obj2>` | `focus` |
| zoom | `z` | 0-1 out, >1 in, with `fo-face`/object | **missing** |
| x, y | `x`, `y` | with `cm-extract` | `x`, `y` |
| xCenter, yCenter | `xc`, `yc` | | **missing** |
| dpr | `dpr` | 0.1-5 or `auto` | `dpr` |
| quality | `q` | 1-100 | `quality` |
| format | `f` | `auto`, `jpg`, `jpeg`, `webp`, `avif`, `png`, `gif`, `orig`; video `mp4`, `webm` | `format` |
| lossless | `lo` | `true` | `lossless` |
| progressive | `pr` | `true` | `progressive` |
| metadata | `md` | `true` | `metadata` |
| colorProfile | `cp` | `true` | `colorProfile` |
| density | `dn` | 1-1200 DPI, or `idn` expression (`image-optimization.md` "Density") | **missing** |
| original | `orig` | `true` | `original` |
| defaultImage | `di` | path with `@@` for `/` | `defaultImage` |
| named | `n` | | `named` |
| radius | `r` | int, `max`, `tl_tr_br_bl` | `radius` |
| background | `bg` | color, `blurred[_<int>_<bright>]`, `dominant`, `dominant_gradient...`, `genfill[-prompt-...]` | `background` |
| border | `b` | `<w>_<color>` | `border` |
| rotation | `rt` | deg, `N<deg>`, `auto` | `rotation` and `rotate` |
| flip | `fl` | `h`, `v`, `h_v` | **missing** |
| blur | `bl` | 1-100 | `blur` |
| trim | `t` | `true`, `false`, 1-99 | `trim` |
| opacity | `o` | 0-100 | **missing** |
| colorReplace | `cr` | `to[_tol[_from]]` | **missing** |
| contrastStretch | `e-contrast` | | `effectContrast` |
| sharpen | `e-sharpen[-n]` | | `effectSharpen` |
| unsharpMask | `e-usm-r-s-a-t` | | `effectUSM` |
| grayscale | `e-grayscale` | | `effectGray` |
| shadow | `e-shadow[-st-x_bl-x_x-x_y-x]` | | `effectShadow` |
| gradient | `e-gradient[-ld-x_from-x_to-x_sp-x]` | | `effectGradient` |
| colorize | `e-colorize[-co-x_in-x]` | | **missing** |
| distort | `e-distort-p-...` or `e-distort-a-<deg>` | | **missing** |
| aiRemoveBackground | `e-bgremove` | in-house | **missing** |
| aiRemoveBackgroundExternal | `e-removedotbg` | third party | **missing** |
| aiChangeBackground | `e-changebg-prompt-...` / `prompte-<b64>` | | **missing** |
| aiEdit | `e-edit-prompt-...` | | **missing** |
| aiDropShadow | `e-dropshadow[-az-x_el-x_st-x]` | | **missing** |
| aiRetouch | `e-retouch` | | **missing** |
| aiUpscale | `e-upscale` | | **missing** |
| aiVariation | `e-genvar` | | **missing** |
| (generative fill) | `bg-genfill` | via `bg` | via `background` |
| (text-to-image) | `ik-genimg-prompt-<p>/<name>.jpg` | path form | **missing** |
| page | `pg` | number, `1_3_5`, `2-4`, `4-`, `name-"layer"` | **missing** |
| (content credentials) | `c2pa-true` | | **missing** |
| startOffset | `so` | seconds, video | **missing** |
| endOffset | `eo` | | **missing** |
| duration | `du` | | **missing** |
| videoCodec | `vc` | `h264`, `vp9`, `av1`, `none` | **missing** |
| audioCodec | `ac` | `aac`, `opus`, `none` | **missing** |
| streamingResolutions | `sr` | `240_360_480_720_1080_1440_2160` | **missing** |
| overlay (layers) | `l-image|l-text|l-video|l-subtitle ... l-end` with `i`, `ie`, `lx`, `ly`, `lxc`, `lyc`, `lap`, `lfo`, `lso`, `ldu`, `leo`, `lm`; text params `fs`, `ff`, `co`, `ia`, `pa`, `al`, `tg`, `bg`, `r`, `rt`, `fl`, `lh`, `w`; solid `bg`, `w`, `h`, `r`, `al`, `e-gradient` | | **missing** (SDK 4.0.0 removed old `ot`/`oi` overlay keys) |
| raw | passthrough | | `raw` (handled in `Url.php`) |

SDK map: `src/ImageKit/Constants/SupportedTransforms.php` (31 keys; `rotation` and `rotate` are duplicates, so 30 distinct codes).

Unknown keys in the SDK pass through verbatim (`Utils/Transformation.php::getTransformKey` returns the key itself when not in the map), so you can already write `['fl' => 'h']`. There is no key that is *wrong* in the map; the problem is that about 35 documented codes have no friendly alias, and layer syntax has no builder at all.

SDK 4.0.2 URL builder behaviour (`src/ImageKit/Url/Url.php`):

- Default position is `path` (`Utils/Transformation.php:7`). Stainless and the docs default to `query` (`src/SrcOptions.php:92`). Keep `path` if you want cache-key parity with existing URLs.
- With `src` (absolute URL) transformations always go to the query string, and signing is impossible because the endpoint prefix is unknown (comment at `Url.php` signing block).
- `/` in a value becomes `@@` (`buildingTransformationBlocks`). Correct per docs for `di` and layer `i`.
- `unparsed_url()` rebuilds the URL by splitting off the last path segment and calls `urldecode()` on the query string (`Url.php` "Build Search Params"), which un-encodes user query params. Sign-before-encode issues follow from that (see section 4).

---

## 4. Signed URLs

Documented algorithm, `https://imagekit.io/docs/media-delivery-basic-security.md` lines 38-285:

1. Take the full URL (with `tr:` in path or `?tr=` in query, and any other query params).
2. Strip the URL endpoint prefix (endpoint must end with `/`). Result e.g. `tr:w-400/sample/testing-file.jpg`.
3. Append the expiry timestamp as a string (seconds since epoch, UTC). If no expiry, use `9999999999`.
4. `signature = hex(hmac_sha1(private_key, that_string))`, lowercase.
5. Append `?ik-t=<expiry>&ik-s=<signature>` (or `&` if a query already exists). `ik-t` is optional; a URL with only `ik-s` never expires.
6. Percent-encode non-ASCII path characters (`é` -> `e%CC%81`) **before** signing.

Expired or bad signature returns `401`. Private files (`isPrivateFile: true`) and the "restrict unsigned URLs" account setting both need this.

SDK 4.0.2 (`src/ImageKit/Url/Url.php`, `src/ImageKit/Signature/Signature.php`):

- Constants match: `ik-s`, `ik-t`, default `9999999999`.
- It builds the intermediate URL, removes the endpoint, appends the timestamp, `hash_hmac('sha1', ...)`. Correct.
- It calls `urldecode(http_build_query(...))` on query params first, so a URL whose query contains `%`-encoded values is signed decoded. If the CDN receives it encoded the signature will not match. The doc says the opposite: encode first, then sign.
- `expireSeconds` <= 0 or non-numeric silently means "never expires". Stainless uses `expiresIn` and signs whenever `expiresIn > 0` even if `signed` is false (`src/SrcOptions.php:54-73`).
- Signing only works with `urlEndpoint + path`, never with `src` (documented in code comment).

---

## 5. What appeared after 2024-09 that SDK 4.0.2 lacks

Cross-checked against the endpoint and param lists above. Items with a date come from the Stainless branch commit log (`git log` not fetched; the latest commit `e8e8475`, 2026-07-14, is "feat(api): add usage analytics breakdown endpoint").

- Upload: `description` field, V2 JWT upload endpoint, `ai-auto-description` and `ai-tasks` extensions, saved-extension references, `publish` object on update, `removeAITags: "all"`.
- Management: `POST /v1/bulkJobs/renameFolder`, `/v1/saved-extensions` CRUD, `/v1/accounts/usage`, `/v1/accounts/usage-analytics`, `/v1/accounts/origins`, `/v1/accounts/url-endpoints`.
- Webhooks: Standard Webhooks signature verification and typed event classes (`src/Webhooks/*.php` in the Stainless branch: video transformation events, file version create/delete, upload pre/post transform events).
- Transformations: `fl`, `o`, `cr`, `z`, `xc`/`yc`, `dn`, `pg`, `c2pa`, `e-colorize`, `e-distort`, every `e-*` AI code (`e-bgremove`, `e-changebg`, `e-edit`, `e-dropshadow`, `e-retouch`, `e-upscale`, `e-genvar`), `bg-genfill`, `ik-genimg`, video `so/eo/du/vc/ac/sr`, layer/overlay builder, conditional transforms.
- Helpers the newer SDKs have and 4.0.2 does not: `SrcOptions` + `Transformation` typed URL builder, `ResponsiveImageAttributes` / `GetImageAttributesOptions` (srcset generation). The Stainless PHP branch ships these models but I found no `buildSrc()` implementation in `src/` yet (searched the tree for Helper/Sign/Src; only the three model files exist), so URL building is still yours to write.

### State of the upstream repo

Source: `https://api.github.com/repos/imagekit-developer/imagekit-php/branches` and `/releases`.

- `master`: last commit 2024-09-05, release 4.0.2 (adds `checks`). Requires PHP >= 5.6, `guzzlehttp/guzzle ~6.0 || ~7.0`, `beberlei/assert ^2.9.9`. No Guzzle 8 support.
- `next`: exists (`04e6535`), but its `composer.json` is already the Stainless layout; last commit 2026-05-23 "build(php): set production target".
- `stainless/release`: `e8e8475`, 2026-07-14. `composer.json`: PHP `^8.1`, PSR-18 client via `php-http/discovery` (Guzzle only in require-dev), `standard-webhooks/standard-webhooks`, version `0.0.1`, license Apache-2.0. README says "APIs may change at any time". Namespace is still `ImageKit\`, so it will collide with 4.0.2 on the same package name when it ships.
- Other branches (`dev`, `php2.0`, `generated`, `release-please--branches--master--changes--next`, etc.) are older work; no `next` branch content differs materially from `stainless/release`.

Practical read: a Guzzle-free, PSR-18 v1 (or "5.x") of `imagekit/imagekit` is being prepared with Stainless, but nothing is tagged. The transformation model in that branch is the best machine-readable list of current URL params.

---

## 6. Short list of the biggest gaps in SDK 4.0.2

1. No V2 upload, no JWT/client-token helper, no typed upload response.
2. 30 transformation aliases vs roughly 65 documented codes; no layer, AI, video, or conditional support.
3. Signed-URL builder decodes query params before signing (doc says encode first), and cannot sign `src`-based URLs.
4. Missing endpoints: rename folder, saved extensions, accounts usage/analytics/origins/url-endpoints, webhook verification.
5. Runtime: PHP 5.6 floor, Guzzle 6/7 only, `beberlei/assert` v2, no rate-limit header handling, no retries.
