=== Ligase — Schema Markup for Blogs ===
Contributors: marcinzmuda
Tags: schema, json-ld, seo, structured data, rich results, ai search, schema.org, entity graph
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 2.0.0
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

== Changelog ==

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
