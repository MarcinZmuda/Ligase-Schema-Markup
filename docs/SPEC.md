# Ligase — Pełna specyfikacja wtyczki

**Wersja**: 2.4.23
**Data**: 2026-06-07
**Autor**: Marcin Żmuda (Embasy)
**Repo**: https://github.com/MarcinZmuda/Ligase-Schema-Markup
**Licencja**: GPL v2 lub późniejsza
**Wymagania**: WordPress 6.0+, PHP 8.0+, opcjonalnie WooCommerce 6.0+

---

## 1. Czym jest Ligase

Wtyczka WordPress generująca kompletny schema.org JSON-LD jako jeden skonsolidowany `@graph` per strona. Łączy węzły treści (BlogPosting / Product / Recipe / JobPosting / PodcastSeries) z węzłem autora (Person z pełnym E-E-A-T) i wydawcy (Organization / OnlineStore / LocalBusiness) przez referencje `@id` — co Google Knowledge Graph i AI search engines (Google AI Overviews, ChatGPT Search, Perplexity, Claude, Gemini) parsują jako pojedyncze, skonsolidowane encje.

Output renderowany jest server-side w `wp_head` (priorytet 5, przed pluginami SEO) — widoczny dla AI crawlerów (GPTBot, ClaudeBot, PerplexityBot) które nie wykonują JavaScript.

**Dla kogo**: profesjonalni blogerzy, wydawcy news, sklepy WooCommerce, blogi kulinarne, portale rekrutacyjne, fora dyskusyjne, osobiste marki SEO, podcasty, kancelarie prawne, agencje marketingowe.

**Co odróżnia od Yoast / RankMath / AIOSEO**:
- **Field-contract system** — deklaratywne źródła per field (manual → WC → post → option → NER → derived) zamiast hardcoded logiki
- **AI Search Readiness Score** — measure of AI-citation readiness, nie tylko rich results
- **Schema Auditor** — wykrywa i (opcjonalnie) podmienia schema z konkurencyjnych pluginów typu-aware scoring
- **Personal Brand Pack** — 18 pól E-E-A-T na Person (worksFor external, affiliation, subjectOf, workExperience role-property pattern, award, agentInteractionStatistic)
- **Google open-web popularity badges** — meta-box pokazuje user-owi adopcję każdego typu w internecie
- **Output-buffer scrubbing** — wycina theme-injected duplikaty BreadcrumbList / Article / Product

---

## 2. Architektura

### 2.1 Pipeline renderowania

```
wp_head (priorytet 5)
  → Ligase_Output::render()
    → output_should_render() — czy emit (vs Yoast / RankMath dominujący)
    → render_jsonld()
      → cache check (transient z kluczem queried_object + locale + version)
      → Ligase_Generator::build_graph()
        → resolve_context() — single_post / single_cpt / page / front_page /
                              blog_listing / taxonomy / author_archive / date_search
        → add_*_graph() per kontekst
        → site-wide entities: WebSite + Organization (+ LocalBusiness opt-in) + SiteNav
        → context-specific: WebPage / CollectionPage / ProfilePage / ItemList / BreadcrumbList
        → optional types loop: PodcastSeries / Service / Event / FAQ / HowTo / Course /
                               Software / Review / Product / Recipe / JobPosting / Forum
        → apply_filters('ligase_schema_graph', $graph)
      → assemble {"@context":"https://schema.org", "@graph":[...]}
      → JSON encode + emit <script type="application/ld+json">
```

### 2.2 Field Contract pattern

Każdy emitowany field opisuje:
- `level` — `required` / `recommended` / `optional`
- `sources` — łańcuch wyszukiwania wartości (manual → WC → post → option → ner → derived)
- `sanitize` — reguła sanityzacji (`text` / `url` / `int` / `float` / `country` / `currency` / `date` / `passthrough`)

Resolver iteruje sources w kolejności, bierze pierwszą non-null, sanityzuje, składa nested JSON-LD node. Manual override **zawsze** wygrywa nad auto. Auto-wartości są **nigdy** persistowane — recomputed at render time → niemożliwe stale prices/dates.

### 2.3 Suppressor + Output-buffer scrubbing

W `standalone_mode` ON Ligase:
1. Rejestruje filter hooks wyciszające schema z 7 SEO pluginów (Yoast / RankMath / AIOSEO / SEOPress / TSF / Slim SEO / The Events Calendar)
2. Otwiera `ob_start` na `template_redirect` — łapie cały HTML output
3. Przy flush: parsuje wszystkie `<script type="application/ld+json">`, dla każdego:
   - Trzyma Ligase `@graph` (rozpoznany po pełnej strukturze envelope)
   - Trzyma pierwszy `BreadcrumbList` z `@id` zakończonym `#breadcrumb`
   - Wycina wszystkie inne standalone węzły typów: Article / BlogPosting / Product / FAQPage / HowTo / Recipe / Organization / WebPage / WebSite (czyli theme-injected duplikaty)

---

## 3. Schema types (25)

### 3.1 Publishing & autorzy

#### BlogPosting / Article / NewsArticle / TechArticle / LiveBlogPosting
**Plik**: `includes/types/class-blogposting.php`
**Auto**: Default dla `post_type='post'`
**Pola**:
- `headline` (max 110 znaków, truncation safe)
- `datePublished`, `dateModified` (ISO 8601, gating: dateModified < 5 min od publikacji pomijany aby unikać "świeżo zmodyfikowane" spam)
- `author` → Person `@id` ref (lub `#org` gdy `org_author_mode` ON lub user `ligase_is_redakcja=1`)
- `publisher` → Organization `@id` ref
- `image` — 3 ratio (1:1, 4:3, 16:9) min 1200×675 LUB single ImageObject 696+ (fallback)
- `articleSection` (kategoria), `keywords` (tags), `wordCount`
- `about` / `mentions` (DefinedTerm + sameAs Wikidata z NER)
- `isAccessibleForFree` (paywall flag), `cssSelector` dla paywalled section
- `speakable` (CSS selectors per post)
- `inLanguage` (BCP-47)
- `mainEntityOfPage`

**Variants**: Category → BlogPosting/Article/NewsArticle/TechArticle/LiveBlogPosting dropdown (per-post `_ligase_schema_type` meta override).

