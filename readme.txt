=== Ligase — Schema Markup for Blogs ===
Contributors: marcinzmuda
Tags: schema, json-ld, seo, structured data, rich results, ai search, schema.org, entity graph
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 2.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete schema.org JSON-LD for WordPress blogs — entity graph, AI Search Readiness Score, and schema auditor in one plugin.

== Description ==

Ligase automatically generates complete, linked schema.org JSON-LD markup for your WordPress blog — optimized for both Google Rich Results and AI search engines (Google AI Overviews, ChatGPT, Perplexity).

Unlike basic SEO plugins that output disconnected schema blocks, Ligase builds a full entity graph: your BlogPosting links to a Person, who links to an Organization, all connected through @id references. This is what Google's AI Mode uses to verify who you are and whether to cite you.

**What makes Ligase different:**

* **AI Search Readiness Score** — a 0–100 score showing exactly how ready your blog is to be cited by AI engines, with specific recommendations
* **Schema Auditor** — scans schema already on your pages (from Yoast, your theme, or other plugins), scores it, and replaces or supplements weak markup automatically
* **Wikidata entity linking** — connect your blog and authors to Wikidata — the strongest identity signal for Google Knowledge Graph post-March 2026
* **knowsAbout** — declare topical expertise for your Organization and authors, directly influencing AI citation authority
* **16 schema types** — including QAPage, ClaimReview, DefinedTerm, Speakable, AudioObject, Course, and Event

**Supported schema types:**

Article / BlogPosting / NewsArticle · Person · Organization · WebSite · BreadcrumbList · FAQPage · HowTo · VideoObject · Review · QAPage · ClaimReview · DefinedTerm · AudioObject · SoftwareApplication · Course · Event

**Works alongside or instead of Yoast, Rank Math, and AIOSEO.**
Default mode detects active SEO plugins and avoids duplicate output. Standalone mode suppresses their schema and takes full control.

== Installation ==

