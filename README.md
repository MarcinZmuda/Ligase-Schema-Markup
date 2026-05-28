<p align="center">
  <img src="assets/images/banner-772x250.png" alt="Ligase — Schema Markup for WordPress" width="772">
</p>

<p align="center">
  <strong>Schema.org JSON-LD for WordPress — entity graph, AI-citation optimization, WooCommerce-aware merchant listings, and a single-source-of-truth field contract.</strong>
</p>

<p align="center">
  <a href="https://wordpress.org/"><img src="https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress" alt="WordPress"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img src="https://img.shields.io/badge/License-GPLv2-green.svg" alt="License"></a>
  <img src="https://img.shields.io/badge/version-2.3.2-orange.svg" alt="Version">
</p>

---

## What is Ligase?

Ligase generates a single consolidated `@graph` of schema.org JSON-LD for every page of a WordPress site — connecting `BlogPosting`/`Product`/`Recipe`/`JobPosting` nodes to a unified `Person` (author) and `Organization` (publisher / OnlineStore) through `@id` references. The graph is rendered server-side in `wp_head` so it's visible to AI crawlers (GPTBot, ClaudeBot, PerplexityBot) that don't execute JavaScript.

**For:** professional bloggers, news publishers, WooCommerce stores, recipe sites, job boards, and forums that want Google rich results and AI-citation visibility without writing schema by hand.

---

## What's new in 2.3.0

- **Three new high-value schema types:** `Recipe` (host-carousel-eligible), `JobPosting` (Google Jobs), `DiscussionForumPosting` (bbPress / Discussions & Forums SERP).
- **WooCommerce merchant listings** — `Product` + `Offer` + `MerchantReturnPolicy` (with `returnPolicyCountry` required since March 2025) + `OfferShippingDetails`, plus `ProductGroup` + `hasVariant` for size/color variants and `SalePrice`/`StrikethroughPrice` for SERP strikethrough pricing.
- **Field-contract system** — every schema field declares its required level, source chain (manual → WC → post → option → ref → NER → derive), and sanitization rule in one declarative file. Auto-fill happens at render time; manual overrides are persisted but auto values never are. Powers the in-editor **readiness panel** that shows which rich results the post qualifies for and why.
- **OnlineStore mode** — site-level return + shipping policies emitted once on the Organization node; product Offers reference them by `@id` instead of repeating the full policy. Massive payload reduction on big catalogs.
- **Hardening:** stored XSS in JSON-LD output fixed (literal `</script>` neutralized by both `str_replace` AND dropping `JSON_UNESCAPED_SLASHES`); capability split between admin and editor AJAX endpoints; logger now safe to leak regardless of webserver (PHP-die prefix + `.php` extension + `web.config`).

Full release notes: [`readme.txt`](readme.txt). Architectural history: [`docs/audit-history/`](docs/audit-history/).

---

## Schema types (v2.3.0)

### Publishing
| Type | Notes |
|---|---|
| `BlogPosting` / `Article` / `NewsArticle` / `TechArticle` / `LiveBlogPosting` | Category → variant resolver; headline ≤ 110; 3 image ratios (1:1, 4:3, 16:9); paywall (`isAccessibleForFree`); dateModified discipline; NewsArticle `citation` + `dateline`. |
| `Person` + `ProfilePage` | `sameAs` (Wikidata/LinkedIn), `knowsAbout`, `alumniOf`, `hasCredential`, `worksFor` → Organization. |
| `Organization` / `OnlineStore` | Logo 112×112+ (Google 2025 requirement), `sameAs`, `knowsAbout`, store-level `hasMerchantReturnPolicy` + `shippingDetails`. |
| `LocalBusiness` | 60 supported subtypes with structured `openingHoursSpecification`. |
| `WebSite` | `SearchAction`. |
| `BreadcrumbList` | Full hierarchy with parent pages. |

### E-commerce
| Type | Notes |
|---|---|
| `Product` + `Offer` | WooCommerce auto-detection; sale strikethrough via `priceSpecification`; `priceValidUntil` past-date guard. |
| `MerchantReturnPolicy` | `returnPolicyCountry` enforced. |
| `OfferShippingDetails` | `MonetaryAmount` + `DefinedRegion` + `ShippingDeliveryTime`. |
| `ProductGroup` + `hasVariant` | Per-variant SKU/GTIN/Offer, `variesBy`, `productGroupID`. |
| `Review` | With `positiveNotes`/`negativeNotes` (pros/cons — editorial reviews only). |