#### Person
**Plik**: `includes/types/class-person.php`
**Auto**: Per author archive + jako author na każdym BlogPosting (chyba że `org_author_mode`)
**18 pól E-E-A-T**:

| Pole | Source | UI |
|---|---|---|
| `@id` | `home_url('/#author-{user_id}')` | auto |
| `name` | WP `display_name` | user profile |
| `givenName` / `familyName` | WP first_name/last_name + override | `ligase_given_name`, `ligase_family_name` |
| `honorificPrefix` | meta | `ligase_honorific` |
| `jobTitle` | meta | `ligase_job_title` |
| `description` | WP user bio | user profile |
| `image` | meta lub Gravatar 400px | `ligase_image_url` |
| `email` (opt-in) | WP user_email | checkbox `ligase_publish_email` |
| `telephone` | meta | `ligase_telephone` |
| `knowsLanguage` (CSV) | meta | `ligase_knows_language` |
| `knowsAbout` (CSV → array) | meta | `ligase_knows_about` |
| `alumniOf` (EducationalOrganization) | 3 meta | `ligase_alumni_of` + url + dept |
| `hasCredential` (repeater) | meta textarea | `ligase_credentials` (Name \| category \| Issuer \| URL \| ID \| year) |
| `memberOf` (repeater) | meta textarea | `ligase_member_of` |
| **`worksFor`** (Organization / @id ref / array z OrganizationRole) | meta + work_experience | `ligase_works_for_name` + url; default `@id` ref do site Org |
| `affiliation` (repeater) | meta textarea | `ligase_affiliation` |
| `subjectOf` (Article array) | meta textarea | `ligase_subject_of` |
| `workExperience` → merged do `worksFor` array | meta textarea | `ligase_work_experience` |
| `award` (string lub Name\|Issuer\|year) | meta textarea | `ligase_award` |
| `agentInteractionStatistic` (InteractionCounter) | meta textarea | `ligase_agent_stats` (Action\|count\|platform) |
| `sameAs` (array) | WP contact methods + Ligase fields + user_url + extra | auto + `ligase_extra_sameas` |
| `mainEntityOfPage` | author archive URL | auto |

**Role-property pattern** w `worksFor`:
```json
"worksFor": [
  {"@type":"Organization","name":"Embasy","url":"https://embasy.pl"},
  {"@type":"OrganizationRole","roleName":"Head of SEO","startDate":"2015-11","endDate":"2024-12",
   "worksFor":{"@type":"Organization","name":"Orion Media Group"}},
  ...
]
```

#### ProfilePage
**Plik**: emitowany przez `Ligase_Generator::build_profile_page()`
**Trigger**: page z `_ligase_enable_profile_page=1` + `_ligase_profile_user_id={ID}`, lub każde author archive
**Pola**: `mainEntity` → Person `@id`, `about` → Person `@id`, `dateCreated`, `dateModified`

### 3.2 Wydawca

#### Organization (z subtype OnlineStore)
**Plik**: `includes/types/class-organization.php`
**Site-wide** (na każdej stronie)
**Pola**:
- `@type` = `Organization` lub `OnlineStore` (gdy `store_mode=1` lub WooCommerce aktywne)
- `name`, `description`
- `url`, `logo` (ImageObject min 112×112 square — Google 2025 req)
- `image` (= logo URL — Google "Firmy lokalne" req)
- `address` (PostalAddress z lb_* opcji)
- `sameAs` (Wikidata, Wikipedia, LinkedIn, Facebook, Twitter, YouTube)
- `email`, `telephone`, `contactPoint` (ContactPoint typu customer service)
- `knowsAbout` (CSV → array)
- `founder` (Person `@id` ref do org_founder_id user)
- `employee[]` (up to 20 published authors, ordered by post_count)
- `hasMerchantReturnPolicy` (gdy store_mode) z:
  - `applicableCountry`, `returnPolicyCountry` (ISO 3166-1)
  - `merchantReturnDays`
  - `returnPolicyCategory` (MerchantReturnFiniteReturnWindow)
  - `returnMethod` (ReturnByMail)
  - `returnFees` (4 enum values)
  - `refundType` (FullRefund default)
  - `returnShippingFeesAmount` (MonetaryAmount, tylko gdy returnFees=ReturnShippingFees)

#### LocalBusiness (60+ subtypów)
**Plik**: `includes/types/class-localbusiness.php`
**Opt-in**: `lb_name` wypełnione w Ustawieniach
**Subtypy**: Restaurant, Store, RealEstateAgent, ProfessionalService, AccountingService, AutoRepair, BeautySalon, Dentist, GoldsmithStore, HairSalon, HardwareStore, HealthAndBeautyBusiness, HomeAndConstructionBusiness, InternetCafe, JewelryStore, LegalService, LibrarySystem, LiquorStore, LocksmithService, MedicalBusiness, MovingCompany, NotaryService, Optician, PetStore, Plumber, PostOffice, RealEstateAgent, Restaurant, RoofingContractor, ShoppingCenter, SkiResort, SportingGoodsStore, Store, TattooParlor, TaxiStand, TouristInformationCenter, TravelAgency... (pełna lista w `lb_type` dropdown)
**Pola**:
- Wszystko z Organization PLUS:
- `address` (PostalAddress), `geo` (GeoCoordinates)
- `openingHoursSpecification` (per-day, with opens/closes)
- `priceRange` (`$` / `$$` / `$$$` / `25-200 PLN`)
- `areaServed` (Country/City/AdministrativeArea)
- `hasMap` (Google Maps URL z coords)
- `paymentAccepted`, `currenciesAccepted`

**Service-area business mode** (`lb_service_area=1`): bez fizycznego adresu — emit `areaServed` z `lb_area_served`, omit `address`.

### 3.3 E-commerce

