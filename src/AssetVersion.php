<?php

/**
 * Cache-busting tag for this app's own locally-served static assets (currently just
 * assets/style.css — there's no locally-served JS file yet, everything JS is inline
 * <script> in the pages that use it, which needs no cache-busting since it's part of the
 * HTML response itself). Bump this (a plain increment is fine) every time you change
 * assets/style.css, or whenever a locally-served JS file is added and changed — see
 * CLAUDE.md's "Asset versioning" section for the list of places that must append it.
 *
 * Deliberately a hand-maintained constant, not derived from filemtime() or similar: this
 * app deploys via a Plesk git-pull webhook (see CLAUDE.md's "Deploying" section), and nothing
 * about that pipeline guarantees a file's mtime reflects when its *content* last changed
 * rather than just when the last deploy happened to touch the filesystem — a hand-bumped
 * version is deliberate and correct by construction instead of depending on that.
 *
 * Third-party CDN assets (e.g. history.php's DataTables/jQuery includes) don't use this —
 * their version is already part of the CDN URL path, and appending our own query string
 * to a CDN request would only defeat that CDN's own shared cache for no benefit.
 */
const ASSET_VERSION = '7';
