# AJAX + admin handler audit — 2025-06-01

Scope: `includes/class-ajax.php` (1451 LOC, 35 endpoints), `admin/class-admin.php` (861 LOC, save_meta_box + save_author_fields), `includes/class-readiness.php` (separate AJAX endpoint), `includes/class-gsc.php`, `includes/class-ner-api.php`, `includes/class-validator.php`, plus JS in `admin/views/{auditor,posts,settings,entities,rules,meta-box}.php`.

Verdict in one line: nonce + cap discipline is generally good (centralized `verify_request()`), but several handlers under-gate destructive bulk actions (no per-post cap), `wp_send_json_error()` is used without `return`/`exit` in branches (so execution continues), `save_author_fields` skips the `_wpnonce` check when the field is missing entirely (always-return-on-missing pattern is correct), and a few sanitization gaps around `_ligase_override` and FAQ/HowTo arrays.

---

## CRITICAL — security

### C1. `handle_ligase_import_settings` — arbitrary user_meta with `ligase_` prefix written without further validation
**File:** `includes/class-ajax.php:678-700`
```php
foreach ( $meta_entries as $key => $value ) {
    $key = sanitize_key( $key );
    if ( ! str_starts_with( $key, 'ligase_' ) ) { continue; }
    $value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );
    update_user_meta( $user_id, $key, $value );
}
```
**Issue:** Cap is `manage_options` (admin), which is fine for scope — but the prefix check is the only gate. An admin who imports a crafted export file can write **any** `ligase_*` user_meta key to **any user** in the export file, with **no whitelist of allowed keys**. Combined with the fact that author profile fields are read straight into Person schema, this can be used to forge author identity (e.g. inject `ligase_wikidata = "https://wikidata.org/wiki/Q42"` for another user) or leak attack content into JSON-LD. Two-dimensional array values are not recursively sanitized — `array_map('sanitize_text_field', $value)` only flattens one level; deeper structures bypass.
**Attack vector:** Crafted export JSON → admin imports thinking it's a backup → silent user profile tampering.
**Fix:** Whitelist meta keys (same approach as `$text_fields` / `$url_fields` in `save_author_fields`). Reject anything else. Also recursively sanitize arrays or reject non-flat values.

### C2. `wp_send_json_error()` calls without `return`/`exit` in several branches
**File:** `includes/class-ajax.php` — multiple sites
`wp_send_json_*` calls `wp_die()`, so in practice execution stops — **but** several handlers continue logic after these calls inside the same try block, which is fragile and gives a false sense of safety if anyone replaces the helper with a non-terminating mock during tests:
- L1001-1003 `handle_ligase_ner_run_post`: `if ( ! $post_id || ! get_post( $post_id ) ) { wp_send_json_error(...); }` then continues to `$ner = new Ligase_NER_API();` (relies on `wp_die`).
- L1088-1094 `handle_ligase_ner_save_entities`: same pattern, two consecutive validation branches with no `return`.
- L184-186, L237, L341-342, L451-453, L626-629, L726-728, L872-873, L922-923, L1000-1002, L1088-1090, L1166-1168, L1180-1182, L1191-1193, L1222-1224.

**Issue:** All depend on `wp_die()` behavior of `wp_send_json_*`. Mostly safe in production, but the `handle_ligase_preview_json` branch on L353-356 **does** have an explicit `return;` — inconsistency. The single-line `wp_send_json_error()` without `return` is also a code-smell that masks future bugs (e.g. a contributor wrapping the call in a logging shim).

**Fix:** Add `return;` after every `wp_send_json_error()` and `wp_send_json_success()` call. Cheap defensive hygiene.

