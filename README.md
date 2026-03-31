<p align="center">
  <img src="assets/images/banner-772x250.png" alt="Ligase — Schema Markup for WordPress" width="772">
</p>

<p align="center">
  <strong>Schema.org JSON-LD for WordPress blogs — optimized for Google Rich Results and AI Search.</strong>
</p>

<p align="center">
  <a href="https://wordpress.org/"><img src="https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress" alt="WordPress"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img src="https://img.shields.io/badge/License-GPLv2-green.svg" alt="License"></a>
  <img src="https://img.shields.io/badge/version-2.0.0-orange.svg" alt="Version">
</p>

---

## What is Ligase?

Ligase automatically generates complete, linked schema.org JSON-LD markup for your WordPress blog. It builds a full entity graph connecting your BlogPosting to its Author and Organization through `@id` references — exactly what Google's AI Mode uses to verify who you are and whether to cite you.

**Who is it for?** Professional bloggers who want Google rich results and AI Overview citations without touching code.

---

## Features

### 16 Schema Types

| Type | Description |
|------|-------------|
| `Article` / `BlogPosting` / `NewsArticle` | Auto-generated for every post |
| `Person` | Author with `sameAs`, `knowsAbout`, `alumniOf`, `hasCredential` |
| `Organization` | Publisher with logo, social links, Wikidata, `knowsAbout` |
| `WebSite` | With `SearchAction` |
| `BreadcrumbList` | Auto-generated with full page hierarchy |
| `FAQPage` | Gutenberg block + schema (AI citation optimized) |
| `HowTo` | Gutenberg block + schema |
| `VideoObject` | Auto-detects YouTube embeds |
| `Review` | With rating, reviewer, item reviewed |
| `QAPage` | Single-question pages (+58% AI citations vs Article) |
| `ClaimReview` | Fact-checking — high-trust AI signal post-March 2026 |
| `DefinedTerm` / `DefinedTermSet` | Glossary and definition pages |
| `SoftwareApplication` | Tool and app reviews |
| `AudioObject` | Auto-detects Spotify / Buzzsprout / Anchor embeds |
| `Course` | Online courses with CourseInstance |
| `Event` | Events with location (online/offline), status, tickets |

### AI Search Readiness Score (0–100)

Unique score showing how ready your blog is to be cited by AI engines (Google AI Overviews, ChatGPT, Perplexity). Measures entity graph quality, Wikidata links, image dimensions, author completeness, and more.

### Schema Auditor

Scans schema already on your pages from Yoast, your theme, or other plugins — scores it, and can replace or supplement weak markup automatically.

Three modes: **Scan** (report only) · **Supplement** (add missing fields) · **Replace** (full takeover below score threshold)

Detects: Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework, Slim SEO, The Events Calendar.

### Entity Detection Pipeline

```
Level 1: WordPress Native      (tags, categories, author)        ~0ms
Level 2: Structural analysis   (Wikipedia links, YouTube, blocks) ~5ms
Level 3: NER                   (persons, organizations, products) ~20ms
Level 4: Wikidata Lookup       (async via WP-Cron)               async
```

### More Features

- **Google Search Console** — rich results dashboard (clicks, impressions, CTR per schema type)
- **Gutenberg sidebar** — live schema preview and validation in the post editor
- **Import from Yoast / Rank Math / AIOSEO** — one-click settings migration
- **WPML / Polylang** support — correct `inLanguage`, sameAs sync across translations
- **Weekly Health Report** — email digest of schema issues across all posts
- **Speakable schema** — mark AI-citable sections via CSS selectors
- **Wikidata entity search** — find and link entities from the admin panel
- **Cache bypass** — WP Rocket, LiteSpeed Cache, W3 Total Cache compatible
- **Auto-repair** — fix ISO 8601 dates, truncate headlines, convert schema types
- **Full i18n** — translation-ready (text domain: `ligase`)

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- No external dependencies

---

## Installation

### From ZIP
1. Download the latest release from [Releases](../../releases)
2. In WordPress: **Plugins → Add New → Upload Plugin**
3. Select `ligase.zip` → **Install Now** → **Activate**

### Post-installation setup
1. Go to **Ligase → Settings**
2. Fill in organization data (name, logo URL, email, Wikidata ID)
3. Add social media links
4. Edit author profiles — add `jobTitle`, `knowsAbout`, `sameAs` links
5. Check your score at **Ligase → Dashboard**

---

## Developer API

### Filters

```php
// Modify the entire schema graph
add_filter( 'ligase_schema_graph', function( array $graph ): array {
    $graph[] = [ '@type' => 'Event', 'name' => 'My Event' ];
    return $graph;
} );

// Modify a specific type
add_filter( 'ligase_blogposting', function( array $schema, int $post_id ): array {
    $schema['speakable'] = [
        '@type'       => 'SpeakableSpecification',
        'cssSelector' => [ '.entry-summary' ],
    ];
    return $schema;
}, 10, 2 );

// Available: ligase_blogposting, ligase_person, ligase_organization,
//            ligase_website, ligase_breadcrumb
```

---

## Testing

```bash
composer install
./vendor/bin/phpunit
```

---

## License

[GNU General Public License v2.0](LICENSE) or later.

## Author

Built by **[Marcin Zmuda](https://marcinzmuda.com)** · [Report a bug](../../issues)
