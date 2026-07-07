# Ligase Schema Markup — pełny audyt (2026-07-07, v2.4.24)

Zaawansowana, wielowarstwowa analiza wtyczki: bezpieczeństwo, poprawność logiki, wydajność, architektura i kierunki rozwoju. Każde znalezisko zweryfikowane bezpośrednio w kodzie źródłowym (plik:linia). Analiza przeprowadzona równolegle przez wyspecjalizowane przebiegi: security / bugs (5 warstw) / performance / architecture.

---

## 1. Bezpieczeństwo — werdykt: bardzo dobry

**Zero luk krytycznych i wysokich.** Wtyczka jest ponadprzeciętnie zabezpieczona:

- Wszystkie ~37 endpointów AJAX ma nonce + capability check (`class-ajax.php:82-98`), endpointy edytorskie dodatkowo per-post `edit_post` (`verify_post_access`, `:104-112`).
- Brak `wp_ajax_nopriv_*`, brak publicznych REST route'ów — zero powierzchni ataku bez logowania.
- Output JSON-LD ma jawną ochronę przed script-breakout (`class-output.php:64-85`: `wp_json_encode` + escape `</` i `<!--`).
- Całe SQL przez `prepare()`/`esc_like()`; brak `sslverify => false`; klucze API redagowane przy eksporcie; katalog logów chroniony potrójnie (`.php` die-prefix + `.htaccess` + `web.config`).

### Znaleziska (Medium/Low)

| # | Waga | Problem | Lokalizacja |
|---|------|---------|-------------|
| S1 | Medium | Klucz API NER (OpenAI/Anthropic/Google/Dandelion) przechowywany **plaintext** w `ligase_options` i renderowany z powrotem w `value` pola password. Niespójne z GSC, które szyfruje AES-256. Wyciek backupu DB = wyciek płatnego klucza. | `admin/class-settings.php:624-626`, `:529-545` |
| S2 | Low | Niespójne escapowanie w admin JS — `warnings` walidatora, GSC `page`/`appearance`, auditor `issues` wstawiane przez `.html()` bez escape. Dziś dane nie są attacker-controlled (latentne), ale każdy przyszły string interpolujący tytuł posta = stored XSS w adminie. | `admin/views/posts.php:541`, `assets/js/admin.js:160-161, 354-355` |
| S3 | Low | Szyfrowanie GSC: AES-256-**CBC** bez HMAC (malleable) + klucz = surowy `wp_salt('auth')` — rotacja salts w wp-config trwale niszczy credentials. Zalecane AES-256-GCM + marker wersji klucza. | `includes/class-gsc.php:359-379` |
| S4 | Low | `maybe_unserialize()` na opcji obcej wtyczki (AIOSEO) — klasyczny sink object-injection; praktyczne ryzyko minimalne, ale warto `allowed_classes => false`. | `includes/class-importer.php:235` |
| S5 | Info | Zrotowane logi `*.log.php.1/.2/.3` tracą ochronę die-prefix (PHP ich nie wykonuje) — na **Nginx** (brak per-dir config) są publicznie pobieralne pod zgadywalnym URL-em. Rotować do `ligase-debug.1.log.php`. | `includes/class-logger.php:249` |

---

## 2. Bugi logiczne — 5 warstw, ~45 potwierdzonych

### KRYTYCZNE — funkcje flagowe, które nie działają

**B1. Regex audytora nie łapie schemy Yoast/Rank Math — audyt konkurencji to no-op na głównych celach.**
`includes/class-auditor.php:118` i `:1157` — wzorzec `/<script\s+type=["\']application\/ld\+json["\']\s*>/` wymaga, by `type=` był pierwszym atrybutem i by `>` następował zaraz po cudzysłowie. Yoast emituje `<script type="application/ld+json" class="yoast-schema-graph">`, Rank Math `class="rank-math-schema"` — **żaden nie matchuje**. Skutek: na stronie z Yoast audytor widzi „brak schemy", scoring i supplement/replace nigdy nie odpalają.
Fix: `/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si` (ten poprawny kształt już jest w `class-suppressor.php:275`).