### C3. `handle_ligase_bulk_set_flags` clobbers `_ligase_paywalled` for every matched post with no per-post cap
**File:** `includes/class-ajax.php:1319-1389`
**Issue:** Cap = default `manage_options` (fine for site-wide admin). But: no `current_user_can('edit_post', $pid)` check inside the loop. An admin can flip toggles on posts they couldn't otherwise edit (e.g. multisite super-admin acting on a sub-blog). Acceptable for the stated use case, but **flag `_ligase_paywalled` is in the allowed list** — flipping that to '1' on a paid-content post hides it from search indexing. That's a content-destructive bulk action with no per-post check.
**Attack vector:** Compromised admin account or rogue editor (if `manage_options` were ever relaxed) could mass-paywall all content.
**Fix:** (a) Keep `manage_options` requirement (already there), (b) add `current_user_can('edit_post', $pid)` inside loop, (c) consider splitting `_ligase_paywalled` into its own confirmation flow.

### C4. `_ligase_override` per-type override accepts arbitrary type strings
**File:** `admin/class-admin.php:578-619`
```php
foreach ( $incoming as $type => $fields ) {
    $type = sanitize_text_field( (string) $type );
    if ( ! is_array( $fields ) ) { continue; }
    $contract = Ligase_Field_Contract::get( $type );
    $allowed  = array_keys( $contract['fields'] ?? array() );
```
**Issue:** `$type` is user-controlled and used as a key in stored meta. Field keys are whitelisted via contract, but the **type** itself isn't validated against `Ligase_Field_Contract::types()`. An unknown type returns `$contract = []`, so `$allowed = []`, so no fields are stored — **but** the empty `$type_overrides` branch deletes the key after `unset()`. Result: no data exfiltration, but stored `$result` array can contain arbitrary type keys with empty values, polluting the meta. If anyone ever loops over `$result` and trusts keys as known types, this becomes a bug surface.
**Fix:** After `sanitize_text_field`, also `if ( ! in_array( $type, Ligase_Field_Contract::types(), true ) ) continue;`.

---

## HIGH — correctness / data loss

### H1. `handle_ligase_apply_audit_replacements` — `mode='restore'` not behind confirm in any UI surface (only `replace` is)
**File:** `includes/class-ajax.php:370-439`; JS callers at `admin/views/posts.php:499` only confirm for bulk type change.
**Issue:** `restore` reverts replacements (destructive — discards Ligase-generated schema in favor of the previous state). No JS confirm dialog. Server-side accepts it just fine.
**Fix:** Add `confirm()` in the audit UI before sending `mode=restore` or `mode=replace`.

### H2. `handle_ligase_bulk_change_schema_type` — raw `$wpdb->query()` UPDATE bypasses cache and hook layer
**File:** `includes/class-ajax.php:1266-1297`
```php
$updated = (int) $wpdb->query( $wpdb->prepare(
    "UPDATE {$wpdb->postmeta} SET meta_value = %s
     WHERE meta_key = '_ligase_schema_type' AND post_id IN (
         SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'
     )", $type ) );
```
**Issue:** Query is prepared (safe), but it skips `update_post_meta()` so `update_post_meta` action hooks don't fire — any plugin watching for schema type changes via `updated_post_meta` won't see this bulk change. Also `wp_cache_delete` isn't called on individual post meta keys, so `get_post_meta()` cached reads return stale values for the rest of the request. Mitigated by `Ligase_Cache::invalidate_all()` at L1300, but only for Ligase's own cache, not WP object cache for postmeta.
**Fix:** After the bulk UPDATE, call `wp_cache_delete( 'post_meta', 'posts' )` group-flush, or iterate `clean_post_cache()` per affected post id (do a `SELECT post_id` first). Or just call `update_post_meta` in a loop and accept the slower path.

### H3. `handle_ligase_clear_cache` — no per-post cap, can be triggered to thrash cache
**File:** `includes/class-ajax.php:840-855`
**Issue:** Any user with `manage_options` can invalidate all cache. That's fine, but the endpoint has no rate-limit / throttle. An attacker with the nonce + admin role can fire this in a loop to make every page regenerate schema (DoS).
**Fix:** Throttle to 1/minute via transient. Low priority — admin already has many ways to do harm.