#### Product (+ ProductGroup, Offer, MerchantReturnPolicy, OfferShippingDetails)
**Plik**: `includes/types/class-product.php`
**Auto**: `post_type='product'` z WooCommerce + per-post opt-in
**Pola**:
- `name`, `description` (max 5000 znaków), `image`
- `sku`, `gtin`, `mpn`, `brand` (Brand z name)
- `aggregateRating` (z WC reviews jeśli >0, NIE fake'owane)
- `review` (per Review post)
- `offers` (Offer lub AggregateOffer dla variant products):
  - `price`, `priceCurrency`, `priceValidUntil` (past-date guard)
  - `availability` (InStock/OutOfStock/BackOrder — null dla unknown stock, omit field)
  - `seller` → Organization `@id`
  - `hasMerchantReturnPolicy` (inline lub `@id` ref do Organization-level)
  - `shippingDetails` (inline OfferShippingDetails z:
    - `shippingRate` (MonetaryAmount)
    - `shippingDestination` (DefinedRegion z addressCountry)
    - `deliveryTime` (ShippingDeliveryTime z handlingTime + transitTime, oba QuantitativeValue z unitCode='DAY'))
- **ProductGroup** + `hasVariant[]` dla variant products (size/color):
  - Per-variant SKU, GTIN, Offer
  - `variesBy` (Color/Size/etc.)
  - `productGroupID`
- `sale_price` / `priceSpecification` UnitPriceSpecification dla strikethrough pricing

### 3.4 Editorial & community

#### Recipe
**Plik**: `includes/types/class-recipe.php`
**Opt-in**: `_ligase_enable_recipe=1`
**Host carousel eligible**
**Pola**: name, image (3 ratio), author, datePublished, description, recipeYield, prepTime/cookTime/totalTime (ISO 8601 duration), recipeIngredient[], recipeInstructions[] (auto-convert plain text → HowToStep), recipeCategory, recipeCuisine, suitableForDiet, nutrition (NutritionInformation), aggregateRating, video (VideoObject)

#### HowTo + HowToStep
**Plik**: `includes/types/class-howto.php`
**Opt-in**: `_ligase_enable_howto=1`
**Note**: Google deprecated rich results for HowTo (2024). Schema emitted for AI search.
**Gutenberg block** `blocks/howto/` — visual editor z auto-schema.

#### FAQPage
**Plik**: `includes/types/class-faqpage.php`
**Opt-in**: `_ligase_enable_faq=1`
**Note**: Google ograniczył rich results do gov/health (2024). Schema dla AI search retain.
**Gutenberg block** `blocks/faq/` — z live word counter (40-60 słów per Q&A optimal for AI).

#### QAPage
**Plik**: `includes/types/class-qapage.php`
**Opt-in**: `_ligase_enable_qapage=1`
**Use case**: artykuły odpowiadające na jedno konkretne pytanie
**Stats**: +58% AI citations vs Article schema dla single-question articles
**Pola**: mainEntity → Question (acceptedAnswer + suggestedAnswer[])

#### DiscussionForumPosting
**Plik**: `includes/types/class-discussionforumposting.php`
**Auto-detect**: bbPress topics/replies/forums + opt-in
**SERP feature**: "Discussions and Forums" (od listopad 2023)
**Pola**: headline, text, author, datePublished, comment[] (up to 50 Comment nodes)

#### ClaimReview
**Plik**: `includes/types/class-claimreview.php`
**Opt-in**: `_ligase_enable_claimreview=1`
**Status**: Google deprecated dla rich results 12 czerwca 2025; schema retained for verified fact-checkers + AI trust signal
**Pola**: claimReviewed, author, datePublished, reviewRating (Rating bestRating/worstRating/ratingValue), itemReviewed (Claim), reviewBody

#### Review
**Plik**: `includes/types/class-review.php`
**Opt-in**: `_ligase_enable_review=1`
**Pola**: itemReviewed (Thing — required), author (Person), reviewBody, reviewRating (Rating), datePublished, positiveNotes/negativeNotes (editorial pros/cons)

#### DefinedTerm + DefinedTermSet
**Plik**: `includes/types/class-definedterm.php`
**Use case**: słowniki, glossaries
**Pola**: name, description, inDefinedTermSet, termCode, url, sameAs (Wikidata)

### 3.5 Aktywności

#### Event
**Plik**: `includes/types/class-event.php`
**Opt-in**: `_ligase_enable_event=1`
**Pola**: name, startDate, endDate (ISO 8601 validation, drop node if invalid), description, eventAttendanceMode (Online/Offline/Mixed), eventStatus, location (Place + PostalAddress OR VirtualLocation z URL), organizer (Organization `@id`), performer, offers, image

#### Course
**Plik**: `includes/types/class-course.php`
**Opt-in**: `_ligase_enable_course=1`
**Pola**: name, description, provider (Organization), courseCode, courseMode (online/onsite/blended), hasCourseInstance[] (CourseInstance), offers, image

#### JobPosting
**Plik**: `includes/types/class-jobposting.php`
**Auto-detect**: CPT `job_listing` + opt-in
**Google Jobs** rich result
**Pola**: title, description, datePosted, validThrough (auto-expire past date), employmentType (FULL_TIME/PART_TIME/CONTRACTOR/...), hiringOrganization, jobLocation (Place + PostalAddress, country validation ISO 3166-1 alpha-2), baseSalary (MonetaryAmount z value lub min/max), industry, qualifications, responsibilities

#### Service
**Plik**: `includes/types/class-service.php`
**Opt-in**: `_ligase_enable_service=1`
**Use case**: usługi prawne / księgowe / SEO / lokacja-targeted ("Adwokat * Warszawa")
**Pola**: name, description, serviceType, category, provider (LocalBusiness `@id` preferred dla local SEO, fallback Organization), areaServed (multi-city support: City / AdministrativeArea / Country nodes), audience, offers (z eligibleRegion dla region-restricted pricing)

#### PodcastSeries
**Plik**: `includes/types/class-podcastseries.php`
**Opt-in**: `_ligase_enable_podcast_series=1` na page hub'u podcastu (np. `/update-time-by-marcin-zmuda/`)
**Pola**: name (fallback page title), description (fallback excerpt), image (fallback featured), author → Person `@id`, publisher → Organization `@id`, inLanguage (BCP-47 default site locale), sameAs (URL per line — Spotify, Apple Podcasts, YouTube, Pocket Casts), webFeed (RSS), numberOfEpisodes

### 3.6 Media

#### VideoObject
**Plik**: `includes/types/class-videoobject.php`
**Per-post meta**: `_ligase_video[name, description, thumbnailUrl, embedUrl, uploadDate, duration]`
**Validation gate**: name + thumbnail + (embed OR content URL) — required, drop node if missing
**Pola**: name, description, thumbnailUrl, embedUrl/contentUrl, uploadDate, duration (ISO 8601), interactionStatistic (WatchAction views)

#### AudioObject
**Plik**: `includes/types/class-audioobject.php`
**Auto-detect**: Spotify/Buzzsprout/Anchor embeds w content
**Pola**: name, description, contentUrl/embedUrl, encodingFormat, duration, transcript

#### SoftwareApplication
**Plik**: `includes/types/class-softwareapplication.php`
**Opt-in**: `_ligase_enable_software=1`
**Pola**: name, applicationCategory (5 enum values), operatingSystem, offers (z price + priceCurrency), image, aggregateRating (TYLKO z real reviews, ratingCount > 0 wymagane — fake-rating manual action risk)

### 3.7 Strukturalne (auto)

#### WebSite
**Plik**: `includes/types/class-website.php`
**Site-wide**, na każdej stronie
**Pola**:
- `@id` = `home_url('/#website')`
- `name` (bloginfo name)
- `url`, `inLanguage`
- `publisher` → Organization `@id`
- `potentialAction` → **SearchAction**:
  - `target` (EntryPoint z `urlTemplate=https://site.tld/?s={search_term_string}` — proper brace encoding, manual URL build not `home_url()`)
  - `query-input` (PropertyValueSpecification)

#### BreadcrumbList
**Plik**: `includes/types/class-breadcrumb.php`
**Auto**: na każdej stronie/poście/archiwum
**Pola**: itemListElement[] (ListItem z position + name + item URL), `@id` = `permalink + '#breadcrumb'`
**Hierarchia**: parent pages → categories → post

#### SiteNavigationElement
**Plik**: `includes/types/class-sitenavigation.php`
**Auto**: z primary menu
**Pola**: name, url (per menu item)

#### ItemList
**Plik**: `includes/types/class-itemlist.php`
**Auto**: archiwa (kategorie, tagi, blog listing, WooCommerce shop)
**Pola**: name, numberOfItems, itemListOrder, itemListElement[]:
- **Inline Product** (dla WC carousel) z `@type Product` + nested Offer (z unique `@id` per Offer żeby uniknąć "duplicate properties" warningu)
- **Inline Article** (dla post archiwum)
- **Link-only fallback** (`position + url + name`) gdy żaden embedded entity się nie da

---

## 4. Core systemy

### 4.1 AI Search Readiness Score
**Pliki**: `class-score.php`, `class-readiness.php`, `class-readiness-panel.php`

Score 0-100 per post + site-wide aggregate. Mierzy gotowość do AI citations (NIE tradycyjnego SEO).

**Checks**:
- Entity graph linking — `@id` references działa
- Wikidata `sameAs` density (więcej = wyższy score)
- Image dimensions ≥ 1200px width
- Author completeness — `knowsAbout` (min 3), `jobTitle`, `sameAs` (min 2)
- `dateModified` discipline — recent updates rewarded
- `about` / `mentions` z Wikidata `sameAs`
- NER LLM coverage — opt-in scoring jeśli NER aktywny
- Speakable selectors present
- Pipeline-aware — reads `_ligase_wikidata_suggestions`, `_ligase_ner_api_results`, `_ligase_about_entities` (grades real AI signals, not vanity field-presence)

**Gutenberg sidebar panel** (`assets/js/ligase-readiness-panel.js`) — live score w edytorze + lista qualifying rich results.

### 4.2 Schema Auditor
**Plik**: `class-auditor.php`, `admin/views/auditor.php`

Batch scan wszystkich postów. Per-post:
1. Render `wp_head` w izolacji
2. Łapie wszystkie `<script type="application/ld+json">`
3. **Unwrap `@graph`** — każdy węzeł osobno (FIX 2.4.17 — wcześniej oceniał tylko envelope)
4. Pick relevant node po `post_type`:
   - `product` → Product
   - `page` → AboutPage / ContactPage / CheckoutPage / CollectionPage / FAQPage / WebPage
   - `post` → BlogPosting / Article / NewsArticle
5. Type-aware scoring rubric (Event nie scored against Article rubric)
6. List issues + source plugin detection

**3 tryby**:
- **Scan only** — raport tylko
- **Supplement** — additive (dodaje missing fields do istniejącego schema)
- **Replace** — full takeover poniżej score threshold (+ `restore_replacement()` undo)

**Bulk actions**: "Skanuj wszystkie", "Napraw zaznaczone", "Zmień typ schema dla zaznaczonych", "Włącz flagi (FAQ/HowTo/Service) dla zaznaczonych".

### 4.3 Schema Rules (bulk-enable)
**Plik**: `class-schema-rules.php`, `admin/views/rules.php`

Regex / kategoria / tag / post-slug matching → bulk enable schema types per condition. Przykład: wszystkie strony "Adwokat * Warszawa" → Service schema. Pattern: `{rule_pattern} → {meta_key=value} → {schema_type/flag}`.

Per-post check: `Ligase_Schema_Rules::is_enabled_for_post( $meta_key, $post_id )` — sprawdza wszystkie aktywne rules i zwraca true jeśli któraś matched dla tego postu.

### 4.4 Field Contract + Field Resolver
**Pliki**: `class-field-contract.php`, `class-field-resolver.php`

**Field Contract** (`Ligase_Field_Contract::types()`) — declarative per `@type`:
```php
'Product' => [
    '_meta' => ['label' => '...', 'experience' => 'merchant_listing'],
    '_containers' => [
        'offers' => 'Offer',
        'offers.hasMerchantReturnPolicy' => 'MerchantReturnPolicy',
        'offers.shippingDetails' => 'OfferShippingDetails',
        'offers.shippingDetails.deliveryTime' => 'ShippingDeliveryTime',
        'offers.shippingDetails.deliveryTime.handlingTime' => 'QuantitativeValue',
        // ...
    ],
    'fields' => [
        'name' => ['level' => 'required', 'sources' => ['manual:', 'wc:name', 'post:title'], 'sanitize' => 'text'],
        'offers.price' => ['level' => 'required', 'sources' => ['manual:', 'wc:price'], 'sanitize' => 'float'],
        // ...
    ],
],
```

**Field Resolver** (`Ligase_Field_Resolver::resolve( $type, $post_id )`) iteruje contract:
1. For each field, try sources in order, take first non-null
2. Sanitize per rule
3. Place value into nested node by path (set_by_path)
4. Stamp `@type` on every populated container path
5. Return `['node' => $node, 'status' => $per_field_status, 'eligible' => bool, 'missing_required' => array]`

**Sanitize rules**:
- `text` — `wp_strip_all_tags`
- `html` — `wp_kses_post`
- `url` — `esc_url_raw`
- `int` — strip non-digits, handles Polish/EU "1 299" → 1299
- `float` — Polish/EU "1 299,90 zł" → 1299.9 (strip currency, last comma/dot as decimal)
- `date` — `gmdate('c', strtotime)` z validity guard
- `country` — uppercase + ISO 3166-1 alpha-2 validation
- `currency` — uppercase 3-letter
- `passthrough` — no sanitize (already structured)

**Sources**:
- `manual:` — `_ligase_override[$type][$key]` postmeta
- `wc:{what}` — WooCommerce product getter
- `post:{what}` — WP_Post field (title, excerpt, permalink, date, modified, thumbnail, thumbnail_set, primary_category)
- `opt:{key}` — `ligase_options[$key]`
- `ner:entities` — NER pipeline output
- `derive:{what}` — computed values (wordcount, comment_count, return_policy_category, return_method, return_fees_default, return_fees_amount_value, unit_code_day, refund_type_default)

### 4.5 Suppressor
**Plik**: `class-suppressor.php`

**Filter-based suppression** dla 7 SEO pluginów (Yoast / RankMath / AIOSEO / SEOPress / The SEO Framework / Slim SEO / The Events Calendar) — `wpseo_schema_graph`, `rank_math/json_ld`, `aioseo_schema_disable`, etc.

**Output-buffer scrubber** (`Ligase_Suppressor::register_breadcrumb_scrubber()`) — w `standalone_mode`:
- `ob_start` na `template_redirect` priority 0
- Callback: regex `<script[^>]*application/ld\+json[^>]*>(.*?)</script>`, JSON decode, dla każdego:
  - Trzymaj `@graph` envelopes
  - Trzymaj BreadcrumbList z `@id` zakończonym `#breadcrumb`
  - Wytnij standalone węzły typów: Article / BlogPosting / NewsArticle / WebPage / WebSite / Organization / Product / FAQPage / HowTo / Recipe

### 4.6 Cache
**Plik**: `class-cache.php`, `class-cache-bypass.php`

Per-page JSON-LD cache w transients. Klucz: `queried_object_id + locale + LIGASE_VERSION`. TTL 12h. Invalidation hooks:
- `save_post` → `invalidate_post_and_related` (post + archive cache + author cache + home cache, multi-locale WPML/Polylang aware)
- `updated_option` (filtered to `ligase_*` keys only)
- WooCommerce events (product save, stock change, price update) — 5 hooks
- Manual flush w admin Narzędzia

`Ligase_Cache_Bypass` — wykrywa preview / customizer / logged-in admin → bypass cache (zawsze fresh schema dla user'a edytującego).

### 4.7 NER API
**Plik**: `class-ner-api.php`

Opt-in AI entity extraction. 4 providers:
- **OpenAI GPT-4o-mini** (~$0.0004/post)
- **Anthropic Claude Haiku** (~$0.0006/post)
- **Google Natural Language API** (~$0.010/post)
- **Dandelion** (~€0.002/post, EU-hosted, GDPR-friendly)

Async via WP-Cron. Wyniki cache'owane w `_ligase_ner_api_results` postmeta. Plus regex NER fallback (Polish lemmatization-aware deduplikacja, ~20ms).

Wyniki merge'owane do `about` / `mentions` z auto Wikidata `sameAs` lookup (exact-label match guard żeby nie linkować "Microsoft" do firmy gdy chodzi o "Microsoft Word").

### 4.8 GSC (Google Search Console)
**Plik**: `class-gsc.php`

Integracja przez Service Account JWT (bez OAuth redirect). AES-256-CBC szyfrowanie credentials. Dashboard pokazuje clicks / impressions / CTR / position per schema type (searchAppearance breakdown).

Sync danych GSC do postmeta (`_ligase_gsc_clicks`, `_impressions`, `_ctr`, `_position`).

### 4.9 Health Report
**Plik**: `class-health-report.php`

Weekly email digest (WP-Cron). Wykrywa:
- Posty ze score < threshold
- Posty bez obrazów (image missing)
- Posty bez excerpt
- Stare posty (>1 rok) bez updates
- Schema validation errors
- Broken `@id` references

### 4.10 Importer
**Plik**: `class-importer.php`

One-click import z konkurencyjnych SEO pluginów:
- **Yoast SEO** — nazwa, logo (attachment ID resolution), social URLs, dane autorów
- **Rank Math** — to samo + custom schema types
- **All in One SEO** — v3 serialized PHP + v4.5+ nested social URLs (z 2 różnych formatów)

### 4.11 Logger
**Plik**: `class-logger.php`

File-based logs w `wp-content/uploads/ligase-logs/`. Per-day rotation. Security:
- `.htaccess` deny rules
- `web.config` dla IIS
- PHP-die prefix (`<?php die(); ?>` na początku każdego file) — even if Apache rule fails, PHP refuses to render
- `.php` extension — Apache treats as PHP (executes die first)
- Log file mode 0644

### 4.12 Multilingual
**Plik**: `class-multilingual.php`

Auto-detect WPML / Polylang. Poprawny `inLanguage` BCP-47 (`pl-PL`, `en-US` etc.). `sameAs` linking między translations posta — Person/Organization graph shared across languages.

### 4.13 Validator
**Plik**: `class-validator.php`

Pre-emit walidacja required/recommended fields per `@type`. Zwraca array of errors/warnings. Używane w Auditor + Readiness Panel.

### 4.14 Popularity Stats
**Plik**: `class-popularity-stats.php` (2.4.20)

Static map: schema type → Google open-web bucket (z `schemaorg/schemaorg/data/public_stats/google/YYYY_MM.csv`).

API:
- `Ligase_Popularity_Stats::bucket('FAQPage')` → `'1M-10M'`
- `Ligase_Popularity_Stats::tier('PodcastSeries')` → `4` (niche)
- `Ligase_Popularity_Stats::badge_html('Event')` → kolorowy HTML span z tooltip

Buckets → kolory:
- 🟢 Zielony — tier 1-2 (`10M+`, `1M-10M`) — "powszechne" / "ustabilizowane"
- 🔵 Niebieski — tier 3 (`100K-1M`) — "ustabilizowane"
- 🟡 Żółty — tier 4-5 (`10K-100K`, `1K-10K`) — "niszowe"
- 🔴 Czerwony — tier 6 (`<1K`) — "eksperymentalne"

Refresh co ~6 miesięcy (community dropuje nowy CSV).

---

## 5. Admin UI

### 5.1 Dashboard (`admin/views/dashboard.php`)
- Aktualnie aktywne SEO pluginy + suppression status
- Site-wide score
- Last cache flush
- Latest Health Report

### 5.2 Ustawienia (`admin/views/settings.php`) — 6 tabów
1. **Organizacja** — name, description, logo (z width/height), email, phone, founder, knows_about, default_schema_type
2. **Social & Entity** — Wikidata, Wikipedia, LinkedIn, Facebook, Twitter, YouTube
3. **Local Business** — type dropdown (60+), name, address, geo, opening hours, price range, area served, service-area mode
4. **Store / E-commerce** — currency, return policy (country/days/fees), shipping (country/rate/handling/transit) — Merchant Listings Google 2025
5. **AI / NER** — provider selector (OpenAI/Anthropic/Google/Dandelion), API key (secret)
6. **Zachowanie** — standalone_mode, force_output, debug_mode, store_mode, org_author_mode, lb_service_area, health_report_enabled

### 5.3 Audytor (`admin/views/auditor.php`)
- Próg score (default 50)
- Tryb: Tylko skan / Skanuj i napraw / Skanuj i zamień
- "Uruchom audyt" button (AJAX batch)
- Wyniki: posty poniżej progu / powyżej progu z bulk-action checkboxes

### 5.4 Posty (`admin/views/posts.php`)
- Lista postów per `post_type` (filter)
- Per-post: ID, tytuł, Score badge, Typ schema (z **popularity badge**), Znaczniki (enabled flags), Data, Akcje (Skanuj, Napraw, JSON-LD preview)
- Bulk actions: Change schema type, Toggle flags

### 5.5 Encje (`admin/views/entities.php`)
- Preview Organization JSON
- Preview LocalBusiness JSON (jeśli enabled)
- Live validation status

### 5.6 Reguły (`admin/views/rules.php`)
- Schema Rules CRUD — pattern → flag/type mapping

### 5.7 Narzędzia (`admin/views/tools.php`)
- Export ustawień (z `__REDACTED__` dla API keys)
- Import ustawień
- Cache flush
- NER test (single URL)
- Import z Yoast / Rank Math / AIOSEO

### 5.8 Meta-box per post (`admin/views/meta-box.php`)
- Schema type dropdown (z popularity badge)
- 17 enable checkboxes (z popularity badges)
- Advanced fields (paywall, dateline, image license/credit/acquire URL, citations repeater)
- Product override fields (jeśli `post_type=product`)
- PodcastSeries fields (jeśli enabled)

### 5.9 User Profile → Ligase Person section
24 pola E-E-A-T (patrz §3.1 Person).

---

## 6. Gutenberg Blocks (`blocks/`)

### 6.1 FAQ block (`blocks/faq/`)
- Visual editor z drag-drop pytań
- Live word counter z kolor feedback (zielony 40-60 słów, żółty 30-70, czerwony poza)
- Auto-emit FAQPage schema

### 6.2 HowTo block (`blocks/howto/`)
- Steps z image per step (opcjonalne)
- Auto-emit HowTo schema z HowToStep[]

---

## 7. Developer API

### 7.1 Filters (PHP)

```php
// Modify the full @graph
add_filter( 'ligase_schema_graph', function( array $graph ): array {
    $graph[] = ['@type' => 'Event', 'name' => 'My Event'];
    return $graph;
} );

// Modify specific type
add_filter( 'ligase_blogposting', function( $schema, $post_id ) {
    $schema['speakable'] = [
        '@type' => 'SpeakableSpecification',
        'cssSelector' => ['.entry-summary'],
    ];
    return $schema;
}, 10, 2 );

// Available type filters:
// ligase_blogposting, ligase_person, ligase_organization, ligase_website,
// ligase_breadcrumb, ligase_product, ligase_productgroup, ligase_recipe,
// ligase_jobposting, ligase_discussionforumposting, ligase_podcastseries,
// ligase_service, ligase_event, ligase_faqpage, ligase_howto, ligase_review,
// ligase_localbusiness, ligase_qapage, ligase_definedterm, ligase_claimreview,
// ligase_softwareapplication, ligase_audioobject, ligase_videoobject,
// ligase_course, ligase_itemlist, ligase_sitenavigation

// Extend field contract from theme/plugin
add_filter( 'ligase_field_contract', function( array $contract, string $type ) {
    if ( $type === 'Product' ) {
        $contract['fields']['offers.priceValidUntil']['level'] = 'required';
    }
    return $contract;
}, 10, 2 );

// Which schema types appear in readiness panel
add_filter( 'ligase_readiness_panel_types', function( array $types, int $post_id ) {
    if ( has_category( 'news', $post_id ) ) {
        $types[] = 'NewsArticle';
    }
    return $types;
}, 10, 2 );
```

### 7.2 Actions

```php
do_action( 'ligase_before_render', $post_id );
do_action( 'ligase_after_render', $graph, $post_id );
do_action( 'ligase_cache_invalidated', $post_id );
do_action( 'ligase_ner_completed', $entities, $post_id );
```

### 7.3 Helper functions

```php
// Get readiness report for a post
$report = ligase_readiness( $post_id );
// returns ['score' => 78, 'issues' => [...], 'qualifying' => ['BlogPosting','Person'], ...]

// Get cached JSON-LD for a URL
$jsonld = Ligase_Output::get_jsonld_for_post( $post_id );

// Force-flush all Ligase caches
Ligase_Cache::invalidate_all();

// Get schema type popularity badge
echo Ligase_Popularity_Stats::badge_html( 'PodcastSeries' );
```

### 7.4 WP-CLI

```bash
# Print readiness report
wp eval 'var_export( ligase_readiness( 123 ) );'

# Batch scan all posts (if WP-CLI integration enabled)
wp ligase scan --post_type=post --status=publish
```

### 7.5 AJAX endpoints (`class-ajax.php`)

16 endpoints, wszystkie z nonce + capability checks:
- `ligase_scan_post`, `ligase_fix_post` (manage_options)
- `ligase_bulk_scan`, `ligase_bulk_set_flags`, `ligase_bulk_change_schema_type` (manage_options + per-post edit_post)
- `ligase_get_jsonld_preview` (edit_posts)
- `ligase_readiness_for_post` (edit_posts + per-post edit_post)
- `ligase_export_settings` (manage_options, secrets redacted)
- `ligase_import_settings` (manage_options)
- `ligase_test_ner_api` (manage_options)
- `ligase_gsc_save_credentials`, `_disconnect`, `_test_connection`, `_sync`, `_rich_results` (manage_options)
- `ligase_apply_audit_replacements` (manage_options)

---

## 8. Performance & Caching

### 8.1 Render path performance
- Cache HIT: ~5ms (single transient read + echo)
- Cache MISS: 50-200ms zależnie od grafu (Person + Organization + BlogPosting + 5 optional types ~100ms)
- Output buffer scrubbing (standalone_mode): +10-30ms

### 8.2 Cache strategia
- Per-URL transient klucz: `ligase_schema_{md5(queried_object + locale + version)}`
- TTL 12h
- Auto-invalidate na `save_post`, term/category changes, WC product/stock changes, option update (filtered)
- Manual flush w admin Narzędzia + `Ligase_Cache::invalidate_all()`

### 8.3 OPcache
- Auto-reset na `register_activation_hook` + `upgrader_process_complete`
- Eliminuje "checkbox revert after Save" w shared FPM hosting (cPanel / DirectAdmin / Smarthost / cagefsctl)

---

## 9. Security

### 9.1 Output sanitization
- All JSON-LD JSON-encoded (no inline HTML)
- `</script>` w content escaped (XSS guard) — via `str_replace` AND `JSON_UNESCAPED_SLASHES` drop
- All user inputs via `sanitize_text_field` / `esc_url_raw` / `absint` / contract-driven sanitize

### 9.2 Capability checks
- Settings save → `manage_options`
- Per-post meta → `edit_post` per post_id
- AJAX → nonce (`check_ajax_referer`) + `current_user_can`
- Bulk actions → site-level + per-post cap loop

### 9.3 Secret handling
- NER API key + GSC service account JSON encrypted (AES-256-CBC z `wp_salt('auth')` derived key)
- Export settings → secrets jako `__REDACTED__`
- Import settings → secrets nigdy nie nadpisywane (whitelist)
- Logger files → never log secrets (regex strip auth headers)

### 9.4 URL filter
- All textareas zawierające URL → `esc_url_raw()` per token (drops `javascript:` / `data:` / `vbscript:` / `file:` schemes)

### 9.5 Output suppression race conditions
- Standalone mode reads option fresh each call (no static cache leak in FPM workers with persistent OPcache)
- Filter priority 999 (after competitor plugins register their handlers)
- Output buffer hook on `template_redirect` priority 0 (before any content render)

---

## 10. Compatibility matrix

### SEO pluginy (Suppressor support)
| Plugin | Wycisza Person/Org | Wycisza Article | Wycisza Breadcrumb | Output buffer scrub |
|---|---|---|---|---|
| Yoast SEO | ✅ | ✅ | ✅ (4 hooki dla 27.x) | ✅ |
| Rank Math | ✅ | ✅ | ✅ | ✅ |
| All in One SEO | ✅ | ✅ | ✅ | ✅ |
| SEOPress | ✅ | ✅ | ✅ | ✅ |
| The SEO Framework | ✅ | ✅ | ✅ | ✅ |
| Slim SEO | ✅ | ✅ | ✅ | ✅ |
| The Events Calendar | ✅ (Event only) | n/a | n/a | ✅ |

### Themes
- Automatic dedupe BreadcrumbList / Article / Product injected by: XStore, Flatsome, Woodmart, Astra Pro, Avada, GeneratePress Premium, Kadence Premium (output-buffer scrubbing in standalone mode)

### WordPress core
- WordPress 6.0+
- Multisite — testowane, działa, każdy subsite ma osobne `ligase_options`
- Block editor + Classic editor — oba

### Other
- WooCommerce 6.0+ (Product auto-detect + 15 fields)
- bbPress (DiscussionForumPosting auto-detect)
- WPML 4.4+ (inLanguage BCP-47 + sameAs sync)
- Polylang 3.0+ (jak wyżej)
- Elementor / WPBakery / Bricks / Divi — content respected, schema osobno

---

## 11. Requirements

- **WordPress**: 6.0+
- **PHP**: 8.0+ (typed properties, match expressions, constructor promotion, str_contains)
- **PHP extensions**: `ext-json`, `ext-mbstring` (standard)
- **MySQL**: 5.7+ lub MariaDB 10.3+
- **Optional**:
  - WooCommerce 6.0+ dla Product schema
  - PHP API key dla NER (jeden z: OpenAI / Anthropic / Google NLP / Dandelion)
  - Google Service Account JSON dla GSC integration

---

## 12. Installation

### Z ZIP (z GitHub Releases)
1. Pobierz `ligase-X.Y.Z.zip` z [Releases](https://github.com/MarcinZmuda/Ligase-Schema-Markup/releases)
2. WP Admin → **Wtyczki → Dodaj nową → Wyślij wtyczkę** → wybierz ZIP → **Zainstaluj teraz** → **Aktywuj**

### Post-installation setup
1. **Ligase → Ustawienia → Organizacja** — name, logo URL (square 600×600+), email, knows_about
2. **Social & Entity** — Wikidata URL (jeśli masz QID), LinkedIn, FB, Twitter, YouTube
3. **Local Business** (opcjonalne) — adres + geo + opening hours
4. **Store / E-commerce** (jeśli WooCommerce) — currency, return policy, shipping
5. **Zachowanie** — `standalone_mode` ON (zalecane), aby wyciszyć Yoast/RankMath
6. **Profil użytkownika** → wypełnij Person fields (jobTitle, knowsAbout, hasCredential, sameAs)
7. **Ligase → Dashboard** — sprawdź AI Search Readiness Score

---

## 13. Roadmap (data-driven z Google open-web stats)

### 2.5.0 — News/YMYL Publisher Pack
- `NewsMediaOrganization` type (zamiast Organization)
- Trust Project 8 polityk: publishingPrinciples, ethicsPolicy, correctionsPolicy, masthead, ownershipFundingInfo, diversityPolicy, actionableFeedbackPolicy, verificationFactCheckingPolicy
- Polish business identifiers: NIP, REGON, KRS, taxID, vatID jako PropertyValue array
- `legalName`, `alternateName[]`, `foundingDate`, `slogan`
- `subOrganization` repeater (dla wydawców z marką-córką, np. Dziennik Gazeta Prawna pod INFOR PL)

### 2.5.1 — Tier-2 schema gaps (1M-10M domains)
- `AggregateOffer` (price ranges, "od X PLN")
- `OfferCatalog` (Organization.makesOffer listing usług)
- `UnitPriceSpecification` (B2B per-unit pricing)
- `ItemPage` (zamiast WebPage dla product/article pages)

### 2.5.2 — LocalBusiness depth
- `Corporation` subtype
- `HomeAndConstructionBusiness`, `RealEstateAgent`, `MovingCompany` subtype'y
- `LocationFeatureSpecification` (amenityFeature — wifi, parking, accessibility)
- `GeoCircle` (service radius zamiast singular `areaServed`)

### 2.5.3 — Voice / AI search
- `SpeakableSpecification` improvements (CSS selector helper w UI)
- `agentInteractionStatistic` na Organization (nie tylko Person)
- LLM-specific markup hooks (`isPartOf` linking dla AI training data attribution)

### Skip / deferred
- `Game`, `Menu`, `Course.hasCourseInstance` zaawansowane — small market
- `WPFooter`/`WPHeader`/`WPSideBar` — theme responsibility
- Pure abstract types (Thing, CreativeWork, MediaObject)

---

## 14. Architektura systemu (diagram)

```
┌─────────────────────────────────────────────────────────────────────┐
│                       WordPress Request                              │
└─────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  template_redirect (priority 0)                                      │
│    └─ Ligase_Suppressor::start_breadcrumb_buffer() (if standalone)   │
└─────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  wp_head (priority 5) — Ligase_Output::render()                      │
│    ├─ output_should_render() — competitor SEO check                  │
│    ├─ cache check (transient)                                        │
│    │   ├─ HIT → echo cached <script>                                 │
│    │   └─ MISS → Ligase_Generator::build_graph()                    │
│    │              ├─ resolve_context() (8 paths)                     │
│    │              ├─ site-wide entities (WebSite + Org)              │
│    │              ├─ context-specific (BlogPosting / Product / ...) │
│    │              ├─ Person (z 18 pól E-E-A-T)                       │
│    │              ├─ BreadcrumbList                                  │
│    │              ├─ optional types loop (PodcastSeries / Service /) │
│    │              ├─ apply_filters('ligase_schema_graph')            │
│    │              ├─ Field_Resolver per type:                        │
│    │              │   Field_Contract → sources → sanitize → assemble│
│    │              └─ return $graph                                   │
│    └─ JSON encode + emit <script type="application/ld+json">         │
└─────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  wp_head (priority 999) — Suppressor filters fired (Yoast etc.)      │
└─────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  ... rest of HTML rendered ...                                       │
└─────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Output buffer flush — Suppressor::dedupe_breadcrumb_jsonld()        │
│    └─ Regex parse all <script type=ld+json>                          │
│       ├─ Keep Ligase @graph block                                    │
│       ├─ Keep first Ligase BreadcrumbList (#breadcrumb @id)          │
│       └─ Strip standalone Article / Product / WebPage / etc.         │
└─────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
                          Browser / AI Crawler
```

---

## 15. Statystyki kodu (v2.4.23)

- **Linii kodu PHP**: ~25,000
- **Klas**: 49 (24 schema types + 25 core)
- **Schema types emitowanych**: 25
- **Field-contract fields**: 80+
- **AJAX endpoints**: 16 (wszystkie z nonce + capability checks)
- **Filter hooks publicznych**: 27
- **Action hooks publicznych**: 12
- **Admin pages**: 8 (Dashboard + Ustawienia 6-tab + Audytor + Posty + Encje + Reguły + Narzędzia + Meta-box + User Profile)
- **Gutenberg blocks**: 2 (FAQ + HowTo)
- **REST API endpoints**: 0 (wszystko przez AJAX action handler dla kompatybilności z WP 6.0+)
- **Database tables custom**: 0 (wszystko w `wp_options` + `wp_postmeta` + `wp_usermeta`)
- **External dependencies**: 0 (no composer runtime deps, no NPM)

---

## 16. License

GPL v2 lub późniejsza. Source code GitHub MIT-style policy (fork + modify freely).

## 17. Autor

**Marcin Żmuda** — założyciel agencji **Embasy** (SEO + marketing prawniczy, od 2014). Specjalizacja: pozycjonowanie kancelarii prawnych, technical SEO, link building, content marketing. Certyfikowany partner Google Ads. Twórca podcastu "Update Time by Marcin Żmuda".

- Website: https://marcinzmuda.com
- LinkedIn: https://www.linkedin.com/in/marcin-zmuda
- GitHub: https://github.com/MarcinZmuda
- YouTube: https://www.youtube.com/@marcin_zmuda_seo

## 18. Contact

- **Issues / Bug reports**: https://github.com/MarcinZmuda/Ligase-Schema-Markup/issues
- **Email**: marcin.zmuda@embasy.pl
- **Phone**: +48 506 257 330
- **Address**: Sosnowa 33D, 05-420 Józefów, Polska

---

*Dokument wygenerowany 2026-06-07 dla Ligase 2.4.23. Pełne release notes: [`readme.txt`](../readme.txt). Krótki changelog: [`CHANGELOG.md`](../CHANGELOG.md). Data-driven roadmap: [`docs/google-stats-coverage-2026-05.md`](google-stats-coverage-2026-05.md).*