**B2. `sync_block_meta` kasuje dane FAQ/HowTo zapisane metaboxem — przy każdym zapisie.**
`includes/class-plugin.php:159, 269-274` vs `admin/class-admin.php` (`save_meta_box`, prio 10). Metabox (klasyczny edytor / Gutenberg bez bloków Ligase) zapisuje `_ligase_faq_items`/`_ligase_howto`, a chwilę później `sync_block_meta` (prio 20) nie znajduje bloku w treści i **bezwarunkowo usuwa oba klucze**. Funkcja FAQ/HowTo z metaboxa nigdy się nie utrwala.
Fix: kasować tylko dane pochodzenia blokowego (flaga `_ligase_faq_source=block`) albo pomijać delete, gdy w tym samym requeście był nonce metaboxa.

**B3. Silnik reguł automatyzacji: każda reguła z UI dostaje `id === ''` — druga reguła nadpisuje pierwszą.**
`includes/class-ajax.php:1225` — `sanitize_key( $rule_data['id'] ?? generate_id() )`: `??` nie odpala się dla pustego stringa, który modal zawsze wysyła (`admin/views/rules.php:336`). Użytkownik nigdy nie ma więcej niż jednej reguły; usunięcie jej z UI też nie działa (`:1277` odrzuca puste `rule_id`).
Fix: `! empty( $rule_data['id'] ) ? sanitize_key(...) : Ligase_Schema_Rules::generate_id()`.

**B4. `str_word_count()` niszczy polskie znaki w CAŁYM pipeline NER.**
`includes/class-ner-api.php:387` — funkcja jest bajtowa: każdy bajt UTF-8 znaków ą/ć/ę/ł/ń/ó/ś/ź/ż działa jak separator. „Łukasz Kowalski urodził się w Łodzi" → `ukasz, Kowalski, urodzi, si, w, odzi`. Taki tekst trafia do LLM-ów; wyekstrahowane encje („odzi" zamiast „Łodzi") zasilają potem Wikidata i `about` w schemie. **Defekt unieważnia cały polskojęzyczny pipeline NER.**
Fix: `preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY )` + `array_slice`.

**B5. `apply_supplement()` audytora to gwarantowany no-op z panelu admina.**
`includes/class-auditor.php:786-825` → `supplement_schema()` zaczyna od `get_post()` na globalnym `$post`, który w admin-AJAX jest `null` (przywrócony przez `get_jsonld_for_post()` przed wywołaniem) → zawsze `return $schema` bez zmian → UI raportuje „nic do dodania". Fix: przekazywać `$post_id` parametrem.

**B6. Strona Narzędzia — fatal error + w ogóle nieosiągalna.**
`admin/views/tools.php:17` woła nieistniejącą metodę `Ligase_Health_Report::get_last_report()` (fatal), ale i tak submenu `ligase-narzedzia` usunięto w 2.4.3 bez przeniesienia paneli: przyciski eksport/import ustawień, auto-repair, health report, walidator istnieją **tylko** w tools.php, a `admin.js` binduje `initTools()` tylko dla tej strony. Pięć działających endpointów AJAX bez osiągalnego UI.
Fix: zaimplementować `get_last_report()` + przenieść panele do settings.php (i `initTools()` w routerze JS) albo przywrócić submenu.

**B7. Import ustawień niszczy nie-whitelistowane opcje — wbrew własnemu designowi eksportu.**
`includes/class-ajax.php:684-710` — import buduje opcje z 14-kluczowej whitelisty i robi `update_option()` **pełnym zastąpieniem**, nie merge. Import backupu kasuje `ner_api_key`, `gsc_service_account_json`, wszystkie `lb_*`, `store_*`, `speakable_selectors`… Komentarz przy eksporcie (`:596-598`) wprost obiecuje „import merges by key" — nieprawda.
Fix: `array_merge( get_option(...), $sanitized )` + rozszerzyć whitelistę, pomijając `__REDACTED__`.