### H4. `handle_ligase_auto_repair` — stores `wp_json_encode()` result without `wp_slash`
**File:** `includes/class-ajax.php:803-807`
```php
update_post_meta( $post_id, '_ligase_schema', wp_json_encode( $schema, JSON_UNESCAPED_UNICODE ) );
```
**Issue:** `update_post_meta` runs the value through `wp_slash()` internally only when called from the REST API context; in WP's normal flow it expects pre-slashed input and calls `wp_unslash` on read. Storing raw JSON here means quotes get backslash-escaped by `wp_slash()` (which `update_post_meta` does call via `sanitize_meta` → `add_metadata`). For JSON strings with `\"`, the round-trip is safe because `wp_unslash` reverses it. But the **same** value read elsewhere via `get_metadata` directly (e.g. WP-CLI, REST) returns escaped JSON. Verify all consumers of `_ligase_schema` go through `get_post_meta()`.
**Risk:** Medium. If any consumer bypasses unslashing, JSON will fail to decode.
**Fix:** Either store as PHP array (`update_post_meta` will serialize), or document that all readers must use `get_post_meta`.

### H5. `save_meta_box` — `_ligase_override` writes happen even when nonce field present but post type cap missing
**File:** `admin/class-admin.php:304-326`
**Issue:** Order is correct: nonce → DOING_AUTOSAVE → revision → cap. ✓ OK. But the cap check uses `$post_type_obj->cap->edit_post` — if `$post_type_obj` is null (deleted/unregistered CPT), this fatal-errors before the check. WP normally prevents this, but a stale post with deleted CPT could trip it.
**Fix:** `if ( ! $post_type_obj || ! current_user_can( $post_type_obj->cap->edit_post, $post_id ) ) return;`

### H6. `save_author_fields` — `_wpnonce` check exists only if `_wpnonce` is set; silent skip otherwise
**File:** `admin/class-admin.php:804-809`
```php
if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce(...) ) { return; }
```
**Issue:** Correct as a defensive guard — returns on missing or invalid nonce. ✓ OK. But: `text_fields`, `url_fields`, `textarea_fields`, and checkboxes are written **unconditionally** (using `isset($_POST[$key])`). For URL fields, `esc_url_raw('')` returns empty string, which is then `update_user_meta` written as empty string instead of deleted. Storage bloat.
**Fix:** For empty values, `delete_user_meta` instead of `update_user_meta` with `''`.

### H7. `save_author_fields` — `ligase_credentials` textarea persists multi-line entries but no per-entry sanitization
**File:** `admin/class-admin.php:842-850`
**Issue:** The textarea fields receive `wp_strip_all_tags` + line-ending normalization, but the credential lines themselves (format `Name | category | Issuer | URL | ID | year`) aren't parsed/sanitized per-component. The URL part can contain `javascript:` schemes which won't trip `wp_strip_all_tags`. When the read-side renders these as `<a href="...">` in JSON-LD or admin UI, this can XSS.
**Verification needed:** Check how `_ligase_credentials` is parsed on output. If the URL is fed through `esc_url`, safe. If it lands in JSON-LD `"url"` field, Google's parser tolerates but consumers don't.
**Fix:** Parse-on-save into structured array, `esc_url_raw` the URL component, store as array (same pattern as FAQ/HowTo above).

### H8. FAQ/HowTo array sanitization uses `sanitize_text_field` on the answer
**File:** `admin/class-admin.php:389-454`
**Issue:** FAQ answers and HowTo step text are passed through `sanitize_text_field`, which strips newlines and collapses whitespace. Users typing multi-sentence FAQ answers will lose formatting silently. Functional (not security) bug.
**Fix:** Use `wp_kses_post` for answer body, or at minimum `sanitize_textarea_field`. Same for HowTo `text`.

---

## MEDIUM — UX / error handling / hardening