### Editorial & community
| Type | Notes |
|---|---|
| `Recipe` | Host-carousel-eligible. `HowToStep` auto-conversion. |
| `JobPosting` | Google Jobs. Auto-expires past `validThrough`. |
| `DiscussionForumPosting` | bbPress auto-detection. Nests up to 50 `Comment` nodes. |
| `FAQPage` / `HowTo` / `QAPage` | All three emitted as JSON-LD (FAQ/HowTo rich results are deprecated but Ligase keeps them for AI-citation signals). |
| `ClaimReview` | Documented as niche — Google deprecated June 12, 2025. Schema still emitted for verified fact-checkers. |
| `Course`, `SoftwareApplication`, `Event`, `Service`, `VideoObject`, `AudioObject`, `DefinedTerm` | All emitted. |

---

## Field-contract system

A single declarative file (`includes/class-field-contract.php`) defines, per @type:

```php
'Product' => [
    'fields' => [
        'name' => [
            'level'    => 'required',
            'sources'  => [ 'manual:', 'wc:name', 'post:title' ],
            'sanitize' => 'text',
        ],
        'offers.price' => [
            'level'    => 'required',
            'sources'  => [ 'manual:', 'wc:price' ],
            'sanitize' => 'float',
        ],
        // …
    ],
],
```

`Ligase_Field_Resolver` walks each contract for a given `(type, post_id)`, tries each source in order, sanitizes, and assembles a nested JSON-LD node. It reports:

- per-field state: `auto` / `manual` / `missing_required` / `missing_optional`
- overall `eligible` — false when any required field is empty
- list of `missing_required` keys

The **eligibility gate** stops Ligase from emitting half-formed merchant listings (e.g. `Product` without `Offer` is downgraded to a snippet rather than fabricated). Manual overrides are persisted in `_ligase_override[Type][key]` post meta and always win over auto sources; clearing the input reverts to auto. Auto values are **never** persisted — they're computed at render time on every cache miss, so stale prices/dates are impossible.

The in-editor **readiness panel** (sidebar metabox) calls `Ligase_Readiness::for_post()` via AJAX and shows the user exactly which fields are filled, by which source, and which required fields are still missing — so non-technical editors can fix rich-result issues without leaving the editor.

---

## AI Search Readiness Score (0–100)

Site-wide and per-post score measuring how ready content is to be cited by AI engines (Google AI Overviews, ChatGPT, Perplexity). Checks include entity-graph linking (`@id` references), Wikidata `sameAs` density, image dimensions ≥ 1200 px, author completeness (`knowsAbout`, `jobTitle`, `sameAs`), `dateModified` discipline, presence of `about`/`mentions` with Wikidata, NER LLM coverage. Pipeline-aware: the score reads `_ligase_wikidata_suggestions`, `_ligase_ner_api_results`, `_ligase_about_entities` so it grades real AI signals, not vanity field-presence checks.

---

## Schema Auditor

Detects and (optionally) suppresses or supplements competitor SEO plugins' schema:

- Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework, Slim SEO, The Events Calendar.
- Three modes: **Scan** (report only), **Supplement** (additive — `apply_supplement()`), **Replace** (full takeover below score threshold + `restore_replacement()` for undo).
- Type-aware scoring rubrics: separate scoring for Article, Event, Product, LocalBusiness, Recipe, FAQ, HowTo, Video, Organization, Person — Event no longer scored against an Article rubric and force-replaced.

---

## Entity-detection pipeline

```
Level 1: WordPress Native       (tags, categories, author)         ~0ms
Level 2: Structural             (Wikipedia links, YouTube, blocks)  ~5ms
Level 3a: Regex NER             (Polish lemmatization-aware dedup) ~20ms
Level 3b: LLM NER (cron)        (OpenAI / Anthropic / Google /
                                Dandelion — merged with regex)     async
Level 4: Wikidata lookup        (locale → en fallback, exact-label
                                match guard, single-confidence)    async
```

