# Changelog

Wszystkie istotne zmiany w projekcie Ligase.

Format oparty na [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/).
Wersjonowanie zgodne z [Semantic Versioning](https://semver.org/lang/pl/).

Pełne, szczegółowe release notes — w pliku [`readme.txt`](readme.txt) (WordPress format).

## [2.4.22] - 2026-06-07

### Dodane
- **PodcastSeries schema** (2.4.19): nowy typ dla landing-page hub'u podcastu (Spotify / Apple / YouTube `sameAs`, `webFeed` RSS, `numberOfEpisodes`).
- **Person — Personal Brand Pack** (2.4.18-19): pięć nowych pól repeater na profilu autora — `worksFor` external, `affiliation`, `subjectOf`, `workExperience` → `worksFor` array z OrganizationRole (role-property pattern schema.org), `award`, `agentInteractionStatistic` (InteractionCounter z YouTube / Spotify / LinkedIn — manual-action ostrzeżenie w UI dla wymyślonych liczb).
- **Google open-web popularity badges** (2.4.20): meta-box pokazuje obok każdego typu schema kolorową odznakę z bucket'em adopcji (`10M+` / `1M-10M` / `100K-1M` / `10K-100K` / `1K-10K`) z `schemaorg/schemaorg/data/public_stats/google/2026_05.csv`.
- **`Ligase_Popularity_Stats`** klasa static — API: `bucket($type)`, `tier($type)`, `badge_html($type)`. Refresh co ~6 miesięcy.
- **Smart schema-type detection** (2.4.10) w listingu admina: page slug/title heuristics → `AboutPage` / `ContactPage` / `CheckoutPage` / `CollectionPage` / `FAQPage` / `WebPage`.
- **OPcache auto-reset** (2.4.10) na `register_activation_hook` + `upgrader_process_complete`.
- **Output-buffer scrubbing** (2.4.13-14): w `standalone_mode` ON, `ob_start` callback strip'uje obce `<script type="application/ld+json">` z BreadcrumbList / Article / Product injectowane przez WooCommerce theme'y (XStore / Flatsome / Woodmart).
- **Audytor `@graph` unwrap** (2.4.17): każdy węzeł `@graph` oceniany osobno + dobierany do post_type.
- **Persistencja tabbed settings** (2.4.14): hidden-input pattern + `array_key_exists()` w `sanitize()`.
- Doc: `docs/google-stats-coverage-2026-05.md` — coverage map Ligase vs Google open-web stats + roadmap 2.5.x.

### Naprawione (krytyczne)
- **Score 0/100 dla wszystkich postów** (2.4.8) — 4 typo'y kluczy opcji w `Ligase_Score` (`organization_name` → `org_name` itp.) + 3 ghost-key readów.
- **`shippingDetails` na OnlineStore — Schema Validator reject** (2.4.10): property dozwolona TYLKO na Offer. Każdy Product Offer inline'uje site-level shipping.
- **`SearchAction.target` URL-encoded** (2.4.10) — `home_url()` łamało Sitelinks Search Box.
- **`workExperience` na Person nie istnieje w schema.org** (2.4.22) — migrate do `worksFor` array z OrganizationRole.
- **`returnPolicyCategory` + `refundType` + `returnShippingFeesAmount`** (2.4.10-13) brakujące pola MerchantReturnPolicy.
- **`@type` stamping na `handlingTime` / `transitTime`** (2.4.11): `QuantitativeValue` + `ShippingDeliveryTime` + `unitCode: DAY`.
- **ItemList karuzele — wykluczające się `url` + `item`** (2.4.15-16).
- **`case 'page'` generatora nie wywoływał optional types** (2.4.21).
- **`Suppressor::is_active()`** static state leak w FPM workers — czyta opcję fresh.
- **VideoObject / WC unknown stock / JobPosting addressCountry** — wszystkie validation fixes.

### Naprawione (bezpieczeństwo)
- **Export settings leakuje `ner_api_key` + GSC service account JSON** (2.4.8) — `__REDACTED__`.
- **`javascript:` URL filter** w textareas użytkownika.
- **`_ligase_override` type whitelist** w `save_meta_box`.
- **`bulk_set_flags`** per-post `edit_post` capability check.

### Zmienione
- `worksFor` na Person — gdy `ligase_works_for_name` ustawiony, override `@id` ref do site Organization na inline Organization.

### Usunięte
- `Ligase_Auditor::intercept()` (2.4.10) — deprecated jako no-op.

## [2.0.0] - 2026-03-29

### Dodane — Google Search Console
- Integracja GSC przez Service Account JWT (bez OAuth redirect)
- AES-256-CBC szyfrowanie credentials (ten sam pattern co Loom)
- Rich Results dashboard: klikniecia, wyswietlenia, CTR, pozycja per typ (searchAppearance)
- Sync danych GSC do post meta (_ligase_gsc_clicks, impressions, ctr, position)
- Dashboard: karta GSC z formularzem polaczenia lub danymi rich results
- AJAX: gsc_save_credentials, gsc_disconnect, gsc_test_connection, gsc_sync, gsc_rich_results

### Dodane — Gutenberg Sidebar
- Schema sidebar panel w edytorze Gutenberg: auto-walidacja, lista typow, bledy/ostrzezenia, przycisk "Testuj w Google Rich Results"

### Dodane — Testy
- BlogPostingTest: test_image_included_at_696px, test_image_multiple_ratios, test_speakable_present, test_potential_action, test_access_mode, test_default_type
- AuditorTest: test_should_render_false_when_yoast_active, test_supplement_schema_author_id_format

### Dodane — Nowe funkcje
- Import z wtyczek SEO: one-click import ustawien z Yoast SEO, Rank Math, All in One SEO (nazwa, logo, social links, dane autorow)
- Schema Validator: walidacja JSON-LD per post z listami bledow/ostrzezen i podgladem
- Tygodniowy raport zdrowia schema: WP-Cron email z problemami (low score, brak obrazow, stare posty, brak zajawek)
- WPML / Polylang support: auto-detekcja, poprawny inLanguage, sameAs linkowanie miedzy tlumaczeniami
- FAQ block: live licznik slow z kolorowym feedbackiem (optymalne 40-60 slow dla AI)

### Dodane — Nowe pola schema
- BlogPosting: temporalCoverage (news/historia)
- Organization: contactPoint (ContactPoint z telefonem)
- Person: mainEntityOfPage (strona archiwum autora)

### Dodane — Admin
- Narzedzia: import z wtyczek SEO, walidator schema, raport zdrowia, ustawienia health report

## [1.2.0] - 2026-03-29

### Dodane — Nowe typy schema
- QAPage — dla artykulow Q&A (+58% cytowan AI vs Article)
- DefinedTerm / DefinedTermSet — slowniki i glossary
- ClaimReview — weryfikacja faktow z 6 poziomami verdict
- SoftwareApplication — recenzje narzedzi i aplikacji
- AudioObject — auto-detekcja Spotify/Buzzsprout/Anchor embeds
- Course — kursy online z CourseInstance i offers
- Event — wydarzenia z lokalizacja (online/offline), status, bilety

### Dodane — Nowe pola schema
- BlogPosting: isBasedOn (cytowane zrodla), hasPart (serie artykulow)
- VideoObject: @id + inLanguage na YouTube auto-detect
- Review: name z tytulu posta jako fallback

### Dodane — UI/UX
- Metabox: 9 typow schema z tooltipami (info o deprecated FAQ/HowTo)
- Dashboard: baner ostrzegawczy gdy wykryto Yoast/AIOSEO bez standalone mode
- Posty: checkboxy bulk select + przycisk "Napraw zaznaczone" (batch AJAX)

### Poprawione — Optymalizacje
- Score: shared get_sample_posts() — eliminacja duplikatow zapytan SQL
- Suppressor: dodany The Events Calendar (tribe_events_jsonld_enabled)

## [1.1.0] - 2026-03-29

### Naprawione
- P0: Domyslny typ schema zmieniony z Article na BlogPosting
- P0: Logika should_render() — poprawne wykrywanie Yoast/AIOSEO/RankMath (nie generuje duplikatow)
- P0: Suppressor przeniesiony na wp_loaded — dziala przed wp_head innych wtyczek
- P0: Meta key mismatch — FAQPage/HowTo/Review teraz poprawnie sie wlaczaja
- P0: supplement_schema() — @id autora zgodny z reszta grafu (home_url/#author-ID)
- P0: BreadcrumbList — hierarchia zagniezdonych stron (get_post_ancestors)
- P1: Bloki Gutenberg FAQ/HowTo — $block->context[postId] zamiast get_the_ID()
- P1: get_graph_for_post() — setup_postdata() dla poprawnego kontekstu WP
- P1: Score cache invalidowany po save_post i updated_option
- P1: Author score cache invalidowany po profile_update i updated_user_meta

### Dodane
- BlogPosting: SpeakableSpecification (cssSelector konfigurowalne w ustawieniach)
- BlogPosting: accessMode (textual/visual)
- BlogPosting: potentialAction ReadAction
- BlogPosting: about (Wikidata-linked entities) + mentions (NER entities)
- BlogPosting: 3 warianty obrazu (oryginal, 4:3, 1:1) zamiast jednego
- Organization: telephone, description, founder, employee (entity graph)
- Person: honorificPrefix, alumniOf (CollegeOrUniversity), hasCredential
- Review: name (fallback z tytulu posta), reviewBody, publisher
- VideoObject: @id, inLanguage, duration (z post meta)
- Ustawienia: Speakable CSS Selectors, telefon organizacji, opis organizacji
- Pola autora: tytul (dr./prof.), uczelnia, certyfikat/kwalifikacja
- Logo + ikony wtyczki (48, 128, 256, 512px + banner 772x250)
- README.md, CHANGELOG.md, CONTRIBUTING.md, SECURITY.md, CODE_OF_CONDUCT.md
- .gitignore, .editorconfig, composer.json
- GitHub templates (bug report, feature request, PR)

## [1.0.0] - 2026-03-29

### Dodane
- Generowanie schema JSON-LD: Article, BlogPosting, NewsArticle
- Person schema z syganlami E-E-A-T (jobTitle, knowsAbout, sameAs)
- Organization schema z logo, social links, Wikidata
- WebSite schema z SearchAction (Sitelinks Search Box)
- BreadcrumbList schema
- FAQPage schema + blok Gutenberg
- HowTo schema + blok Gutenberg
- VideoObject schema z auto-wykrywaniem YouTube embed
- Review schema
- AI Search Readiness Score (0-100) na dashboardzie
- E-E-A-T Author Scoring per autor
- Schema Auditor: skan / uzupelnianie / zastepowanie cudzej schema
- Wykrywanie konfliktow z Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework, Slim SEO
- Entity Detection Pipeline (4 poziomy: Native, Structure, NER, Wikidata)
- Wikidata Lookup (async via WP-Cron)
- Panel admin: Dashboard, Ustawienia, Posty, Audytor, Encje, Narzedzia
- Metabox "Schema Markup" w edytorze postow
- Pola autora: jobTitle, knowsAbout, LinkedIn, Twitter/X, Wikidata
- Auto-naprawa: daty ISO 8601, skracanie naglowkow, konwersja typow
- Cache schema z transients + automatyczna invalidacja
- Bypass cache pluginow (WP Rocket, LiteSpeed, W3 Total Cache)
- Import/eksport ustawien (JSON)
- Logger do pliku
- Pelna internacjonalizacja (i18n ready, text domain: ligase)
- Uninstall handler (czysci opcje, post meta, user meta, transients, logi)
- Testy jednostkowe PHPUnit