### M1. `handle_ligase_ner_run_post` — no per-post cap check
**File:** `includes/class-ajax.php:996-1031`
**Issue:** Cap defaults to `manage_options` (sufficient for the configure-NER flow), but NER results are stored on a **specific post** via `update_post_meta`. There's no `current_user_can('edit_post', $post_id)` verification. An admin can scan any post (acceptable), but if cap were ever relaxed to `edit_posts` this becomes a write-anywhere bug.
**Fix:** Add `verify_post_access($post_id)` after `absint`.

### M2. `handle_ligase_ner_save_entities` — same as M1
**File:** `includes/class-ajax.php:1082-1131`
**Issue:** Writes `_ligase_about_entities` and `_ligase_mentions` post meta. Cap = `manage_options`. No per-post check. Same recommendation.

### M3. `handle_ligase_wikidata` — no cache, no rate limit
**File:** `includes/class-ajax.php:446-466`
**Issue:** Hits Wikidata API on every keystroke if the JS calls live-search. No transient cache. Wikidata's terms allow it, but politeness is good.
**Fix:** Cache by `md5($name)` for 1 hour.

### M4. `handle_ligase_gsc_save_credentials` — service_account_json passed without size limit
**File:** `includes/class-ajax.php:917-938`
**Issue:** `wp_unslash( $_POST['service_account_json'] )` — no length limit. PHP's `post_max_size` is the only cap. A malicious admin (or attacker with admin nonce) can submit a multi-MB blob that gets `json_decode`'d. Low impact but easy to bound.
**Fix:** Reject if `strlen($json) > 10000` before json_decode.

