# Parallel Upload for Piwigo 16

Adds a **Parallel Upload** tab to *Photos → Add* that cuts every file into chunks
small enough to pass a Cloudflare Tunnel (100 MB request-body cap), sends several
chunks at once across all queued files, and shows a live dashboard: overall time
remaining, speed, per-file split and progress, and every chunk's state.

Tested against Piwigo 16.4.0 (PHP 8.3, nginx + php-fpm, MariaDB) with a 100,000,000-byte
request cap in front, including 95 MB chunks, a corrupted chunk, an injected 502,
pause/resume, and a finish request cut off mid-flight. Stored files were byte-identical
(MD5) to the originals.

## How stock Piwigo uploads (16.4)

* The Web Form uses **plupload** posting to `pwg.images.upload`, chunked at
  `$conf['upload_form_chunk_size']` (default **500 KB**).
* The server **appends** each chunk to `upload/buffer/<md5(name)>.part`
  (`fopen(..., "ab")`), and treats the request with `chunk == chunks-1` as the end.
  Chunks therefore *must* arrive in order, one at a time — parallelism is impossible
  with that endpoint, and every 500 KB chunk pays a full round-trip through the tunnel.
* Raising the chunk size to cut round-trips runs into the 100 MB tunnel limit
  (HTTP 413) and PHP's `upload_max_filesize`.
* `pwg.images.uploadAsync` does accept random-order chunks, but needs the whole
  file's MD5 up front (slow for large videos in a browser), loads each chunk into
  memory to merge, and does the merge plus registration inside the final request —
  which can outlive Cloudflare's 100-second response timeout (HTTP 524).

## What this plugin does instead

| Step | Method | Notes |
|---|---|---|
| open | `pu.upload.init` | validates type/size/album, preallocates a sparse file of the final size |
| send | `pu.upload.chunk` | any order, any concurrency; written straight to `index × chunk_size`; SHA-256 checked while streaming |
| close | `pu.upload.finish` | verifies every chunk landed, then the same `add_uploaded_file()` path core uses (duplicates, update mode, video posters, lounge) |
| recover | `pu.upload.status` | finish runs with `ignore_user_abort`; if the proxy drops it at 100 s the browser polls for the result |
| cancel | `pu.upload.abort` | deletes the partial |

There is no merge step: the last chunk to arrive completes the file in place, and the
final rename into `upload/YYYY/MM/DD/` is instant because the buffer lives on the
same filesystem.

Browser side, one pool of N workers pulls chunks oldest-file-first, so a big video
fills all connections and a batch of photos goes N at a time. Failed chunks retry
with exponential back-off without blocking a slot; a chunk with no progress for
90 s is aborted and re-sent. **Pause** stops dispatching and lets in-flight chunks
land (nothing is re-sent); **Cancel** stops immediately.

## Install (shell, inside the Piwigo container)

```bash
curl -fsSL https://raw.githubusercontent.com/longblonde/piwigo-parallel-upload/main/install.sh | sudo bash
```

Add options after `bash -s --`, e.g. to also replace the Web Form:

```bash
curl -fsSL https://raw.githubusercontent.com/longblonde/piwigo-parallel-upload/main/install.sh | sudo bash -s -- --replace-web-form
```

The script:

1. finds Piwigo (or use `--piwigo /path`), checks the version;
2. clones this repo into `plugins/ParallelUpload` (re-running it updates in place) and gives it to the web user;
3. writes `99-parallel-upload.ini` into each PHP-FPM / Apache `conf.d` so `upload_max_filesize` and `post_max_size` are at least 100M (it never lowers a bigger value), and warns about FPM pool overrides;
4. adds `client_max_body_size 100m` for nginx (only if `nginx -t` accepts it) and lists any smaller per-site values you need to raise;
5. reloads PHP-FPM / nginx / Apache;
6. activates the plugin in Piwigo's database.

| Option | |
|---|---|
| `--piwigo DIR` | Piwigo root, if auto-detection misses it |
| `--replace-web-form` / `--keep-web-form` | turn the Web Form replacement on / off |
| `--limit MB` | request size to ensure (default 100) |
| `--no-php`, `--no-webserver` | leave those configs alone |
| `--no-activate` | files only; activate in *Admin → Plugins* |
| `--ref TAG` | install a specific tag or branch |

Manual alternative: copy this folder to `piwigo/plugins/ParallelUpload` and activate it in *Plugins*.

## Upload speed and the transfer plan

Each uploader can type their connection's upload speed (Mbps) next to the album
picker; it is saved to their Piwigo account, so it follows them across browsers.
The plugin then aims at **80 %** of it (webmaster setting *Speed target*) and paces
itself so the line stays usable for everything else:

