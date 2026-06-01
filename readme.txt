=== Ligase — Schema Markup for Blogs ===
Contributors: marcinzmuda
Tags: schema, json-ld, seo, structured data, rich results, ai search, schema.org, entity graph
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 2.4.17
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

= 2.4.17 =
**Audytor: 0/100 dla wszystkich postów (był to bug audytora, nie schemy).**

Symptom: panel "Skanowanie i naprawa" pokazywał Score = 0 dla wszystkich 176 postów makumi.eu, mimo że Rich Results Test ich produktów i artykułów był zielony.

Bug w `Ligase_Auditor::get_jsonld_for_post()`: parsowanie pierwszego `<script type="application/ld+json">` z `wp_head` zwracało top-level **envelope** `{"@context": "schema.org/", "@graph": [...]}`. Scorer patrzył na `$schema['headline']` / `$schema['@type']` / `$schema['author']` — których na envelope NIE MA (one są wewnątrz `@graph` w poszczególnych węzłach). Wynik: "Brak headline, Brak datePublished, Brak author..." dla każdego postu.

Naprawione:
* `get_jsonld_for_post()` rozpakowuje `@graph` — każdy węzeł trafia jako osobny schema block do scorer'a.
* `scan_post()` wybiera relevantny węzeł do oceny zamiast pierwszego: dla `post_type=product` → `Product`, dla `page` → `AboutPage`/`ContactPage`/`WebPage`/etc., dla `post` → `BlogPosting`/`Article`/`NewsArticle`. Fallback do pierwszego węzła gdy nie znajdzie target type.

Po wgraniu kliknij "Uruchom audyt" ponownie — Score powinien skoczyć z 0 do realnych 40-90 dla większości postów.

= 2.4.16 =
**ItemList Karuzele: usunięto duplikat `url` + dodano `@id` per-Offer (ostatnie 2 critical Rich Results errors).**

Po 2.4.15 karuzela kategorii miała wciąż 2 krytyczne "Podano identyczne wartości właściwości". Powód: każdy inline Product w karuzeli miał JEDNOCZEŚNIE `@id` (z fragmentem `#product`) ORAZ `url` (bez fragmentu) — base URL identyczny dla danego produktu. Plus każdy Offer wyglądał strukturalnie identycznie (`@type: Offer`, taka sama waluta, dostępność, seller `@id`) — bez unique `@id` parser widział N offerów jako duplikat.

Fix:
* Usunięto `url` z inline `Product` w karuzeli (`@id` carries the URL).
* Usunięto `url` z inline `Article` w karuzeli (parytet).
* Usunięto `url` z `Offer` w karuzeli (duplikowało base URL z Product `@id`).
* Dodano unique `@id` per Offer (`{url}#offer`) — każdy Offer ma teraz distinct identity w grafie.

Te zmiany dotyczą TYLKO inline ItemList nodes — pełna ścieżka Product na stronie produktu (`class-product.php`) zachowuje `url` (potrzebne dla self-contained Merchant Listing).

= 2.4.15 =
**ItemList: wykluczające się właściwości + duplikat url (CRITICAL Rich Results error).**

Karuzela kategorii makumi.eu (`/kategoria/koldry-obciazeniowe/`) zgłaszała 3 krytyczne błędy:
* "Podano identyczne wartości właściwości, a muszą one być niepowtarzalne" (×2)
* "W jednym elemencie uporządkowanych danych użyto co najmniej dwóch wykluczających się wzajemnie właściwości"

Przyczyna: `build_list_item()` emitował JEDNOCZEŚNIE:
* `url` na poziomie ListItem
* `item` z pełnym Product / Article który **też** miał `url`

Schema.org dopuszcza tylko JEDNĄ z dwóch form:
* **Short**: `{ position, url, name }` — carousel z linkami
* **Full**:  `{ position, item: { ...pełna entity... } }` — embedded Product/Article

Mieszanie obu = `url` na ListItem i `item.url` to ta sama wartość → "identyczne wartości". I forma short + full razem → "wykluczające się właściwości".

Naprawiono: gdy budujemy `item` (Product lub Article) — nie emitujemy `url`/`name` na poziomie ListItem. Tylko gdy żadnej embedded entity nie da się zbudować → fallback do short shape.

= 2.4.14 =
**Tabbed settings persist + Article image fallback + scrubber rozszerzony na Article/Product/wszystkie typy.**

