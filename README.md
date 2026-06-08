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
  <a href="https://github.com/MarcinZmuda/Ligase-Schema-Markup/releases/latest"><img src="https://img.shields.io/github/v/release/MarcinZmuda/Ligase-Schema-Markup?label=release&color=orange" alt="Latest release"></a>
  <a href="https://github.com/MarcinZmuda/Ligase-Schema-Markup/releases"><img src="https://img.shields.io/github/release-date/MarcinZmuda/Ligase-Schema-Markup?label=last%20release" alt="Last release date"></a>
</p>

---

## What is Ligase?

Ligase generates a single consolidated `@graph` of schema.org JSON-LD for every page of a WordPress site — connecting `BlogPosting`/`Product`/`Recipe`/`JobPosting` nodes to a unified `Person` (author) and `Organization` (publisher / OnlineStore) through `@id` references. The graph is rendered server-side in `wp_head` so it's visible to AI crawlers (GPTBot, ClaudeBot, PerplexityBot) that don't execute JavaScript.

**For:** professional bloggers, news publishers, WooCommerce stores, recipe sites, job boards, and forums that want Google rich results and AI-citation visibility without writing schema by hand.

---

## What's new in 2.4.x

- **PodcastSeries schema** (2.4.19) — landing-page hub markup for podcast websites. Per-page opt-in with `sameAs` (Spotify / Apple Podcasts / YouTube), `webFeed` (RSS), `numberOfEpisodes`. Author resolved through the shared `BlogPosting::author_ref_id()` so `org_author_mode` + `ligase_is_redakcja` decisions apply.
- **Person extension — personal-brand SEO pack** (2.4.18-19): five new repeater fields per author profile —
  - `worksFor` external (override Organization `@id` ref with your own firm)
  - `affiliation` (loose ties — advisory, partnerships)
  - `subjectOf` (cross-link to external interviews / write-ups where you're the topic)
  - `workExperience` → emitted as schema.org-canonical role-property pattern inside `worksFor` array
  - `award` (recognition with optional issuer + year)
  - `agentInteractionStatistic` (InteractionCounter — YouTube subs, Spotify plays, LinkedIn followers — AI/LLM authority signal)
- **Smart schema-type detection in admin** (2.4.10) — Pages auto-classify by slug + title heuristics: Kontakt → `ContactPage`, O nas → `AboutPage`, Koszyk → `CheckoutPage`, Sklep → `CollectionPage`, FAQ → `FAQPage`, plus heuristics for blog hubs.
- **Google open-web popularity badges** (2.4.20) — meta-box shows next to every schema type a color-coded badge with adoption tier (`10M+`, `1M-10M`, `100K-1M`, `10K-100K`, `1K-10K`) sourced from `schemaorg/schemaorg` public stats. Helps users decide whether enabling a niche type makes sense for their site.
- **Schema.org Validator compliance** (2.4.22) — `worksFor` array now uses canonical role-property pattern (`OrganizationRole` with nested `worksFor`, not non-standard `workExperience`). Plus `refundType` + `returnShippingFeesAmount` on `MerchantReturnPolicy`, `@type` stamping for `handlingTime`/`transitTime` (`QuantitativeValue` with `unitCode: DAY`), and `image` + `address` on `Organization`/`OnlineStore` for "Firmy lokalne" / Local Business Google check.
- **Output-buffer scrubbing** (2.4.13-14) — when `standalone_mode` is on, an `ob_start` callback strips foreign `BreadcrumbList` / `Article` / `Product` JSON-LD scripts injected by themes (XStore, Flatsome, Woodmart) that filter hooks can't catch. Idempotent: leaves Ligase's own `@graph` block untouched.
- **Multi-agent code audit fixes** (2.4.8-9) — 30+ production bugs fixed: score-killer typos (`organization_name` → `org_name` across 7 places), `shippingDetails` removed from `OnlineStore` (invalid in schema.org), URL-template encoding fix for `SearchAction.target` (Google's Sitelinks Search Box), JobPosting country code validation, VideoObject empty-field gate, `has_published_posts` deprecated-API fix, secret redaction in settings export, suppressor static-state leak, Polish/EU number formatting (`"1 299,90 zł"` → `1299.9`).
- **OPcache auto-reset** (2.4.10) — fires on plugin activation and `upgrader_process_complete`. Eliminates the regression class where shared-host PHP-FPM kept the old compiled `sanitize()` whitelist, causing newly added checkboxes to silently revert on Save.
- **Tabbed settings persistence** (2.4.14) — hidden-input pattern + `array_key_exists()` in `sanitize()` so saving one tab no longer wipes checkboxes in other tabs.
- **Audytor `@graph` unwrap** (2.4.17) — auditor now reads each `@graph` node separately and picks the relevant one by post type, instead of scoring the envelope (which produced 0/100 across all posts).

Full release notes: [`readme.txt`](readme.txt). Architectural history: [`docs/audit-history/`](docs/audit-history/). Data-driven coverage analysis: [`docs/google-stats-coverage-2026-05.md`](docs/google-stats-coverage-2026-05.md).

---

## Schema types (v2.4.22 — 25 types)

### Publishing & authorship
| Type | Notes |
|---|---|
| `BlogPosting` / `Article` / `NewsArticle` / `TechArticle` / `LiveBlogPosting` | Category → variant resolver; headline ≤ 110; 3 image ratios (1:1, 4:3, 16:9); paywall (`isAccessibleForFree`); dateModified discipline; NewsArticle `citation` + `dateline`. |
| `Person` + `ProfilePage` | 18 E-E-A-T fields: `givenName`/`familyName`/`honorificPrefix`, `jobTitle`, `description`, `image`, `knowsAbout`/`knowsLanguage`, `alumniOf` (EducationalOrganization), `hasCredential` repeater, `memberOf` repeater, `worksFor` (Organization or `@id` ref to site), `affiliation` repeater, `subjectOf` (external Articles), `workExperience` → `worksFor` array of OrganizationRole (role-property pattern), `award`, `agentInteractionStatistic` (InteractionCounter), `sameAs` (Wikidata/LinkedIn/Wikipedia/ORCID + WP contact methods). |
| `Organization` / `OnlineStore` | Logo 112×112+ (Google 2025), `image`, `address` (PostalAddress), `sameAs`, `knowsAbout`, `email`, `telephone`, `contactPoint`, `founder` (Person `@id`), `employee[]` (up to 20 published authors), store-level `hasMerchantReturnPolicy` with `refundType` + `returnShippingFeesAmount`. |
| `PodcastSeries` | Landing-page hub (`/podcast/`). Per-page opt-in with `name`, `description`, `image`, `sameAs` (Spotify / Apple / YouTube), `webFeed`, `numberOfEpisodes`, `inLanguage` BCP-47. Author resolved via centralized `author_ref_id`. |
| `LocalBusiness` | 60+ supported subtypes via dropdown (ProfessionalService, Store, Restaurant, RealEstateAgent, etc.) with structured `openingHoursSpecification`, `geo` (GeoCoordinates), `priceRange`, `areaServed`, `hasMap`. |
| `WebSite` | `SearchAction` (Sitelinks Search Box — proper URL template encoding). |
| `BreadcrumbList` | Full hierarchy with parent pages + theme-injected duplicates scrubbed in standalone mode. |

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

Available type filters: `ligase_blogposting`, `ligase_person`, `ligase_organization`, `ligase_website`, `ligase_breadcrumb`, `ligase_product`, `ligase_productgroup`, `ligase_recipe`, `ligase_jobposting`, `ligase_discussionforumposting`, `ligase_podcastseries`, `ligase_service`, `ligase_event`, `ligase_faqpage`, `ligase_howto`, `ligase_review`, `ligase_localbusiness`.

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