1. Download the latest `ligase.zip` from the [GitHub Releases](https://github.com/marcinzmuda/ligase/releases) page
2. In WordPress: go to **Plugins → Add New → Upload Plugin**
3. Select the zip file and click **Install Now**
4. Activate the plugin

= After activation =

1. Go to **Ligase → Settings**
2. Enter your organization name, logo URL, and email
3. Add social media links (especially Wikidata — it's the strongest entity signal)
4. Edit author profiles — add job title, expertise areas, and profile links
5. Check your AI Search Readiness Score at **Ligase → Dashboard**

== Frequently Asked Questions ==

= Does Ligase work alongside Yoast SEO? =

Yes. In default mode, Ligase detects Yoast and skips its own output to avoid duplicates. Enable Standalone Mode in Settings to suppress Yoast schema and use Ligase exclusively.

= Will my schema data be lost if I deactivate the plugin? =

No. Your settings and post meta are preserved on deactivation. Data is only removed when you uninstall (delete) the plugin.

= What is the AI Search Readiness Score? =

It's a 0–100 score that measures how well your blog is set up to be cited by AI engines like Google AI Overviews, ChatGPT, and Perplexity. It checks entity graph completeness, Wikidata links, image quality, author profiles, and more.

= Do FAQPage and HowTo still work? =

The schema is generated and has real value for AI search and voice search. However, Google removed rich result display for HowTo (2024) and restricted FAQPage to government/health sites (2024). Ligase shows this information directly in the metabox so you know what to expect.

= Does Ligase support multiple languages? =

Yes. Ligase detects WPML and Polylang and automatically sets the correct `inLanguage` for each post. sameAs links are synchronized across translations.

== Screenshots ==

1. Dashboard — AI Search Readiness Score with actionable recommendations
2. Schema Auditor — scanning and replacing weak markup from other plugins
3. Post editor — Schema Markup metabox with type selector and toggles
4. Settings — Organization data, Wikidata linking, social profiles
5. Entities — Wikidata search and author E-E-A-T scores

== External services ==

This plugin relies on the following external services. Ligase only contacts these services when you actively use the relevant feature; no data is sent without explicit configuration.

= Wikidata (wikidata.org) =
Used for: entity lookup and `sameAs` linking. When you trigger entity scanning on a post, the plugin queries the Wikidata API (`wbsearchentities` endpoint) to resolve named entities to canonical Q-numbers.
Data sent: entity names (strings) extracted from your post content; site locale.
Terms of service: https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use
Privacy policy: https://foundation.wikimedia.org/wiki/Policy:Privacy_policy

= Google Search Console (search.google.com/search-console) =
Used for: the rich-results dashboard. Only contacted when you connect a GSC property in Ligase → Settings → GSC.
Data sent: OAuth credentials, site URL.
Terms of service: https://policies.google.com/terms
Privacy policy: https://policies.google.com/privacy

= OpenAI (api.openai.com) — OPTIONAL =
Used for: LLM-based Named Entity Recognition. Only contacted when you select "OpenAI" as the NER provider in Settings and supply an API key.
Data sent: post title and body content (up to 3000 words).
Terms of service: https://openai.com/policies/terms-of-use
Privacy policy: https://openai.com/policies/privacy-policy

= Anthropic (api.anthropic.com) — OPTIONAL =
Used for: LLM-based Named Entity Recognition. Only contacted when you select "Anthropic" as the NER provider.
Data sent: post title and body content (up to 3000 words).
Terms of service: https://www.anthropic.com/legal/consumer-terms
Privacy policy: https://www.anthropic.com/legal/privacy

= Google Cloud Natural Language API (language.googleapis.com) — OPTIONAL =
Used for: LLM-based Named Entity Recognition (alternative provider).
Data sent: post body content.
Terms of service: https://cloud.google.com/terms
Privacy policy: https://policies.google.com/privacy

= Dandelion (api.dandelion.eu) — OPTIONAL =
Used for: EU/GDPR-compliant entity extraction (alternative provider).
Data sent: post title and body content.
Terms of service: https://dandelion.eu/terms-of-service/
Privacy policy: https://dandelion.eu/privacy-policy/

== Privacy ==

Ligase does not collect, store, or transmit any personal data about your site visitors. The plugin stores:

* Plugin settings (organization name, logo URL, social profiles) — in the WordPress options table.
* Per-post schema metadata (FAQ items, HowTo steps, entity suggestions) — in WordPress post meta.
* Per-author metadata (job title, sameAs links) — in WordPress user meta.
* Debug logs (only when debug mode is enabled) — in `wp-content/uploads/ligase-logs/`. Logs may contain post IDs, error messages, and API response excerpts. Logs are protected with `.htaccess`, `web.config`, and a leading PHP-die header so they cannot be served directly. Disable debug mode in Settings to stop writing logs.

When you enable external NER providers, post content is transmitted to the chosen provider. Read the relevant provider's privacy policy above before enabling.

== Changelog ==

= 2.4.2 =
**Encoded-entities fix + Blog type for /blog/ + ProfilePage on any page + FAQ/HowTo metabox UI.**

* **HTML entities in JSON-LD (regression fix).** `Ligase_Generator::build_collection_page()` and `build_profile_page()` were still passing `name` / `description` through `esc_html()` before `wp_json_encode` — same double-encoding bug the 19 type classes had in 2.0.x, now closed across the whole generator. Polish dashes, ampersands, and quotes appear as raw UTF-8 in archive JSON-LD again (not `&#8211;` / `&amp;` / `&quot;`).
* **`Blog` @type for blog listing.** When WP renders the "Posts page" (`is_home() && ! is_front_page()`) the collection node now emits `@type: Blog` with `mainEntity → #itemlist`, not generic `CollectionPage`. Google docs explicitly support Blog for the top-level blog index page.
* **ProfilePage opt-in on any page.** New toggle `_ligase_enable_profile_page` + dropdown `_ligase_profile_user_id` in metabox. When set, a static page (e.g. `/o-mnie/`, `/zespol/lucyna/`, `/lucyna-w-mediach/`) emits a full `Person` (from the user's profile fields, including the 15 fields added in 2.4.0) + `ProfilePage` with `mainEntity → Person`. Falls back to the page's author when no explicit user is selected. Major E-E-A-T win for sites that profile team members on dedicated pages.
* **FAQ + HowTo metabox UI.** Two new fieldsets, both pipe-separated repeaters:
  - **FAQ:** `Pytanie | Odpowiedź` per line. Replaces / supplements the Gutenberg FAQ block.
  - **HowTo:** `Nazwa kroku | Opis` per line, plus standalone inputs for title + totalTime (ISO 8601). Replaces / supplements the Gutenberg HowTo block.
  Both still respect Google's deprecation notes (HowTo rich result desktop-only since 2024, FAQ ograniczone do gov/health) but emit valid schema for AI/voice search.

= 2.4.1 =
**ItemList for archives + Service overhaul + universal metabox UI.**

* **`Ligase_Type_ItemList`** — new schema emitted on archives so Google can build product/post carousels (host carousel for Recipe/Course/Movie/Restaurant; Beta EEA carousel for Product/Event/Hotel). Auto-detects context: WooCommerce shop home, product category/tag, regular WP taxonomy archive, blog posts listing, author archive. Each entry is a `ListItem` with `position` + inline `Product` (price/availability/image) for WooCommerce or `Article` for posts. Capped at 50 items per list. Uses the actual `WP_Query` results so what's in the schema matches what the user sees.
* **`Ligase_Type_Service` overhaul** — supports location-targeted service pages like "Adwokat rozwód Warszawa":
  - `provider` resolution chain: explicit `provider_id` meta (`#attorney`/`#localbusiness`/`#org`) → LocalBusiness when configured → Organization fallback. Local SEO now uses LocalBusiness `@id` not Organization (Google's local pack matches LocalBusiness).
  - `areaServed` accepts multi-line: one location per line, optional `| Type` suffix (`City` default, `AdministrativeArea`/`State`/`Country`/`Place` available). Single → object; multiple → array. Plain strings replaced with typed nodes.
  - `eligibleRegion` on Offer automatically inherits `areaServed`.
  - `priceSpecification` with `minPrice`/`maxPrice` when `price_low`/`price_high` set (typical for legal/consulting services). Falls back to flat `price` when no range.
  - `availability` enum on Offer (InStock / OnlineOnly / LimitedAvailability / OutOfStock / PreOrder).
  - `category` for umbrella service category alongside `serviceType`.
* **Universal metabox UI for Service / Recipe / JobPosting** — three new fieldsets under "Pola zaawansowane". No more meta-editor required to fill structured data on these types. Service section auto-shows on pages; Recipe/JobPosting auto-show when their enable toggle is on or post type matches (`job_listing`/`job`/`jobs` CPT for JobPosting).
* **Resolver bridge for Recipe + JobPosting** — flat metabox fields (`_ligase_recipe`, `_ligase_jobposting`) now merge on top of contract resolver output, so the UI just works. Resolver still drives auto-fields (post:title, post:thumbnail) and validation gates; manual fields win where provided.
* **Generator wiring** — ItemList added to taxonomy_archive, author_archive, blog_listing branches, plus runtime check for WooCommerce `is_shop()` / `is_product_taxonomy()`.

= 2.4.0 =
**Person schema — major E-E-A-T upgrade. 15+ new fields, auto sameAs from WP contact methods.**

The Person node generated for every post author used to be thin: name + url + jobTitle + knowsAbout + 3 hard-coded URL fields. As of 2.4.0 it builds a full professional profile worthy of citation by Google AI Overviews and LLMs.

**New / expanded fields in Person schema:**
* `givenName` + `familyName` — pulled from WP `first_name`/`last_name` (or derived from `display_name`), overridable via `ligase_given_name`/`ligase_family_name` user meta.
* `email` — opt-in via `ligase_publish_email` checkbox (default OFF, so account emails don't leak by default).
* `telephone` — separate from Organization's telephone.
* `knowsLanguage` — ISO 639-1 codes, CSV format (e.g. "pl, en, de").
* `alumniOf` — promoted from plain string to full `EducationalOrganization` with `name` + `url` + `department`.
* `hasCredential` — repeater. One per line, pipe-separated:
  `Name | category | Issuer name | Issuer URL | identifier? | year?`
  Categories: `license` / `degree` / `certification` / `membership` / `award`. Emits `EducationalOccupationalCredential` nodes with `recognizedBy` Organization.
* `memberOf` — repeater for professional bodies (Bar Association, ACM, etc.). One per line: `Name | URL`.
* `image` override via `ligase_image_url` (replaces Gravatar fallback).
* Legacy `ligase_credential` (single string) preserved as fallback for migrated sites.

**Auto-sameAs from WP contact methods:**

Ligase now auto-pulls every common social/profile URL added to user contact methods (by themes, Yoast, or `user_contactmethods` filter) and merges them into `Person.sameAs`:

* `facebook`, `instagram`, `linkedin`, `youtube`, `pinterest`, `wikipedia`, `myspace`, `soundcloud`, `tumblr` (full URLs)
* `x-username` (Yoast 21+ stores just the handle — auto-wrapped to `https://x.com/{handle}`)
* `twitter` (legacy URL key)
* WP `user_url` (Website)
* Existing Ligase legacy fields (`ligase_linkedin`/`ligase_twitter`/`ligase_wikidata`) — backward compat
* New `ligase_extra_sameas` textarea for ORCID, Google Scholar, branch catalogs etc. (one URL per line)

All deduplicated by normalized host+path, validated as http/https. No more 3-URL ceiling.

**Admin UI**

Profile page → "Ligase — Profil autora (Person schema)" section rebuilt with 15 fields grouped into Identity / Contact / Languages & Expertise / Education / Credentials / Membership / Extra sameAs / Legacy / Image. Each field carries an inline hint with format examples (especially for the pipe-separated credential repeater).

**Compatibility**

Drop-in upgrade from 2.3.x. Existing `ligase_*` meta keys still read; new fields are additive. No migration required.

= 2.3.3 =
**Critical fix: schema for the wrong page on themes/plugins that call query_posts().**

Diagnosed live on makumi.eu (XStore theme + WooCommerce + Yoast SEO 27.7): single
product pages received CollectionPage schema with a CATEGORY url, no Product node.
Root cause: the XStore theme calls `query_posts()` before `wp_head` priority 5
fires, corrupting `is_single()` / `is_tax()` / `get_the_ID()` so that Ligase's
Generator chose the taxonomy-archive branch on what was actually a product page.

Same pattern affects Divi, Avada, Flatsome, and several "related products"
widgets/plugins (anything that runs a secondary main-query before wp_head).

FIXES

1. **Generator now derives page context from `get_queried_object()`** instead
   of conditional tags. The queried object is set ONCE when the main query is
   parsed and is unaffected by subsequent `query_posts()` calls. New
   `resolve_context()` returns one of: `single_post`, `single_cpt`, `page`,
   `front_page_posts`, `blog_listing`, `taxonomy_archive`, `author_archive`,
   `date_or_search`, `unknown`. Each routes to exactly one builder — no more
   multiple branches firing on the same request.

2. **`with_post_globals()` wraps type-class builders** with forced
   `$GLOBALS['post']` + `setup_postdata()` AND overrides `$wp_query->is_singular`/
   `is_single`/`is_page`/`is_archive`/`is_tax`/`is_category`/`is_tag` for the
   duration of the build. Try/finally restores everything so the rest of the
   page render isn't affected. Without this override, type classes' own
   `if ( ! is_singular() ) return null` guards still short-circuit on hijacked
   globals even though the Generator routed correctly.

3. **`Ligase_Output` cache key now uses `get_queried_object()`** instead of
   `get_the_ID()`. Previously: when globals were hijacked, `get_the_ID()`
   returned 0 → fell into the "archive" branch → built an archive cache key
   for the wrong term → served stale CollectionPage schema on every product hit.
   Now: cache key reflects the actual queried object (post / term / user) and
   is invariant to `query_posts()`.

UPGRADE

- Drop-in. The version bump alone invalidates all 2.3.x cache entries, so the
  first hit after upgrade rebuilds with correct schema.
- If you also run WP Rocket / LiteSpeed Cache / W3 Total Cache, flush their
  page cache as well — they may have stored the bad HTML.

= 2.3.2 =
**PHP floor lowered from 8.2 to 8.0 — wider compatibility, no code changes.**

The 8.2 floor in 2.0.2 was conservative: a full code audit confirmed the plugin
uses **zero** PHP 8.1+ structural features (no `readonly`, no `enum`, no `never`
return type, no first-class callable syntax `Foo::bar(...)`, no `array_is_list()`,
no `new` in initializers, no intersection/DNF types, no final class constants).

All language features that ARE used — constructor promotion, `match` expressions,
union types, `mixed` type, nullsafe `?->`, `str_contains`/`str_starts_with`/
`str_ends_with` — work on PHP 8.0 (released Nov 2020).

CHANGES
- ligase.php header: `Requires PHP: 8.0`
- composer.json require: `php >=8.0`
- readme.txt: `Requires PHP: 8.0`
- phpcs.xml.dist: PHPCompatibilityWP testVersion `8.0-`
- README.md badge + Requirements section

NOTE
- PHP 8.0 reached EOL on 2023-11-26. The plugin runs on it but you should still
  plan to upgrade to PHP 8.1+ on the hosting side — Ligase will keep working
  either way.
- PHP 7.4 is NOT supported (constructor promotion, match, union types, nullsafe,
  str_contains et al. all require 8.0).

= 2.3.1 =
**Lifecycle cleanup — no ghost cron events or orphaned files after deactivate/uninstall.**

* **`register_deactivation_hook`** added to the main plugin file. On deactivate, calls `Ligase_Health_Report::unschedule()` and explicitly clears the three known cron hooks (`ligase_weekly_health_report`, `ligase_ner_api_extract`, `ligase_wikidata_lookup`). Without this, WP-Cron kept firing scheduled events against missing class handlers after every deactivate, filling debug.log with PHP fatals.
* **`uninstall.php`** rewritten:
  - Explicit `wp_clear_scheduled_hook()` for all three cron hooks (single + recurring events for the same hook are both cleared by this call).
  - Explicit `delete_transient('ligase_gsc_access_token')` + `delete_transient('ligase_site_score')` up front (the LIKE catch-all already covered these, but explicit calls survive any future key-shape change).
  - Added the four `ligase_ner_bulk_*` options introduced in 2.0.2/2.1.0 to the explicit delete list, plus a LIKE catch-all `ligase_%` for anything we forget to list.
  - Log-directory cleanup now removes hidden protection files (`.htaccess`, IIS `web.config`, `index.php`) via a `glob('.[!.]*')` pass before `rmdir()`. Previously `rmdir()` failed silently because the default `glob('*')` doesn't match dotfiles, leaving the directory orphaned.

= 2.3.0 =
**Three new schema types — closing the biggest P1 gaps from the competitive benchmark.**

* **Recipe** — host-carousel-eligible rich result (one of four with Course/Movie/Restaurant). New `Ligase_Type_Recipe` + contract entry. Required fields gated: name + image; recipeIngredient/recipeInstructions trigger the step-by-step enhancement when present. Plain-string instructions auto-converted to `HowToStep` nodes. Validator: `validate_recipe()` with ISO 8601 duration check on prep/cook/totalTime.
* **JobPosting** — Google Jobs rich result (separate search experience). New `Ligase_Type_JobPosting`. Auto-detects `job_listing`/`job`/`jobs` CPTs. Auto-expires when `validThrough` is in the past (returns null so stale jobs don't pollute Google Jobs). `directApply` cast to real boolean. Validator: `validate_jobposting()` requires title/description/datePosted/validThrough/hiringOrganization + jobLocation OR jobLocationType=TELECOMMUTE.
* **DiscussionForumPosting** — Discussions & Forums SERP feature (active since Nov 2023). New `Ligase_Type_DiscussionForumPosting`. Auto-detects bbPress topic/reply/forum CPTs. Nests up to 50 approved comments as `Comment` nodes (thread depth signal). Validator: `validate_forum_posting()`.

**Pipeline improvements**

* **Full NER integration in resolver** — `resolve_ner_entities()` now merges three sources in priority order: `_ligase_about_entities` (curated) → `_ligase_ner_api_results` (LLM cron output, mapped to schema.org Person/Organization/Place/Product by bucket) → `_ligase_wikidata_suggestions` (single-confidence + exact-label match only, attaches `sameAs`). Dedup by lowercased name; previous stub only read curated meta.
* **`derive:comment_count`** — new computed source used by DiscussionForumPosting `interactionStatistic.userInteractionCount`.

**Metabox UI**

* **Three new toggles** in meta-box for Product / Recipe / JobPosting / Forum (already wired in 2.2 backend, now visible in editor).
* **"Pola zaawansowane" collapsible section** with:
  - Paywall toggle + CSS selector for the gated section.
  - `_ligase_force_date_modified` toggle (override the 5-min discipline).
  - NewsArticle `dateline` text input.
  - Image license trio: credit / license URL / acquireLicensePage URL (Licensable badge).
  - NewsArticle `citation` repeater (name + URL per row).
  - **Product manual overrides** (visible only on `product` CPT) — direct inputs for name / GTIN / MPN / price / priceCurrency / priceValidUntil / returnPolicyCountry; empty input = auto wins.
* All new fields read/written through `save_meta_box()` with proper sanitization (text/url/citations array). Citations and override clears delete the meta when empty.

**Wiring**

* `Ligase_Schema_Rules::SCHEMA_TYPES` gains Recipe / JobPosting / DiscussionForumPosting entries with their `_ligase_enable_*` flags.
* `Ligase_Generator::get_optional_types()` adds the three new builders.
* `Ligase_Validator::validate_post()` routes the new types.
* `Ligase_Plugin` autoloads the three new type-class files.

= 2.2.0 =
**Field-contract system — single source of truth for "what does Google need".**

* **`Ligase_Field_Contract`** declares per-type field requirements (level: required/recommended/optional), source chains (manual → WC → post → option → ref → ner → derive), sanitize rules, and nested container @types. Extensible via the `ligase_field_contract` filter. Built-in contracts: Product, BlogPosting, NewsArticle.
* **`Ligase_Field_Resolver`** walks the contract for `(type, post_id)`: tries each source in order, sanitizes, assembles a nested node with @type stamps, and reports per-field state + overall eligibility. WooCommerce data auto-populates Product (name/price/currency/availability/sku/gtin/image/description/ratings) — no more hand-filled `_ligase_product` meta required for shops.
* **`Ligase_Readiness::for_post()`** + AJAX endpoint (`wp_ajax_ligase_readiness`) — capability `edit_posts` + per-post `edit_post` check. Returns field-by-field status for every schema type relevant to the post.
* **In-editor readiness panel** — sidebar metabox with live ✓ auto / ✓ manual / ✗ missing-required / ○ optional list per type, eligibility badge ("Kwalifikuje się do rich resultu" vs "Brakuje pól wymaganych"), source labels, refresh button. Auto-refreshes after Gutenberg save via `wp.data` subscription.
* **Manual overrides** — `_ligase_override[<Type>][<key>] = value` post meta. Saved through the existing metabox form (`ligase_override[...]` inputs); sanitized per contract sanitize rule. Manual overrides always win over auto sources; clearing the value reverts to auto. Auto values are NEVER persisted to meta — resolved on every render so stale prices/dates are impossible.
* **Eligibility gate (`brama kompletności`)** for Product:
  - Missing `name` → no Product node emitted at all.
  - Missing `offers.price`/`priceCurrency`/`availability` → emit valid Product **without** offers (downgrade to snippet, not merchant listing). Better than emitting an incomplete Offer that Search Console flags.
  - Missing `returnPolicyCountry` → drop the entire `MerchantReturnPolicy` object (per Google's March 2025 requirement).
  - WooCommerce post type auto-detected — Product schema renders for any `product` CPT without manual enable flag.
* **Validator delegation** — new `Ligase_Validator::validate_via_contract( $type, $post_id )` returns `eligible` + `missing_required` from the resolver; eliminates rule duplication between per-type validate_* methods and the contract.
* **WP-CLI helper** — `ligase_readiness( $post_id )` global function for `wp eval` introspection.
* **`Ligase_Readiness_Panel`** registers metabox on all public post types; enqueues vanilla JS (`assets/js/ligase-readiness-panel.js`, no build step) with `wp-i18n` + `wp-data` deps and `wp_set_script_translations`.
* **Acceptance tests** — `tests/unit/FieldContractTest.php` covers: full WC data → eligible; no price → ineligible + offers dropped; manual override beats auto; headline ≤110 with `…` indicator; author is `@id` reference; country sanitized to ISO 3166-1 alpha-2 uppercase; auto values never persisted to meta.

**Architectural invariants enforced by the contract:**
- One source of truth for "what's required" (contract) — no rules in three places (resolver/validator/UI).
- Auto-fill never lies: missing data = missing key, no placeholders.
- Missing one node never kills the whole `@graph` — generator's `array_filter` preserves Organization/WebSite/Breadcrumb even if Product/BlogPosting downgrade.
- JSON-LD output stays escaped (`</script>` neutralized by existing `str_replace` + no `JSON_UNESCAPED_SLASHES`).

= 2.1.0 =
**Blog/News track**
* **Article variant resolution** — new `resolve_article_type()` picks BlogPosting/NewsArticle/TechArticle/LiveBlogPosting based on (1) per-post override, (2) category→type mapping in settings (`category_article_type_map`), (3) CPT name (`news` → NewsArticle), (4) default. Lets newsrooms qualify for Top Stories without changing settings per post.
* **Image rich result — 3 cropped ratios** — registered `ligase_1x1`, `ligase_4x3`, `ligase_16x9` image sizes; `build_images()` now emits actual cropped URLs (not the same URL with fake dimensions). Falls back to the original if WP couldn't crop.
* **Image licensing** — ImageObject can carry `creditText`, `license`, `acquireLicensePage` (per-post or site default in settings). Produces the "Licensable" badge in Google Images.
* **NewsArticle citation + dateline** — emits `citation` (CreativeWork list from `_ligase_citations` post meta) and `dateline` (from `_ligase_dateline`) when type is NewsArticle. Major AI-citation signal.
* **dateModified discipline** — no longer emitted when modification is <5 minutes after publish (or never modified). Prevents inflated freshness signals and reduces manual-action risk. Override per-post via `_ligase_force_date_modified` meta.
* **headline ellipsis** — when title > 110 chars, append `…` instead of hard-cutting at 110.

**E-commerce track**
* **OnlineStore mode** — Organization auto-promotes to `OnlineStore` when WooCommerce is active or `store_mode` option is on. Site-level `hasMerchantReturnPolicy` and `shippingDetails` emit once on the OnlineStore node; product Offers reference them by `@id` instead of repeating. Massive payload reduction on big catalogs.
* **Sale price + strikethrough** — when `regular_price > price`, Offer emits `priceSpecification` with `SalePrice` + `StrikethroughPrice` UnitPriceSpecifications. Google shows a strikethrough in SERP, raising CTR.
* **ProductGroup variants** — variant products (size/color) emit `ProductGroup` with `variesBy` + `hasVariant` array. Each variant is a Product with its own SKU/GTIN/Offer. Required for Shopping enhancements that show variant stock.
* **WooCommerce cache invalidation** — hooks into `woocommerce_after_product_object_save`, `woocommerce_product_set_stock_status`, `woocommerce_variation_set_stock_status`, `woocommerce_product_set_stock`, `woocommerce_updated_product_stock`. Schema cache now updates the moment price/stock changes; Google never sees a stale price.
* **Validator** — new `validate_product_group()` checks for hasVariant, variesBy, unique SKUs, and missing offers per variant.
* **Review.php docblock** — explicitly documents that pros/cons rich result is only granted for editorial product reviews, not shop pages or user-review aggregates.

= 2.0.2 =
* **Security:** Dropped `JSON_UNESCAPED_SLASHES` from all front-end JSON-LD encoders. Combined with the existing `str_replace` defense this gives belt-and-braces protection against any `</script>` break-out.
* **Security:** Escaped `keywords` from tag names (BlogPosting) and `knowsAbout` lists (Organization, Person) using `wp_strip_all_tags` — previously these were emitted unescaped.
* **Security:** Capability separation in AJAX. Editor-context endpoints (`scan_post`, `validate_post`, `preview_json`) now use `edit_posts` + `edit_post` per-post check. Settings/GSC/NER bulk/import keep `manage_options`. Editors and Authors can now use the post sidebar without the previous 403.
* **Compliance:** Organization logo default changed from old AMP 600×60 to Google's 2025 requirement: minimum 112×112 square, recommended 600×600+. Settings labels updated.
* **New type:** `Product` + `Offer` with merchant return policy (`returnPolicyCountry` required since March 2025) and `OfferShippingDetails`. Supports both Product snippet (review/ranking) and Merchant listing (sales). Includes `validate_product()` and `pros/cons` (`positiveNotes`/`negativeNotes`) for editorial reviews. Wired into generator, schema rules, and validator.
* **Fix:** `BlogPosting.isAccessibleForFree` no longer hard-coded `true`. Now respects `_ligase_paywalled` post meta and emits `WebPageElement` with `cssSelector` for the gated section (Google's anti-cloaking spec).
* **Fix:** Archive cache key now uses `queried_object_id` + context tag instead of the full `REQUEST_URI` — prevents cache fragmentation across `?utm_*`/`?fbclid` variants.
* **Fix:** Removed unused `$original_query` in `Ligase_Generator::get_graph_for_post()`.
* **Documentation:** `ClaimReview` class header now explicitly marks the type as niche (Google deprecated fact-check rich result in June 2025) — only verified fact-checkers should enable it.
* **DX:** Quality gates — added `phpcs.xml.dist` (WordPress Coding Standards + PHPCompatibilityWP), `phpstan.neon.dist` (level 5 with `szepeviktor/phpstan-wordpress`), and `composer scripts` for `phpcs`/`phpcbf`/`phpstan`.
* **DX:** PHP floor raised from 8.0 to 8.2 (constructor promotion, readonly properties, enums are now safe to use).

= 2.0.1 =
* **Security:** Fixed stored XSS in JSON-LD output when post content contained the literal substring `</script>`. The fix is a server-side escape applied after `wp_json_encode`. All users should update.
* **Security:** Logger directory now writes a `web.config` for IIS, a leading PHP-die header on every log file, and uses a `.php` extension so the file is safe-when-served regardless of webserver config.
* **Security:** Added `wp_unslash` before `wp_verify_nonce` on all admin endpoints to prevent magic-quotes mismatches.
* **Fix:** Removed `esc_html()` double-encoding from all 19 schema type classes. Polish titles with diacritics, quotes, or ampersands now appear correctly in JSON-LD rather than as `&quot;` / `&amp;` entities.
* **Fix:** AudioObject anchor.fm URLs no longer get a duplicate `anchor.fm/` prefix (404).
* **Fix:** VideoObject now emits `hqdefault.jpg` as primary thumbnail (guaranteed to exist) with `maxresdefault.jpg` as enhancement (fixes ~30% missing thumbnails).
* **Fix:** Event with `OfflineEventAttendanceMode` and no venue is now suppressed instead of emitting invalid Event schema.
* **Fix:** HowTo and SoftwareApplication now emit the Google-required `image` (and `publisher` for SoftwareApplication).
* **Fix:** FAQPage now emits `@id`, `inLanguage`, `isPartOf`, `mainEntityOfPage` — properly linked into the entity graph.
* **Fix:** BlogPosting `wordCount` now uses Unicode-aware word counting (Polish multibyte words no longer undercounted).
* **Fix:** Settings sanitize no longer wipes other sections when saving a partial sub-form.
* **Fix:** FAQ/HowTo Gutenberg blocks now write post meta in `save_post` (correct) instead of every frontend render.
* **Fix:** Suppressor filter hook names updated for current versions of Yoast SEO, Rank Math, AIOSEO, SEOPress, TSF, and Slim SEO — eliminates double-schema output on these sites.
* **Fix:** Auditor scoring is now type-aware — Event/Product/LocalBusiness/Recipe/HowTo/FAQ/Video each have their own rubric. Previously these scored 0 against the Article rubric and could be auto-replaced.
* **Fix:** Audit AJAX endpoint now respects the `mode` parameter (`replace`/`supplement`/`restore`) and `threshold` parameter — previously both were silently ignored.
* **Added:** Auditor `restore_replacement()` undoes a prior schema replacement.
* **Added:** Auditor `apply_supplement()` produces additive schema rather than always replacing.
* **Fix:** Importer now correctly resolves attachment IDs to URLs for Yoast (v14+), Rank Math (v1.0.50+), and AIOSEO logo fields; handles AIOSEO v3 serialized PHP and v4.5+ nested social URLs.
* **Fix:** Entity pipeline now consumes LLM NER results (`_ligase_ner_api_results`) — previously the LLM was called but its output was never read.
* **Fix:** Wikidata lookup tries the site locale, then falls back to English — entities like "Cloudflare"/"Stripe" no longer fail on Polish sites. Negative cache reduced from 4 weeks to 6 hours. User-agent string now WMF-policy-compliant.
* **Fix:** Wikidata auto-apply now requires a label match OR LLM confirmation, not just a single search result — drastically reduces wrong-entity linking.
* **Fix:** Polish NER deduplication now uses inflection-aware stems — "Jan Kowalski / Jana Kowalskiego / Janowi Kowalskiemu" merge into a single entity.
* **Fix:** NER place detector no longer treats Polish demonstrative "to" as an English locative preposition.
* **Fix:** Bulk NER scan now correctly increments the progress counter (admin UI no longer stuck at 0%); adds 24h cooldown and 500-post cap per run.
* **Fix:** AI Readiness Score now reads pipeline outputs (Wikidata suggestions, LLM NER results, about/mentions with sameAs) instead of only easy-to-pass "field is non-empty" checks.
* **Added:** XSS regression test for the JSON-LD `</script>` break-out.

= 2.0.0 =
* Added: Google Search Console integration (rich results dashboard)
* Added: Gutenberg sidebar schema preview and validator
* Added: Import settings from Yoast SEO, Rank Math, All in One SEO
* Added: WPML / Polylang support
* Added: Weekly schema health report (WP-Cron email digest)
* Added: 7 new schema types: QAPage, ClaimReview, DefinedTerm, SoftwareApplication, AudioObject, Course, Event
* Added: Speakable property on BlogPosting (configurable CSS selectors)
* Added: alumniOf, hasCredential, honorificPrefix for Person
* Added: founder, employee for Organization
* Added: isBasedOn, hasPart, accessMode for BlogPosting
* Added: Live word counter in FAQ Gutenberg block (optimal: 40–60 words)
* Added: Bulk select and fix in Posts view
* Fixed: Meta key mismatch — FAQPage/HowTo/Review toggles now work correctly
* Fixed: BreadcrumbList now includes full page hierarchy (parent pages)
* Fixed: supplement_schema() author @id format consistency
* Fixed: Score cache invalidation on save_post and settings update

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.0.0 =
Major update with 7 new schema types, GSC integration, and important bug fixes. Update recommended for all users.