**Tabbed settings: zapis jednej zakładki zerował checkboxy z innych zakładek.**

Krytyczna regresja UX widoczna w 2.4.x. Settings ma tabbed UI gdzie KAŻDA zakładka renderuje TYLKO swoje sekcje, ale wszystkie tab'y dzielą jeden `<form>`. Submit z jednej tab'y wysyłał tylko swoje pola — `$_POST['ligase_options']` nie zawierał kluczy z innych tab'ów.

Pętla checkboxów w sanitize() bezwarunkowo zapisywała `$clean[$key] = ! empty($input[$key]) ? '1' : ''` dla każdego znanego checkboxa → kasowała `store_mode` / `org_author_mode` / `lb_service_area` / itd. gdy user był w innej tab'ie.

Symptom: user zaznaczał "Włącz tryb OnlineStore" w Store, klikał Save w innej zakładce → odznaczał się Store, vice versa.

Naprawa dwuczęściowa:
1. `render_checkbox()` emituje **hidden input z `value=""`** PRZED każdym `<input type="checkbox">` o tej samej `name`. PHP $_POST trzyma OSTATNIĄ wartość — checked → `"1"`, unchecked → `""`, brak na tabie → klucz w ogóle nie istnieje w `$input`.
2. `sanitize()` używa `array_key_exists($key, $input)` zamiast bezwarunkowego nadpisania. Klucze których nie ma w `$input` (czyli inne zakładki) zachowują obecną wartość z merge'a z current options.

Bez tego nie da się zapisać ustawień w pełni — każdy save jednej tab'i tracił dane z innych.

**Article: brakujące pole image dla postów z mniejszym featured image.** `build_images()` w BlogPosting wymagało ≥ 1200×675px — dla mniejszych zwracało `[]`, czyli Article emitował się BEZ `image` w ogóle. Google flag'ował "Brakujące pole image (opcjonalnie)". Teraz: zachowano logikę 3-ratio variantów dla ≥ 1200×675, ale dla 696-1199 emituje pojedynczy ImageObject z oryginałem (lepsze niż puste). Poniżej 696 (Google's hard min) nadal nie emituje.

**Scrubber rozszerzony.** Output-buffer scrubber z 2.4.13 dedupowal tylko BreadcrumbList. Powiększony o resztę typów które Ligase zawsze emituje wewnątrz `@graph`: Article / BlogPosting / NewsArticle / WebPage / WebSite / Organization / Product / FAQPage / HowTo / Recipe. Każdy standalone JSON-LD script z tymi typami = obca kopia z theme'a → wycina. Aktywne tylko w `standalone_mode`. Eliminuje duplikat Article widoczny na XStore / Flatsome / Woodmart / inne WooCommerce theme'y.

= 2.4.13 =
**Organization-level MerchantReturnPolicy: refundType + returnShippingFeesAmount + output-buffer BreadcrumbList dedupe.**

**BreadcrumbList duplikat (XStore / Flatsome / Woodmart / Avada).** Filter'y suppressora dla Yoast 27.x z 2.4.10 nie obejmują BreadcrumbList wstrzykiwanego inline przez WooCommerce theme'y — emitują własny `<script type="application/ld+json">{"@type":"BreadcrumbList",...}</script>` bezpośrednio z template parts, bez żadnego filter hook'a do przechwycenia.

