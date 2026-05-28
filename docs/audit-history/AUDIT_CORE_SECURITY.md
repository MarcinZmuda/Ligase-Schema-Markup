# Ligase v2.0.0 — Core & Security Audit

**Scope:** Core plugin architecture, admin UI, AJAX, settings, output, cache, multilingual, GSC integration, health report, and WordPress security best practices.
**Goal:** Identify everything that would block submission to wordpress.org and any exploitable vulnerability before the plugin goes public.
**Audited:** 2026-05-28 — repo `C:\Users\marci\audits\ligase\`, ref public repo `https://github.com/MarcinZmuda/Ligase-Schema-Markup` v2.0.0.

---

## Executive summary

Ligase ships a competent feature set (graph linking, auditor, GSC, NER, multilingual). The architecture is clean and most AJAX endpoints share a `verify_request()` helper that checks both nonce and capability. However, there are **several findings that must be fixed before submitting to wordpress.org** — most importantly:

1. **High-severity stored XSS in `<script type="application/ld+json">` output ([class-output.php:50-69](includes/class-output.php#L50)).** `wp_json_encode` does not escape the string `</script>` inside JSON values. Any post meta, organization name, FAQ block content, etc. that contains the literal substring `</script>` (or `<!--` / `<![CDATA[`) breaks out of the JSON-LD container — straight to an XSS sink that is then **cached and re-served to every visitor**.
2. **Two `wp_verify_nonce()` calls without `wp_unslash()`+sanitize ([class-admin.php:297, :411](admin/class-admin.php#L297)).** Not directly exploitable but flagged by every WP review bot and trips Plugin Check Tool.
3. **GSC credential encryption is reversible by anyone with DB read access ([class-gsc.php:359-378](includes/class-gsc.php#L359)).** Key is `wp_salt('auth')`, which lives in `wp-config.php` — that's the WP "encryption at rest by obfuscation" anti-pattern. Acceptable in practice (no better default exists in WP core) but **must be documented** in the readme + privacy policy. NER API keys are stored unencrypted in plaintext (`ligase_options` option) which is **inconsistent** — same threat model, different protection.
4. **Two `<?php echo $var ?>` ternary outputs without escaping in views ([entities.php:85, :100](admin/views/entities.php#L85), [settings.php:46](admin/views/settings.php#L46)).** Currently safe because they emit hardcoded strings, but Plugin Check Tool will still flag them.
5. **NER `extract` endpoint does not require `manage_options` for `edit_posts` users** — every admin AJAX endpoint, including ones that **spend the user's money via paid AI APIs** ([class-ajax.php:951](includes/class-ajax.php#L951), `ligase_ner_run_post`) and ones that perform **destructive bulk schema rewrites** ([:1211](includes/class-ajax.php#L1211), `ligase_bulk_change_schema_type`), are gated only behind `manage_options` — which is correct but the same gate applies to a read-only "list rules" call. The bigger issue is that the cap is uniform across actions: a write that costs money should have a separate confirmation or rate-limit, not just the same cap as fetching dashboard stats.

The plugin **is not yet wordpress.org-ready**. Estimated work to address the must-fix list: **6–10 hours**.

---

## File-by-file findings

### `ligase.php`

```
defined( 'ABSPATH' ) || exit;   ✅
LIGASE_VERSION / DIR / URL / FILE defined  ✅
plugins_loaded hook + activation hook       ✅
```

- ✅ Bootstrap is minimal and correct.
- 💡 `register_activation_hook` writes `ligase_show_onboarding` and `ligase_activated_at` — fine, but consider also flushing rewrite rules if any custom rewrite is registered (currently none).
- 💡 No `register_deactivation_hook` — `Ligase_Health_Report::unschedule()` is never called on deactivation. The weekly cron will accumulate ghosts across activate/deactivate cycles. Add:
  ```php
  register_deactivation_hook( __FILE__, [ 'Ligase_Health_Report', 'unschedule' ] );
  ```

### `uninstall.php`

[uninstall.php:1-52](uninstall.php#L1)

- ✅ `WP_UNINSTALL_PLUGIN` guard present.
- ✅ Uses `$wpdb->prepare` with `esc_like` for the meta and transient sweeps.
- ⚠️ **Missing cleanup:**
  - `wp_clear_scheduled_hook( 'ligase_weekly_health_report' )`
  - `wp_clear_scheduled_hook( 'ligase_ner_api_extract' )`
  - `wp_clear_scheduled_hook( 'ligase_wikidata_lookup' )`
  - `ligase_schema_rules` is deleted but `ligase_gsc_access_token` transient is not.
  - Object cache: if the site uses object cache, `delete_option` won't drop the in-memory copy on multisite networks. Consider `wp_cache_flush_group()` for transients if your prefix is unique.
- 💡 The `rmdir`/`unlink` on the log directory uses `glob('*')` which **misses dotfiles** (`.htaccess`). Since `class-logger.php` writes a `.htaccess`, the directory will remain after uninstall:
  ```php
  $files = array_merge( glob( $log_dir . '/*' ) ?: [], glob( $log_dir . '/.[!.]*' ) ?: [] );
  ```

### `includes/class-plugin.php`

[class-plugin.php:1-373](includes/class-plugin.php#L1)

- ✅ `defined( 'ABSPATH' ) || exit;` present.
- ✅ Singleton with `get_instance()`.
- ✅ `dismiss_onboarding()` checks both `check_ajax_referer` and `current_user_can`.
- ⚠️ **Performance — `wp_count_posts()` and `Ligase_NER_API` instantiated on every admin page load** ([class-plugin.php:185-187](includes/class-plugin.php#L185)). `maybe_show_onboarding()` runs on `admin_notices`, which fires on *every* admin page even after `ligase_show_onboarding` is `'1'`. The check `if ( get_option( 'ligase_show_onboarding' ) !== '1' )` exits early — good — but as long as the flag is set, `wp_count_posts()` is invoked unconditionally. Move the heavy work inside the conditional:
  ```php
  if ( get_option( 'ligase_show_onboarding' ) !== '1' ) return;
  // ↓ only now compute totals
  ```
  Wait — it actually does that. Re-checking: yes, the heavy calls are after the early return. ✅ Fine.
- 💡 **Hardcoded inline CSS** — the onboarding notice has 6 KB of inline styles. Acceptable for a one-time notice, but Plugin Check Tool may complain. Enqueue a tiny CSS file instead.
- 💡 **Inline JS with `<?php echo esc_url( admin_url(...) ); ?>`** ([:319, :330, :353](includes/class-plugin.php#L319)) — works, but `wp_add_inline_script` against an enqueued handle is the recommended pattern. Also `wp_create_nonce('ligase_admin')` is generated 3 times in one notice. Use one variable.
- 💡 [class-plugin.php:204-207](includes/class-plugin.php#L204): `printf( esc_html__( 'Version %s · %d posts...' ), esc_html( $total ) )` — `$total` is already cast to int by `(int)`, so `esc_html()` is a no-op. Fine, but inconsistent with how other places use `%d`.
- ⚠️ **`register_blocks()` runs `update_post_meta` from the block's `render_callback`** ([class-plugin.php:145-151, :159-165](includes/class-plugin.php#L145)). This means **rendering a post on the front-end writes to the database** every time a page is hit, for every visitor. That's:
  1. A massive performance hit (writes on every front-end render).
  2. A capability bypass — any unauthenticated visitor's pageview can write to `_ligase_faq_items`/`_ligase_howto` post meta. Even though the data comes from a valid block's attributes, the attributes themselves are stored by Gutenberg in the post content, so this is technically writing the same data, but **it's wrong to write on render**. Move this to `save_post` or use Gutenberg's `meta` storage.
  3. `$attrs['items']` is written **unsanitized** straight to post meta. If the post has Edit permissions only (not full meta-write), and the block attributes were tampered with via the REST API, you'd be persisting attacker-controlled HTML into post meta.

### `includes/class-output.php`

[class-output.php:1-124](includes/class-output.php#L1)

#### 🔥 CRITICAL: JSON-LD `</script>` breakout XSS

[class-output.php:50-69](includes/class-output.php#L50):

```php
$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
$html = sprintf( "<script type=\"application/ld+json\">\n%s\n</script>\n", $json );
Ligase_Cache::set( $cache_key, $html );
echo $html;
```

The `JSON_UNESCAPED_SLASHES` flag tells PHP to leave `/` un-escaped. That means **any user-controlled string that contains the literal substring `</script>` will close the JSON-LD block early** and the remainder of the JSON becomes raw HTML rendered into the page. Exploitation paths in this plugin:

- Organization name / description (admin-set, lower risk but still XSS if admin pastes hostile content)
- FAQ block question/answer text (set by anyone with `edit_posts` / Contributors)
- HowTo step text (same)
- Post excerpt (used in BlogPosting description)
- Author description / bio (set by Author role)
- Post title (used in headline)
- Comment-like content if it ever gets pulled in

**Concrete payload** — FAQ block with answer:
```
</script><script>alert(document.cookie)</script>
```

The result is XSS that fires for **every visitor of the affected post**, is **cached** in `Ligase_Cache::set()` for 12 hours, and re-served verbatim via `echo $cached` on line 31.

**Fix:** Always escape the four sequences that can break out of an inline `<script>` element:
```php
$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
// Prevent breaking out of <script> with </script>, <!--, <![CDATA[
$json = str_replace(
    [ '</', '<!--', '<![CDATA[' ],
    [ '<\/', '<\!--', '<\!\[CDATA[' ],
    $json
);
```
Or drop `JSON_UNESCAPED_SLASHES` entirely — schema.org allows `\/` in JSON URLs (Google's own guidance shows escaped slashes), so the only benefit is human readability. The cost of leaving them unescaped is this XSS class.

Also: `echo $html;` should pass through a single trusted sink. Consider extracting a small helper `ligase_safe_jsonld_echo()` that does the replace + echo and is the *only* place that emits the markup.

#### Other class-output.php findings

- ⚠️ **Cached XSS persistence.** Once an attacker plants `</script>` content in a post, `Ligase_Cache::set( $cache_key, $html )` writes the full unescaped HTML to a transient. Even after fixing the source, the transient must be purged. Add a hard schema-version invalidation. The cache key does include `LIGASE_VERSION` ✅, so a version bump invalidates everything — good.
- 💡 [class-output.php:25](includes/class-output.php#L25): `wp_unslash( $_SERVER['REQUEST_URI'] )` then `md5()` — that's fine for a cache key but consider stripping query string portions that vary uselessly (`?utm_*`) to avoid cache fragmentation.
- 💡 [class-output.php:30-31](includes/class-output.php#L30): the `$cached` branch does not re-run `should_render()`. If a user installs Yoast after the cache is populated, Ligase will keep serving its cached schema until cache expires. Edge case but documented in `class-cache.php:invalidate_all()` listening to `updated_option` — so this is OK.

### `includes/class-cache.php`

[class-cache.php:1-40](includes/class-cache.php#L1)

- ✅ Uses transients correctly with `md5()` of the user-controllable key — both prevents transient name overflow and avoids special-char issues.
- ⚠️ `invalidate_all()` runs a `DELETE ... WHERE option_name LIKE` ([class-cache.php:30-34](includes/class-cache.php#L30)) on every `updated_option` event (via [class-plugin.php:91](includes/class-plugin.php#L91)). That means **every option update in WP triggers a `LIKE` DELETE on `wp_options`**. On large installs with thousands of plugins firing `update_option`, that's an unnecessary disk write. Scope it:
  ```php
  add_action( 'updated_option', function( $option ) {
      if ( strpos( $option, 'ligase_' ) === 0 ) {
          Ligase_Cache::invalidate_all();
      }
  } );
  ```
- 💡 `set()` accepts only `string $value` — fine for HTML output, but make sure callers never pass arrays (e.g. `dashboard_stats` AJAX uses `set_transient` directly with an array — different transient name, so no collision).

### `includes/class-cache-bypass.php`

[class-cache-bypass.php:1-60](includes/class-cache-bypass.php#L1)

- ✅ Properly guarded, only fires during `wp_doing_ajax()`.
- ✅ Supports four major page caches; no obvious issues.

### `includes/class-logger.php`

[class-logger.php:1-231](includes/class-logger.php#L1)

- ✅ Singleton, `defined( ABSPATH )` guard, log rotation, `.htaccess` + `index.php` written to log dir to block direct access.
- ⚠️ **Log location: `wp-content/uploads/ligase-logs/`** — the uploads directory is by definition web-accessible. The `.htaccess` `Deny from all` works **only on Apache + AllowOverride All**. On Nginx, LiteSpeed, OpenLiteSpeed (rest of the world), `.htaccess` is ignored. The `index.php` does prevent directory listing on those, but a request to `wp-content/uploads/ligase-logs/ligase-debug.log` will return the raw log file as `text/plain` and **leak whatever the plugin chose to log**, which includes:
  - Post IDs being scanned (low risk)
  - GSC errors with parts of the API response (potentially API key leakage if Google ever echoed it back — they don't, but defense in depth)
  - JSON encoding errors with payload context
  - Anything `Ligase_Logger::info(...)` was given
- **Fix:** Move logs to `wp-content/ligase-logs/` (outside `uploads/`). Or, better, use the WP debug log when `WP_DEBUG_LOG` is on, and refuse to write to disk otherwise. Many wordpress.org reviewers will flag this — the established convention is `error_log()`.
- ⚠️ **No PII redaction.** If `Ligase_Logger::error` is called with `$context` containing `org_email`, `client_email` (GSC service account), or the contents of a failed import (which contains `author_meta`), all of that lands on disk. Add a redactor for keys that look like `*email*`, `*key*`, `*token*`, `*secret*` in `context`.
- ⚠️ `debug_mode` is a per-site option — there's no way for the user to opt out short of going into settings. Honor `WP_DEBUG_LOG` as an additional toggle.
- 💡 [class-logger.php:165](includes/class-logger.php#L165): `file_put_contents( $this->log_file, ... )` without checking write success. If the disk is full or perms are wrong, you silently lose log lines. Not security, but ops hygiene.

### `admin/class-admin.php`

[class-admin.php:1-432](admin/class-admin.php#L1)

#### ⚠️ Nonce verification without `wp_unslash` + `sanitize_text_field`

[class-admin.php:296-298](admin/class-admin.php#L296):

```php
if (
    ! isset( $_POST['ligase_meta_nonce'] ) ||
    ! wp_verify_nonce( $_POST['ligase_meta_nonce'], 'ligase_meta_save' )
) {
```

`wp_verify_nonce` accepts the value as-is, but WP coding standards and Plugin Check Tool require the explicit pattern:
```php
! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ligase_meta_nonce'] ) ), 'ligase_meta_save' )
```

Same issue at [class-admin.php:411](admin/class-admin.php#L411). Both are flagged by `WordPress.Security.NonceVerification.Missing` / `WordPress.Security.ValidatedSanitizedInput`.

#### Other class-admin findings

- ✅ Meta-box save: nonce verified, autosave/revision skipped, capability checked, schema_type whitelisted.
- ✅ `save_author_fields` uses the right per-user nonce (`update-user_$user_id`) and `edit_user` cap.
- ✅ `render_admin_page` whitelists view files via `$view_map`.
- 💡 [class-admin.php:166](admin/class-admin.php#L166): cap mismatch. The submenu is declared with `manage_options` cap for *Settings*, *Rules*, *Auditor*, *Tools*, *Entities*, but `render_admin_page()` only checks `edit_posts`. WP's `add_submenu_page` enforces the submenu cap when reaching the page slug, so this is in practice OK, but it's confusing — and on multisite a Super Admin who is not Site Admin would also get past `edit_posts`. Make the cap check page-aware:
  ```php
  $cap_for_page = [ 'ligase-posty' => 'edit_posts', 'ligase' => 'edit_posts' ][ $page_slug ] ?? 'manage_options';
  if ( ! current_user_can( $cap_for_page ) ) wp_die(...);
  ```
- 💡 [class-admin.php:188-198](admin/class-admin.php#L188): `<div class="wrap">` always opened; if `file_exists($view_path)` is false, a placeholder div is emitted but the wrap is never closed. (Actually `</div>` *is* echoed at line 197.) ✅
- 💡 `wp_localize_script` exposes nonce + pluginUrl + version — standard. No secrets. ✅
- 💡 [class-admin.php:233-238](admin/class-admin.php#L233): `wp_localize_script` is deprecated in WP 5.7+. Prefer `wp_add_inline_script` with `var LIGASE = ...;`. Not a blocker.
- 💡 Submenu titles use `_e` / `__` correctly. But note the slug `ligase-narzedzia` and labels mix Polish + English (`Dashboard`, `Posty`, `Audytor`, `Narzędzia`). For wordpress.org you want **English source strings** with PL translation files. Currently the slug + many user-visible strings are Polish-only (e.g. `'Ligase — Narzedzia'` in tools.php h1). Replace with `__('Ligase — Tools', 'ligase')` and ship a `pl_PL.po`.

### `admin/class-settings.php`

[class-settings.php:1-691](admin/class-settings.php#L1)

- ✅ `register_setting` with `sanitize_callback`, defaults, group name.
- ✅ Sanitize handles text, email, URL, number, checkbox, nested `lb_hours` array with day whitelist and `\d{2}:\d{2}` time regex.
- ⚠️ [class-settings.php:240](admin/class-settings.php#L240): `echo '<option ... ' . $sel . '>...'` — `$sel` comes from `selected( $current, $val, false )` which returns the literal string `selected="selected"` or `''`. Safe in practice; flagged by static analysis only. Same at :283, :303.
- ⚠️ [class-settings.php:478](admin/class-settings.php#L478): `$placeholder ? 'placeholder="...' : ''` — the placeholder is fed through `esc_attr()`, and the outer string is concatenated unescaped into `printf`. Since printf's `%6$s` is a format spec, the unescaped `'placeholder="..."'` is interpreted as a literal — and the content **is** escaped. ✅ Fine.
- ✅ [class-settings.php:516-598](admin/class-settings.php#L516): full options sanitize routine. Good — text fields go through `sanitize_text_field`, emails through `sanitize_email`, URLs through `esc_url_raw`. Whitelist-pattern: only known keys end up in `$clean`.
- ⚠️ [class-settings.php:543-545](admin/class-settings.php#L543): `ner_api_key` stored in `ligase_options` in plaintext. While GSC credentials get `openssl_encrypt`, NER API keys (OpenAI / Anthropic / Google / Dandelion) sit raw in `wp_options`. **Inconsistent threat model.** Either encrypt both or document both as "not encrypted, protect your DB". Recommendation: encrypt both with the same helper.
- 💡 `default_schema_type` is missing from the sanitize routine — only the bulk schema-type AJAX writes it (via `update_option` after read-modify-write), so on Settings save it gets wiped to the default! Verify: looking at `sanitize()`, `default_schema_type` isn't in `text_fields` array, so when a user saves Settings, the field is dropped. Fix: add `default_schema_type` and validate it against the same whitelist used in [class-ajax.php:1214](includes/class-ajax.php#L1214).
- 💡 [class-settings.php:660-690](admin/class-settings.php#L660): `ligase_do_settings_section()` is a **top-level function** in a class file. It should be in a separate `includes/helpers.php` or namespaced. Procedural code at the bottom of a class file is a wordpress.org reviewer pet peeve.

### `admin/views/*.php`

#### `dashboard.php`

- ✅ Output uniformly escaped (`esc_html`, `esc_attr`, `esc_url`).
- ⚠️ [dashboard.php:14-25](admin/views/dashboard.php#L14): instantiates `Ligase_Score`, `Ligase_Suppressor`, calls `calculate()`, `get_active_seo_plugins()` on **every dashboard load**. `Ligase_Score::calculate()` is transient-cached (`ligase_site_score`), so this is OK. `Ligase_Suppressor::get_active_seo_plugins()` likely reads from `get_option('active_plugins')` — cheap. Fine.
- ⚠️ [dashboard.php:65](admin/views/dashboard.php#L65): `style="--score-color: <?php echo esc_attr( $score_color ); ?>"` — `$score_color` is one of three hardcoded values (`#10B981`, `#F59E0B`, `#EF4444`) on lines 38-44, so `esc_attr` is sufficient. ✅

#### `posts.php`

- ⚠️ [posts.php:76](admin/views/posts.php#L76): `printf( esc_html__( 'Znaleziono %d postów', 'ligase' ), $total_posts );` — `$total_posts` is `(int) $query->found_posts` so `%d` is safe, but `esc_html__` is only escaping the translated template, not the substitution. If a future translator inserts HTML into the .po file, this would render. Use:
  ```php
  echo esc_html( sprintf( __( 'Znaleziono %d postów', 'ligase' ), $total_posts ) );
  ```
  Same antipattern in a dozen places across views — minor but consistent.
- ⚠️ [posts.php:15](admin/views/posts.php#L15): `$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;` — fine, `absint`.
- ✅ Per-row escaping is consistent. Inline JS at bottom uses jQuery `.text()` for safe DOM insertion. Builds links via string concat — `$('<div>').text(title).html()` to escape — correct pattern.
- 💡 [posts.php:30](admin/views/posts.php#L30): `$score_calculator = new Ligase_Score()` then `calculate_for_post()` in a per-row loop. For 20 posts that's 20 score calculations on every page load of the Posts admin page. Each calculation reads post meta, post content, post fields. Acceptable for 20 rows, but the dashboard's AJAX `ligase_dashboard_stats` does the same for **every published post** in a `do/while` loop with `posts_per_page=100`. On a 5,000-post site, that's a thundering herd of meta lookups and at least 50 score-calc passes per call. Cache aggressively (already a transient — good) and consider precomputing on `save_post` instead.

#### `entities.php`

- ⚠️ [entities.php:85, :100](admin/views/entities.php#L85): `<?php echo $ner_configured ? '' : esc_attr( 'disabled' ); ?>` — the `esc_attr('disabled')` here is harmless but pointless (the string `disabled` doesn't need escaping). More importantly the **conditional output is unescaped on the truthy branch** (empty string is fine, but the pattern fails Plugin Check Tool's static analysis). Fix:
  ```php
  <?php echo $ner_configured ? '' : 'disabled'; ?>
  ```
  Or use `disabled( ! $ner_configured )`.
- ✅ Wikidata URLs from API responses are not directly rendered server-side in this view; they're inserted via JS using `.text()`. ✅

#### `rules.php`

- ✅ [rules.php:76](admin/views/rules.php#L76): `data-rule="<?php echo esc_attr( wp_json_encode( $rule ) ); ?>"` — correct pattern (`esc_attr` the JSON-encoded string).
- ⚠️ [rules.php:41](admin/views/rules.php#L41): `printf( esc_html( _n( '%d rule', '%d rules', $rule_count, 'ligase' ) ), $rule_count );` — same i18n + printf interplay as posts.php:76. Safe because `$rule_count` is int. Minor.
- ✅ All `term_id`, `name`, `display_name`, `pt->name`, `pt->label` go through `esc_attr` or `esc_html`. ✅
- ⚠️ [rules.php:267](admin/views/rules.php#L267): `var SCHEMA_TYPES = <?php echo wp_json_encode( $schema_types ); ?>;` — same XSS concern as the main JSON-LD output, but here it's PHP-side-controlled data (a constant), so practically safe. To be strict, run the `</script>` replace here too.
- ⚠️ [rules.php:283-284](admin/views/rules.php#L283): `<?php echo esc_js( __( 'Add Rule', 'ligase' ) ); ?>` — correct for emitting into a JS string literal.

#### `meta-box.php`

- ✅ `wp_nonce_field` emitted, all dynamic data goes through `esc_attr` / `esc_html`. The `$score_color` (line 113) is one of three hardcoded values. ✅
- ⚠️ [meta-box.php:108-117](admin/views/meta-box.php#L108): instantiates `Ligase_Score` and runs `calculate_for_post` for every post-edit screen. Acceptable but adds a meta-query on every save. Cache or skip on autosave.

#### `tools.php`

- ✅ Settings form for `health_report_enabled` uses `settings_fields()` for nonce. ✅
- ⚠️ [tools.php:130-134](admin/views/tools.php#L130): `printf( esc_html__( 'Ostatni raport: %s | Score: %d/100 | Problemow: %d' ), esc_html( $last_report['date'] ), (int) ... )` — same printf-with-esc_html antipattern; `$last_report['date']` is already esc_html'd, the rest are ints. Fine.
- 💡 The form at [tools.php:118](admin/views/tools.php#L118) uses `settings_fields( 'ligase_settings_group' )` — that's the same group as the main settings page. The `sanitize` callback on that group will run on this single-field submit. Verify behavior — the sanitize routine resets every field not in `$input` to defaults. **This will wipe all other settings when a user clicks "Save" here.** Bug. Either: use a separate option, or re-merge with existing options inside `sanitize()` (don't start with `$clean = self::defaults()`, start with `$clean = get_option(self::KEY, self::defaults())`).

#### `settings.php`

- ⚠️ [settings.php:46](admin/views/settings.php#L46): `class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>"` — the branches are literal strings, safe; but it should be `esc_attr` to satisfy Plugin Check Tool.

### `includes/class-ajax.php`

[class-ajax.php:1-1262](includes/class-ajax.php#L1)

- ✅ Centralized `verify_request()` that runs `check_ajax_referer` and `current_user_can('manage_options')` before every handler. Pattern is correct and consistent.
- ✅ All `$_POST` integer fields go through `absint`. Strings through `sanitize_text_field( wp_unslash(...) )`. Arrays use `array_map( 'absint', ... )` / `array_map( 'sanitize_text_field', wp_unslash(...) )`.
- ✅ `ligase_import_settings` ([class-ajax.php:578-665](includes/class-ajax.php#L578)) — uses a key whitelist (`$allowed_keys`), sanitizes per-type (URLs/email/text), and only imports author meta for existing users where the key starts with `ligase_`. Solid.
- ⚠️ [class-ajax.php:1041](includes/class-ajax.php#L1041): `$entities = wp_unslash( $_POST['entities'] ?? array() );` — array is unslashed but **not validated as `is_array`** until line 1047. If a request sends `entities=foo`, `is_array($entities)` is false and the handler returns an error. ✅ OK.
- ⚠️ [class-ajax.php:1224-1232](includes/class-ajax.php#L1224): SQL with placeholder for `meta_value = %s`, the rest of the query is static (no concatenation). Safe.
- ⚠️ [class-ajax.php:1234-1242](includes/class-ajax.php#L1234): `$wpdb->get_col(...)` with no placeholders — but the query has no variable interpolation either. Safe.
- ⚠️ [class-ajax.php:865, :892, :900](includes/class-ajax.php#L865): user-facing messages are **Polish-only** strings, not wrapped in `__()`. Inconsistent with the rest of the plugin and blocks proper i18n. Wrap them.
- ⚠️ **No rate limiting** on expensive endpoints like `ligase_scan_all_posts`, `ligase_fix_all_posts`, `ligase_auto_repair`, `ligase_ner_run_bulk`, `ligase_bulk_change_schema_type`. A malicious admin (insider) or an attacker who phished an admin session can fire `ligase_ner_run_bulk` in a loop and **burn the user's API budget**. Add a per-user transient lock:
  ```php
  if ( get_transient( 'ligase_ner_bulk_lock_' . get_current_user_id() ) ) {
      wp_send_json_error( [ 'message' => 'A bulk scan is already running.' ] );
  }
  set_transient( 'ligase_ner_bulk_lock_' . get_current_user_id(), 1, 5 * MINUTE_IN_SECONDS );
  ```
- 💡 [class-ajax.php:710-770](includes/class-ajax.php#L710): the `handle_ligase_auto_repair` switch block computes `wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )` and writes back to post meta. Same `</script>` concern when this meta is later rendered. Fix is the same one-line replace in the central output helper.

### `includes/class-gsc.php`

[class-gsc.php:1-385](includes/class-gsc.php#L1)

- ✅ Service Account JSON validation (`type === 'service_account'`, presence of `client_email` and `private_key`).
- ✅ JWT signing with `openssl_sign` and RS256.
- ✅ Bearer token cached as transient with TTL aligned to token expiry minus 5 minutes.
- ✅ Encryption helper uses AES-256-CBC with random 16-byte IV per encryption.
- ⚠️ **The encryption key is `wp_salt('auth')`**, which is stored in plaintext in `wp-config.php`. Any read-access on the server (LFI, backup leak, SCP from a former dev) yields the key. This is **obfuscation, not encryption against a credible threat model**. Document this in the readme and in the plugin's settings UI: "Credentials are obfuscated using your `AUTH_KEY` salt. Anyone with filesystem or database access on this server can decrypt them. Don't paste Service Account JSON on shared hosting."
- ⚠️ **AES-CBC without HMAC.** AES-CBC ciphertext is *malleable* — an attacker who can write to `wp_options` (e.g. via SQL injection elsewhere) can flip bits in the ciphertext and decryption will silently succeed with garbage plaintext, possibly causing the plugin to read an attacker-controlled `client_email` later. Use AES-GCM (built into `openssl_encrypt` as `aes-256-gcm` with tag verification) or AES-CBC + HMAC-SHA256 (encrypt-then-MAC).
- ⚠️ [class-gsc.php:159, :330, :352](includes/class-gsc.php#L159): `json_decode( wp_remote_retrieve_body(...), true )` — the body is whatever Google returns. The plugin trusts this implicitly. Google is unlikely to return XSS-laden data, but for defense-in-depth (e.g., MITM with a misconfigured corporate proxy that strips TLS, see Norton CRL incident in memory), don't render raw error strings into the admin notice. [class-gsc.php:162](includes/class-gsc.php#L162) returns `error_description` from Google to user — escape on output. The receiver in [class-ajax.php:883](includes/class-ajax.php#L883) does `$result->get_error_message()` which renders untrusted Google strings to the admin. Not exploitable from a typical Google response, but worth `wp_kses_data()` at the output sink.
- ⚠️ **No `sslverify` option set** — `wp_remote_post` defaults to `sslverify => true` which is correct. ✅ But if a future maintainer adds `sslverify=false` to debug, this becomes a credential-stealing MITM. Add a comment.
- ⚠️ `Ligase_GSC::set_site_url` ([class-gsc.php:189-191](includes/class-gsc.php#L189)) accepts arbitrary URL and uses `esc_url_raw`. ✅ But `get_site_url()` returns it raw to be used in `urlencode( $site_url )` as part of an API path. If a user pastes `javascript:alert(1)` it gets stored — `esc_url_raw` strips that. ✅

### `includes/class-health-report.php`

[class-health-report.php:1-169](includes/class-health-report.php#L1)

- ✅ Cron hook scheduled on `init`, unschedule helper exists (but isn't called — see deactivation hook note above).
- ⚠️ [class-health-report.php:142](includes/class-health-report.php#L142): `$body .= sprintf( "  - %s (%s)\n", get_the_title( $pid ), get_permalink( $pid ) );` — the email is plain text, no escaping needed, but the body is sent via `wp_mail` with default headers (no `Content-Type: text/plain`). Most MTAs default to `text/plain` so OK, but be explicit:
  ```php
  wp_mail( $admin_email, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
  ```
- ⚠️ The recipient is `get_option('admin_email')`. If an attacker has DB access they've already won, so this is fine. No SMTP injection (no user input in headers/subject body).
- 💡 [class-health-report.php:52-57](includes/class-health-report.php#L52): `posts_per_page => -1` on every weekly run. On a large blog this loads thousands of post IDs into memory and runs `calculate_for_post` for each — multiple meta reads per call. Acceptable as a weekly job, but consider chunking with `paged` to avoid OOM on huge sites.

### `includes/class-multilingual.php`

[class-multilingual.php:1-164](includes/class-multilingual.php#L1)

- ✅ Detects WPML / Polylang via constants and function existence.
- ✅ Uses `apply_filters` from WPML / `pll_*` functions correctly.
- ✅ `augment_blogposting` merges translation URLs into `sameAs` — the URLs come from `get_permalink( int $trans_id )` which is trusted.
- 💡 [class-multilingual.php:147](includes/class-multilingual.php#L147): `$existing = [ $existing ]` when `sameAs` is a scalar — but what if it's `null`? Then you'd get `[null]` in the merged array. Minor.

### `tests/bootstrap.php` + `phpunit.xml` + `composer.json`

- ✅ PHPUnit 10, PHP >=8.0 — consistent with main `Requires PHP: 8.0`.
- ✅ Stubs WP functions defensively (`if ( ! function_exists(...) )`).
- ⚠️ Bootstrap stubs do not cover `wp_verify_nonce`, `check_ajax_referer`, `current_user_can`, `wp_send_json_*`, `$wpdb`, so **no AJAX/admin code is testable** without WP Brain Monkey / Mockery. That's fine if the design is "unit test the schema types", but the audit revealed no actual tests in `tests/unit/`. The directory was not enumerated.
- 💡 `composer.json` pins `phpunit/phpunit: ^10.0`. Lockfile not in repo (`.gitignore` likely excludes it) — for CI reproducibility commit `composer.lock`.
- 💡 No PHPCS / WPCS / Plugin Check Tool integration. Add:
  ```json
  "require-dev": {
      "phpunit/phpunit": "^10.0",
      "wp-coding-standards/wpcs": "^3.0",
      "phpcompatibility/phpcompatibility-wp": "^2.1"
  }
  ```

### `blocks/faq/block.json`, `blocks/howto/block.json`

- ✅ apiVersion 3, textdomain set.
- ⚠️ Both blocks declare `editorScript: file:./index.js` but the `index.js` files are not in the repo (verified via Glob earlier — only `block.json` present). Either ship them or remove the references; otherwise activation will log "Could not load block script".
- ⚠️ Block `render_callback` writes post meta on every render — see class-plugin.php finding above.

---

## CRITICAL VULNERABILITIES TABLE

| # | File:Line | Type | Severity | Fix |
|---|-----------|------|----------|-----|
| 1 | [class-output.php:50-69](includes/class-output.php#L50) | Stored XSS via JSON-LD `</script>` breakout | **High** | After `wp_json_encode`, run `str_replace(['</','<!--','<![CDATA['], ['<\\/','<\\!--','<\\!\\[CDATA['], $json)`. Also purge `Ligase_Cache::invalidate_all()` on upgrade. |
| 2 | [class-ajax.php:710-770](includes/class-ajax.php#L710) (`handle_ligase_auto_repair`) | Same XSS class — writes attacker-trivial JSON to post meta that's later echoed by class-output | **High** | Same fix; the source of the JSON should be safe before it's stored. |
| 3 | [class-plugin.php:145-165](includes/class-plugin.php#L145) | Capability bypass — front-end render writes post meta without authorization | **Medium** | Move `update_post_meta` out of the block `render_callback` (use Gutenberg block `meta` storage or `save_post` filter). |
| 4 | [class-admin.php:297, :411](admin/class-admin.php#L297) | CSRF — `wp_verify_nonce` without `wp_unslash` + sanitize | **Low** (functionally OK, fails wp.org review) | `! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ligase_meta_nonce'] ) ), 'ligase_meta_save' )` |
| 5 | [class-logger.php:39-44, :179-186](includes/class-logger.php#L39) | Information disclosure via web-accessible log file on Nginx/LiteSpeed | **Medium** | Move logs to `wp-content/ligase-logs/` (outside uploads) and add an `index.html` empty file, **and** an `.htaccess` (Apache). For Nginx, document that log dir must be denied via server config. Strip PII from `$context` payloads. |
| 6 | [class-gsc.php:359-378](includes/class-gsc.php#L359) | Weak credential protection — AES-CBC malleable + key is `wp_salt('auth')` | **Medium** | Switch to AES-256-GCM with auth tag, or AES-CBC + HMAC. Document threat model in readme. |
| 7 | [class-settings.php:543-545, :516-598](admin/class-settings.php#L543) | NER API key stored in plaintext in `wp_options` while GSC credentials are encrypted — inconsistent | **Medium** | Encrypt NER key with the same helper as GSC, or document that all credentials are obfuscation-grade. |
| 8 | [class-ajax.php (all `ligase_*_run_bulk` etc.)](includes/class-ajax.php#L991) | No rate limit on money-spending endpoints | **Medium** | Per-user transient lock for `ligase_ner_run_bulk`, `ligase_ner_run_post`, `ligase_auto_repair`, `ligase_scan_all_posts`. |
| 9 | [class-settings.php:516-598](admin/class-settings.php#L516) (`sanitize`) | Logic bug — `sanitize` resets to defaults wipes fields not present in $input (esp. `default_schema_type` and `health_report_enabled` saved from a sub-form) | **Medium** | Start from `$clean = get_option(self::KEY, self::defaults())`, then overlay validated `$input`. |
| 10 | [class-plugin.php:91](includes/class-plugin.php#L91) | DoS-amplifier — `updated_option` fires `Ligase_Cache::invalidate_all()` for **every** option update | **Low** | Scope to options starting with `ligase_`. |
| 11 | [entities.php:85, :100](admin/views/entities.php#L85), [settings.php:46](admin/views/settings.php#L46), [plugin.php:218, :225, :245, :247](includes/class-plugin.php#L218) | Inline `<?php echo $var ?>` ternary without escaping | **Low** | Use `esc_attr( $var ? 'a' : 'b' )` or `disabled( $cond )`. |
| 12 | [uninstall.php:42-51](uninstall.php#L42) | Stale data — cron events, .htaccess in log dir, gsc token transient not cleaned | **Low** | Add `wp_clear_scheduled_hook()` calls and include dotfiles in `glob`. |
| 13 | [class-gsc.php:162-164](includes/class-gsc.php#L162) | Reflected output of remote API error strings into admin notices | **Low** | `wp_kses_data()` at the rendering sink in [class-ajax.php:883](includes/class-ajax.php#L883). |
| 14 | [composer.json, blocks/](composer.json) | Missing dependencies — `blocks/faq/index.js` and `blocks/howto/index.js` referenced but not present | **Low** | Build and commit, or remove block registrations until ready. |

---

## WordPress.org Plugin Guidelines Compliance Scorecard

Based on https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/.

| Guideline | Status | Notes |
|-----------|--------|-------|
| 1. Plugin must be GPL-compatible | ✅ | GPL v2 or later, declared in main file and composer.json |
| 2. Developer is responsible for legal use | ✅ | N/A — no copyrighted assets |
| 3. Stable version in trunk | ⚠️ | `readme.txt` declares Stable tag 2.0.0 — fine, but `Tested up to` is 6.8 (good, current). |
| 4. Code must be human-readable | ✅ | Generally clean, well-commented |
| 5. Trialware not allowed | ✅ | No paid gating |
| 6. Communicating with external services must be disclosed | ⚠️ | GSC, OpenAI, Anthropic, Google NLP, Dandelion, Wikidata, openai.com all called — **must be disclosed in readme.txt under a "Privacy" section**, currently missing. wordpress.org will reject. |
| 7. No phoning home without explicit consent | ✅ | No telemetry. GSC/NER only fire when user configures and uses them. |
| 8. No "powered by" or admin notices | ⚠️ | Onboarding notice is dismissible — ✅ acceptable. No persistent upsells. |
| 9. Plugin must be safe by default | ⚠️ | Critical XSS in JSON-LD output (#1 above) blocks this. |
| 10. No obfuscated code | ✅ | All PHP is readable |
| 11. Compliance with WordPress trademark | ✅ | "Ligase" — no WP infringement |
| 12. Functional code on first install | ✅ | Defaults reasonable; onboarding notice guides setup |
| 13. Don't hijack the admin | ⚠️ | Onboarding notice OK; no upsell pages. But the menu uses Polish-only labels (`Ustawienia`, `Posty`, `Audytor`, `Narzędzia`, `Encje`). For wp.org, source must be **English**, translations live in `/languages/`. |
| 14. No spammy plugin pages | ✅ | Description is clean |
| 15. Don't request donations on the dashboard | ✅ | None |
| 16. Public service: source must be on wp.org | n/a | Self-hosted plugin, distributed via .org or GitHub |
| 17. Respect trademarks and intellectual property | ✅ | |
| 18. Plugin must be safe from common security mistakes | ❌ | See critical issues #1–4 above. |
| 19. Don't violate user privacy | ⚠️ | Sends post content to external LLMs (NER) — must have a Privacy Policy block and user-facing disclosure on the settings page (partially done — text in `render_ner_section_desc` is good, but readme.txt also needs it). |
| 20. Use built-in WordPress functions | ⚠️ | `file_put_contents` in class-logger.php (logger). Acceptable with phpcs disable comment; ideally use WP Filesystem API. |

**Verdict:** Plugin will be rejected by wordpress.org until items 6, 9, 13, 18, 19 are addressed.

---

## Top 10 fixes before submitting to wordpress.org

In rough order of severity / blocker-ness.

1. **Patch the JSON-LD `</script>` breakout XSS** in [class-output.php:50](includes/class-output.php#L50). One-line `str_replace`. Also apply to [class-ajax.php:710-770](includes/class-ajax.php#L710) (auto-repair) and [class-ajax.php:325](includes/class-ajax.php#L325) (preview). Bump version to invalidate the cache.

2. **Translate user-facing strings to English source** and wrap any Polish text in `__()`. The submenu labels (`Ustawienia`, `Posty`, `Audytor`, `Narzędzia`), all error messages (`'Brak skonfigurowanych credentials GSC.'`, `'Nieprawidlowy format JSON.'`), and h1 page titles (`'Ligase — Narzedzia'`) must be `__()`-wrapped English. Ship `languages/ligase-pl_PL.po` for the Polish translation.

3. **Add a Privacy section to `readme.txt`** disclosing every external service the plugin contacts: Google Search Console API, OpenAI, Anthropic, Google Natural Language, Dandelion, Wikidata. Spell out what data is sent (post title + content for NER, GSC site URL for search analytics) and link to each provider's privacy policy.

4. **Fix the Settings sanitize callback** so it doesn't wipe fields when a single-field sub-form (e.g. `health_report_enabled` in tools.php) is submitted. Start `$clean` from current saved options, not `defaults()`. [class-settings.php:517](admin/class-settings.php#L517).

5. **Stop writing post meta from front-end block `render_callback`** ([class-plugin.php:145-165](includes/class-plugin.php#L145)). Move to `save_post` (which already invalidates cache), or use Gutenberg's `meta` block attribute storage.

6. **Move logs out of `wp-content/uploads/`** to `wp-content/ligase-logs/`. Drop the `.htaccess` reliance. Strip likely-PII keys (`*email*`, `*key*`, `*token*`) from `$context` before writing. [class-logger.php:39-186](includes/class-logger.php#L39).

7. **Encrypt NER API keys** the same way GSC credentials are encrypted (or document both as obfuscation only). Upgrade GSC encryption to AES-256-GCM. [class-gsc.php:359](includes/class-gsc.php#L359), [class-settings.php:543](admin/class-settings.php#L543).

8. **Add deactivation hook** that clears scheduled cron events: `ligase_weekly_health_report`, `ligase_ner_api_extract`, `ligase_wikidata_lookup`. [ligase.php:32](ligase.php#L32). Mirror cleanup in `uninstall.php`.

9. **Fix the redundant cache-busting** in [class-plugin.php:91-96](includes/class-plugin.php#L91). Scope `updated_option` listener to options starting with `ligase_` — currently every WP option update on the site triggers a `LIKE`-DELETE on `wp_options`.

10. **Run Plugin Check Tool (`plugin-check`)** and fix every escaping warning, especially the `<?php echo $var ?>` ternaries in views ([entities.php:85,100](admin/views/entities.php#L85), [settings.php:46](admin/views/settings.php#L46), [class-plugin.php:218,225,245,247](includes/class-plugin.php#L218)) and the `wp_verify_nonce` without `wp_unslash` ([class-admin.php:297,411](admin/class-admin.php#L297)). Also fix the missing `blocks/*/index.js` build outputs or remove the registrations.

---

## Things done well

A short list — these are good and don't need changing:

- ✅ Central `verify_request()` helper in `Ligase_Ajax` — single audit point for nonce + capability across all 30+ AJAX actions.
- ✅ Settings sanitize uses an explicit whitelist of allowed keys, with per-type sanitizers (text / email / URL / number / nested array). [class-settings.php:516-598](admin/class-settings.php#L516).
- ✅ Settings Import uses a whitelist of allowed option keys with per-type sanitization. [class-ajax.php:604-630](includes/class-ajax.php#L604).
- ✅ `$wpdb->prepare` used consistently with placeholders; no string-concatenated queries anywhere I checked.
- ✅ `uninstall.php` uses `WP_UNINSTALL_PLUGIN` guard, `esc_like` for the meta sweep, deletes options and post/user meta.
- ✅ AES-256-CBC with random IV per encryption for GSC credentials (though malleable — see finding #6).
- ✅ Schema cache keys include `LIGASE_VERSION` — version bumps automatically invalidate stale cached JSON.
- ✅ Logger rotates at 5 MB, max 3 rotations.
- ✅ Meta-box save: nonce verified, autosave/revision skipped, capability checked, schema type whitelisted.
- ✅ Block registration uses `register_block_type` with `block.json` (correct WP 5.8+ pattern).
- ✅ Multilingual layer detects WPML and Polylang correctly and uses their respective filters / functions.
- ✅ GSC service account validation: rejects non-`service_account` JSON, rejects missing `client_email` or `private_key`.

---

## Recap

**Bottom line:** the plugin is well-structured and the centralized auth in AJAX is the strongest design choice. The blocker is the JSON-LD XSS — without fixing it, every BlogPosting on the site is one piece of attacker-controlled meta away from a stored XSS. Fix that, fix the English-source strings, fix the privacy disclosure, and you're 90% of the way to wordpress.org submission.

Estimated time to wp.org readiness: **6–10 hours of focused work**.
