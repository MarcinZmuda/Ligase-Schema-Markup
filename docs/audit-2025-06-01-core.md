# Core pipeline audit — 2025-06-01

Scope: bootstrap, plugin singleton, generator, output renderer, cache (+ bypass), suppressor,
field contract / resolver, validator, logger, multilingual, schema-rules, auditor, importer.
Cross-references where relevant: types/class-organization.php, types/class-blogposting.php,
types/class-localbusiness.php, entities/class-pipeline.php, entities/class-wikidata-lookup.php,
includes/class-ner-api.php, uninstall.php.

Audit posture: ship-blocker first. "CRITICAL" means a real visitor-side or admin-side break
with a reproducible path. "HIGH" means a correctness bug in a code path that DOES run.
"MEDIUM" means cache/perf/integration gap that hurts at scale but won't crash today.

------------------------------------------------------------------------------------------

## CRITICAL — fatal / data loss risks

### C1. Generator switch has a duplicate `case 'blog_listing'` — dead arm and fall-through bug
File: `includes/class-generator.php:57-77`

```php
case 'blog_listing':
    $graph[] = $this->build_collection_page();
    $graph[] = ( new Ligase_Type_BreadcrumbList() )->build();
    break;
...
case 'blog_listing':                       // line 73 — duplicate
    // (already handled above; ItemList added there too via fall-through fix)
case 'date_or_search':
    $graph[] = $this->build_collection_page();
    break;
```

This is not just dead code — PHP allows duplicate `case` labels but the SECOND one is
unreachable, so the comment "ItemList added there too via fall-through fix" is a lie:
`blog_listing` cannot fall through to `date_or_search` because it already broke at line
60. The first arm doesn't append `Ligase_Type_ItemList`, only the rescue block at
lines 84-91 does — meaning the only reason ItemList renders on the WP "Posts page" is the
post-switch `in_array( $resolved_context, [ 'blog_listing', 'unknown' ], true )` check.

Risk: medium-severity correctness bug rather than a fatal — but PHPStan / php -l on
strict-types projects will flag it, and a maintenance edit that "fixes" the comment by
deleting the rescue block will silently break ItemList on blog index. Some linters in CI
will reject the file outright.

Repro: load `/blog/` on a site where "Settings → Reading → Posts page = Blog" → ItemList
appears; remove the rescue at lines 84-91 thinking the fall-through covers it → ItemList
disappears.

Fix: delete the second `case 'blog_listing':` line; move the ItemList append into the
first arm explicitly.

### C2. `Ligase_Generator::with_post_globals()` mutates `$wp_query` without instantiation check
File: `includes/class-generator.php:221-265`

`global $wp_query;` is used, then `$wp_query->is_singular = true;` etc. Reading via
`$wp_query->is_singular ?? false` is safe, but the WRITES `$wp_query->is_singular = true`
will fatal-error with "Attempt to assign property … on null" if `$wp_query` is null
(possible on very early `wp_head` triggered before `template-loader.php` ran, e.g. AMP
plugins that short-circuit, custom REST renders calling `do_action('wp_head')`, some
sitemap render paths, or any plugin that fires `wp_head` in a CLI context).

Repro: any context where `$wp_query` is null at output time (uncommon but real on AMP
Web Stories renderers and some headless setups using `do_action('wp_head')` to harvest
metadata). Result: HTTP 500 on the affected page; `Ligase_Output::render()` already
guards the surrounding flow but a fatal here aborts the whole request.

Fix: `if ( ! ( $wp_query instanceof WP_Query ) ) { $fn(); return; }` at top of method,
or only mutate when not null.

### C3. `Ligase_Auditor::intercept()` is dead code — no caller registers it
File: `includes/class-auditor.php:91-103`

`intercept()` calls `ob_start( [ $this, 'process_buffer' ] )` but no `add_action` /
`add_filter` anywhere wires it to `wp_head` or anything else. Grep for "intercept" finds
only this method. The auditor's documented "intercept wp_head, score, replace/supplement"
flow never runs on the frontend. The only working paths are admin-side `scan_post()` /
`apply_replacement()` / `apply_supplement()` invoked from AJAX.

Consequence: setting auditor mode to `replace` or `supplement` in admin only mutates
post meta during explicit admin scans — it never modifies live wp_head output
opportunistically. Users who trust the docs will believe schema replacement happens on
every page load; in reality it only happens after they push a button.

Repro: enable replace mode → load a post → existing competitor schema is NOT removed
from wp_head until you explicitly run the AJAX scanner.