Naprawione: output-buffer scrubber w `Ligase_Suppressor::register_breadcrumb_scrubber()`. Aktywne tylko gdy `standalone_mode` ON. Otwiera `ob_start` na `template_redirect`, przy flush'u:
* Trzyma Ligase'owy BreadcrumbList (identifikowany po `@id` zakończonym `#breadcrumb`)
* Trzyma wszystkie `@graph` bloki nietknięte (Ligase'owy główny payload)
* **Wycina** każdy inny standalone BreadcrumbList script

Defensive: parsuje JSON, jeśli się nie da → zostawia, nie modyfikuje admin / REST / AJAX / feeds.

Rich Results Test → "Zasady zwrotów" na stronie głównej flag'ował dla `#return-policy` na Organization/OnlineStore:
* Brakujące pole `refundType`
* Brakujące pole `returnShippingFeesAmount`

Te właściwości naprawiłem wcześniej w Field_Contract (dla Product Offer), ale Organization-level policy w `build_store_return_policy()` ma osobną logikę i tych fix'ów nie dostała.

Dodane:
* `refundType` → `https://schema.org/FullRefund` (zgodne z UPK PL — prawo do zwrotu pieniędzy 14 dni)
* `returnShippingFeesAmount` (MonetaryAmount) — emituje się tylko gdy `store_return_fees=ReturnShippingFees`, pulled z `store_shipping_rate` + `store_currency`
* Whitelist `store_return_fees` (gdy ktoś ręcznie wpisze bzdurną wartość → fallback do `FreeReturn`)
* Plus `refundType` dla Product Offer w Field_Contract (derive helper)

= 2.4.12 =
**Organization: `image` + `address` (PostalAddress) — Google "Firmy lokalne" flags.**

Rich Results Test "Firmy lokalne" na OnlineStore #org flag'ował 2 brakujące pola:
* "Brakujące pole `address`"
* "Brakujące pole `image`"

Naprawione:
* `image` na Organization/OnlineStore — reuse logo URL. Google traktuje to jako legalne (większość schema generators tak robi). Bez tego LocalBusiness/OnlineStore check pokazuje warning.
* `address` jako PostalAddress — pobierane z LocalBusiness option group (`lb_street`/`lb_city`/`lb_region`/`lb_postal`/`lb_country`). Emituje się tylko gdy minimum street LUB city jest wypełnione — pusty PostalAddress to anti-pattern.

Aby pole `address` rzeczywiście pojawiło się w JSON-LD: wypełnij **WP Admin → Ligase → Ustawienia → LocalBusiness** street + city. Reszta (region, postal, country) jest opcjonalna. Pure-online stores bez fizycznego adresu mogą zostawić puste — wtedy `address` nie jest emitowane (zamiast emitować pusty obiekt).

= 2.4.11 =
**Field_Resolver: @type stamping na deliveryTime/handlingTime/transitTime + returnShippingFeesAmount + unitCode DAY.**

Rich Results Test flag'ował na produkcji makumi.eu po 2.4.10:
* "Nieprawidłowy typ obiektu w polu handlingTime (opcjonalnie)"
* "Nieprawidłowy typ obiektu w polu transitTime (opcjonalnie)"
* "Brakujące pole returnShippingFeesAmount (opcjonalnie)"

Przyczyna: w Field_Contract brakowało deklaracji `_containers` dla `deliveryTime`, `handlingTime`, `transitTime` — Field_Resolver budował te obiekty bez stempla `@type`, walidator widział je jako goły `StructuredValue` zamiast `QuantitativeValue`/`ShippingDeliveryTime`.

Plus brak `returnShippingFeesAmount` gdy `returnFees: ReturnShippingFees` — Google chce kwoty którą klient zapłaci za zwrot.

Naprawione:
* 3 nowe containers w field-contract: `deliveryTime` → `ShippingDeliveryTime`, `handlingTime`/`transitTime` → `QuantitativeValue`, `returnShippingFeesAmount` → `MonetaryAmount`.
* 2 nowe fields `unitCode` (handling + transit) → derived `'DAY'`.
* 2 nowe fields `returnShippingFeesAmount.value` + `.currency` — derived z `store_shipping_rate` + `store_currency` (tylko gdy `store_return_fees=ReturnShippingFees` i `store_shipping_rate > 0`).

= 2.4.10 =
**Schema.org validator fix (shippingDetails na OnlineStore odrzucone), returnPolicyCategory + smart type detection + OPcache auto-reset.**

**shippingDetails na OnlineStore = invalid schema.** Wcześniej Organization w trybie store emitowała `shippingDetails` na poziomie Organization/OnlineStore — schema.org Validator słusznie odrzucał ("Property shippingDetails was not recognised by the schema as part of an object of type OnlineStore"). Property `shippingDetails` istnieje TYLKO na `Offer` / `OfferShippingDetails`, nie na Organization. Fix:
* Organization przestaje emitować `shippingDetails`. `hasMerchantReturnPolicy` zostaje (jest dozwolone na OnlineStore).
* Każdy Product Offer inline'uje teraz site-level shipping z opcji (zamiast `@id` ref do nieistniejącego `#shipping-policy`). Cost: kilka linii JSON więcej per produkt. Benefit: każdy Offer jest self-contained Merchant Listing który Google + Validator akceptują.

**Brakujące pola MerchantReturnPolicy.** Field_Contract nie deklarował `returnPolicyCategory`, `returnMethod`, `returnFees` na ścieżce site-level — Field_Resolver ich nie emitował, Google flag'ował "Brakujące pole returnPolicyCategory". Dodane:
* `returnPolicyCategory` → derived `https://schema.org/MerchantReturnFiniteReturnWindow` (bo `merchantReturnDays > 0`).
* `returnMethod` → derived `https://schema.org/ReturnByMail` (PL e-commerce default).
* `returnFees` → derived z opcji `store_return_fees` z prefixem `https://schema.org/`.
* `shippingDetails.*` (8 sub-properties) — pełna struktura field-contract dla site-level shipping inline'u.

**Smart schema type detection.** Pages w admin liście "Ligase → Posty" pokazywały się jako `BlogPosting` (sztywny hardcode-fallback gdy nie ma override + global default). To było UI prediction (front emitował WebPage), ale mylące. Teraz funkcja `ligase_guess_schema_type_for_post()`:
* WooCommerce produkt → `Product`
* `post_type === 'post'` → `BlogPosting`
* Page slug/title zawiera "kontakt"/"contact" → `ContactPage`
* "o-nas"/"about" → `AboutPage`
* "koszyk"/"cart"/"zamowienie"/"checkout" → `CheckoutPage`
* "sklep"/"shop"/"blog"/"aktualnosci" → `CollectionPage`
* "faq"/"pytania" → `FAQPage`
* inne pages → `WebPage`

**OPcache auto-reset.** Po install + po WP "Replace current with uploaded" auto-fires `opcache_reset()` jeśli funkcja dostępna. Bez tego shared-host PHP-FPM (Smarthost / cPanel / DirectAdmin) trzymał skompilowaną starą wersję `class-settings.php` z mniejszą sanitize() whitelist'ą. Symptom: nowo dodane checkboxy "klikały się, ale wracały do unchecked po Save". Bug wystąpił na 2.4.6→2.4.7 (`org_author_mode`) i 2.4.7→2.4.8 (`default_schema_type`, `health_report_enabled`). Auto-reset eliminuje tę klasę regresji.

**Yoast BreadcrumbList duplikat.** Dodane 4 nowe filter'y Yoast 27.x dla BreadcrumbList: `wpseo_schema_breadcrumb`, `wpseo_schema_breadcrumb_list_show`, `wpseo_should_output_breadcrumbs_schema`, `wpseo_schema_BreadcrumbList`. Gdy `standalone_mode` ON, Yoast nie powinien już emitować swojego BreadcrumbList.

= 2.4.8 =

Pages domyślnie pokazywały się w panelu "Ligase → Posty" jako `BlogPosting` (sztywny fallback gdy ani per-post override ani globalny default_schema_type nie ustawione). To było UI prediction, nie realny output JSON-LD (generator zawsze emitował WebPage dla pages), ale mylące i prowadziło do podejrzeń że schema jest zła. Teraz:
* **WooCommerce produkt** → Product
* **post_type `post`** → BlogPosting
* **page slug/title** zawiera "kontakt"/"contact" → ContactPage
* zawiera "o-nas"/"o-mnie"/"about" → AboutPage
* zawiera "koszyk"/"cart"/"zamowienie"/"checkout" → CheckoutPage
* zawiera "sklep"/"shop"/"blog" → CollectionPage
* zawiera "faq"/"pytania" → FAQPage
* inne pages → WebPage (Google-safe default)

Funkcja jest globalna (`ligase_guess_schema_type_for_post( int $post_id ): string`), więc Audytor i Score też mogą jej używać do dopasowania check-listy do realnego typu strony.

**OPcache reset po install/update** — funkcja `opcache_reset()` jest teraz wywoływana w:
* `register_activation_hook` (przy każdym Aktywuj)
* `upgrader_process_complete` (po WP-side "Replace current with uploaded")

Bez tego shared-host PHP-FPM (Smarthost / cPanel / DirectAdmin) trzymał skompilowaną starą wersję `class-settings.php` z mniejszą `sanitize()` whitelist'ą. Symptom: nowo dodane checkboxy/dropdowny "klikały się, ale wracały do unchecked po Save". Bug wystąpił na linii upgrade'ów 2.4.6→2.4.7 (`org_author_mode`) i 2.4.7→2.4.8 (`default_schema_type`, `health_report_enabled`). Auto-reset eliminuje tę klasę regresji raz na zawsze.

= 2.4.8 =
**Multi-agent production audit — 14 bug fixes including 4 score-killer typos and 1 FPM state leak.**

A full read-only audit by 4 parallel agents (settings integration / type classes / AJAX security / core pipeline) surfaced 35+ findings. This release fixes the 14 most production-critical. Detailed audit reports in `docs/audit-2025-06-01-*.md`.

**Settings / data persistence (silent data loss):**
* `default_schema_type` dropdown — was a ghost field (rendered, accepted choice, never saved). Added to sanitize() text_fields whitelist.
* `health_report_enabled` checkbox — Turn ON worked via defaults merge, turn OFF never persisted. Added to sanitize() checkbox whitelist.

**Score-killer typos (explains 0/100 across all sites):**
* `Ligase_Score` was reading FOUR non-existent option keys: `organization_name` / `organization_logo` / `organization_knows_about` / `organization_same_as`. Real keys are `org_name` / `org_logo` / `knows_about` / aggregated from `social_*` fields. Score was permanently missing 30+ points on every site since plugin's first release. Fixed all 4 reads.
* Three more ghost-key reads: `use_graph`, `enable_breadcrumb`, `enable_search_action` — none of these settings exist; the corresponding Ligase features (entity @graph, BreadcrumbList, SearchAction) are always-on. Hardcoded to `true` so score reflects reality.

**Output correctness (Google can reject these):**
* `WebSite.potentialAction.target` — `home_url()` was URL-encoding the `{search_term_string}` placeholder to `%7Bsearch_term_string%7D`, breaking the Sitelinks Search Box. Build the URL by string concat to preserve the literal braces.
* `Organization.employee[]` — `get_users(has_published_posts => true)` is deprecated since WP 6.4 (expects array of post_types). Fixed + added 20-entry cap + post_count ordering so big sites don't dump 500-entry employee arrays into every page's JSON-LD.
* `JobPosting.jobLocation.address.addressCountry` — `strtoupper(substr("Poland", 0, 2))` mangled human-readable country names to `"PO"`. Now validates as ISO 3166-1 alpha-2 (2 letters only) or drops the field.
* `VideoObject` — emitted empty `thumbnailUrl` / `embedUrl` / `description` strings when manual meta was missing. Google flags this as invalid AND it's an SEO-spam signal ("we said we have a video, we don't"). Now: only emits fields with real values; returns empty array if essentials missing so Generator drops the node.
* WC unknown stock status — previously defaulted to `https://schema.org/InStock` for unknown stock states (custom 'preorder', 'discontinued' added by 3rd-party plugins). Misleading-info = manual-action risk. Now returns null so the field is omitted, not lying.

**Security / data leakage:**
* Settings export (`handle_ligase_export_settings`) leaked the LLM API key (`ner_api_key`) and GSC service account JSON in plaintext. Now redacted to `__REDACTED__` in the exported file; re-import on the same site keeps live values (import has a whitelist that doesn't accept those keys anyway).

**Fatal-error guards:**
* `Generator::with_post_globals()` wrote `$wp_query->is_singular = true;` unconditionally. Fatals on AMP renderers / REST controllers / certain page builders that call `do_action('wp_head')` with a non-WP_Query global. Added `instanceof WP_Query` guard.
* Generator had a duplicate `case 'blog_listing':` in the switch — second arm was unreachable. Linter / PHPStan reject. Cleaned up.
* `Suppressor::is_active()` returned the legacy `private static bool $is_active` which could leak across requests in long-lived FPM workers with OPcache class-table persistence. Now reads `ligase_options.standalone_mode` directly every call.

**Author @id integrity:**
* `Ligase_Type_BlogPosting::author_ref_id()` extended to return `#org` not just for redakcja flag but ALSO when the author user doesn't exist (orphaned post). Eliminates dangling `#author-0` references in JSON-LD.
* 7 other type classes (Review, QAPage, ClaimReview, ItemList, Generator profile_page + about/mainEntity) now route through `author_ref_id()` instead of constructing `#author-{id}` directly. Single source of truth for author entity resolution.

**Polish/EU number formatting:**
* Float/int sanitizers cast `"1 299,90 zł"` to `1.0` — a 1300× price misstatement. Now strips currency/spaces, normalises the last comma OR dot as decimal separator, treats earlier separators as thousands. `"1 299,90"` → `1299.9`, `"1.299,90"` → `1299.9`, `"1299"` → `1299`.

= 2.4.7 =
**Store tab not visible (2.4.6 ship bug) + 2 checkboxes silently never saved.**

* **Store / E-commerce tab missing from UI** — 2.4.6 added the `SECTION_STORE` PHP section but the settings view (`admin/views/settings.php`) uses a custom hand-built tab nav, not the default WP Settings API rendering. The new section was registered server-side but never appeared in the tab strip. Added explicit `store` tab entry between Local Business and AI/NER.
* **`org_author_mode` and `lb_service_area` checkboxes — silently never saved.** The `Ligase_Settings::sanitize()` checkbox loop hard-codes which keys to persist. Only 4 keys were listed: `standalone_mode`, `force_output`, `debug_mode`, `store_mode`. The `org_author_mode` checkbox (added in 2.4.6) and `lb_service_area` (older) were missing from the list, so they rendered, accepted clicks, even checked the box visually after Save — but the option was wiped to empty in sanitize. Fix: extended the checkbox key whitelist.
* No other code changes — 2.4.6 features (Store backend + Redakcja mode + per-user `ligase_is_redakcja`) now work end-to-end.

= 2.4.6 =
**Store / E-commerce settings UI + Organization-as-author (Redakcja) mode wired.**

* **`Ligase → Ustawienia → Store / E-commerce`** — nowa sekcja w panelu ustawień z 10 polami zasilającymi site-level merchant policies (włącznie z `returnPolicyCountry` wymaganym przez Google od marca 2025):
  - Włącz tryb OnlineStore (checkbox)
  - Waluta (ISO 4217) — auto-uppercase, 3 znaki
  - Polityka zwrotów — kraj (ISO 3166-1 alpha-2), dni, opłaty (dropdown z 4 enum'ami schema.org: FreeReturn / ReturnFeesCustomerResponsibility / ReturnShippingFees / RestockingFees)
  - Wysyłka — kraj docelowy, stawka (0 = darmowa), handling time min/max, transit time min/max
  - Wszystkie pola sanityzowane do ISO-format gdzie trzeba; pusty input = nie emituj (graceful degradation).
* **`org_author_mode` site-wide flag** podpięta do generatora i BlogPosting. Wcześniej była w UI ale **nic nie robiła**. Teraz gdy ON: każdy post emituje `author: { "@id": "#org" }`, węzeł Person jest pomijany. Idealne dla blogów redakcyjnych / firmowych gdzie konta WP są techniczne, nie reprezentują realnych bylinów.
* **Per-user `ligase_is_redakcja` toggle** — nowy checkbox w profilu autora ("Ten użytkownik to redakcja / zespół"). Pozwala na **mieszany site** gdzie 95% autorów to realne osoby (Person), a 1-2 konta to byliny zespołowe (Organization). Per-user override działa niezależnie od globalnego org_author_mode.
* Oba mechanizmy spinają `author = publisher = #org` w grafie. AI Overviews + LLMs traktują ten sygnał lepiej niż fake Person z minimalnymi polami.

= 2.4.5 =
**Posty page: filter post_type/flag + flags column + auditor "Standalone aktywny" status fix + 2.4.4 auditor type-aware.**

* **Filtry w Ligase → Posty:** dropdown "Pokaż" wybiera typ wpisu (post / page / product / każdy publiczny CPT) + opcjonalny dropdown "Tylko z włączonym" filtruje listę po jednej z 16 flag schema. URL ma teraz `?ligase_pt=page&ligase_flag=_ligase_enable_service` żeby zapamiętać widok między akcjami.
* **Kolumna "Znaczniki"** w tabeli pokazuje na każdym wierszu wszystkie aktywne flagi schema jako badge'e (Service / FAQ / Recipe / Product / itd.). Widać od razu które strony co już mają.
* **Bulk panel auto-syncuje z filtrem** — gdy wybierzesz "page" w głównym filtrze, dropdown bulk panela też skacze na "page". Już nie zastosujesz akcji do złej populacji przez przypadek.
* **Audytor schema → "Wykryte wtyczki SEO" status fix.** Wcześniej zawsze pokazywał "⚠ Aktywna — włącz tryb standalone" nawet gdy Standalone Mode był włączony. Teraz:
  - **Standalone Mode ON** → zielony badge "✓ Wyciszone" + zielony banner z hintem "Sprawdź źródło — jeśli widzisz duplikat, niektóre wersje (Yoast 27.x) emitują przez nowe filtry których Ligase jeszcze nie zna; wyłącz schema w tamtej wtyczce ręcznie".
  - **Force Output ON** → żółty badge "⚠ FORCE — duplikat ryzyko" + ostrzeżenie o duplikacie.
  - **Default mode** → ostrzeżenie + przycisk "Przejdź do ustawień → włącz Standalone".

= 2.4.4 =
**Auditor false-positive fix + Polish messages.**

`Ligase_Auditor::collect_issues()` was emitting Article-only checks for EVERY schema node it found — so Product/Service/Person/LocalBusiness/Event/Recipe nodes always carried 9 fake "Missing headline / datePublished / author / publisher" warnings even when those fields are not part of those types' specifications.

* **Type-aware issue rubrics.** Each @type now has its own rubric matching Google's actual requirements for that type:
  - Article-family → existing 9 checks (headline, dates, author, image, publisher, @id)
  - Product → name + image + offer required + identifier (sku/gtin/mpn/brand) + merchant return policy
  - Service → name + provider + serviceType + areaServed
  - Person → name + @id + image + sameAs
  - Organization / OnlineStore → name + url + logo + sameAs
  - LocalBusiness / Attorney / LegalService / Restaurant / Store / Hotel → name + address + telephone + openingHours + geo
  - Event → name + startDate (ISO 8601) + location-or-online + organizer
  - Recipe → name + image + recipeIngredient + recipeInstructions
  - JobPosting → 5 required + location/locationType
  - FAQPage → ≥2 questions
  - HowTo → name + image + ≥2 steps
  - VideoObject → name + thumbnailUrl + uploadDate + content/embedUrl
  - WebSite / WebPage / Blog / CollectionPage / ProfilePage → basic name + url
  - BreadcrumbList / ItemList → ≥1 item
  - SiteNavigationElement → no checks (always silent)
  - Unknown type → minimal generic check (name OR headline)
* **All messages translated to Polish** to match the rest of the admin UI (was English).
* **Sample false-positive eliminated:** a valid Product node with name+price+offer no longer triggers "Missing headline" / "Missing datePublished" / "Missing publisher" / "Missing author".

= 2.4.3 =
**Removed broken "Narzędzia" submenu + bulk schema flags panel.**

* **"Narzędzia" submenu retired.** The page was broken (mixed/incomplete actions, no clear use case) and most of its functionality moved into purpose-built places: bulk flags into Posty, clear cache + import/export into Ustawienia. The `tools.php` view stays on disk for back-compat, but it's no longer linked from the menu.
* **Bulk schema flags panel** (Ligase → Posty → "Masowe ustawianie znaczników schema"):
  - **Scope filter:** post type (post / page / product / any public CPT) + optional taxonomy term slug. Term lookup walks every taxonomy attached to the chosen post type and matches the first one with the given slug.
  - **Flag grid:** 16 schema toggles (Service, FAQPage, HowTo, Review, QAPage, Product, Recipe, JobPosting, DiscussionForumPosting, Course, Event, SoftwareApplication, DefinedTerm, ClaimReview, ProfilePage, Paywall).
  - **Action:** Enable / Disable for the selected flags.
  - **Article variant** field (only for `post`): bulk-set BlogPosting/Article/NewsArticle/TechArticle/LiveBlogPosting on every matching post.
  - **Preview button:** counts matching pages before applying, so you don't fire a 5000-post update by mistake.
  - **Confirm dialog** with explicit list of what will change.
  - Cache invalidated per-post after each update.
* **Two new AJAX endpoints:** `ligase_bulk_set_flags` (apply) and `ligase_bulk_count_targets` (preview). Both `manage_options` only. Flag keys whitelisted server-side to prevent arbitrary meta injection.

**Example workflow** — kancelaria adds Service schema to all "Adwokat * Warszawa" pages in one click:
1. Posty → "Masowe ustawianie znaczników"
2. Typ wpisu: `page`, Termin: `usugi-warszawa` (slug)
3. ☑ Service
4. Akcja: Enable
5. Najpierw pokaż ile pozycji → "Znaleziono 12 pozycji"
6. Zastosuj → ✅ Zaktualizowano 12 z 12 pozycji
7. Wejdź w jeden z pages → wypełnij Service section w metaboxie (areaServed, priceRange, serviceType)

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
