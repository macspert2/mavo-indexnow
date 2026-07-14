# IndexNow Auto Submit — Plugin Plan

Fixes for the recurring complaints found in the WordPress.org reviews of the existing "IndexNow Plugin" (3.2/5, 47 reviews).

## Complaints being addressed

| # | Complaint (from reviews) | Root cause | Fix |
|---|---|---|---|
| 1 | "Let's Get Started" button greyed out / setup flaky | Key generation/verification happens client-side via JS that silently fails | No wizard UI. Key + verification file are generated automatically on activation, no button to break. |
| 2 | "0 Submissions?" / "does nothing" / no error feedback | Plugin fires the API call but never surfaces the response | Every submission attempt is logged (status code + response body) to an admin-visible log screen. |
| 3 | Fails on automatic or manual submission | Silent failures (network errors swallowed, no retry) | Use `wp_remote_post` with error checking, 3x retry w/ backoff, failures logged and optionally emailed. |
| 4 | Invalid URL on non-standard TLDs (.builders, .website) | Overly strict regex validation of host | Validate using `wp_parse_url()` + PHP `filter_var(FILTER_VALIDATE_URL)` only; no TLD allow-list. |
| 5 | Plugin submits noindex'd URLs | No check against robots meta / privacy settings | Skip submission if post has `noindex` (via Yoast/Rank Math meta, or WP's own "discourage search engines" site setting) or is not `publish` + `public` post status. |
| 6 | Abandoned support / bugs never fixed | N/A (process, not code) | Out of scope for code, but plan keeps the codebase small and dependency-free so a fork/replacement is trivial. |

## Goals

1. Automatically submit new/updated post URLs to IndexNow (Bing, Yandex, Seznam, Naver via the shared endpoint) on publish and on update.
2. Zero-click setup: works the moment the plugin is activated, no wizard steps that can get stuck.
3. Visible feedback: an admin screen showing last N submissions, their HTTP status, and any errors — so "did it work?" is never a mystery.
4. Safe by default: never submits noindex/private/password-protected content.
5. No unnecessary URL rejections: valid URLs on any TLD or script are accepted.

## File structure

```
mavo-indexnow/
├─mavo-─ indexmit.php      # bootstrap, activation hook, constants
├── includes/
│   ├── class-key-manager.php     # generate/store/serve per-site key + verification file
│   ├── class-submitter.php       # builds request, calls API, retry logic
│   ├── class-eligibility.php     # decides if a post should be submitted (noindex/status checks)
│   ├── class-logger.php          # writes/reads submission log (custom table or option, capped size)
│   └── class-admin-page.php      # Settings > IndexNow screen: status, log table, manual re-submit button
├── uninstall.php                 # cleans up options/tables on uninstall
└── readme.txt
```

## Key implementation details

### Key generation & verification (fixes #1)
- On `activate_plugin` (and on `wpmu_new_blog` for multisite), generate a random hex key with `wp_generate_password(32, false)` and store it in `wp_options` (or per-site option in multisite).
- Serve `https://{site}/{key}.txt` via a rewrite rule + `template_redirect` hook (not a physical file) so it works regardless of file write permissions, and so multisite subsites resolve correctly.
- No JS-driven "click to activate" step; everything is derived from the key at request time.

### Eligibility check (fixes #5)
- Hook into `transition_post_status`.
- Skip if: post type isn't in the allowed list (default: `post`, `page`); post isn't `publish`; post is password-protected; `get_post_meta($id, '_yoast_wpseo_meta-robots-noindex')` or Rank Math's equivalent meta is set to noindex; site-wide "Discourage search engines from indexing this site" option is on.
- Configurable allow-list of post types via a filter (`indexnow_eligible_post_types`).

### Submission (fixes #2, #3, #4)
- Build the payload with `wp_json_encode` using `home_url()`'s host (no manual string concatenation).
- Validate the final URL with `filter_var($url, FILTER_VALIDATE_URL)`; if invalid, log and abort — but no pre-emptive TLD blocklist/allowlist.
- Encode the path/query with `esc_url_raw()` so non-ASCII characters are percent-encoded before the request goes out.
- Send via `wp_remote_post()`, non-blocking for the "fire and forget" fast path, but always followed by a scheduled `wp_schedule_single_event` check a few seconds later that reads the response and logs it (works around `blocking = false` discarding the response).
- On network error or non-2xx response, retry up to 3 times with exponential backoff via Action Scheduler (or `wp_schedule_single_event` if Action Scheduler isn't available), then log final failure.

### Logging & admin visibility (fixes #2, #3)
- Store the last 200 submission attempts (timestamp, URL, HTTP status, response snippet, success/fail) in a custom table created on activation.
- Settings > IndexNow admin page shows: current key + verification URL (for manual sanity-check), a table of recent submissions with status, and a "Resubmit" button per row.
- Optional: WP-CLI command `wp indexnow submit <url>` for manual/bulk submission and debugging.

## Non-goals / explicitly out of scope
- No bundled UI wizard/onboarding flow (this is what caused the greyed-out button bug in the original plugin).
- No SEO plugin dependency — Yoast/Rank Math meta checks are optional/soft (checked only if those plugins are active).
- Not attempting to guarantee actual Bing indexing/crawl timing — IndexNow only notifies of the URL; scope is limited to reliable notification + visibility into whether the notification succeeded.

## Testing / verification plan
1. Unit test `class-eligibility.php` logic with mocked post objects (noindex, draft, password-protected, non-public post types).
2. Unit test URL validation/encoding against the known failure cases from reviews: `.builders`/`.website` TLDs, Korean-language slugs, IDN domains.
3. Manual test on a WP Multisite install: confirm each subsite gets a distinct key and verification file resolves correctly per subdomain/subdirectory.
4. Manual test: publish a post, confirm log entry appears with real HTTP status from `api.indexnow.org` within seconds.
5. Manual test: simulate API failure (block outbound request) and confirm retry + failure logging, no PHP notices/warnings.
6. Confirm `uninstall.php` fully removes options and custom tables.

Question: is it possible to test all post's urls to check whether they are indexed and bulk submit those which are not?
