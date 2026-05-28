# Ligase v2.0.0 — Audit: Auditor + Suppressor + Importer subsystem

**Scope:** the differentiating "schema policing" pipeline — detecting, scoring, suppressing, supplementing and replacing competing plugins' JSON-LD.
**Verdict at a glance:** the architecture is reasonable but the wiring is broken in several places. The auditor's main entry point (`intercept()`) is unreachable, the test suite calls methods that don't exist on the class, the suppressor has hardcoded the wrong filter names for the two biggest competitors (Yoast and Rank Math), `Replace` mode permanently overwrites the schema source without any rollback or undo, and the suppressor list is duplicated and inconsistent with the auditor's detection list. This is a **CRITICAL** subsystem audit.

---

## 1. `includes/class-auditor.php` — **CRITICAL**

### What it claims to do
Three modes: `scan`, `supplement`, `replace`. Hooks `wp_head` via `ob_start()`, finds `<script type="application/ld+json">`, scores 0-100, and acts. Also exposes synchronous `scan_post()` / `scan_all_posts()` / `apply_replacement()` for AJAX.

### Findings

#### 1.1 (CRITICAL) `intercept()` is never wired up
[class-auditor.php:91-103](includes/class-auditor.php#L91) defines `intercept()` to set up the buffer hook. It is never called from `class-plugin.php` `init_hooks()`, never registered against `wp_head`, and never referenced from `class-ajax.php`. Grep for `->intercept(` and `'intercept'` returns zero hits. **The runtime mode setting (`scan / supplement / replace` as a passive page-load behaviour) is dead code.** Everything documented in `admin/views/auditor.php` (the mode dropdown) is wired through the on-demand AJAX path (`apply_replacement()`), which only supports replace-style behaviour. "Supplement" as a runtime mode does not exist on production pages.

#### 1.2 (CRITICAL) Tests reference an API that doesn't exist
[AuditorTest.php:168](tests/unit/AuditorTest.php#L168), [184](tests/unit/AuditorTest.php#L184), [195](tests/unit/AuditorTest.php#L195), [270](tests/unit/AuditorTest.php#L270) all call `$this->subject->audit( $schema, $mode )` and [216](tests/unit/AuditorTest.php#L216) calls `$this->subject->detect_plugins()`. Neither method exists in `class-auditor.php` — actual public surface is `score()`, `scan_post()`, `scan_all_posts()`, `apply_replacement()`, `apply_all_replacements()`, `consume_replacement_flag()`, `get_detected_plugins()`, `get_results()`, `intercept()`, `process_buffer()`. This means the entire `AuditorTest` test class would crash with `Error: Call to undefined method` the moment it runs — i.e. the test suite cannot currently exercise the auditor at all. Either the class was refactored without updating the tests or the tests were copy-pasted from a draft that never landed. Either way, there is **no coverage** of the most important subsystem.

#### 1.3 (CRITICAL) JSON-LD parsing via regex
[class-auditor.php:122](includes/class-auditor.php#L122) and [667](includes/class-auditor.php#L667) extract JSON-LD via:
```php
'/<script\s+type=["\']application\/ld\+json["\']\s*>(.*?)<\/script>/si'
```
Problems:
- The regex requires `type` to be the first attribute. Real-world `<script id="..." type="application/ld+json">` (Yoast and AIOSEO both add an `id`/`class` first) **will not match**. Yoast emits `<script type="application/ld+json" class="yoast-schema-graph">` (type first, so it works), but Rank Math emits `<script type="application/ld+json" class="rank-math-schema">` and **also a fallback variant where id comes first**. Coverage is brittle.
- It misses `application/ld+json; charset=UTF-8` MIME variants.
- It greedy-fails on minified pages containing `</script>` inside string values (rare but legal — Schema.org allows `</script>` not actual `</script>`, but a buggy generator can emit it).

#### 1.4 (CRITICAL) "Replace" path is permanent and irreversible
[class-auditor.php:171-178](includes/class-auditor.php#L171) writes `_ligase_replaced_schema` and `_ligase_needs_own_schema` to post meta, then **mutates the live output buffer**:
```php
$buffer = str_replace( $full_tag, '', $buffer );
```
The `_ligase_replaced_schema` backup is written but **never read by anything** — grep confirms only the auditor writes it, no class consumes it. No `restore_replacement()`, no `revert_post()`, no admin UI to roll back. Worse, every page load that re-enters `process_buffer()` will overwrite the backup with the *current* (already replaced) schema if it gets re-fed — meaning the "rollback" snapshot can be silently destroyed.

Combined with finding 1.1, this is mostly latent — the runtime path never fires today. But the on-demand `apply_replacement()` at [class-auditor.php:369-396](includes/class-auditor.php#L369) writes the same flag and is reachable via `wp_ajax_ligase_fix_post` and `wp_ajax_ligase_apply_audit_replacements`. The user clicks "Zastosuj naprawy" and there is no undo button.

#### 1.5 (HIGH) `consume_replacement_flag()` is never consumed
[class-auditor.php:434](includes/class-auditor.php#L434) defines `consume_replacement_flag()` — designed so that `Ligase_Output::render()` can check + clear the flag, generating the replacement once. But `Ligase_Output` instead has its own `needs_own_schema()` at [class-output.php:75-78](includes/class-output.php#L75) that **only reads, never deletes** the flag. The flag is therefore sticky — once a post is flagged, every page load goes through the "needs replacement" branch forever. Two unrelated functions doing the same thing inconsistently is a smell; one of them is dead code.

#### 1.6 (HIGH) Scoring algorithm is plausibly self-serving
[class-auditor.php:207-265](includes/class-auditor.php#L207). The score awards:
- 15pt for `headline`, `datePublished`, `author.name`, `image`
- 10pt for `dateModified`, `publisher`, `author.@id`
- 5pt for `@id`, `description`
- Penalties for long headline, invalid ISO 8601, image width <696px

Max raw score: 100. But the algorithm only inspects `Article`-shaped schemas. It ignores `Product`, `Event`, `Recipe`, `LocalBusiness`, `Review` — any of which would naturally lack `headline` and `datePublished`. **An Event schema from The Events Calendar would score near zero** (no `headline`, no `datePublished`, no `author`) and trigger "Replace" by default at threshold 50. The plugin would then helpfully generate a `BlogPosting` for the Event post, destroying valid SEO.

This is functionally a bug, but it's also a "Replace by default looks good in marketing because everyone else scores low." Either way: not fair.

Also: penalty for `image.width < 696` returns -10, but Google's actual minimum is `1200x675` for AMP Article. The 696 threshold is the old 2019 AMP guideline. Inputs are stale.

#### 1.7 (MEDIUM) Source plugin detection is naive substring match
[class-auditor.php:615-639](includes/class-auditor.php#L615) detects "yoast" / "rank-math" / "aioseo" / "schema-pro" / "seopress" by literal substring in the encoded JSON. Slim SEO, The SEO Framework and The Events Calendar are not detected at all — they will return `''`. Conversely, any post that legitimately mentions the string `yoast` in `description` will be misattributed.

#### 1.8 (MEDIUM) `get_jsonld_for_post()` triggers `do_action('wp_head')` synchronously
[class-auditor.php:648-684](includes/class-auditor.php#L648). To scan a single post the auditor sets up `global $post`, runs `do_action('wp_head')`, captures the output buffer, then re-parses. Concerns:
- Many plugins enqueue scripts/styles, fire analytics pixels, emit `<link rel="preload">`, etc. in `wp_head`. Running this once per post in `scan_all_posts()` (called from the AJAX endpoint with `posts_per_page => -1`) can produce dozens of side-effects per scan: GA hits, dynamic enqueue cache pollution, transient writes from other plugins, third-party API calls. On a 1000-post site this is a small DDoS of the admin's own infrastructure.
- It does not suspend `actions` that send mail, queue cron, or hit external APIs.
- `wp_head` actions can call `die()` or `wp_redirect()` (e.g. on a soft 404). One bad theme = the whole batch dies.

#### 1.9 (MEDIUM) ISO 8601 regex is permissive and doesn't fully validate
[class-auditor.php:73](includes/class-auditor.php#L73) `^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?([+-]\d{2}:\d{2}|Z)?)?$` accepts `2025-13-45` (no month/day range check) and rejects `2025-01-15T10:00:00.000Z` (no fractional seconds support). Google's Structured Data Test accepts both forms. The penalty of -20 for "invalid" dates can fire on perfectly valid input.

#### 1.10 (LOW) `image_width_below()` ignores `url`-only images and `ImageObject` URL form
[class-auditor.php:705-717](includes/class-auditor.php#L705). If `image` is a plain string URL, no penalty. If `image` is `[ '@type'=>'ImageObject', 'url'=>'...', 'width'=>0 ]` (Yoast often emits width 0 when EXIF is missing), penalty fires. Asymmetric.

#### 1.11 (LOW) `nested_has()` is called twice per author check, hot path
[class-auditor.php:223](includes/class-auditor.php#L223), [235](includes/class-auditor.php#L235), [579](includes/class-auditor.php#L579), [593](includes/class-auditor.php#L593). On `scan_all_posts()` with 1000 posts this is wasted work, but it's micro.

#### 1.12 (LOW) `KNOWN_SEO_PLUGINS` here doesn't match `Ligase_Suppressor::KNOWN_PLUGINS`
[class-auditor.php:57-66](includes/class-auditor.php#L57) lists 8 plugins by file path. `Ligase_Suppressor::KNOWN_PLUGINS` lists 7 plugins by constant/class. They overlap but **differ** — auditor includes "Schema Pro" and "Schema & Structured Data for WP" but no "The Events Calendar"; suppressor includes "The Events Calendar" but no "Schema Pro". Two sources of truth.

### File verdict: **CRITICAL**
Wiring broken (1.1), tests broken (1.2), unsafe replace (1.4, 1.5), unfair scoring on non-Article (1.6).

---

## 2. `includes/class-suppressor.php` — **CRITICAL**

### What it claims to do
Detect active SEO plugins via `defined()` / `class_exists()`, then `add_filter()` `__return_false` / `__return_empty_array` on each plugin's schema output hook.

### Findings

#### 2.1 (CRITICAL) Yoast suppression filter names are wrong
[class-suppressor.php:18-21](includes/class-suppressor.php#L18). The filters listed are:
```php
[ 'wpseo_json_ld_output', '__return_false' ],
[ 'wpseo_schema_graph_pieces', '__return_empty_array' ],
```
Neither is the right hook to disable Yoast's JSON-LD as of Yoast 14+. Correct:
- The graph output filter is `wpseo_schema_graph` (not `wpseo_schema_graph_pieces`). `wpseo_schema_graph_pieces` is a *registration* filter that runs once, not on each render; returning empty array there only removes new pieces, not pieces Yoast core has already registered.
- There is **no** `wpseo_json_ld_output` filter in current Yoast. The historical kill switch is `wpseo_json_ld_output`... wait, that exists for **breadcrumb only** (`wpseo_json_ld_output` historically applied to `WPSEO_JSON_LD` which is the breadcrumb class, removed in Yoast 14). For modern Yoast you need to either dequeue the `Yoast\WP\SEO\Integrations\Front_End\Schema` integration or filter `wpseo_schema_graph` to return `[]`.

The user thinks Yoast is suppressed; Yoast keeps rendering. The auditor's `should_render()` check at [class-output.php:94-103](includes/class-output.php#L94) then also notices Yoast is active and **declines to render Ligase's schema**. Net result: in default mode with Yoast installed, **Ligase emits nothing and Yoast wins**. That's actually safe behaviour, but it makes "standalone mode" silently broken — the user enables standalone mode expecting Yoast off + Ligase on, gets Yoast on + Ligase on (double schema).

#### 2.2 (CRITICAL) Rank Math filter is wrong
[class-suppressor.php:34-36](includes/class-suppressor.php#L34). `rank_math/json_ld/disable` does not exist. Rank Math's documented disable filter is `rank_math/frontend/disable_integration` (controls the whole frontend), or simpler: `rank_math/json_ld` returning empty array. `rank_math/schema/post_schemas` is a *settings retrieval* filter, not a render-time gate.

Same outcome as Yoast: standalone mode silently leaks Rank Math schema alongside Ligase.

#### 2.3 (HIGH) AIOSEO filter is wrong
[class-suppressor.php:26-29](includes/class-suppressor.php#L26). AIOSEO does not have `aioseo_schema_output` as a kill switch. The correct hook is `aioseo_disable_schema` (returns bool) or filter `aioseo_schema_graph` to return `[]`.

#### 2.4 (HIGH) SEOPress filter name is wrong
[class-suppressor.php:42-44](includes/class-suppressor.php#L42). `seopress_schemas_output` is not a SEOPress filter. SEOPress emits schemas through `seopress_schemas_single_json` (Article-type schemas) and `seopress_schemas_woocommerce_json`. There is no single master switch; you need to filter each per-type schema individually.

#### 2.5 (HIGH) Slim SEO has zero filters
[class-suppressor.php:60-64](includes/class-suppressor.php#L60). `'filters' => []`. The plugin is detected but never suppressed. Comment is silent on why. Slim SEO does expose `slim_seo_schema_data` which returns the data array — settable to `[]` to disable.

#### 2.6 (HIGH) The SEO Framework filter is wrong
[class-suppressor.php:54-58](includes/class-suppressor.php#L54). `the_seo_framework_schema_output` is not a TSF filter. TSF uses `the_seo_framework_use_schema` (or `tsf_use_schema`) to gate output, and `the_seo_framework_ld_json_breadcrumbs` etc. for per-type.

#### 2.7 (MEDIUM) The Events Calendar filter likely outdated
[class-suppressor.php:46-52](includes/class-suppressor.php#L46). `tribe_events_jsonld_enabled` is from old TEC. Modern TEC (6.x) emits schema through `Tribe\Events\Views\V2\Template\JSON_LD`; filter is `tribe_events_view_v2_jsonld_enabled` or you remove the action.

#### 2.8 (HIGH) `suppress_all()` is fire-and-forget — no idempotency, no scope
[class-suppressor.php:102-122](includes/class-suppressor.php#L102). Called from `Ligase_Output::maybe_suppress_early()` on `wp_loaded` — but only when `standalone_mode` is on. Each request creates a new `Ligase_Suppressor`, adds filters at priority 999, then drops the instance. Concerns:
- `restore_all()` at [class-suppressor.php:128-144](includes/class-suppressor.php#L128) is never called by anything. The `$suppressed` instance property is meaningless because the instance dies after `wp_loaded`.
- No scope filter — suppression applies on **every request**, including admin AJAX, REST API, sitemaps, feeds. If Yoast generates schema for the sitemap (it does for `wpseo_sitemap_index_links`), suppressing all of its schema filters can break Yoast features that aren't related to JSON-LD at all if the filter names happen to collide.
- No per-post-type scope. A site that wants Ligase to handle blog posts but let WooCommerce / The Events Calendar handle their CPTs cannot do that. It's all-or-nothing per plugin.

#### 2.9 (MEDIUM) Race with own `should_render()` check
The plugin runs `maybe_suppress_early()` only when `standalone_mode` is on, but in default mode `should_render()` at [class-output.php:94](includes/class-output.php#L94) **detects active SEO plugins independently** and decides not to render. The two checks share detection logic but it's split across two files. If a user installs a competitor that has the constant `WPSEO_VERSION` set (via a wrapper plugin like "WPSEO Local"), Ligase will refuse to render even if Yoast's main JSON-LD path isn't active.

#### 2.10 (MEDIUM) Static `$is_active` is a singleton-shaped flag — never read
[class-suppressor.php:8](includes/class-suppressor.php#L8) and [150-152](includes/class-suppressor.php#L150). `Ligase_Suppressor::is_active()` is defined but grep shows it's never called. Dead API surface.

#### 2.11 (LOW) Detection uses class names with single backslash escape
[class-suppressor.php:55](includes/class-suppressor.php#L55) `'The_SEO_Framework\Bootstrap'` and [62](includes/class-suppressor.php#L62) `'SlimSEO\Slim_SEO'`. In single-quoted strings PHP does not interpret `\` as escape unless followed by `'` or `\`, so this works — but it's inconsistent with [17](includes/class-suppressor.php#L17) `'Yoast\\WP\\SEO\\Main'` which uses double-escaped slashes. Pick one.

### File verdict: **CRITICAL**
Wrong filter names for **every** major competitor means the suppressor is theatre. Standalone mode is broken in practice; users will see double schema.

---

## 3. `includes/class-importer.php` — **NEEDS WORK**

### What it claims to do
Detect available Yoast/Rank Math/AIOSEO data, then run a one-click migration of org name, logo, phone, social URLs.

### Findings

#### 3.1 (HIGH) Yoast importer uses `else { $skipped++; }` on every iteration — counts collisions as "skipped"
[class-importer.php:80-114](includes/class-importer.php#L80). If a Yoast value exists AND Ligase already has a value, it's "skipped" — but if a Yoast value is empty, it's also "skipped". The user can't tell whether their 11 "skipped" items mean "11 conflicts preserved" or "Yoast didn't have those fields". The UI is misleading.

#### 3.2 (HIGH) Yoast `wpseo_titles['company_logo']` is not a URL — it's an attachment ID in modern Yoast
[class-importer.php:89](includes/class-importer.php#L89). Since Yoast 14+, `company_logo` is an attachment ID, not a URL. `company_logo_id` is the canonical key; `company_logo_meta` stores serialized data. Running `esc_url_raw( '12345' )` returns empty. Logo import almost certainly fails silently on any modern Yoast install.

#### 3.3 (HIGH) Twitter URL normalisation is naive
[class-importer.php:108-110](includes/class-importer.php#L108). Yoast stores `twitter_site` as `@username` or `username`. The check `! str_starts_with( $value, 'http' )` then `'https://twitter.com/' . ltrim( $value, '@' )`. Three problems:
- Twitter rebranded to X in 2023; canonical URL is `https://x.com/`. Both work but if you care about freshness use x.com.
- Yoast `twitter_site` may be empty string for users who never set it; `! empty()` catches that.
- Does not handle the rare case of full `@twitter.com/user` strings.

#### 3.4 (HIGH) Rank Math importer ignores `homepage_*` data entirely
[class-importer.php:139-191](includes/class-importer.php#L139). Rank Math stores org data in **both** `rank-math-options-titles` AND `rank-math-options-general`. The importer only reads `titles` for org_name/logo/social and `general` is fetched but never used. Rank Math's actual structure:
- `rank-math-options-titles` → `knowledgegraph_name`, `knowledgegraph_logo`, `phone`, `social_url_*` (✅ correct)
- `rank-math-options-general` → `local_business_*` for LocalBusiness type (✅ ignored, fine)
- But `knowledgegraph_logo` since v1.0.50 is an ID, not URL — same bug as Yoast (3.2).

#### 3.5 (HIGH) AIOSEO option may be serialized PHP, not JSON
[class-importer.php:194-195](includes/class-importer.php#L194). AIOSEO stores `aioseo_options` as a **JSON string** in recent versions (4.x), but older 3.x stored it as serialized PHP. The branch `is_string($raw) ? json_decode($raw, true) : ...` silently returns `[]` on a serialized payload — `maybe_unserialize()` is not tried. The importer will look "available" in `detect_sources()` (because the option is non-empty) but produce zero imports on AIOSEO 3.x.

Also: AIOSEO 4.x stores under nested path `searchAppearance.global.schema.organizationName` (✅ used) but social profiles since 4.5 live at `social.profiles.urls.facebookPageUrl` (NOT `facebookUrl`) and `social.profiles.urls.twitterUrl` — the importer's `social_map` uses old key names (`facebookUrl`, `twitterUrl`, etc.) that match neither the current `urls.*PageUrl` style nor the legacy `profile.*` style. Likely 0% hit rate on real AIOSEO 4.x data.

#### 3.6 (MEDIUM) No CSRF protection wrapping; relies on AJAX layer
The importer class has no nonce/capability check; it trusts `class-ajax.php` `verify_request()` to do that. [class-ajax.php:823-836](includes/class-ajax.php#L823) handlers do call `verify_request()` ✅ — so this is OK in practice, but if anyone calls `Ligase_Importer::import()` from CLI or another integration, no protection.

#### 3.7 (MEDIUM) Conflict policy is silent "skip"
The whole importer uses `empty( $opts[ $ligase_key ] )` to decide whether to import. If Ligase already has *any* value (default org name from `get_bloginfo('name')` populated on install, for example), nothing imports. There is no UI prompt "Override existing?", no diff view, no per-field choice. For users migrating from Yoast → Ligase this is a footgun: they think they're migrating, they get nothing, they uninstall Yoast, now they have stale defaults.

#### 3.8 (MEDIUM) `import_yoast()` has dead Facebook→LinkedIn mapping
[class-importer.php:126-130](includes/class-importer.php#L126):
```php
$fb = get_user_meta( $uid, 'facebook', true );
if ( $fb && ! get_user_meta( $uid, 'ligase_linkedin', true ) ) {
    // Yoast stores Facebook, we map to whatever is available
}
```
Empty if-body. Either it's leftover scaffolding or someone deleted the implementation. Cleanup needed.

#### 3.9 (LOW) `sanitize_text_field` strips newlines from logos
Not applicable here (logos are URLs), but `sanitize_text_field( $titles['company_name'] )` would corrupt brand names containing tab/newline (rare, but corporate names with em-dash sometimes survive sanitize as bytes). Use `wp_kses` for names.

#### 3.10 (LOW) Polish-only `$details` strings
[class-importer.php:91](includes/class-importer.php#L91), [157](includes/class-importer.php#L157), [217](includes/class-importer.php#L217), all `$details` entries are Polish without `__()`/`_e()` wrapping. The plugin uses `'ligase'` text domain elsewhere — inconsistent localisation.

### File verdict: **NEEDS WORK**
Mostly correct shape but the option keys for two of three sources are wrong/stale (3.2, 3.4, 3.5). Migration will produce empty results on modern Yoast/AIOSEO.

---

## 4. `includes/class-validator.php` — **GOOD**

### What it does
Renders the user-facing schema validator (the "Validate" button in admin / Gutenberg sidebar). Per-type checks for Article/Person/Org/Review/FAQ/HowTo/Video/Event/Course/SoftwareApplication/ClaimReview/QAPage/LocalBusiness/Service.

### Findings

#### 4.1 (LOW) Validator builds graph by calling `Ligase_Generator` — validates own output, not external
[class-validator.php:22-24](includes/class-validator.php#L22). This is fine for "validate my Ligase output" but means the validator class has **no role in the audit pipeline** — it can't be used to validate competitor schema, only Ligase's. The audit feature would benefit from a Validator that accepts a raw schema array.

#### 4.2 (LOW) `validate_faq()` has a guaranteed warning
[class-validator.php:208](includes/class-validator.php#L208) always appends "FAQPage: rich results ograniczone do gov/health od 2024" — every FAQPage validation produces a warning even on a perfect schema. The warning is informational, but it taints the "warnings.length === 0" UX signal.

#### 4.3 (LOW) `validate_person()` `@id` regex assumes Ligase's own format
[class-validator.php:146](includes/class-validator.php#L146) `/#author-(\d+)$/`. If a post has imported Yoast schema, the Person `@id` is `#person-<sha1>` and this preg_match fails — the "Edytuj profil" link falls back to `profile.php` (current user's profile), which may not be the right author. Minor UX bug.

#### 4.4 (LOW) Mixed Polish/English messages
Errors are Polish (`'Article: brak headline (wymagane).'`) but the docblock and method names are English. No `__()` wrapping. The plugin already has localisation infra (`load_textdomain`), so these strings should go through it.

#### 4.5 (LOW) `JSON_PRETTY_PRINT` always on
[class-validator.php:40](includes/class-validator.php#L40). For the validator UI display this is correct — but if this same JSON is ever surfaced to the user as the live output, pretty-printing wastes bytes. (Output class at [class-output.php:50-53](includes/class-output.php#L50) also uses `JSON_PRETTY_PRINT` — bloats every page load. Not strictly in scope but worth flagging.)

### File verdict: **GOOD**
Per-type checks are reasonable, sane error/warning split. Outside the audit pipeline, so it doesn't carry the auditor's structural issues.

---

## 5. `includes/class-schema-rules.php` — **GOOD (minor)**

### What it does
Per-post conditional schema enablement: rules keyed by category / tag / post_type / author / slug_contains map to schema type flags. Stored in `ligase_schema_rules` option.

### Findings

#### 5.1 (MEDIUM) `'always'` rule overrides `'public' => true` post types only
[class-schema-rules.php:135-138](includes/class-schema-rules.php#L135). `'always'` rule applies to all `public => true` post types but checks `get_post_type( $post_id )` against that list. If a user creates a private/internal CPT and assigns an `'always'` rule to it, the rule silently does nothing. Probably correct behaviour, but the UI should warn.

#### 5.2 (MEDIUM) `category` comparison casts term_id to string both sides
[class-schema-rules.php:147-148](includes/class-schema-rules.php#L147). `(string) $cat->term_id === (string) $value`. If `$value` is stored as int (rule editor saves as JSON number), this works. If `$value` is the slug (string), it also works because both `$cat->slug` and `$value` are compared raw. Defensive enough.

#### 5.3 (LOW) `sanitize_key()` lowercases the schema_key from rules
[class-schema-rules.php:103](includes/class-schema-rules.php#L103). `_ligase_enable_faq` etc. are already lowercase — fine. But `sanitize_key()` strips uppercase, so a future rename to `_Ligase_Enable_FAQ` would silently break.

#### 5.4 (LOW) Per-request cache `self::$cache` keyed by post_id only
[class-schema-rules.php:45](includes/class-schema-rules.php#L45). Two different builds for the same post in one request will hit the cache. Fine for normal flows; could cause stale data in long-running CLI scripts that mutate rules mid-run. `save_rules()` busts the cache, so it's correct in practice.

#### 5.5 (LOW) `generate_id()` collision risk
[class-schema-rules.php:223-225](includes/class-schema-rules.php#L223). `'rule_' . substr( md5( uniqid('', true) ), 0, 8 )` — 32 bits of entropy. Collision unlikely at this scale, but `wp_generate_uuid4()` would be 0 cost and bulletproof.

### File verdict: **GOOD**
Clean, well-bounded module. No security issues, sensible caching. Not really part of the auditor/suppressor critical path — it's a rules engine that runs at render time.

---

## 6. `admin/views/auditor.php` — **NEEDS WORK**

### What it does
Server-rendered admin page: lists detected plugins, exposes threshold/mode selectors, table for results.

### Findings

#### 6.1 (HIGH) Calls `new Ligase_Auditor()` without checking class exists
[admin/views/auditor.php:15](admin/views/auditor.php#L15). If autoload fails or the class file is missing, this view fatals. Defensive `class_exists()` check is missing.

#### 6.2 (HIGH) Mode dropdown wires UI to a runtime mode that does not exist
[admin/views/auditor.php:75-80](admin/views/auditor.php#L75). The select has options `scan`, `supplement`, `replace`. But:
- The `apply_audit_replacements` AJAX endpoint at [class-ajax.php:357](includes/class-ajax.php#L357) only accepts `replace` or `supplement` — never `scan`.
- The endpoint then calls `apply_replacement()` regardless of mode. [class-ajax.php:377](includes/class-ajax.php#L377) — `$mode` is read but not used. **`supplement` mode in the UI silently runs `replace`.**
- This is a security-of-intent issue: the user picks "Uzupelniaj" expecting their existing schema to be preserved with added fields; instead Ligase fully replaces it.

#### 6.3 (MEDIUM) Threshold input goes 0-100 step 5, default 50
[admin/views/auditor.php:68](admin/views/auditor.php#L68). UI default is 50; constructor default at [class-auditor.php:81](includes/class-auditor.php#L81) is 50. Consistent. But the threshold is sent to the AJAX endpoint that **ignores it** — see [class-ajax.php:345-395](includes/class-ajax.php#L345), no `threshold` parameter is read. The threshold setting is decorative.

#### 6.4 (MEDIUM) "Wlacz tryb standalone" hint in [admin/views/auditor.php:47](admin/views/auditor.php#L47) doesn't link to the toggle
Just a text description; user has to navigate to Settings manually. UX nit, but the suppressor's wrong filter names (Section 2) mean enabling standalone here will produce a broken result anyway.

#### 6.5 (LOW) No nonce on JS-side action triggers
The view renders buttons but doesn't print any nonce — relies on the global `wpApiSettings`-style nonce that's enqueued elsewhere. Hopefully wired correctly in `class-admin.php`; this file is fine.

#### 6.6 (LOW) All strings Polish, no i18n
Same i18n inconsistency as elsewhere — `esc_html_e()` is used (good!) but the strings themselves are Polish. The plugin should ship with a `.pot` and Polish `.po`.

### File verdict: **NEEDS WORK**
The mode dropdown's "supplement" option is misleading because the underlying AJAX path ignores it. Defensive class_exists missing.

---

## 7. `tests/unit/AuditorTest.php` — **CRITICAL**

### Findings

#### 7.1 (CRITICAL) Tests reference methods that don't exist
Already covered in Section 1.2 — `audit()` and `detect_plugins()` are tested but not implemented on `Ligase_Auditor`. **This file cannot run.** It's not "failing tests"; it's a syntax-valid file that crashes immediately on first call.

#### 7.2 (HIGH) `test_should_render_false_when_yoast_active_and_standalone_off()` defines `WPSEO_VERSION` and never undefines it
[AuditorTest.php:228-241](tests/unit/AuditorTest.php#L228). `define( 'WPSEO_VERSION', '24.0' )` persists for the rest of the PHPUnit run. Subsequent tests that need a "Yoast inactive" baseline will be polluted. Should use a mocking layer (Brain Monkey / WP_Mock) and never touch real constants.

#### 7.3 (HIGH) `test_supplement_mode_adds_missing_fields()` asserts `inLanguage` is added
[AuditorTest.php:206-208](tests/unit/AuditorTest.php#L206). Looking at the actual `supplement_schema()` at [class-auditor.php:492-549](includes/class-auditor.php#L492), `inLanguage` is **not** added — only headline/datePublished/dateModified/description/author/image/publisher. So even if `audit()` existed, this assertion would fail. Test written against an aspirational API.

#### 7.4 (MEDIUM) No tests for the `Suppressor`
Despite `Ligase_Suppressor` being safety-critical (Section 2's filter-name bugs), there is no `SuppressorTest.php`. One test in `AuditorTest` touches the suppressor at [228-241](tests/unit/AuditorTest.php#L228) but only verifies detection, not suppression.

#### 7.5 (MEDIUM) No tests for the `Importer`
Same — no `ImporterTest.php`. Migration is destructive and silent. Untested.

#### 7.6 (MEDIUM) No tests cover `Validator`
No `ValidatorTest.php`.

### File verdict: **CRITICAL**
The file would fatal on first run. The auditor subsystem has effectively **zero test coverage**.

---

# Cross-cutting issues

## A. Two sources of truth for plugin detection
- `Ligase_Auditor::KNOWN_SEO_PLUGINS` — keyed by display name, value is the plugin's main file path. Used by `get_detected_plugins()` which checks `is_plugin_active()`.
- `Ligase_Suppressor::KNOWN_PLUGINS` — keyed by short ID, value is array with `detect` constants/classes, filter list, display name. Used by `get_active_seo_plugins()`.

The lists overlap but don't match (see 1.12). On a site that has The Events Calendar:
- The Suppressor detects it (✅) and tries to suppress (with wrong filter).
- The Auditor's `get_detected_plugins()` does **not** include TEC, so the admin view's "Wykryte wtyczki" table will not show it.

Make `Ligase_Suppressor::KNOWN_PLUGINS` the single source of truth and have the Auditor consume it.

## B. Replace mode has no undo
- Backup written at [class-auditor.php:385](includes/class-auditor.php#L385) to `_ligase_replaced_schema`.
- Backup is never read by any class.
- No admin UI surface for "Restore original schema".
- Even if the user toggles standalone_mode off and reinstalls Yoast, the `_ligase_needs_own_schema` flag remains in post_meta forever — Ligase will keep generating its replacement even though the user moved away from Replace mode.

Need: a `revert_post( $post_id )` method that reads `_ligase_replaced_schema`, restores the original, deletes both meta keys. Admin UI button. Bulk action for "Revert all replacements".

## C. Mode terminology is inconsistent
- Auditor class constructor accepts `scan / supplement / replace`.
- Auditor public methods don't take a mode parameter at all (`apply_replacement` is implicitly "replace", `scan_post` is implicitly "scan"; "supplement" has no public method).
- AJAX endpoint accepts `replace / supplement` but uses neither.
- Admin view dropdown shows `scan / supplement / replace`.
- The constant `Ligase_Auditor::ALLOWED_MODES` at [class-auditor.php:43](includes/class-auditor.php#L43) lists all three but only the constructor consults it.

Pick a single contract. Either commit to all three modes end-to-end or drop "supplement" and "scan" from the UI.

## D. No filter for users to extend competitor list
Adding support for a new plugin (Squirrly, SmartCrawl, SEOZen, etc.) requires editing both classes. A `apply_filters( 'ligase_known_seo_plugins', ... )` would let the community extend without forking.

## E. Logger is fire-and-forget
`Ligase_Logger::info/warning/error` is called liberally. If logging goes to `error_log`, batch operations like `scan_all_posts` will produce thousands of log lines per run. No level threshold check is visible at call sites — runtime log noise.

---

# Competitor-by-competitor coverage table

| Competitor          | Detected?                                                                                       | Suppressed correctly?                                                                                              | Imported correctly?                                                                                                                                                          |
| ------------------- | ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Yoast SEO           | ✅ Auditor (file path) + Suppressor (`WPSEO_VERSION`)                                            | ❌ `wpseo_json_ld_output` is wrong; `wpseo_schema_graph_pieces` is wrong filter. Real switch: `wpseo_schema_graph`. | ⚠️ Org name OK, logo broken on Yoast 14+ (it's an ID, not a URL — `esc_url_raw('12345')` returns empty), social mostly OK, Twitter username heuristic OK.                    |
| Rank Math           | ✅ Both (`RANK_MATH_VERSION`)                                                                    | ❌ `rank_math/json_ld/disable` is wrong; `rank_math/schema/post_schemas` is a settings filter, not output gate.     | ⚠️ Org name OK, logo broken since v1.0.50 (ID not URL), phone OK, social OK.                                                                                                 |
| All in One SEO      | ✅ Both (`AIOSEO_VERSION`)                                                                       | ❌ `aioseo_schema_output` does not exist. `aioseo_schema_graph` is the right one.                                   | ❌ Social key names (`facebookUrl`, `twitterUrl`) match neither AIOSEO 3.x nor 4.x. AIOSEO 3.x option is serialized PHP, decoded as JSON → empty. AIOSEO 4.5+ uses different nested path. |
| SEOPress            | ✅ Both (`SEOPRESS_VERSION`)                                                                     | ❌ `seopress_schemas_output` does not exist. Correct: `seopress_schemas_single_json` (per-type).                    | ❌ Not implemented in importer at all. `Ligase_Importer::SOURCES` only lists yoast/rankmath/aioseo.                                                                            |
| The SEO Framework   | ✅ Suppressor only (auditor doesn't list it in `KNOWN_SEO_PLUGINS`)                              | ❌ `the_seo_framework_schema_output` is not a TSF filter. Use `the_seo_framework_use_schema`.                       | ❌ Not implemented in importer.                                                                                                                                              |
| Slim SEO            | ✅ Suppressor only                                                                               | ❌ `'filters' => []` — explicitly empty. Plugin detected but **never suppressed**.                                  | ❌ Not implemented in importer.                                                                                                                                              |
| The Events Calendar | ❌ Auditor lacks it; ✅ Suppressor lists `TEC_VERSION`                                            | ⚠️ `tribe_events_jsonld_enabled` works on TEC 5.x, deprecated in 6.x. Modern TEC will leak Event schema.           | ❌ Not implemented (and probably shouldn't be — TEC isn't an SEO plugin in the same sense).                                                                                  |
| Schema Pro          | ✅ Auditor only (Suppressor lacks it)                                                            | ❌ Not in suppressor's `KNOWN_PLUGINS` — no suppression attempted. Schema Pro will double up.                       | ❌ Not in importer.                                                                                                                                                          |
| Schema & Structured Data for WP | ✅ Auditor only                                                                       | ❌ Same as Schema Pro.                                                                                              | ❌ Not in importer.                                                                                                                                                          |

**Bottom line:** the suppressor list is bigger than the importer list and inconsistent with the auditor list. Even where suppression is attempted, the **filter names are wrong for all four major SEO plugins**. The actual safety net is `Ligase_Output::should_render()` declining to render when *any* SEO plugin is detected — which means default-mode users get no Ligase output, and standalone-mode users get **double schema** (Ligase + competitor) because the filters fail silently.

---

# Replace mode risks ranked by severity

| # | Risk                                                                                                                                                                                            | Severity | Where                                                                                                                    |
| - | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | ------------------------------------------------------------------------------------------------------------------------ |
| 1 | **No rollback.** Backup is written to `_ligase_replaced_schema` but no class ever reads it. No "Restore" button. User clicks "Apply" and the original is gone from view.                       | CRITICAL | [class-auditor.php:171,385](includes/class-auditor.php#L171); grep for `_ligase_replaced_schema` returns only writes.    |
| 2 | **Replace fires on non-Article schema (Event, Product, Recipe, LocalBusiness).** Scorer awards near-zero to anything without `headline`+`datePublished`. Threshold 50 default = aggressive replace. | CRITICAL | [class-auditor.php:207-265](includes/class-auditor.php#L207)                                                              |
| 3 | **"Supplement" mode in the UI silently runs Replace.** Mode dropdown sends `supplement` but the AJAX endpoint discards it and calls `apply_replacement()`.                                      | CRITICAL | [class-ajax.php:351,377](includes/class-ajax.php#L351); [admin/views/auditor.php:77](admin/views/auditor.php#L77)         |
| 4 | **Flag is sticky.** `_ligase_needs_own_schema` is set by `apply_replacement()` but `Ligase_Output::needs_own_schema()` only reads, never deletes. The post stays in "replaced" state forever, even after uninstalling competitor and removing Replace mode. | HIGH     | [class-auditor.php:434](includes/class-auditor.php#L434) vs [class-output.php:75](includes/class-output.php#L75)         |
| 5 | **Race with suppressor.** Replace removes the competitor's `<script>` from the buffer (`str_replace($full_tag, '', $buffer)`) BUT the suppressor (when active) has already tried to filter it out — if suppression worked, the regex won't match anything; if suppression failed, the str_replace will run. Not safety-critical but means logging is inaccurate ("Replaced schema for post X" even when nothing was there). | MEDIUM   | [class-auditor.php:175](includes/class-auditor.php#L175)                                                                  |
| 6 | **Threshold parameter is decorative.** UI lets user set threshold; AJAX endpoint ignores it; underlying `apply_replacement()` uses `$this->threshold` which is the constructor default 50.   | MEDIUM   | [class-ajax.php:345-395](includes/class-ajax.php#L345); [class-auditor.php:81](includes/class-auditor.php#L81)            |
| 7 | **No dry-run.** No "preview which posts would be affected" before clicking Apply. The admin view scans then applies in two separate clicks, but the second click is irreversible.            | MEDIUM   | [admin/views/auditor.php:109](admin/views/auditor.php#L109)                                                               |
| 8 | **`apply_replacement()` does not verify post type.** Will mark a `attachment` or custom CPT (where competitor's schema may be intentional and Ligase has no equivalent type) just as readily as a blog post. | MEDIUM   | [class-auditor.php:369-396](includes/class-auditor.php#L369)                                                              |
| 9 | **Buffer mutation runs every request when `intercept()` is wired.** If a future change wires `intercept()` to `wp_head`, every page render does regex parse + decode + score on every JSON-LD block. No per-request cache. | LOW (latent) | [class-auditor.php:115](includes/class-auditor.php#L115)                                                                  |

---

# Top 5 fixes (in order)

### Fix 1 — Replace the suppressor's filter list with the actually correct hooks
Without this, **standalone mode is a lie**. Every Yoast / Rank Math / AIOSEO / SEOPress / TSF / Slim SEO / TEC user enabling standalone mode gets double schema. Concrete changes:

```php
'yoast' => [ ..., 'filters' => [
    [ 'wpseo_schema_graph', '__return_empty_array' ],
    // optional secondary kill switch for breadcrumb-only:
    [ 'wpseo_breadcrumb_output_class', '__return_false' ],
] ],
'rankmath' => [ ..., 'filters' => [
    [ 'rank_math/json_ld', '__return_empty_array' ],
] ],
'aioseo' => [ ..., 'filters' => [
    [ 'aioseo_schema_disable', '__return_true' ],
    [ 'aioseo_schema_graph', '__return_empty_array' ],
] ],
'seopress' => [ ..., 'filters' => [
    [ 'seopress_schemas_single_json', '__return_empty_array' ],
    [ 'seopress_pro_schemas_array', '__return_empty_array' ],
] ],
'the_seo_framework' => [ ..., 'filters' => [
    [ 'the_seo_framework_use_schema', '__return_false' ],
] ],
'slim_seo' => [ ..., 'filters' => [
    [ 'slim_seo_schema_data', '__return_empty_array' ],
] ],
'the_events_calendar' => [ ..., 'filters' => [
    [ 'tribe_events_jsonld_enabled', '__return_false' ],
    [ 'tribe_events_view_v2_jsonld_enabled', '__return_false' ],
] ],
```
Plus integration test: install each competitor in a Docker WP instance, enable Ligase standalone, assert page source contains exactly one `<script type="application/ld+json">` block with Ligase's signature.

### Fix 2 — Implement a real "Revert" path for Replace mode
Add to `Ligase_Auditor`:
```php
public function revert_post( int $post_id ): bool {
    if ( ! current_user_can( 'edit_post', $post_id ) ) return false;
    $backup = get_post_meta( $post_id, '_ligase_replaced_schema', true );
    if ( ! $backup ) return false;
    delete_post_meta( $post_id, '_ligase_needs_own_schema' );
    delete_post_meta( $post_id, '_ligase_replaced_schema' );
    // Don't restore the schema text — the competitor will regenerate on next render.
    return true;
}
public function revert_all(): array { ... }
```
Expose `wp_ajax_ligase_revert_replacement` and a "Revert" column in the auditor view. Run on uninstall via `uninstall.php` to clean both meta keys.

### Fix 3 — Honour the mode and threshold parameters in the AJAX endpoint
[class-ajax.php:345-395](includes/class-ajax.php#L345):
```php
$threshold = isset( $_POST['threshold'] ) ? max(0, min(100, absint( $_POST['threshold'] ))) : 50;
$mode      = sanitize_text_field( wp_unslash( $_POST['mode'] ?? 'scan' ) );
$auditor   = new Ligase_Auditor( $threshold, $mode );
foreach ( $post_ids as $post_id ) {
    if ( $mode === 'scan' )        { $results[] = $auditor->scan_post( $post_id ); }
    elseif ( $mode === 'replace' ) { $results[] = [ 'post_id' => $post_id, 'success' => $auditor->apply_replacement( $post_id ) ]; }
    elseif ( $mode === 'supplement') { $results[] = [ 'post_id' => $post_id, 'success' => $auditor->apply_supplement( $post_id ) ]; }
}
```
Then implement `apply_supplement()` properly (read live schema, run `supplement_schema()`, write a post-level filter to inject extra fields on render).

### Fix 4 — Fix the scorer for non-Article schemas
Either:
- (a) detect `@type` first, dispatch to per-type scorers (Article, Event, Product, LocalBusiness, ...), or
- (b) explicitly opt-out: if `@type` is not in `['Article','BlogPosting','NewsArticle','TechArticle']`, return 100 (don't recommend replacement). The current behaviour — scoring Events at 0 and replacing them with BlogPosting — is actively destructive SEO.

Bonus: drop the 696px image threshold; replace with Google's actual current minimum (1200×675 for Article rich result imageObject, 50×50 for Organization logo). Or remove image-size scoring entirely and let `validate_*` handle it.

### Fix 5 — Make the test suite actually run
Either restore the missing `audit()` and `detect_plugins()` methods on `Ligase_Auditor` (probably the intent — a unified entry that takes a schema array + mode + opts and returns `[ 'schema' => ..., 'score' => ..., 'action' => ..., 'below_threshold' => ... ]`), or rewrite the tests to use the actual public surface (`score()`, `scan_post()`, `apply_replacement()`). Add:
- `SuppressorTest.php` — given a fake Yoast constant defined, assert `add_filter` was called with the right hook names (use a filter-capture helper).
- `ImporterTest.php` — given fake `wpseo_titles` / `rank-math-options-titles` / `aioseo_options`, assert `ligase_options` contains expected mapped values.

Without tests, the next refactor will silently re-break the wrong-filter-name bugs and the broken Yoast logo import.

---

# Summary table

| File                                                                          | Verdict       | Top issue                                                                                                                |
| ----------------------------------------------------------------------------- | ------------- | ------------------------------------------------------------------------------------------------------------------------ |
| [`includes/class-auditor.php`](includes/class-auditor.php)                    | **CRITICAL**  | `intercept()` unreachable, scoring unfair to non-Article, replace mode has no consumer for backup                        |
| [`includes/class-suppressor.php`](includes/class-suppressor.php)              | **CRITICAL**  | Wrong filter names for Yoast, Rank Math, AIOSEO, SEOPress, TSF; Slim SEO empty                                           |
| [`includes/class-importer.php`](includes/class-importer.php)                  | NEEDS WORK    | Logo keys stale (Yoast/RankMath store IDs not URLs); AIOSEO social keys don't match v4.x; SEOPress + TSF + Slim SEO absent |
| [`includes/class-validator.php`](includes/class-validator.php)                | GOOD          | Validates Ligase's own output only — can't validate competitor schema                                                    |
| [`includes/class-schema-rules.php`](includes/class-schema-rules.php)          | GOOD          | Small i18n / collision nits                                                                                              |
| [`admin/views/auditor.php`](admin/views/auditor.php)                          | NEEDS WORK    | Mode dropdown lies — "supplement" silently runs replace                                                                  |
| [`tests/unit/AuditorTest.php`](tests/unit/AuditorTest.php)                    | **CRITICAL**  | Calls `audit()` and `detect_plugins()` which don't exist; whole file fatals                                              |

The differentiating feature is, in its current state, **not safe to ship to non-technical users**. The auditor table will display "Yoast detected" with green-looking UI, the user will click Replace, Yoast will keep emitting schema next to Ligase, and the user has lost control over the rich result for that page until they go into the database and delete `_ligase_needs_own_schema` for each affected post.