LLM results take precedence on entity-name conflicts; Wikidata `sameAs` attaches only when the label exactly matches the entity name (avoids wrong-entity linking).

---

## More features

- **Google Search Console** — rich-results dashboard (clicks, impressions, CTR per schema type).
- **Gutenberg sidebar** — live schema preview + validator.
- **Import from Yoast / Rank Math / AIOSEO** — handles attachment-ID logos, AIOSEO v3 serialized PHP, v4.5+ nested social URLs.
- **WPML / Polylang** — `inLanguage` BCP47 + sameAs sync across translations.
- **Weekly Health Report** — email digest of schema issues.
- **Speakable schema** — configurable CSS selectors per post.
- **Auto-repair** — ISO 8601 date fixes, headline truncation, type conversion.
- **WooCommerce cache invalidation** — hooks into 5 product/stock events; price changes never lag the schema cache.
- **i18n-ready** — `wp_set_script_translations` + `wp-i18n` for JS strings (translation source needs migration to English for wordpress.org; current source is Polish).

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- No external runtime dependencies (`ext-json`, `ext-mbstring` standard)
- Optional: WooCommerce 6.0+ for store features
- Optional: PHP API key for one of OpenAI / Anthropic / Google NLP / Dandelion to enable LLM NER

---

## Installation

### From ZIP
1. Download the latest release from [Releases](../../releases).
2. WordPress → **Plugins → Add New → Upload Plugin** → select the zip → **Install Now** → **Activate**.

### Post-installation setup
1. **Ligase → Settings** → fill organization data (name, logo URL ≥ 112×112 square, email, Wikidata ID).
2. Add social media links (Wikidata + LinkedIn = strongest entity signals).
3. Edit author profiles — `jobTitle`, `knowsAbout`, `sameAs`.
4. (Stores) Settings → Store → return + shipping country, return days, shipping rates.
5. Check **Ligase → Dashboard** for AI Search Readiness Score.

---

## Developer API

### Filters

```php
// Modify the full @graph
add_filter( 'ligase_schema_graph', function ( array $graph ): array {
    $graph[] = [ '@type' => 'Event', 'name' => 'My Event' ];
    return $graph;
} );

// Modify a single type node
add_filter( 'ligase_blogposting', function ( array $schema, int $post_id ): array {
    $schema['speakable'] = [
        '@type'       => 'SpeakableSpecification',
        'cssSelector' => [ '.entry-summary' ],
    ];
    return $schema;
}, 10, 2 );

// Extend the field contract from a theme/plugin
add_filter( 'ligase_field_contract', function ( array $contract, string $type ): array {
    if ( $type === 'Product' ) {
        $contract['fields']['offers.priceValidUntil']['level'] = 'required';
    }
    return $contract;
}, 10, 2 );

// Which schema types appear in the readiness panel for this post
add_filter( 'ligase_readiness_panel_types', function ( array $types, int $post_id ): array {
    if ( has_category( 'news', $post_id ) ) {
        $types[] = 'NewsArticle';
    }
    return $types;
}, 10, 2 );
```

Available type filters: `ligase_blogposting`, `ligase_person`, `ligase_organization`, `ligase_website`, `ligase_breadcrumb`, `ligase_product`, `ligase_productgroup`, `ligase_recipe`, `ligase_jobposting`, `ligase_discussionforumposting`.

### WP-CLI helper

```bash
# Print readiness report for a post
wp eval 'var_export( ligase_readiness( 123 ) );'
```

---

## Testing & quality gates

```bash
composer install
composer test            # PHPUnit
composer phpcs           # WordPress Coding Standards
composer phpstan         # PHPStan level 5
```

GitHub Actions runs all of the above on every push, plus a smoke test that boots WordPress, activates the plugin, creates a post, and verifies that `<script type="application/ld+json">` appears in the raw HTML *and* that `</script>` inside the JSON is properly escaped (regression guard for the XSS fix).

---

## License

[GNU General Public License v2.0](LICENSE) or later.

## Author

Built by **[Marcin Żmuda](https://marcinzmuda.com)** · [Report a bug](../../issues) · [Releases](../../releases)