**B8. „Auto-repair" — permanentny no-op.**
`includes/class-ajax.php:791, 840-844` czyta/pisze meta `_ligase_schema`, którego **nic innego w repo nie tworzy ani nie czyta**. Zawsze `fixed: 0`. Fix: wskazać realne meta/graf albo usunąć feature.

### WYSOKIE — błędna schema emitowana na froncie

**B9. `case 'page'` generatora bez `with_post_globals()`** — `includes/class-generator.php:41-63`: branch stron statycznych (w przeciwieństwie do `single_post`/`single_cpt`) nie jest chroniony przed motywami psującymi globalny `$post` (XStore/Divi/Avada — dokładnie ten scenariusz, dla którego wrapper powstał, i rodzeństwo buga naprawionego w 2.4.21). Skutek: WebPage z URL/tytułem innego posta, flagi typów czytane z cudzego meta.

**B10. Front page (latest posts) emituje CollectionPage z danymi… najnowszego wpisu** — `class-generator.php:64-66` → `build_webpage()` używa `get_the_ID()`/`get_permalink()`, a WP przy `wp_head` ma już ustawiony `$post` na pierwszy post pętli. Strona główna deklaruje `@id`/`name`/`url` ostatniego artykułu — zmienne przy każdej publikacji. Fix: użyć `build_collection_page()`.

**B11. Zduplikowane węzły ItemList** na listingu bloga i archiwach taksonomii produktowych — pushowane w `case` (`class-generator.php:70, 75`) **i ponownie** w catch-all (`:93-100`). Dwa identyczne węzły z tym samym `@id` — Rich Results Test to flaguje.

**B12. Kontener `author` w kontrakcie psuje JSON-LD każdego DiscussionForumPosting** — `class-field-contract.php:530` + stamping w `class-field-resolver.php:77-81`: `author` jest listą `[ {'@id':...} ]`, a stamping dopisuje `['@type']='Person'` do listy → `{"0":{"@id":...},"@type":"Person"}` — niepoprawny węzeł na każdym topiku/reply bbPress. Fix: usunąć wpis kontenera albo pomijać listy numeryczne w stampingu.

**B13. Szkieletowe obiekty shipping w KAŻDEJ ofercie WooCommerce** — `class-field-contract.php:258-263, 276-281`: źródło `derive:unit_code_day` zwraca bezwarunkowo `DAY`, więc sklep bez skonfigurowanej wysyłki i tak emituje `shippingDetails.deliveryTime.handlingTime={"unitCode":"DAY"}` bez stawek i regionów — Google flaguje, a walidator wtyczki tego nie widzi (bo `shippingDetails` nie jest puste). Analogicznie: dangling `returnShippingFeesAmount` (waluta bez kwoty) przy FreeReturn i szkieletowy `baseSalary` w JobPosting bez pensji (`:500-503`); `MerchantReturnFiniteReturnWindow` bez `merchantReturnDays` (`class-field-resolver.php:326-328`).

**B14. Ceny z przecinkiem korumpowane w legacy path** — `class-product.php:259` (i `class-service.php:209-222`): `(float)"24,99"` = `24.0` → JSON-LD reklamuje złą cenę (ryzyko manual action za misleading data). Resolver ma poprawny parser locale-aware (`class-field-resolver.php:439-465`) — ale legacy Product fallback, warianty ProductGroup i cały Service go omijają. Dodatkowo `admin/class-admin.php:692-693` psuje override naiwnym castem **zanim** mądry parser go zobaczy.

**B15. `manual:` w kontrakcie nigdy nie czyta realnego storage Recipe/JobPosting** — resolver czyta `_ligase_override[Typ][klucz]`, ale metabox renderuje takie pola tylko dla Product; Recipe/JobPosting zapisują płaskie `_ligase_recipe`/`_ligase_jobposting`. Skutek: **panel gotowości i walidator kontraktowy zawsze raportują `eligible=false` / brakujące pola wymagane** dla w pełni wypełnionego przepisu/oferty pracy, mimo że output jest kompletny.