Fix: either (a) register `intercept` on `wp_head` at priority 1 when the option is on,
or (b) explicitly document that this is admin-scan-only. Recommended (b) — frontend
output buffering of wp_head is extremely fragile (interferes with `wp_print_styles`,
`wp_print_scripts`, AMP, ESI, page-cache pre-render). The "do_action('wp_head')"
already in `get_jsonld_for_post()` at `:1117` is the right pattern.

### C4. `Ligase_Cache::invalidate_post()` doesn't account for term/user/context cache keys
File: `includes/class-cache.php:17-26` vs. `includes/class-output.php:22-35`

Output builds 4 cache key shapes:
- `ligase_{post_id}_{locale}_{ver}` (WP_Post)
- `ligase_term_{term_id}_{locale}_{ver}` (WP_Term)
- `ligase_user_{user_id}_{locale}_{ver}` (WP_User)
- `ligase_ctx_{search|home|front|date|other}_{locale}_{ver}` (no queried object)

`invalidate_post()` only deletes the WP_Post key. When a post is saved, its taxonomy
archive pages, the home/blog listing, the author archive, and the front page CollectionPage
all keep serving stale JSON-LD for up to 12 hours. On a publishing site this means
"published a post" → "the post appears in the blog rich result on Google" is decoupled
by up to 12h.

Repro: publish a new post → load /blog/ → `application/ld+json` ItemList does not include
the new post until cache TTL expires.

Fix: on save_post, also delete the cached "ligase_ctx_home", "ligase_ctx_front", the
author key for the post's author, and the term keys for every category/tag attached.
Or: switch to a versioned cache key (`ligase_ver` option bumped on save_post → all
existing transients ignored).

### C5. `wp_loaded` is too late to suppress Yoast / Rank Math reliably
File: `includes/class-plugin.php:100`

```php
add_action( 'wp_loaded', [ Ligase_Output::class, 'maybe_suppress_early' ] );
```

`wp_loaded` fires once, AFTER all plugins are loaded but BEFORE most plugins' own init
hooks. For schema filters like `wpseo_schema_graph` / `rank_math/json_ld` this is
usually fine because the filters fire at wp_head. BUT some competitors register their
"final output" filter inside `wp_head` at priority < 5 (TSF, Slim SEO) by adding
`add_action('wp_head', ..., 1)` from their OWN plugins_loaded handler. If Ligase loads
LATER in the include order (alphabetical / dependency order), the suppressor adds the
filter AFTER the competitor has already echoed.