* **connections** = target ÷ ~64 Mbps per stream (one HTTPS stream through a tunnel
  rarely does much more), at least 2, at most *Max parallel connections*;
* **chunk size** = about 6 seconds of transfer per connection, between 8 MB and
  *Max chunk size*;
* **pacing**: a token bucket admits a new chunk only when the running average is
  under the target, so a 1 Gbps line told "500" really averages ~400 Mbps.

Leave the field empty for *Auto*: max connections × max chunk, unpaced.

| Stated speed | Target | Plan (max 8 connections, max 64 MB chunks) |
|---|---|---|
| 25 Mbps | 20 Mbps | 2 × 8 MB |
| 100 Mbps | 80 Mbps | 2 × 30 MB |
| 300 Mbps | 240 Mbps | 4 × 45 MB |
| 500 Mbps | 400 Mbps | 7 × 43 MB |
| 1000 Mbps | 800 Mbps | 8 × 64 MB (connection-limited) |

With the out-of-the-box ceilings (4 connections, 32 MB) anything above ~250 Mbps is
capped at 4 × 32 MB; raise both in the settings for faster lines. Every chunk in
flight occupies one PHP-FPM worker, so `pm.max_children` must be at least
connections + 2 (Debian's default is 5) — the installer warns when it's lower.
With PHP as an Apache module (mod_php) the same limit is Apache's
`MaxRequestWorkers` (Debian default 150, so normally plenty). The settings page
shows whichever applies (PHP-FPM pool and `pm.max_children`, or Apache's MPM and
`MaxRequestWorkers`, the file it comes from and how many workers are running), warns when it's too low for *Max
parallel connections*, and the uploader never opens more chunks than
that limit − 2 whatever the settings say.

## Choosing an album

The uploader uses Piwigo's own album picker, the same modal as the core Web Form:
search across all albums, and **Creation mode** to add a sub-album and select it in
one step. The last album used is remembered, and links like *add photos* on an album
open the uploader with that album already chosen. On a gallery with no albums yet,
the uploader shows the core *Welcome! / Create a first album* prompt.

## Replacing the Web Form

*Admin → Plugins → Parallel Upload → Settings* → **Replace the Web Form uploader** (or `--replace-web-form`). The core Web Form
tab disappears, Parallel Upload becomes the first tab under *Photos → Add*, and every
core link to the Web Form (the menu entry, the "add photos" button on albums, the
empty-gallery prompt) opens Parallel Upload, keeping the album preselected. Two core
pages stay reachable because the plugin doesn't replace them: the post-upload batch
view and *add formats to a photo*. Untick the option to get the Web Form back.

## Server limits to raise (inside the Piwigo container)

Chunk size is automatically capped by what the server will accept; the settings page
shows which limit is binding. For 32 MB chunks (default):

```ini
; php.ini / pool config
upload_max_filesize = 40M
post_max_size       = 40M
max_execution_time  = 300      ; for registering large videos
```

```nginx
client_max_body_size 40m;      # nginx in front of php-fpm, if any
```

For Apache, `LimitRequestBody` (if set) must also exceed the chunk size.

## Settings and who can use what

* **Uploader** (*Photos → Add → Parallel Upload*): any Piwigo administrator.
* **Settings** (*Admin → Plugins → Parallel Upload → Settings*, or
  `admin.php?page=plugin-ParallelUpload`): **webmaster only**. Other administrators
  are sent to the uploader, don't see the Settings link, and can't save changes. The
  "chunks are capped by PHP" hint is also shown only to webmasters.


* **Chunk size** (default 32 MB, max 95). Bigger means fewer requests; smaller means
  finer progress, cheaper retries and less RAM in the browser (`chunk × connections`
  are held in memory while hashing).
* **Parallel connections** (default 4). 3–6 usually saturates a home uplink; more
  helps on high-latency links.
* **Retries per chunk** (default 6).
* **SHA-256 per chunk** — runs only in a secure context (HTTPS or localhost). Over
  plain-HTTP LAN access it is skipped automatically; the per-chunk size check and the
  final completeness check still apply.
* **Purge after** (default 24 h) — abandoned partials in `upload/buffer/parallel/`.

## Notes

* Cloudflare's per-request limit is 100 MB on Free/Pro plans (200 MB Business,
  500 MB Enterprise). The 95 MB ceiling leaves room for multipart overhead.
* Allowed file types and maximum file size follow core's
  `$conf['upload_form_all_types']`, `file_ext`/`picture_ext` and
  `$conf['upload_form_max_file_size']`. Videos need `upload_form_all_types = true`.
* Piwigo 16.4's video poster code throws a PHP TypeError if `ffprobe` can't read the
  file (prints `N/A`). The plugin reports that error on the file's row instead of
  hanging; a valid video registers normally.

License: GPL-2.0 (registration code mirrors core `pwg.images.upload`).