**B16. Bloki FAQ/HowTo nie renderują NIC na froncie** (`blocks/faq/index.js:171-174` `save: null`, `render_callback` zwraca `''`), a schema FAQPage/HowTo jest emitowana. Google wymaga, by markup odpowiadał widocznej treści — ryzyko manual action za structured-data spam. Fix: renderować HTML Q&A w `render_callback`.

### ŚREDNIE (wybrane — pełna lista poniżej w tabeli)

- **GSC: zapytanie rich-results jest niepoprawne** — `class-gsc.php:232-244`: `searchAppearance` nie może być łączone z wymiarem `page` (HTTP 400), a wartość filtra `WEB_LIGHT_RESULTS` nie istnieje w enumie API; `api_post()` nie sprawdza kodu odpowiedzi, więc błąd Google udaje dane. Dashboard rich-results realnie nie może działać.
- **GSC: `esc_url_raw()` niszczy domain properties** `sc-domain:example.com` (`class-gsc.php:190`) — każdy kolejny call na złą ścieżkę.
- **Bucket `locations` vs `places`** — `class-pipeline.php:43, 115` i `class-score.php:440` iterują po `locations`, ale wszyscy producenci NER emitują `places`: encje-miejsca z LLM są gubione przy merge, nie są LLM-confirmowane i zaniżają score.
- **Wikidata suggestions nadpisywane zamiast merge'owane** (`class-wikidata-lookup.php:115`) → pętla churn: kolejne przebiegi kasują sobie nawzajem wyniki, `sameAs` flip-flopuje.
- **Encje z LLM (najlepszy sygnał) nigdy nie trafiają do lookupu Wikidata** — `class-pipeline.php:154`: warunek `frequency >= 2`, a wpisy LLM mają `confidence`, nie `frequency` (`null >= 2` = false + warning PHP 8).
- **Błędy 403/429 Google NLP/Dandelion cachowane 30 dni jako „sukces bez encji"** (`class-ner-api.php:305-378`) — zły klucz = miesiąc cichej pustki.
- **Import z konkurencji czyta nieistniejące klucze**: Rank Math — tylko Facebook się importuje (Twitter siedzi w `twitter_author_names`, reszta w `social_additional_profiles`); AIOSEO — `facebookUrl` zamiast `facebookPageUrl`; Yoast — socials przeniesione do `other_social_urls` w 14.1 (2020), import ich nie widzi.
- **`sameAs` między tłumaczeniami to semantycznie zła schema** (`class-multilingual.php:139-153`) — poprawne właściwości to `workTranslation`/`translationOfWork`.
- **Score: check awatara nigdy nie failuje** (`class-score.php:~630`: literał `avatar/?d=` nie występuje w realnych URL-ach Gravatara — 10 pkt gratis) oraz `org_name ?? get_bloginfo()` nie odpala fallbacku dla `''` (`:266`) — score kłóci się z faktycznym outputem.
- **`invalidate_all()` czyści transienty SQL-em** — na Redis/Memcached bez efektu: zmiana nazwy/logo organizacji serwuje starą schemę do 12 h (`class-cache.php:130-141`). Hooki WooCommerce unieważniają tylko bieżący locale — na WPML tłumaczenia produktów mają nieświeże ceny (`class-plugin.php:129-143`).
- **JobPosting: `description` odzierany z HTML** dwa razy (save + render), a Google wymaga pełnego HTML (`class-jobposting.php:50-53`, `admin/class-admin.php:500`).
- **Recipe: prepTime/cookTime bez walidacji ISO-8601** — „30 min" przechodzi do outputu (HowTo waliduje, Recipe nie; `class-recipe.php:51-55`).
- **Event/Course/SoftwareApplication: `?? `/`isset()` przepuszcza `price:""`** → odrzucenie rich result (`class-event.php:97`, `class-course.php:69-76`, `class-softwareapplication.php:67-73`).
- **Organization: do 20 wiszących referencji `@id` `#author-N`** na każdej stronie (graf zawiera tylko Person bieżącego autora; refy budowane z pominięciem `author_ref_id()` — użytkownicy `ligase_is_redakcja` zawsze dangling) + logo z zaszytymi na sztywno wymiarami 112×112 niezależnie od pliku (`class-organization.php:96-117, 278-284`).
- **`ligase_fix_post` zwraca cały array score jako `new_score`** (`class-ajax.php:254-262`); **encje „about" zawsze tracą URL Wikidata** — JS nie wysyła `wikidata_url` (`entities.php:374-382`); **reguła AudioObject martwa** — nic nie czyta `_ligase_enable_audio`.