In addition, `maybe_suppress_early()` itself only runs when `standalone_mode` option is
set. For users with `force_output = 1` (the other escape hatch), nothing suppresses —
two `<script type="application/ld+json">` blocks appear in head simultaneously, and
Google de-dupes inconsistently (https://developers.google.com/search/blog/2022/04…).

Repro: enable `force_output` with Yoast active → page source shows 2 BlogPosting nodes,
one from Yoast and one from Ligase, with different `@id`. Google sees two competing
publishers.

Fix: hook suppressor on `plugins_loaded` priority 999 (after every competitor's filter
registration) OR keep `wp_loaded` but ALSO run suppressor in `force_output` mode.
Better still: register the filters unconditionally at plugin-load time — `add_filter`
on a hook that doesn't yet exist is harmless.

### C6. Suppressor `is_active` static state leaks across requests in long-running PHP
File: `includes/class-suppressor.php:8`, `class-suppressor.php:155, 178, 190-192`

`private static bool $is_active` is a class-level static — fine in mod_php. Under
PHP-FPM with opcache+jit and long-lived workers, FastCGI keeps the worker alive across
requests; if a request enables standalone_mode then a subsequent request on the same
worker observes `is_active === true` even though no `suppress_all()` ran for it. WP
re-bootstrap clears `$this->suppressed` (instance) but NOT `self::$is_active` (class
static).

Result: code that reads `Ligase_Suppressor::is_active()` to decide "should I emit my
own schema" can wrongly suppress on requests where no competitor filters were actually
added. Today only one consumer (admin readiness panel) reads this — but it's a footgun
for future code.

Fix: drop `self::$is_active` or reset it at the top of `suppress_all()` /
`get_active_seo_plugins()`. Alternatively, derive it from `! empty( $this->suppressed )`
on an instance basis.

### C7. `Ligase_Output::render()` cache stores the full HTML — XSS-fix scrubber bypassed on cache hit
File: `includes/class-output.php:78, 85, 39-42`

This is actually OK by design (cached value IS the scrubbed HTML), but flagged to
verify: cache `set()` happens at line 85 after the `str_replace('</', '<\/', ...)`
scrubber at line 78, and cache `get()` at line 39 echoes whatever is stored. As long as
the scrubber is on the write path, the cache is safe.

HOWEVER — if a future commit moves the scrubber out of `render()` into a separate
filter, the cache will retain unscrubbed payloads from before the move (no version bump
needed for that change). Risk-mitigation: the `LIGASE_VERSION` is in the cache key, so
bumping the plugin version on any change to the scrubber path will purge.

Verdict: not a current bug, but document the invariant "scrubber MUST run before
`Ligase_Cache::set`".

------------------------------------------------------------------------------------------

## HIGH — correctness

### H1. `match (true)` in `build_webpage` has a default arm — OK; but auto-detection by slug only handles 4 hard-coded slugs
File: `includes/class-generator.php:329-334`

```php
$type = match ( true ) {
    in_array( $slug, [ 'about', 'o-nas', 'about-us', 'o-mnie' ], true ) => 'AboutPage',
    in_array( $slug, [ 'contact', 'kontakt', 'contact-us' ], true )     => 'ContactPage',
    default                                                              => 'WebPage',
};
```

No fatal — `default` covers it. But a Polish site with `/o-firmie/`, `/kontakt-z-nami/`,
`/zespol/` will silently get `WebPage` instead of the AboutPage/ContactPage subtype.
Knowledge-graph quality signal lost. Consider: filter `ligase_webpage_type_for_slug` or
move the map to options.

### H2. `Ligase_Output::should_render()` returns `false` when ANY competitor plugin is detected
File: `includes/class-output.php:97-123`

The default (no `force_output`, no `standalone_mode`) is "don't render if any competitor
is active". Combined with C5, this means the COMMON case for a user who installs Ligase
on a Yoast site is: Yoast continues to emit its (possibly weak) schema, Ligase emits
nothing, and the user has no idea why their score panel doesn't reflect rich Person /
sameAs / about data.

UI signal: the admin panel needs a prominent "Schema output is suppressed because
{competitor} is active. Switch to Standalone Mode to use Ligase's schema instead."
banner — otherwise users believe Ligase is broken.

Repro: fresh Ligase install on a Yoast site → wp_head source still shows Yoast's
schema and zero Ligase nodes. The `should_render` logs an info-level message but
debug_mode is off by default.

Fix: same code, but add admin notice OR switch the default to "supplement with @id
references that link Yoast's @graph to Ligase's Person/Organization detail".

### H3. `Generator::resolve_context` calls `is_front_page()` etc. without function_exists guards EXCEPT for line 135 onward — internally inconsistent
File: `includes/class-generator.php:115, 117`

```php
if ( function_exists( 'is_home' ) && is_home() && ! is_front_page() ) {
```

`is_home()` is guarded but `is_front_page()` is not (called bare). `is_front_page` is
a core WP function so the guard isn't strictly needed, but the inconsistency suggests
the guard exists because the code runs in contexts where `$wp_query` is missing. If
that's the concern, `is_front_page()` will also throw because it ultimately reads
`$wp_query->is_front_page`.

Likely a non-issue in production (REST/AJAX requests don't reach `render` because
`should_render` filters them implicitly via `is_404` guard plus the fact that wp_head
doesn't fire on REST), but worth a consistency pass.

### H4. `Generator::get_graph_for_post()` (AJAX preview) does not honor `author_is_organization` flag
File: `includes/class-generator.php:271-316` vs. `:152-172`

The live render at `add_blog_post_graph` skips the Person node when
`author_is_organization` returns true (per-user `ligase_is_redakcja` or site-wide
`org_author_mode`). The preview at `get_graph_for_post` unconditionally appends Person:

```php
$graph[] = ( new Ligase_Type_Person( $author_id ) )->build();
```

Consequence: validator (which calls `get_graph_for_post` via `validate_post`) will see
a Person node for a redakcja author, but the live page will not. User sees green check
in admin and a "missing Person" in some other tool.

Fix: replicate the `author_is_organization` gate.

### H5. `Field_Resolver::resolve()` builds `@id` using `get_permalink($post_id)` — falsy permalink for unpublished posts
File: `includes/class-field-resolver.php:87`

```php
'@id'   => esc_url( get_permalink( $post_id ) ) . '#' . strtolower( $type ),
```

`get_permalink()` returns `false` for unpublished/trashed posts. `esc_url(false)` returns
empty string, so `@id` becomes `'#product'` — a relative fragment, not a stable global
ID. Any AJAX-preview call against a draft will emit broken @id references that other
nodes in the graph try to link to.

Repro: editor opens a draft, the Ligase block shows preview → resolver returns
`@id = "#product"`.

Fix: fall back to `home_url('/?p=' . $post_id) . '#' . $type` when permalink is empty.

### H6. `Field_Resolver::resolve_wc_source('availability')` returns `InStock` by default
File: `includes/class-field-resolver.php:208-215`

```php
$stock = $product->get_stock_status();
$map   = array( 'instock' => ..., 'outofstock' => ..., 'onbackorder' => ... );
return $map[ $stock ] ?? 'https://schema.org/InStock';
```

If WooCommerce ever introduces a new stock status (e.g. `preorder`, `discontinued`,
`reserved`), the unknown status silently maps to `InStock`. This is potentially a
manual-action risk — Google policy classifies wrong availability as misleading.

Fix: return `null` (drop the offers.availability) on unknown status, so the resolver
treats it as missing and the gate-logic in `class-product.php` drops the whole offer
block (preferable to lying).

### H7. `Field_Resolver::sanitize` 'float' / 'int' coerce arbitrary strings to 0 silently
File: `includes/class-field-resolver.php:380-383`

```php
case 'int':   return (int) $value;
case 'float': return (float) $value;
```

For `offers.price`, a Polish-formatted "1 299,90 zł" coerces to `(float) 1.0` (PHP
parses "1" then stops at space) — a 1300x price misstatement. WooCommerce always stores
numeric strings (decimal-dot), but the manual override path stores whatever the editor
typed.

Fix: validate format before coercion; reject non-numeric and return null so the gate
drops the offer.

### H8. `Generator::add_cpt_single_graph` emits `WebPage` for ALL non-post non-page CPT
File: `includes/class-generator.php:192-209`

Including products. A WC product page therefore gets a `WebPage` node AND a `Product`
node (from `get_optional_types`), both with `@id = permalink`. Two nodes with the same
`@id` violates schema.org's identity rule — Google merges them but warns in Search
Console. Should be either WebPage @id with `#webpage` fragment or omit WebPage entirely
for CPTs that have a primary schema (Product/Recipe/Event).

Fix: for known primary-schema CPTs (product, recipe, event, job_listing), skip the
WebPage emit; rely on the type's own `@id` (which uses `#product` etc. fragments).

### H9. `Output::render()` cache key omits per-author scope — same post for different visitors gets same cache
File: `includes/class-output.php:24`

Not a bug today because output doesn't vary by visitor — but if a future feature gates
schema fields on user role (e.g. "show pricing only to logged-in B2B users"), the cache
key as-is will leak the logged-in variant to logged-out visitors. Future-proofing:
add a `is_user_logged_in() ? 'u' : 'g'` segment OR document "schema MUST NOT vary by
viewer".

### H10. Validator `validate_post` calls Generator's preview path, which differs from production
File: `includes/class-validator.php:42-44` → `Generator::get_graph_for_post`

Combined with H4 (preview emits Person where production wouldn't), the validator's
green check is unreliable for redakcja authors. Also: preview doesn't run the
`ligase_schema_graph` filter chain in the same context as production (it does call
`apply_filters` but `is_singular` etc. report differently because `get_graph_for_post`
uses bare `$GLOBALS['post']` swap without the full `$wp_query` setup that
`with_post_globals` does).

Fix: have Generator expose a single entry point used by both paths (`get_graph_for_context`)
and have AJAX preview pass `WP_Post` to it; remove the duplicate emit logic.

### H11. `Ligase_Type_Organization::build()` queries ALL published-post authors on EVERY page render
File: `includes/types/class-organization.php:84-92`

```php
$authors = get_users( [ 'has_published_posts' => true, 'fields' => 'ID' ] );
```

On a site with 50 authors, this is fine. On a multi-author site with 5000 users (which
exists for SaaS-backed publishers, community sites) `get_users` with
`has_published_posts` runs a JOIN query against `wp_posts`. Wrapped in the schema cache
so it runs once per cache miss per page, but cache misses happen on every save_post
and during cron, AND when invalidate_all() is triggered (e.g. settings save), every
cached page rebuild re-runs this query.

Risk: settings-save → full cache wipe → next 100 page requests each rebuild graph,
each calling this query → DB load spike. Add per-request memoization at minimum.

Fix: cache the author list separately with a longer TTL keyed off the published-author
list signature; invalidate on `profile_update` / `user_register` / `delete_user` /
`save_post` only when `post_status === 'publish'` toggles.

### H12. `Ligase_Type_Organization` emits `employee` listing as flat array of `@id` refs only
File: `includes/types/class-organization.php:89-92`

Schema-wise this is fine (graph @id resolution), but Google's Knowledge Graph parser
historically did NOT follow `@id` to a separate Person node for `employee`. Either keep
the full `{ '@type':'Person', '@id':..., 'name':... }` shape for `employee` entries OR
omit `employee` entirely and let the per-page Person node carry the relationship.

Cosmetic — not a fatal — but adds bloat to the @graph on every page without giving Google
the data shape it actually consumes. On a 5000-author site, this is several KB of JSON
per page (worth noting alongside H11).

### H13. `Ligase_Auditor::supplement_schema()` writes new `<script>` tag without `JSON_UNESCAPED_UNICODE`-only encoding — extra encoding flags mismatch
File: `includes/class-auditor.php:182`

```php
$new_json = wp_json_encode( $supplemented, JSON_UNESCAPED_UNICODE );
```

Compare with `Ligase_Output::render()` at `class-output.php:62-65` which uses
`JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT`. Inconsistent but not a bug. The scrubber
at line 185 runs after `wp_json_encode`, good.

But — `wp_json_encode` can fail (return false). Auditor doesn't check before string-replacing:

```php
$new_json     = str_replace( [ '</', '<!--' ], [ '<\/', '<\!--' ], $new_json );
```

If `$new_json === false`, `str_replace` returns empty string, then the `<script>` tag
has an empty body, then `str_replace($full_tag, $new_tag, $buffer)` REPLACES the
competitor's schema with an empty script tag. Net effect: schema disappears entirely.

Fix: check `$new_json === false` and skip the replacement (return early, log error).

### H14. `Ligase_Auditor::supplement_schema()` overwrites `author` even when existing has only `@id` reference
File: `includes/class-auditor.php:780-789`

```php
if ( empty( $schema['author'] ) ) {
    $author_id        = (int) $post->post_author;
    $schema['author'] = array( array( '@type'=>'Person', '@id'=>..., 'name'=>... ) );
}
```

`empty()` returns true for arrays with all-falsy values; for `[ '@id' => 'home/#author-1' ]`,
empty() is FALSE so the branch is skipped. Good. But if existing author is `[]` (empty
array from a buggy upstream encoder), this overwrites with a fully formed Person — OK.

Risk path: Yoast emits author as a single string name (some older versions did). 
`empty("Jan Kowalski") === false`, so Ligase doesn't supplement. Then Person's @id link
from BlogPosting points nowhere. Cosmetic, not fatal.

### H15. Importer `import_yoast` reads `wpseo_titles['company_logo']` but Yoast also stores `company_or_person_user_id` for the Person mode
File: `includes/class-importer.php:120-127`

Yoast supports both Organization mode and Person mode. If Yoast site uses Person mode
(`company_or_person` = 'person'), `company_logo` is empty and the avatar URL lives at
`person_logo` or `wpseo_user_meta`. Ligase only imports Organization mode → user with
Person-mode Yoast gets nothing imported.

Fix: read `wpseo_titles['company_or_person']`; if 'person', map `wpseo_titles['person_logo']`
and `company_or_person_user_id` → Ligase's `org_founder_id` + reuse the logo.

------------------------------------------------------------------------------------------

## MEDIUM — cache, integration, multilingual, NER

### M1. Cache invalidation does not wire `created_term` / `edited_term` / `deleted_term`
Files: `includes/class-plugin.php:104-138`, `class-cache.php`.

Renaming a category doesn't bust the cached CollectionPage / BreadcrumbList JSON-LD for
that term. Users editing terms (common: "rename slug, fix typo in name") observe stale
schema for up to 12h. Hook `edited_term` / `deleted_term` and call
`Ligase_Cache::invalidate_post()` for posts in that term (use `get_objects_in_term`).

### M2. Cache invalidation does not wire `set_object_terms` (post-to-term assignment changes)
File: `includes/class-plugin.php:104`.

Changing a post's category/tag changes its `articleSection` / `BreadcrumbList`. We
only invalidate on `save_post`, but `set_object_terms` can fire WITHOUT save_post (REST
PATCH on `taxonomy=category` endpoint, WP-CLI `wp post term set`). Add the hook.

### M3. WP Rocket / LiteSpeed page cache cooperation only happens via `Ligase_Cache_Bypass` and only on AJAX
File: `includes/class-cache-bypass.php:15-28`.

The page cache plugin's snapshot of the post page still contains the OLD `<script type=…>`
block until the page cache itself invalidates (which save_post triggers in WP Rocket,
but only for the post being saved — not for /blog/, archives, etc.). Combined with C4,
the visible bug is "I updated a post — Google sees stale schema for 12h on archive pages".

Fix: extend `flush_post_cache()` to also flush archive URLs (blog index, author archive,
each category/tag archive) when a post is saved.

### M4. `Ligase_Cache::invalidate_post()` only knows current locale — doesn't invalidate translated variants
File: `includes/class-cache.php:18-21`.

```php
$locales = [ get_locale() ];
```

Hardcoded single-element array (vestigial — the foreach is now redundant). On a WPML
site, saving the Polish post does NOT invalidate the English transient. Stale English
JSON-LD until TTL or until the English post is independently saved.

Fix: when multilingual is active, enumerate languages via
`Ligase_Multilingual::get_translation_urls( $post_id )` and invalidate one cache key per
language.

### M5. `Ligase_Multilingual::get_current_language` returns full WP locale string when no MU plugin
File: `includes/class-multilingual.php:54`.

Returns `pl_PL` from `get_locale()` when neither WPML nor Polylang is active. But
`get_language_tag()` at :60 then does `str_replace('_', '-', ...)` → `pl-PL`. The map at
:64-75 only matches 2-letter codes, so `pl_PL` (5 chars) skips the map and goes through
`str_replace`. Effectively correct but indirect.

The bug: when WPML is active, `apply_filters('wpml_current_language', get_locale())`
returns the WPML 2-letter code (e.g. `pl`), then `get_language_tag()` maps it to
`pl-PL` via the array. Correct. But `get_current_language()` falls back to `get_locale()`
when WPML returns empty — `get_locale()` returns 5-char `pl_PL`, then the map check
(strlen===2) fails, and we return `pl-PL` via str_replace anyway. Coincidentally works.

Fix: simplify by always extracting the 2-letter prefix then mapping. Today's code works
by accident.

### M6. `Multilingual::augment_blogposting` overrides `inLanguage` but `Ligase_Type_BlogPosting` already sets it
File: `includes/class-multilingual.php:140-141`, `types/class-blogposting.php:63`.

BlogPosting sets `inLanguage = str_replace('_', '-', get_locale())` unconditionally, e.g.
`pl-PL`. Multilingual filter then overrides it with `get_language_tag()` which ALSO
returns `pl-PL`. Same value, no bug, but the filter does work — confirmed OK.

### M7. NER cron events scheduled with `array( $post_id )` args; `wp_clear_scheduled_hook` in uninstall.php clears them
File: `uninstall.php:23-30`.

Good — `wp_clear_scheduled_hook` with NO args removes ALL scheduled events for that hook
regardless of how they were scheduled (single-event with args included). Verified.

But during DEACTIVATE (not uninstall), `ligase.php:55-61` does the same `wp_clear_scheduled_hook`
calls. Good. So both paths cleaned up. ✅

### M8. `Ligase_NER_API::extract()` cache key uses `$post->post_modified` — race condition on rapid edits
File: `includes/class-ner-api.php:75`.

```php
$cache_key = 'ligase_ner_api_' . $post_id . '_' . md5( $post->post_modified );
```

Save twice within the same second → same `post_modified` value (MySQL DATETIME has 1s
granularity) → same cache key. The first call's API result is stored under that key;
the second call gets the cached result, missing any change made in the second save.

In practice: editor clicks "Save" → "Update" within 1 second → second save reads cached
results from first save. Unlikely to actually trigger because the cron schedule batches
to time + 5s, but if scheduled is bypassed (sync `extract` call), it can.

Fix: include `strlen( $post->post_content )` or a content hash in the cache key.

### M9. `Ligase_Wikidata_Lookup` doesn't invalidate cache when LLM API key rotates
File: `includes/entities/class-wikidata-lookup.php` + `class-ner-api.php`.

User changes NER provider from OpenAI to Anthropic → existing transients keyed on
post_modified hash continue to serve old OpenAI results. Worse: if user disables NER
entirely, the cron still tries to process — `is_configured()` returns false, schedule
silently skips. OK on functional side, but `_ligase_ner_api_results` post meta still
shows old OpenAI data forever.

Fix: when `ligase_options` changes `ner_provider` or `ner_api_key`, run an option-listener
that deletes `_ligase_ner_api_results` for all posts (or a flag to mark them stale).

### M10. Logger `maybe_rotate` race between concurrent web requests
File: `includes/class-logger.php:220-250`.

`maybe_rotate` is called every log write under a `LOCK_EX` for the WRITE but not for
the rotate. Two concurrent requests on a busy site can both pass the size check, then
both rename simultaneously — one wins, one fails silently (`rename` overwrites on
POSIX). Result: occasional log line lost. Not a data-loss vector for production data
(only debug logs), so MEDIUM not HIGH.

Fix: wrap rotation in `flock` on a sentinel file.

### M11. Logger `is_debug_enabled()` reads `ligase_options` on every log call — minor overhead
File: `includes/class-logger.php:123-131`.

`get_option` is internally object-cached after first call within a request, but
`get_instance()` → `is_debug_enabled` → `get_option` runs for every `Ligase_Logger::debug`
call. With debug_mode off (the common case), it returns false fast. With it on, every
`debug()` writes — fine. Just noting that a runtime cache (static var) would shave
microseconds per call.

### M12. Importer `Ligase_Importer::detect_sources` does not check whether the plugin is currently active
File: `includes/class-importer.php:29-56`.

`get_option('wpseo_social')` returns data even when Yoast is DELETED (rows persist in
wp_options unless Yoast's uninstall handler ran). So "Yoast detected" = "Yoast was
once installed". The importer happily imports old logo IDs that may no longer resolve.

Fix: combine with `is_plugin_active(...)` check OR document that this is "detect legacy
data" rather than "detect active plugin".

### M13. Importer overwrites only when `$opts['org_name']` is empty — but doesn't merge sameAs arrays intelligently
File: `includes/class-importer.php:140-148`.

Each social key check: `if ( ! empty( $value ) && empty( $opts[ $ligase_key ] ) )`.
This means once any of `social_facebook`, `social_twitter`, etc. is set in Ligase, the
import skips it. If Ligase user has set `social_facebook` to brand-new URL but left
`social_linkedin` empty, importer adds LinkedIn but not Facebook. Probably the intended
behavior, but documented nowhere.

Fix: at minimum, surface "X imported, Y skipped because already set" prominently in the
admin UI (the `$details` array supports this; verify the UI shows it).

### M14. Suppressor `KNOWN_PLUGINS` filter lists need annual revalidation
File: `includes/class-suppressor.php:23-105`.

Yoast 27.x and Rank Math 1.x kept the same `wpseo_schema_graph` and `rank_math/json_ld`
filter names — verified. But Yoast 28.x is moving toward a new "Schema Builder" API
(internal beta as of Q1 2026). When that lands, `wpseo_schema_graph` may continue working
in compat mode but the new filter `yoast_schema_v2_graph` (placeholder name) will appear
alongside. Set a calendar reminder to revalidate post Yoast 28.0 GA.

AIOSEO `aioseo_schema_disable` confirmed as recommended way to disable (per their docs
4.5+). Slim SEO `slim_seo_schema_graph` confirmed.

### M15. Suppressor doesn't suppress The Events Calendar `tribe_events_jsonld_enabled` until plugins_loaded — same C5 timing issue
File: `includes/class-suppressor.php:78-83`.

Same class of timing bug as C5. The Events Calendar's schema runs at wp_head priority
10 — Ligase suppressor at wp_loaded should beat it, but TEC's hook registration is at
TEC's own `plugins_loaded` priority 10 (verified by reading TEC). Ligase plugin file
runs `Ligase_Plugin::get_instance()` at `plugins_loaded` (default priority 10 — line
27 of `ligase.php`). Order is undefined when two plugins share the same hook priority.

Fix: explicit later priority. `add_action('plugins_loaded', fn() => Ligase_Plugin::get_instance(), 20)`.

### M16. `Logger::ensure_log_directory` PHP-die prefix not added to ROTATED files
File: `includes/class-logger.php:170-176, 220-250`.

When the active log is first created, PHP-die prefix is written. When `rename` shifts
log → log.1 → log.2, the prefix moves with them (`rename` is byte-perfect). ✅ Safe.

But the OLDEST rotation (log.3) gets deleted at line 233 — never restored. Fine. The
issue: someone running custom backups that GZip the log files for archival will
preserve the prefix in the gzip — also fine. No bug. Removing this item from the
audit list — verified safe.

### M17. Logger logs PII (post content snippets, user IDs) without redaction
File: throughout — `Logger::info` calls in `Generator`, `Output`, `Auditor` pass arrays
with `post_id` which is fine, but the auditor logs `wp_json_encode($schema)` indirectly
via `detect_source_plugin` — and one of the strings checked against could contain the
full schema. Lower risk because logs are written only in debug mode, and the log dir is
hardened with .htaccess / web.config / index.php (verified at
`class-logger.php:182-209`). 

Still, the post content can include user email addresses, phone numbers, etc. (e.g.
contact-form thank-you pages). Recommend a `Logger::redact()` helper that strips
email/phone regexes from arbitrary context arrays.

------------------------------------------------------------------------------------------

## Subsystem verdict

| Subsystem            | Status | Top issue                                                                                                                          |
|----------------------|:------:|------------------------------------------------------------------------------------------------------------------------------------|
| Generator pipeline   |   ⚠   | Duplicate `case 'blog_listing'` (C1); `with_post_globals` writes to `$wp_query` without null guard (C2); preview ≠ production (H4).|
| Output renderer      |   ✅   | Scrubber on write-path, JSON encode failure guarded, cache key stable on queried_object_id. Confirmed JSON_UNESCAPED_SLASHES NOT set.|
| Cache                |   ⚠   | `invalidate_post()` misses term/user/context keys (C4); no multilingual variant invalidation (M4); no term-edit hooks (M1, M2).    |
| Suppressor           |   🔴   | `wp_loaded` is too late vs. competitor plugins (C5); `is_active` static state leaks (C6); `force_output` mode doesn't suppress.    |
| Validator            |   ✅   | Calls Generator's preview path — drifts from production on redakcja-author posts (H10) but no fatal.                              |
| Multilingual         |   ⚠   | Polylang term path returns slugs not WP_Term IDs in some flows (works); no WPML draft-language fallback; M4 cache miss.            |
| NER pipeline         |   ⚠   | Cron correctly cleaned up on uninstall ✅; rapid-edit cache key collision (M8); no invalidation on provider/API key rotation (M9). |
| Logger               |   ✅   | Hardening solid (.htaccess + web.config + index.php + .php prefix + rotation). No PII redaction (M17) — non-blocking.              |
| Importer             |   ⚠   | Yoast Person-mode not handled (H15); social map skip-if-set silently (M13); detects deleted Yoast data (M12).                     |
| Auditor              |   🔴   | `intercept()` is DEAD CODE — no caller (C3). Admin scan paths work; "auto replace on render" advertised but unimplemented.         |

------------------------------------------------------------------------------------------

## Priority fix order (top 15)

1. **C3** — Audit `Ligase_Auditor::intercept` dead-code. Either wire it to `wp_head` priority 1 (with output-buffer caveats) or remove + update docs. User-facing trust risk.
2. **C5** — Move plugin bootstrap from `plugins_loaded` default priority to priority 20, and run suppressor on `plugins_loaded` priority 999 unconditionally. Stops duplicate schema blocks in `force_output` mode.
3. **C1** — Delete duplicate `case 'blog_listing'` in `Generator::get_graph()`; merge ItemList emit into the live arm.
4. **C2** — Guard `$wp_query` in `Generator::with_post_globals()` with `instanceof WP_Query` check before mutating properties.
5. **C4** — Extend `Ligase_Cache::invalidate_post()` to invalidate term archives, author archive, and home/front cache keys for the saved post's terms + author.
6. **H2** — Add admin notice when `should_render()` returns false because a competitor is active. Users will assume Ligase is broken otherwise.
7. **H5** — `Field_Resolver::resolve()` `@id` fallback for unpublished/draft posts. AJAX preview returns broken IDs today.
8. **H6** — `Field_Resolver::resolve_wc_source('availability')` should return null on unknown stock status, not default to InStock. Manual-action risk.
9. **H7** — Format-validate `int`/`float` sanitizers before coercion; reject non-numeric to avoid silent 1300x price errors.
10. **C6** — Drop `Ligase_Suppressor::$is_active` static OR reset it at suppress_all entry. Cross-request leak under FPM.
11. **H4 + H10 + H11** — Unify Generator entry points (live render + preview + validator) into single `get_graph_for_context(WP_Post|WP_Term|WP_User|null)`. Eliminates author_is_organization drift; allows fixing H11 (`get_users` per-render hit) by caching authors once.
12. **H1** — Make `build_webpage` slug → type map filterable / option-driven. Out-of-the-box Polish slugs other than the 4 hard-coded miss the AboutPage/ContactPage signal.
13. **M3 + M4** — Cache-bypass cooperation: flush archive URLs on save_post (WP Rocket, LiteSpeed, W3TC, WP Super Cache); enumerate languages for invalidate_post on multilingual sites.
14. **H13** — Auditor `supplement_schema()` JSON encode failure check before replacing competitor's `<script>` tag with empty body.
15. **H15** — Importer: handle Yoast Person mode (`company_or_person == 'person'`) by mapping `person_logo` + `company_or_person_user_id` → Ligase founder + logo.

------------------------------------------------------------------------------------------

## Notes for follow-up audits (out of scope here)

- `includes/types/class-product.php` — variant builder (`build_product_group`) and downgrade
  rules for missing Offer fields look correct; recommend dedicated audit when adding
  variant-as-Offer support.
- `includes/types/class-localbusiness.php` — `is_configured()` is a pure
  `lb_street && lb_city` check, type-safe, no array index risk.
- `includes/types/class-organization.php` — see H11 (`get_users` on every render) and H12
  (`employee` shape).
- `includes/entities/class-pipeline.php` — `analyze()` correctly checks `class_exists` on
  the NER extractor before instantiating; merge_ner safe on missing buckets.
- `includes/entities/class-wikidata-lookup.php` — separate negative-cache TTL (6h) for
  failed lookups distinguished from successful (4w); good.
- `includes/class-schema-rules.php` — rule-evaluation cache per request (`self::$cache`)
  safe; busted on save_rules; no fatals.
- `includes/class-cache-bypass.php` — admin-AJAX-only bypass works; the production-side
  page-cache invalidation in `flush_post_cache` is the right pattern but needs M3 work.