### M5. `handle_ligase_gsc_save_credentials` — service account JSON encryption key is `wp_salt('auth')`
**File:** `includes/class-gsc.php:359-378`
**Issue:** AES-256-CBC with `wp_salt('auth')` as key. `wp_salt` is **not** a secret — it's derived from constants in `wp-config.php`. If `wp-config.php` is dumped (common via misconfigured backup, accidental git commit), the salt is exposed and credentials are decryptable. This is the standard WP pattern, but for **Service Account credentials** (which can be exfiltrated and used outside the site), this is weaker than acceptable.
**Mitigation:** Document this in SECURITY.md. For a real fix, derive key from `AUTH_KEY . SECURE_AUTH_KEY` and warn admin if `wp-config.php` is in DOCROOT (it shouldn't be, but often is).

### M6. `handle_ligase_gsc_save_credentials` — error message includes raw `WP_Error` text
**File:** `includes/class-ajax.php:927-929`
**Issue:** OK — `get_error_message()` returns sanitized strings. No leak.

### M7. NER API key stored in plaintext option `ligase_options['ner_api_key']`
**File:** `includes/class-ner-api.php:44-45`
**Issue:** Unlike the GSC SA JSON, the OpenAI/Anthropic/Google API keys are stored **unencrypted** in `ligase_options`. Any plugin with `get_option` access reads them. Standard WP, but inconsistent with the GSC encryption pattern in the same plugin.
**Fix:** Encrypt with the same `Ligase_GSC::encrypt`/`decrypt` helpers, or at least move to a separate option that's not exported by `handle_ligase_export_settings`. **(Currently `ner_api_key` is NOT in the import whitelist on L649-655 — good for import. But it IS in `get_option('ligase_options')` returned by export at L572 → export leaks API keys.)** That's a real bug.
**Critical sub-finding:** **`handle_ligase_export_settings` exports ALL of `ligase_options` including `ner_api_key`. Anyone who downloads an export file gets the LLM API key.**
**Fix urgent:** In export, strip `ner_api_key` (and any future secret-shaped key) before serialization.

### M8. `handle_ligase_validate_post` — no try/catch
**File:** `includes/class-ajax.php:886-899`
**Issue:** `$validator->validate_post()` can throw (calls generator → can hit DB / external). Uncaught → 500 + nasty admin notice.
**Fix:** Wrap in try/catch like sibling endpoints.

### M9. `handle_ligase_run_import` — no try/catch
**File:** `includes/class-ajax.php:868-880`
**Issue:** Same as M8. Plus `$importer->import()` could be long-running with no timeout protection.
**Fix:** Wrap try/catch, consider chunking.

### M10. `Ligase_Readiness::handle_ajax` returns plural-form post on non-empty `$type` even if type isn't valid
**File:** `includes/class-readiness.php:127-130`
**Issue:** `sanitize_key()` on an unknown type returns it unchanged. `for_post()` then calls `Ligase_Field_Contract::get($t)` — if it returns `[]`, the readiness payload is half-empty. Not a security issue, but the API surface allows arbitrary keys to be probed (mild info disclosure of which types exist).
**Fix:** Validate `$type` against `Ligase_Field_Contract::types()`.

### M11. `handle_ligase_import_settings` checks `wp_unslash($_POST['json_data'])` size with no limit
**File:** `includes/class-ajax.php:625`
**Issue:** Large JSON eats memory in `json_decode`. Cap on PHP side, but easy to bound.
**Fix:** `if ( strlen($json_data) > 1_000_000 ) reject;`.

### M12. `handle_ligase_auto_repair` — nested try/catch, but outer one re-throws
**File:** `includes/class-ajax.php:732-833`
**Issue:** Inner try in the foreach catches per-post errors and increments `$errors`. ✓ OK. Outer try catches everything else. The `do { ... } while (...)` pagination has no max-iterations safeguard — if `get_posts` returns the same posts page (e.g. broken query), loop is infinite. Unlikely but worth a counter cap.
**Fix:** `if ( ++$page > 1000 ) break;`.

### M13. GSC `wp_remote_get` / `wp_remote_post` timeouts of 15-20s
**File:** `includes/class-gsc.php:152, 324, 346`
**Issue:** ✓ OK. Sane.

### M14. NER `wp_remote_post` timeouts of 30s
**File:** `includes/class-ner-api.php:251, 273, 296, 344`
**Issue:** OK for LLM calls but during admin AJAX (single-post NER), a 30s wait blocks the browser. For bulk it's WP-Cron so fine.
**Fix:** For interactive `handle_ligase_ner_run_post`, consider 15s.

### M15. `Ligase_Readiness::handle_ajax` uses `check_ajax_referer` without `false` arg
**File:** `includes/class-readiness.php:116`
```php
check_ajax_referer( 'ligase_admin', 'nonce' );
```
**Issue:** When the 3rd arg is omitted (defaults to `true`), `check_ajax_referer` **dies** on failure with a -1/-2 response, not a JSON error. Sibling AJAX handlers use `false` so they can send a proper JSON error. Inconsistent UX.
**Fix:** Pass `false` and `wp_send_json_error`.

### M16. `bulk_resolve_post_ids` — `posts_per_page = -1` with no upper bound
**File:** `includes/class-ajax.php:1419`
**Issue:** A site with 50k posts gets all IDs into memory, then iterated. Bulk operations on huge sites can OOM. No batching.
**Fix:** Hard cap, e.g. 5000, with a "use WP-CLI for larger" hint.

### M17. `handle_ligase_get_schema_rules` returns 200 authors/cats/tags — no auth-scope check
**File:** `includes/class-ajax.php:1144-1156`
**Issue:** Returns ALL authors with published posts. Cap = `manage_options`, fine. But `get_users` returns sensitive fields by default (email, etc.). Currently the map only takes `ID` + `display_name`. ✓ OK.

### M18. `handle_ligase_run_health_report` — no try/catch
**File:** `includes/class-ajax.php:905-911`
**Issue:** Mailable side-effect, no error handling.
**Fix:** Wrap try/catch.

### M19. `_ligase_override` cache invalidation runs ONLY when `$_POST['ligase_override']` is set
**File:** `admin/class-admin.php:621-624`
**Issue:** Other meta-box fields (FAQ, HowTo, citations, structured meta) write meta but do NOT bust schema cache. Save the metabox without touching override → FAQ/HowTo changes won't appear until cache expires.
**Fix:** Move `Ligase_Cache::invalidate_post( $post_id )` to the end of `save_meta_box`, unconditional.

### M20. `handle_ligase_fix_post` allows `manage_options` only — fine, but inconsistent with `scan_post` (`edit_posts`)
**File:** `includes/class-ajax.php:231-265`
**Issue:** Editors can scan but not fix. That's a deliberate UX choice (fix mutates content). ✓ OK but note in docs.

### M21. JS-side `entities.push({ name: $row.data('name'), save_as: saveAs })` — name from row data attribute
**File:** `admin/views/entities.php:375`
**Issue:** The data-name attribute was set via `$('<div>').text(e.name).html()` — that's HTML-encoded. When read back via `.data('name')`, jQuery returns the decoded string. So if `e.name` contained `<img src=x onerror=...>`, it gets HTML-encoded into the attribute, then decoded by `.data()` back to raw HTML, then sent to the server where `sanitize_text_field` strips tags. ✓ Safe, but fragile.

### M22. `handle_ligase_bulk_set_flags` uses `$_POST['action_kind']` to avoid colliding with WP's `action`
**File:** `includes/class-ajax.php:1324`
**Issue:** ✓ Good — correctly named. (WP uses `$_POST['action']` for the handler dispatch.)

---

## Endpoint-by-endpoint table

Cap legend: `MO` = `manage_options`, `EP` = `edit_posts`. "Per-post" = additionally calls `current_user_can('edit_post', $post_id)`.

| Endpoint | Nonce | Cap | Per-post | Sanit | Notes |
|---|---|---|---|---|---|
| ligase_dashboard_stats | OK | MO | n/a | n/a | Cached 1h. No issues. |
| ligase_scan_post | OK | EP | YES | absint(post_id) | OK |
| ligase_scan_all_posts | OK | MO | n/a | n/a | Heavy; no per-page cap on internal loop. Heavy CPU. |
| ligase_fix_post | OK | MO | NO | absint | Could add per-post (but MO covers it). |
| ligase_fix_all_posts | OK | MO | n/a | absint(threshold) | OK |
| ligase_preview_json | OK | EP | YES | absint | JSON encode error handled w/ `return`. Best-practice example. |
| ligase_apply_audit_replacements | OK | MO | NO | array_map(absint), sanitize_key | Mode whitelisted. Could add per-post. |
| ligase_wikidata | OK | MO | n/a | sanitize_text_field | No caching → M3 |
| ligase_get_readiness_score | OK | MO | n/a | n/a | OK |
| ligase_get_author_scores | OK | MO | n/a | n/a | OK |
| ligase_get_plugin_conflicts | OK | MO | n/a | n/a | OK |
| ligase_export_settings | OK | MO | n/a | n/a | **EXPORTS ner_api_key — see M7 sub-finding** |
| ligase_import_settings | OK | MO | n/a | whitelist+per-type | **C1: user_meta keys not whitelisted** |
| ligase_auto_repair | OK | MO | n/a | sanitize_text_field, array_intersect whitelist | OK |
| ligase_clear_cache | OK | MO | n/a | n/a | No throttle (M9-ish) |
| ligase_detect_import_sources | OK | MO | n/a | n/a | OK |
| ligase_run_import | OK | MO | n/a | sanitize_key | No try/catch (M9) |
| ligase_validate_post | OK | EP | YES | absint | No try/catch (M8) |
| ligase_run_health_report | OK | MO | n/a | n/a | No try/catch (M18) |
| ligase_gsc_save_credentials | OK | MO | n/a | wp_unslash, esc_url_raw | No size limit (M4); key=wp_salt (M5) |
| ligase_gsc_disconnect | OK | MO | n/a | n/a | OK |
| ligase_gsc_test_connection | OK | MO | n/a | n/a | OK |
| ligase_gsc_sync | OK | MO | n/a | n/a | OK |
| ligase_gsc_rich_results | OK | MO | n/a | n/a | OK |
| ligase_ner_run_post | OK | MO | NO | absint | M1 — add per-post; 30s timeout (M14) |
| ligase_ner_run_bulk | OK | MO | n/a | !empty(force) | OK; 24h cooldown ✓ |
| ligase_ner_bulk_status | OK | MO | n/a | n/a | OK |
| ligase_ner_save_entities | OK | MO | NO | sanitize_text_field per entity, esc_url_raw | M2 — add per-post |
| ligase_get_schema_rules | OK | MO | n/a | n/a | OK |
| ligase_save_schema_rule | OK | MO | n/a | sanitize_key, sanitize_text_field, whitelist schema_keys | OK |
| ligase_delete_schema_rule | OK | MO | n/a | sanitize_key | OK |
| ligase_toggle_schema_rule | OK | MO | n/a | sanitize_key | OK |
| ligase_bulk_change_schema_type | OK | MO | n/a | sanitize_text_field + whitelist | H2 — raw $wpdb bypasses meta cache |
| ligase_bulk_set_flags | OK | MO | NO | sanitize_key + flag whitelist | **C3: paywall toggle dangerous without per-post; also no per-post cap** |
| ligase_bulk_count_targets | OK | MO | n/a | sanitize_key, sanitize_title | OK |
| ligase_readiness (separate class) | OK (no `false` arg → M15) | EP | YES | absint, sanitize_key | M10 — no type whitelist |

**Total: 36 AJAX endpoints. All have nonce verification. All have capability checks.** No raw `$_POST` SQL concatenation found. No string concat into shell. No file_put_contents with user input found.

---

## Save handlers

### `save_meta_box` (admin/class-admin.php:304-626)
- Nonce: OK (`ligase_meta_save` / `ligase_meta_nonce`) — checked first, returns on failure.
- DOING_AUTOSAVE: OK
- wp_is_post_revision: OK
- Cap check: `$post_type_obj->cap->edit_post` — assumes `$post_type_obj` is not null (H5).
- Field-level sanitization:
  - Schema type: whitelist of 5 types ✓
  - Toggles: cast to '1'/'0' ✓
  - text_meta / url_meta: empty → delete (good)
  - `_ligase_profile_user_id`: absint + delete-on-zero (good)
  - FAQ: `wp_strip_all_tags` → split → `sanitize_text_field` (H8 — strips newlines)
  - HowTo: same pattern + ISO 8601 duration regex ✓
  - structured_meta (Service/Recipe/JobPosting): per-field rule (text/url/textarea/lines) ✓ — best implementation in the file
  - citations: array of rows, url required, name optional ✓
  - `_ligase_override`: per-type whitelist via Contract — **type itself not validated** (C4)
- Cache bust: only on `_ligase_override` change (M19)

### `save_author_fields` (admin/class-admin.php:798-860)
- Cap: `edit_user` ✓
- Nonce: `update-user_$user_id` (WP's built-in) ✓ — returns on missing/invalid
- DOING_AUTOSAVE: n/a (profile page doesn't autosave)
- Field-level:
  - text_fields: sanitize_text_field + unslash ✓
  - url_fields: esc_url_raw + unslash ✓ — empty stored as '' instead of delete (H6)
  - textarea_fields: wp_strip_all_tags + line-ending normalize ✓ (H7 for credential URL parsing)
  - checkboxes: '1'/'0' coerced ✓
- **Note:** No cache invalidation. If an author updates their bio, post schemas referencing them are stale until cache expires.

---

## GSC / NER specific risks

- **GSC service account JSON:** AES-256-CBC with `wp_salt('auth')`. ✓ Better than plaintext but key is recoverable from `wp_config.php` (M5).
- **NER API key:** **plaintext in `ligase_options`** (M7) and **leaked in export** (M7 sub-finding — CRITICAL).
- **Refresh tokens:** GSC uses Service Account JWT (no refresh token). ✓
- **HTTP timeouts:** GSC 15-20s ✓; NER 30s (M14 — high for interactive).
- **JSON decode failures:** Both GSC and NER handle gracefully (return null/error).
- **429/5xx upstream:** NER `parse_llm_response` checks `code !== 200` and logs warning, returns null ✓. GSC doesn't check `response_code` separately — relies on `access_token` being absent in body to flag errors. **Mild bug:** A 500 with body `{"access_token":"foo"}` (won't happen from Google, but...) would be treated as success.

---

## JS-side concerns

- ✓ `LIGASE.nonce` passed via `wp_localize_script`.
- ✓ `LIGASE.ajaxUrl` is absolute (`admin_url('admin-ajax.php')`).
- ✓ `confirm()` on bulk type change (posts.php:499) and bulk flags apply (posts.php:584).
- ✗ `confirm()` MISSING on `mode='restore'` and `mode='replace'` in auditor.php — H1. (Verified: `admin/views/auditor.php` has no JS — the audit UI is rendered by React via `admin.js`, which isn't in scope of this audit, but the AJAX is reachable from any JS.)
- ✗ `ligase-ner-save-btn` (entities.php:366) — no confirm before overwriting `_ligase_about_entities` / `_ligase_mentions`.
- ✓ XSS escaping in JS output uses `$('<div>').text(...).html()` pattern consistently in entities.php and posts.php.

---

## Priority fix order

1. **(C1 + M7 sub-finding) Export leaks NER API key + Import allows arbitrary user_meta.** Strip secrets from export, whitelist user_meta keys on import. — `class-ajax.php:572-708`
2. **(C3) Add `current_user_can('edit_post', $pid)` per-post in `handle_ligase_bulk_set_flags` loop.** Even if MO is required, defense-in-depth before bulk paywalling. — `class-ajax.php:1354-1370`
3. **(M1 + M2) Add `verify_post_access($post_id)` to `handle_ligase_ner_run_post` and `handle_ligase_ner_save_entities`.** Even though cap is MO today, these write to specific post IDs and should be hardened. — `class-ajax.php:996-1131`
4. **(H7) Sanitize credential URL on save** — parse the pipe-separated structure, `esc_url_raw` the URL field. — `class-admin.php:842-850`
5. **(H8) Switch FAQ/HowTo answer/step text sanitizer to `sanitize_textarea_field` or `wp_kses_post`.** — `class-admin.php:401-403, 441-443`
6. **(C2) Add `return;` after every `wp_send_json_error()` and `wp_send_json_success()` call.** Hygiene.
7. **(H2) Bust object cache for postmeta after bulk_change_schema_type raw UPDATE.** — `class-ajax.php:1269-1297`
8. **(C4) Validate `_ligase_override` type against `Ligase_Field_Contract::types()`.** — `class-admin.php:583`
9. **(M19) Move `Ligase_Cache::invalidate_post($post_id)` to end of `save_meta_box` unconditionally.** — `class-admin.php:621-624`
10. **(M5) Document GSC `wp_salt` weakness in SECURITY.md** or upgrade to derived key.
11. **(H5) Null-check `$post_type_obj` in save_meta_box.** — `class-admin.php:323-326`
12. **(H6) Empty URL → delete_user_meta, not update with ''.** — `class-admin.php:828-832`
13. **(M15) `class-readiness.php:116` — pass `false` to `check_ajax_referer`** for JSON error response consistency.
14. **(M3, M4, M11, M14, M16, M18) Caching + size limits + try/catch hygiene.**
15. **(M21) JS confirm() on NER save (overwrites entities) and on auditor restore/replace.**

---

## Out-of-scope but noticed

- `admin/views/posts.php:472` — `window.location.origin + '/wp-admin/...'` constructs admin URLs by hand. Use `LIGASE.adminUrl` if added, or just relative `/wp-admin/...` paths.
- `includes/class-validator.php` is read-only (no AJAX-direct usage outside `ligase_validate_post`), no security exposure.
- `entities.php:375` — entity name is read from data-attribute via `.data()` which decodes once; verify no second decode happens server-side. (Confirmed safe — `sanitize_text_field` strips any surviving tags.)
- `Ligase_Settings::register` (admin_init hook) is not audited here — should be in a follow-up settings audit.

End.