### NISKIE (skrót)

`upvoteCount` z liczby komentarzy w QAPage (fabrykowany sygnał), `datePublished` recenzowanego claimu = data recenzji (ClaimReview), `contentUrl` AudioObject wskazuje strony HTML platform zamiast plików audio, `itemListOrder: Descending` deklarowany bezwarunkowo, FAQPage tłumi poprawne jednopytaniowe FAQ (`count < 2`), regex Wikipedii matchuje `notwikipedia.org`, regex ISO-8601 akceptuje `"P"`/`"PT"`, detekcja miejsc pomija zdania zaczynające się przyimkiem („W Warszawie…"), heurystyka HowTo odpala się na „Jakość…", licznik bulk NER może pokazać >100%, `mainEntity` bloga może wisieć na `/blog/page/2/`, `apply_replacement()` zwraca błąd przy ponownym zastosowaniu (semantyka `update_post_meta` przy identycznej wartości), duplikat reguły `language` w rubryce site-score, scrubber suppressora ignoruje `@type` będące tablicą, preview/audyt zawsze dokleja Person wbrew `author_is_organization()`, podcast toggle poza bulk-whitelistą.

---

## 3. Wydajność

Hot-path frontu jest **dobrze zaprojektowany**: transient cache 12 h, jedna autoloadowana opcja, zero blokujących HTTP przy renderze, assety tylko na stronach wtyczki, przemyślana inwalidacja (per-post + archiwa + hooki Woo). Problemy koncentrują się w operacjach masowych:

| # | Waga | Problem | Skala bólu |
|---|------|---------|-----------|
| P1 | High | `scan_all_posts()`: `posts_per_page => -1` + **pełny render `wp_head` per post** w jednym requeście AJAX (`class-auditor.php:475-502, 1138-1155`). | 100 postów ≈ 10-20 s; 1k = timeout/504; 10k = OOM |
| P2 | High | `fix_all_posts()`: to samo, ale **podwójny** render per post (score + re-scan w `apply_replacement`) (`class-ajax.php:290-330`). | od ~500 postów |
| P3 | High | Bulk NER: do **500 pojedynczych eventów wp_cron** (`class-ner-api.php:147-181`) → autoloadowana opcja `cron` puchnie o 50-100 KB czytana na KAŻDYM wejściu na stronę przez ~83 min; 30-sekundowe cale LLM potrafią wisieć na requestach odwiedzających (page-load cron). Fix: jeden worker + kolejka (lub Action Scheduler). | każdy shared hosting |
| P4 | High | GSC sync: `url_to_postid()` × do 1000 wierszy + do 6000 zapisów meta w jednym blokującym requeście (`class-gsc.php:282-309`). | każda realna witryna |
| P5 | Medium | Standalone mode: `ob_start` + regex + `json_decode` KAŻDEGO bloku ld+json (w tym własnego 20-50 KB @graph) na pełnym HTML każdego requestu — nawet przy cache-hit i braku obcej schemy (`class-suppressor.php:251-316`). Fix: tani pre-check `substr_count` + skip `@graph`. | ruch bez page cache |
| P6 | Medium | `Organization::build()` — `get_users( orderby => post_count )` = skorelowany COUNT po `wp_posts` per user, przy każdym cache-miss; po update wtyczki zimny cache + crawler = seria wielosekundowych zapytań (`class-organization.php:105-111`). | 100k postów |
| P7 | Medium | Health report: sweep `-1` + 5-8 zapytań per post w jednym ticku crona (`class-health-report.php:52-103`); pierwszy load dashboardu na 10k postów = minuty. Fix: chunking + `_prime_post_caches()`. | 10k+ postów |
| P8 | Medium | `opcache_reset()` czyści **cały** OPcache serwera przy aktywacji/upgrade — compile storm dla wszystkich stron w puli FPM (`ligase.php:43-64`). Fix: `opcache_invalidate()` po plikach wtyczki. | shared hosting |
| P9 | Low | `JSON_PRETTY_PRINT` na produkcji: +30-50 % wagi payloadu JSON-LD na każdej stronie (`class-output.php:64-67`). Gate'ować na debug. | bandwidth |
| P10 | Low | Autoload hygiene: `ligase_gsc_service_account` (3 KB, admin-only) z autoload=yes (`class-gsc.php:56`); klucz cache z wersją wtyczki osieroca pełny zestaw transientów po każdym release (bounded, 12 h). | drobne |

---

## 4. Architektura i jakość

**Mocne strony:** typowany PHP 8+, skonfigurowane PHPUnit 10 / PHPCS (pełny ruleset WordPress) / PHPStan lvl 5, wzorowy `uninstall.php`, ~25 filtrów `apply_filters` dla deweloperów, system field-contract (`class-field-contract.php` + `class-field-resolver.php`) — deklaratywne źródła/sanityzacja/eligibility per typ — to realna przewaga nad wewnętrzną architekturą Yoast/RankMath.

**Słabości:**

1. **Dwie architektury naraz** — tylko 4 z 26 klas typów używa resolvera (Product, JobPosting, Recipe, DiscussionForumPosting); pozostałe ~20 to kopiuj-wklej proceduralnych `build()` bez wspólnej klasy bazowej (guard enable-flag powtórzony 15×, featured-image 9×; niespójne sygnatury `array` vs `?array`).
2. **God classes**: `class-ajax.php` (1519 linii, 37 handlerów, 6 domen), `class-auditor.php` (1257 — interceptor + scoring + persystencja + detekcja), `class-score.php` (1132 — checki zaszyte na sztywno zamiast rejestrowalnych obiektów).
3. **Brak CI** — `.github/workflows/` nie istnieje, mimo że `composer lint`/`test` są gotowe. Fix na godzinę, ogromny zwrot.
4. **Testy: 6 plików / 57 testów na ~35 klas.** Nietestowane: Generator (rdzeń, 8 branchy kontekstu), Field_Resolver, cały Ajax, Importer, GSC, Suppressor, Cache, 22 klasy typów. CHANGELOG sam dokumentuje klasę regresji, którą testy by złapały („Score 0/100 — 4 typos kluczy opcji").
5. **i18n blokuje oba rynki**: źródła to mieszanka PL/EN; ~surowy polski poza `__()` w `class-score.php` (linie 274-996) i `class-validator.php:204-209`; bloki JS bez `wp.i18n`; brak `.pot`; slugi stron admina po polsku (`ligase-ustawienia`). Wejście na wordpress.org wymaga English-first + `pl_PL.po`.
6. **Autoload**: composer classmap zadeklarowany, ale runtime robi 45 ręcznych `require_once` na każdym requeście (`class-plugin.php:20-94`), z kruchym komentarzem o kolejności.
7. **Brak punktów rozszerzeń pod add-ony**: zero `do_action`, brak filtra rejestracji typów (Generator instancjonuje ~30 klas na sztywno), brak filtra na checki score i `SCHEMA_TYPES` reguł.

**Priorytety inżynieryjne:** (1) CI na GitHub Actions; (2) golden-file testy pełnego @graph dla ~8 kontekstów; (3) testy Field_Resolver + Importer (czysta logika); (4) English-first i18n + .pot; (5) split class-ajax.php per domena; (6) dokończenie migracji na kontrakt + `Ligase_Type_Base`; (7) autoloader + filterowalny rejestr typów `ligase_schema_types`.

---

## 5. Kierunki rozwoju (vs Yoast / RankMath / Schema Pro)

**Największa dźwignia (architektura już pasuje):**
1. **Źródło `acf:{field}` w Field_Resolverze** — jedna metoda (`try_sources()`) i Ligase dorównuje flagowej płatnej funkcji Schema Pro (mapowanie custom fields → schema), później Metabox/Pods/Toolset tym samym mechanizmem.
2. **Field-mapping w regułach** — dziś reguły tylko włączają typ; połączenie z ACF-sources = przeskoczenie konkurencji. (Najpierw naprawić B3!)
3. **Alerting utraty rich results na bazie istniejącego syncu GSC** — killer retencyjny („Recipe -40 % w tym tygodniu"); plumbing w `class-gsc.php` + `class-health-report.php` już jest (po naprawie B-GSC).
4. **Per-post import z RankMath/Yoast** — obecny importer bierze tylko ustawienia globalne; per-post schema to prawdziwy koszt zmiany wtyczki.
5. **WP-CLI (`wp ligase scan|score|regenerate|import`) + REST `/ligase/v1/schema/{id}`** — tanie, wyróżnia u agencji i headless; przy okazji rozwiązuje P1/P2 (batch z CLI).
6. **Dystrybucja: wordpress.org** — readme.txt i uninstall praktycznie gotowe; blokerem jest i18n.

**Brakujące typy o realnej wartości:** `PodcastEpisode` (jest tylko Series — a treścią są odcinki), `Dataset`, `Book`, `Movie`, rozszerzenia JobPosting (`EstimatedSalary`, `EmployerAggregateRating`), `VacationRental`/`RealEstateListing`, licencje obrazów (`acquireLicensePage` — łatwy, niedoceniony rich result), `SpecialAnnouncement`.

**Monetyzacja:** brak infrastruktury licencyjnej (Freemius/EDD) i gate'ów `is_pro()`. Naturalny split: free = typy + reguły + auditor + score; pro = zarządzany NER (zamiast BYO-key — kalkulacja $0.0005/post już jest w onboardingu), monitoring GSC + alerty, ACF-mapping, per-post import, zaawansowane Woo (warianty/ProductGroup), multisite/agency + WP-CLI. Warunki wstępne: hooki `do_action`, rejestr typów, CI+testy, English-first.

**Luki integracyjne:** WooCommerce (OfferCatalog kategorii, głębsze warianty), Elementor (detekcja widgetów FAQ/accordion w extractorze struktury), multisite (zero `is_multisite`; uninstall nie czyści per-site przy network uninstall).

---

## 6. Rekomendowana kolejność napraw

**Sprint 1 — funkcje flagowe, które dziś nie działają:** B1 (regex audytora), B3 (ID reguł), B4 (str_word_count/UTF-8), B2 (sync_block_meta), B6 (Narzędzia: fatal + nieosiągalne UI), B5 (supplement no-op), B7 (import niszczy opcje), GSC (searchAppearance + sc-domain).

**Sprint 2 — błędna schema na froncie:** B9/B10 (page/front-page), B11 (duplikat ItemList), B12 (kontener author), B13 (szkieletowe shipping/baseSalary/return), B14 (ceny z przecinkiem), B15 (readiness Recipe/JobPosting), B16 (render bloków FAQ/HowTo), JobPosting HTML, Recipe ISO-8601, puste `price`.

**Sprint 3 — skala i higiena:** P1-P4 (batching + kolejka NER + GSC sync), P5-P6, S1 (szyfrowanie klucza NER), S5 (rotacja logów), cache na Redis (B-invalidate_all), locale w hookach Woo.

**Sprint 4 — fundament wzrostu:** CI, golden testy, i18n English-first, split ajax, rejestr typów, potem roadmapa produktowa (ACF → rules-mapping → GSC alerting → wp.org).
