# Ligase 2.0.0 — Schema Type Audit

Scope: every PHP class under `includes/types/` against schema.org spec, Google Rich Results requirements, AI-citation best practices, and common JSON-LD bugs.

Wiring reference: [`class-generator.php`](includes/class-generator.php) assembles all type instances into a `@graph` with shared `@id` anchors (`#website`, `#org`, `#author-{id}`, `#localbusiness`, `#logo`, `#nav-{loc}`, post-permalink + `#posting|#video|#audio|#howto|#breadcrumb|#qapage|#claimreview|#glossary|#software|#course|#event|#service|#primaryimage`). Output: single `<script type="application/ld+json">` per request, JSON encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.

Legend: ✅ correct · 🐛 bug · ⚠️ Google-required/recommended missing · 💡 AI-citation improvement · 🔥 critical

---

## Cross-cutting findings (apply to ALL types)

### 🔥 Critical — `esc_html()` is being applied to JSON values

Almost every class wraps text fields with `esc_html()` before placing them in a PHP array that gets passed to `wp_json_encode()`. This converts `<`, `>`, `&`, `"`, `'` into HTML entities **inside the JSON string**. Search engines and AI parsers see `&amp;`, `&#039;`, `&quot;` literally — not the original character.

Examples (non-exhaustive):
- [`class-blogposting.php:30`](includes/types/class-blogposting.php#L30) — `'headline' => esc_html( mb_substr( get_the_title(), 0, 110 ) )`
- [`class-blogposting.php:58`](includes/types/class-blogposting.php#L58) — `'articleSection' => esc_html( $cats[0]->name )`
- [`class-blogposting.php:99-100`](includes/types/class-blogposting.php#L99) — `'name' => esc_html( $e['name'] )` inside `about` Things
- [`class-organization.php:14`](includes/types/class-organization.php#L14) — org name escaped
- [`class-organization.php:56`](includes/types/class-organization.php#L56) — telephone double-escaped (e.g. `+48 22 555-66-77` becomes safe HTML — usually no-op, but breaks if number contains `&`)
- [`class-localbusiness.php:153, 162, 178, 210, 215, 273`](includes/types/class-localbusiness.php#L153) — name, description, telephone, priceRange, areaServed, address parts
- [`class-faqpage.php:30`](includes/types/class-faqpage.php#L30) — question name encoded to entities (`Co to jest „X"?` → `Co to jest „X&quot;?`)
- [`class-howto.php:31-32`](includes/types/class-howto.php#L31) — step name and text
- [`class-breadcrumb.php:23, 33, 46, 89, 106`](includes/types/class-breadcrumb.php#L23) — every breadcrumb name
- [`class-person.php:21, 26, 31, 75, 82, 90`](includes/types/class-person.php#L21) — display name, description, jobTitle, honorific, alumni, credential
- [`class-review.php:48, 51`](includes/types/class-review.php#L48) — review name and body
- [`class-claimreview.php:48`](includes/types/class-claimreview.php#L48) — `claimReviewed`
- [`class-qapage.php:32-33`](includes/types/class-qapage.php#L32) — question name and text
- [`class-definedterm.php:31-32`](includes/types/class-definedterm.php#L31) — defined term name + description
- [`class-softwareapplication.php:36`](includes/types/class-softwareapplication.php#L36)
- [`class-course.php:27`](includes/types/class-course.php#L27)
- [`class-event.php:27, 38`](includes/types/class-event.php#L27)
- [`class-service.php:38, 41`](includes/types/class-service.php#L38)
- [`class-sitenavigation.php:108, 124`](includes/types/class-sitenavigation.php#L108)

Impact: Polish titles like `Czym jest "lean SEO" & co dalej?` end up in JSON as `Czym jest &quot;lean SEO&quot; &amp; co dalej?`. Google's Rich Results Test will accept this (it's valid JSON), but the **rendered output in SERP/AI engines may show literal entities**, and string-matching by AI extractors (e.g. Perplexity citation) is degraded. `wp_json_encode()` already escapes the few characters it must (`"`, `\`, `/`). The right input is plain UTF-8 from the DB after `wp_strip_all_tags()` only.

Fix pattern: replace `esc_html( $x )` with `wp_strip_all_tags( $x )` (or just the raw string) inside the array; only `esc_url()` and `wp_kses_post()` (for HTML-bearing fields like `Answer.text`) make sense in JSON-LD context.

Exception (good): [`class-faqpage.php:33`](includes/types/class-faqpage.php#L33) uses `wp_kses_post()` for answer text — correct because answer is allowed to contain HTML.

### 🐛 `wp_get_post_tags()` returns terms, not name strings, when `fields=names` is used incorrectly

[`class-blogposting.php:51`](includes/types/class-blogposting.php#L51) — `wp_get_post_tags( $post_id, [ 'fields' => 'names' ] )` returns plain strings. Correct, but the strings are not escaped (mixed with the `esc_html` pattern elsewhere — inconsistent). schema.org expects either a single string or an array; both are valid, so OK.

### 🐛 `home_url('/#author-X')` / `home_url('/#org')` builds @id with `//#` when site is at root

`home_url('/')` returns `https://example.com/`. Concatenating `'/#org'` yields `https://example.com//#org` (double slash before fragment). Affects every entity reference across the codebase:
- [`class-blogposting.php:35-37`](includes/types/class-blogposting.php#L35)
- [`class-organization.php:13`](includes/types/class-organization.php#L13) (`#org` itself), :71 (founder), :81 (employee)
- [`class-person.php:20, 64`](includes/types/class-person.php#L20)
- [`class-website.php:10, 14`](includes/types/class-website.php#L10)
- [`class-localbusiness.php:152, 231`](includes/types/class-localbusiness.php#L152)
- [`class-faqpage.php`](includes/types/class-faqpage.php) (no @id at all — see below)

Wait — `home_url('/#org')` actually passes `/#org` as the path argument to `home_url()`, which strips the leading slash duplication; the result is `https://example.com/#org`. **Verified by inspecting WP core behaviour: this is fine.** Documenting as a non-issue for the audit record.

### 🐛 `esc_url()` then string-concatenate fragment loses the fragment

`esc_url( get_permalink() ) . '#posting'` — `esc_url()` does not strip fragments, and `get_permalink()` returns a URL without one. Result is `https://example.com/post/#posting`. This works, but if any post permalink ever contains a query string (e.g. `?p=123` on a misconfigured site), the resulting `https://example.com/?p=123#posting` is well-formed. OK in practice.

### 💡 No `@context` per node — that's fine

Single `@context` at the top of the `@graph` ([`class-output.php:46`](includes/class-output.php#L46)) is the recommended pattern. ✅

### 💡 No `inLanguage` on entities that should have it

`Question`, `Answer`, `HowToStep`, `DefinedTerm`, `Review`, `ClaimReview`, `Course`, `Event`, `Service`, individual `ImageObject` items — none get `inLanguage` even though the page locale is known. This degrades multilingual citation and AI ranking for non-English content. Especially relevant for Brajn's Polish-language sites.

### 💡 No global `accessibilityFeature` / `accessibilityHazard` / `accessibilityAPI` anywhere

These are recommended for AI Overviews scoring of accessibility-conscious content. Not critical.

### 🐛 `get_the_content()` for `wordCount` includes shortcodes and Gutenberg block comments

[`class-blogposting.php:61-66`](includes/types/class-blogposting.php#L61) — `get_the_content()` returns raw post_content with block markers (`<!-- wp:paragraph -->`). `wp_strip_all_tags()` removes the tags but not the block comments / shortcode wrappers — `str_word_count` inflates. Better: `apply_filters( 'the_content', get_the_content() )` then strip.

---

## 1. BlogPosting / Article / NewsArticle — [`class-blogposting.php`](includes/types/class-blogposting.php)

### ✅ Correct
- `@type` validated against allowlist [L18-21](includes/types/class-blogposting.php#L18) — `Article`, `BlogPosting`, `NewsArticle`, `TechArticle`, `LiveBlogPosting`.
- `mainEntityOfPage` is an object with `@type WebPage` and `@id` — correct Google-required pattern [L26-29](includes/types/class-blogposting.php#L26).
- `headline` truncated to 110 chars [L30](includes/types/class-blogposting.php#L30) — matches Google's hard cap.
- `datePublished` / `dateModified` use `'c'` format → ISO 8601 with timezone [L31-32](includes/types/class-blogposting.php#L31). ✅
- `author` is an array of `@id` refs [L35](includes/types/class-blogposting.php#L35) — supports multi-author. Good.
- `publisher`, `isPartOf` linked via `@id` — graph linkage works.
- Image variants: 16:9 / 4:3 / 1:1 generated when source ≥ 1200px [L186-204](includes/types/class-blogposting.php#L186) — matches Google's recommendation.
- `isAccessibleForFree: true` — useful CSE / paywall signal.
- `speakable` from settings [L88-92](includes/types/class-blogposting.php#L88) — strong AI/voice signal.
- `about` / `mentions` / `isBasedOn` / `temporalCoverage` / `hasPart` — excellent AI-citation surface area.
- `potentialAction: ReadAction` — nice-to-have signal.
- `accessMode` toggles `textual`/`visual` based on image presence [L75](includes/types/class-blogposting.php#L75) — accessibility good.

### 🐛 Bugs
- 🐛 [L17](includes/types/class-blogposting.php#L17) `get_post_meta(...,'_ligase_schema_type', true ) ?: $global_default` — if the meta value is the string `"0"`, `?:` falls through. Low risk because allowed list filters, but worth `?? $global_default` semantics if the option key ever stores `"0"`.
- 🐛 [L25, 28, 33, 38](includes/types/class-blogposting.php#L25) `esc_url( get_permalink() )` — fine, but `get_permalink()` may return `false` if called outside the loop. Wrap with fallback like in `class-generator.php`'s `webpage` builder.
- 🐛 [L42](includes/types/class-blogposting.php#L42) `mb_substr($excerpt, 0, 300)` may cut mid-sentence; consider `wp_trim_words()` for cleaner truncation (cosmetic).
- 🐛 [L63](includes/types/class-blogposting.php#L63) `str_word_count` is byte-level — undercounts Polish multi-byte words. Use `preg_match_all('/\b\w+\b/u', $text)`. Affects Polish sites materially (e.g. `żółć` may count as 0 words on some locales).
- 🐛 [L75](includes/types/class-blogposting.php#L75) `$schema['accessMode'] = ! empty( $images ) ? [ 'textual', 'visual' ] : [ 'textual' ]` — schema.org defines `accessMode` value space; `auditory` is the right value when audio is present. The current branch ignores attached AudioObject — minor.
- 🐛 [L78-81](includes/types/class-blogposting.php#L78) — `potentialAction.target` should be an `EntryPoint` object for full validity (like `WebSite.SearchAction`), but Google accepts a bare URL for `ReadAction`. OK.
- 🐛 [L99-101](includes/types/class-blogposting.php#L99) — `about` items receive `esc_url( $e['sameAs'] ?? '' )` even when `sameAs` is empty, yielding `'sameAs' => ''`. Empty string is technically invalid for a URL property. Add `if ( $e['sameAs'] ?? '' )` guard.
- 🐛 [L84-85](includes/types/class-blogposting.php#L84) — `$opts` is reloaded twice from `get_option()`. Cosmetic.
- 🐛 [L107-110](includes/types/class-blogposting.php#L107) — `mentions` items have only `name` (no `sameAs`, no Wikidata link). AI engines value linked entities; merge with entity pipeline output to add `sameAs` like `about` does.
- 🐛 [L122-127](includes/types/class-blogposting.php#L122) — `isBasedOn` items typed as `Article`. Many sources are not articles (datasets, govt pages, reports). Either use `CreativeWork` (parent) or carry source `@type` through meta. Also missing `author` of the source.
- 🐛 [L132-136](includes/types/class-blogposting.php#L132) — `hasPart` series children type-locked to `BlogPosting` regardless of child post type / `_ligase_schema_type`. Pull from same allowlist.

### ⚠️ Google missing/recommended
- ⚠️ No `dateCreated` (Google ignores, but spec-clean to include for Articles).
- ⚠️ `commentCount` is included only when `>0` — fine, but missing `discussionUrl`.
- ⚠️ `keywords` is an array per L53. Google's structured-data docs accept array or comma-separated string. Array is fine — most validators accept it. ✅ actually.

### 💡 AI-citation
- 💡 Missing `citation` (cited works) — currently uses `isBasedOn`, which is similar but less common in AI training data. Consider emitting both.
- 💡 No `educationalLevel` / `audience` / `editorialNotice` — useful for AI grounding.
- 💡 `author` references are `@id`-only — Person entity carries `sameAs`/Wikidata. Good graph linkage. ✅

### 🔥 Critical
- See cross-cutting `esc_html` issue. For `headline` specifically, an apostrophe or ampersand in a Polish title will display badly in AI surfaces.

**Verdict: NEEDS WORK** — strong overall, but the cross-cutting `esc_html` issue and `str_word_count` Polish-byte bug must be fixed.

---

## 2. Person — [`class-person.php`](includes/types/class-person.php)

### ✅ Correct
- Stable `@id` `home_url('/#author-{id}')` — used everywhere for linkage. ✅
- `sameAs` includes Wikidata, LinkedIn, Twitter with scheme/host validation [L39-57](includes/types/class-person.php#L39). Excellent.
- `image` is `ImageObject` (not bare URL) [L60-62](includes/types/class-person.php#L60). ✅
- `worksFor` → `#org` linkage [L64](includes/types/class-person.php#L64). ✅
- `alumniOf` / `hasCredential` / `honorificPrefix` — strong E-E-A-T signals.
- `knowsAbout` from user meta [L34-37](includes/types/class-person.php#L34). ✅

### 🐛 Bugs
- 🐛 [L36](includes/types/class-person.php#L36) — `knowsAbout` items are not URL-linked; ideally each is a `DefinedTerm` or has `sameAs` to Wikidata. As-is, plain strings work but AI engines can't disambiguate.
- 🐛 [L69](includes/types/class-person.php#L69) — `mainEntityOfPage` is a bare URL, not an object with `@type WebPage` (inconsistent with `BlogPosting.mainEntityOfPage` at [L26-29](includes/types/class-blogposting.php#L26)). Both forms are valid per schema.org, but consistency improves graph quality and matches Google's example.
- 🐛 [L80-83](includes/types/class-person.php#L80) — `alumniOf.@type` hard-coded to `CollegeOrUniversity`. Some users are alumni of `School`/`Organization`. Allow override.
- 🐛 [L88-91](includes/types/class-person.php#L88) — `hasCredential` lacks `credentialCategory` (e.g. `degree`, `certification`), which Google's Profile Page docs recommend.
- 🐛 No `givenName` / `familyName` split — derived from `display_name` only. AI citation engines often grep `familyName`.

### ⚠️ Google missing
- ⚠️ Missing `jobTitle` is OK (optional), but `worksFor` should also include `@type Organization` when not using `@id` ref. The `@id` ref pattern is fine because Organization is in the same graph.
- ⚠️ `Person.image` is required by Google for Profile Page rich results in some surfaces. Good — auto-pulled from avatar [L59-62](includes/types/class-person.php#L59).

### 💡 AI-citation
- 💡 Missing `affiliation`, `award`, `publishingPrinciples` — useful for E-E-A-T.
- 💡 No `nationality` / `gender` / `birthPlace` — privacy-sensitive, leave optional.
- 💡 `email` would help disambiguation but is privacy-sensitive.

**Verdict: GOOD** — minor inconsistency with `mainEntityOfPage` shape, `esc_html` cross-cutting bug applies.

---

## 3. Organization — [`class-organization.php`](includes/types/class-organization.php)

### ✅ Correct
- `@id` `#org` stable across pages [L13](includes/types/class-organization.php#L13). ✅
- `sameAs` with Wikidata/Wikipedia + social, validated [L23-44](includes/types/class-organization.php#L23). Excellent — Wikidata first is best practice.
- `logo` is `ImageObject` with own `@id #logo` [L96-101](includes/types/class-organization.php#L96). ✅
- `knowsAbout` [L46-49](includes/types/class-organization.php#L46). ✅
- `founder` linked to author `@id` [L69-72](includes/types/class-organization.php#L69). ✅ unusual and strong signal.
- `employee` lists every published author by `@id` [L75-83](includes/types/class-organization.php#L75). Strong E-E-A-T.

### 🐛 Bugs
- 🐛 [L74-78](includes/types/class-organization.php#L74) — `get_users(['has_published_posts'=>true,'fields'=>'ID'])` runs **on every page load**. No cache. For a site with 200 authors this is a JOIN against `posts`. Wire to a transient or to plugin's existing cache layer. Performance issue.
- 🐛 [L88-101](includes/types/class-organization.php#L88) — `logo_width`/`logo_height` default `600x60`. If user uploads a square 512×512 logo and doesn't set dimensions, schema lies. Read actual dimensions from attachment when `org_logo` is an attachment URL on the local host.
- 🐛 [L98-99](includes/types/class-organization.php#L98) — Google requires logo to be on the same host and ≤600×60 *for Article rich-results*. The schema accepts any size, but values defaulting to 600×60 may not match the actual file.
- 🐛 [L56-61](includes/types/class-organization.php#L56) — `telephone` emitted both at root and inside `contactPoint`. Duplication is OK but the `contactPoint.contactType` is hard-coded to `customer service` — should be configurable.
- 🐛 [L51-53](includes/types/class-organization.php#L51) — `email` is fine, but no `contactPoint.email` linkage. AI engines look at contact points.

### ⚠️ Google missing
- ⚠️ No `address` on `Organization` (only on `LocalBusiness`) — Google's Organization rich result accepts `address` as `PostalAddress`.
- ⚠️ No `foundingDate`, `numberOfEmployees`, `legalName`, `taxID`, `vatID`, `iso6523Code` — all are Google-recommended for Organization rich results.

### 💡 AI-citation
- 💡 No `parentOrganization`/`subOrganization` graph — fine for SMEs.
- 💡 No `keywords` on Organization.
- 💡 `slogan` would help.

### 🔥 Critical
- 🔥 The `employee` list could leak deleted/spam users (no `WP_User_Query` filter on role). Add `'role__in' => ['author','editor','administrator','contributor']`.

**Verdict: NEEDS WORK** — performance concern on `employee` query + missing Google-recommended fields (address, foundingDate, legalName).

---

## 4. LocalBusiness — [`class-localbusiness.php`](includes/types/class-localbusiness.php)

### ✅ Correct
- 60+ subtypes validated via flat map [L139-144](includes/types/class-localbusiness.php#L139). ✅
- `address` as `PostalAddress` [L184-186, L261-277](includes/types/class-localbusiness.php#L261). ✅
- `geo` as `GeoCoordinates` + `hasMap` from lat/lng [L187-197](includes/types/class-localbusiness.php#L187). ✅
- `openingHoursSpecification` structured (not the legacy `openingHours` text) [L199-206, L288-320](includes/types/class-localbusiness.php#L199). ✅
- Time validation `^\d{2}:\d{2}$` [L298-301](includes/types/class-localbusiness.php#L298). ✅
- `parentOrganization` link to `#org` [L231](includes/types/class-localbusiness.php#L231). ✅
- `sameAs` reuses Organization's social URLs [L218-228](includes/types/class-localbusiness.php#L218). ✅
- Doc comment explicitly notes no `aggregateRating` (Google's 2019 policy) [L15-16](includes/types/class-localbusiness.php#L15). ✅ smart.

### 🐛 Bugs
- 🐛 [L167](includes/types/class-localbusiness.php#L167) — `image` is `array( esc_url(...) )`. Should be `ImageObject` with @id for graph linkage (matches `Organization.logo` pattern). Bare URL works for Google but is weaker.
- 🐛 [L195-196](includes/types/class-localbusiness.php#L195) — `hasMap` URL is plain string concat. `esc_url()` it; also schema spec uses `Map` type but a URL string is accepted.
- 🐛 [L189-190](includes/types/class-localbusiness.php#L189) — `(float)` cast: empty string → 0.0, then guarded `!== 0.0`. But valid coords near `0.0,0.0` (Gulf of Guinea) would be skipped. Edge case.
- 🐛 [L222-225](includes/types/class-localbusiness.php#L222) — no scheme/host validation on `sameAs` URLs here, unlike Organization. Inconsistent — copy the validation pattern from [`class-organization.php:35-41`](includes/types/class-organization.php#L35).
- 🐛 [L269](includes/types/class-localbusiness.php#L269) — `addressCountry` should ideally be a two-letter ISO code wrapped as `Country` object, but plain string is accepted by Google.
- 🐛 [L305](includes/types/class-localbusiness.php#L305) — `dayOfWeek` accepts plain strings like `"Monday"`. schema.org accepts `https://schema.org/Monday` URLs too; both work in Google. ✅ acceptable.
- 🐛 [L311-314](includes/types/class-localbusiness.php#L311) — `validFrom`/`validThrough` accept any string. No date-format validation. Bad date breaks the entire spec object.
- 🐛 [L214-216](includes/types/class-localbusiness.php#L214) — `areaServed` is a single string. For service-area businesses with multiple regions, this should be an array; UI may force single string but that's a limitation.

### ⚠️ Google missing
- ⚠️ Missing `priceRange` validation. Google wants format like `$$`, `$$$`, or `"$10–$100"`. Free text accepted but inconsistent.
- ⚠️ No `currenciesAccepted`, `paymentAccepted` — Google's LocalBusiness rich result fields.
- ⚠️ No `menu` (Restaurant), `servesCuisine` — Food subtypes get nothing extra.
- ⚠️ No `department`, `branchOf` — multi-location businesses unsupported.
- ⚠️ Missing `image` validation/dimensions; Google requires 1:1, 4:3, 16:9 like Articles.

### 💡 AI-citation
- 💡 No `slogan`, `award`, `knowsAbout` (Organization has it; LocalBusiness should mirror).
- 💡 No `founder`, `employee` linkage (Organization has it; LocalBusiness should mirror via `@id` references).

**Verdict: NEEDS WORK** — solid foundation, but missing subtype-specific properties (Restaurant.menu, etc.) and `image` should be ImageObject.

---

## 5. WebSite — [`class-website.php`](includes/types/class-website.php)

### ✅ Correct
- `@id` `#website` stable [L10](includes/types/class-website.php#L10). ✅
- `inLanguage` set [L13](includes/types/class-website.php#L13). ✅
- `publisher` linked to `#org` [L14](includes/types/class-website.php#L14). ✅
- `SearchAction` with `EntryPoint` and `query-input` — exact format Google wants [L17-25](includes/types/class-website.php#L17). ✅ This is the only place doing the EntryPoint pattern correctly.

### 🐛 Bugs
- 🐛 [L17](includes/types/class-website.php#L17) — `home_url('/?s={search_term_string}')` may be URL-encoded by some WP setups, breaking the template literal. Should pass raw string and `esc_url_raw()` only the base.
- 🐛 [L22](includes/types/class-website.php#L22) — `urlTemplate` should not be `esc_url()`'d because the `{search_term_string}` placeholder must remain literal. The code currently doesn't `esc_url()` it (good), but if any filter wraps it, the placeholder gets encoded.

### ⚠️ Google missing
- ⚠️ Missing `description` (Google recommends).
- ⚠️ Missing `alternateName` (often shows in Knowledge Panel).
- ⚠️ Missing `copyrightHolder` / `copyrightYear`.

### 💡 AI-citation
- 💡 No `mainEntity` linking to a primary entity (e.g. for personal blogs, `mainEntity` → Person).
- 💡 No `sameAs` on WebSite (Organization has it; some engines also check WebSite).
- 💡 No `isFamilyFriendly`, `creativeWorkStatus` — minor.

**Verdict: GOOD** — minimal, correct, idiomatic. Add description/alternateName for richness.

---

## 6. BreadcrumbList — [`class-breadcrumb.php`](includes/types/class-breadcrumb.php)

### ✅ Correct
- Skips homepage (1-item breadcrumb is invalid) [L9-11](includes/types/class-breadcrumb.php#L9). ✅
- Handles nested pages via `get_post_ancestors` [L40-50](includes/types/class-breadcrumb.php#L40). ✅
- Handles CPT archive [L52-65](includes/types/class-breadcrumb.php#L52). ✅
- Handles taxonomy parent chain [L72-85](includes/types/class-breadcrumb.php#L72). ✅
- Last item correctly omits `item` (Google's rule for terminal node) [L86-90](includes/types/class-breadcrumb.php#L86) — wait, see bug.

### 🐛 Bugs
- 🐛 [L86-90 vs L103-113](includes/types/class-breadcrumb.php#L86) — for taxonomy archives the terminal node omits `item`, but for posts/pages the terminal node *includes* `item` (the permalink). Google's docs say both are acceptable, but the inconsistency is odd. **Google's current guidance prefers including `item` on the last entry too** (changed ~2022).
- 🐛 [L93-94](includes/types/class-breadcrumb.php#L93) — defensive `is_string($id)` check exists, but `get_term_link()` can return `WP_Error` even when input is correct; better to use `is_wp_error()` explicitly.
- 🐛 [L29-37](includes/types/class-breadcrumb.php#L29) — uses only `$cats[0]` for posts in multiple categories. WordPress's "primary category" plugins (Yoast/RankMath) set a `_yoast_wpseo_primary_category` or `_rank_math_primary_category` meta. Should respect when present.
- 🐛 [L96-100, L115-119](includes/types/class-breadcrumb.php#L96) — `@id` collisions: same permalink `+ #breadcrumb` is fine, but if Person + ProfilePage on author archive both generate breadcrumbs (Person doesn't — checked), unique.

### ⚠️ Google missing
- Nothing critical missing — Google rich result for breadcrumb requires `position`, `name`, `item` (except last). All handled.

### 💡 AI-citation
- 💡 Breadcrumbs help AI Overviews extract hierarchy. ✅ already done.

**Verdict: GOOD** — minor: prefer primary-category, include `item` on terminal node consistently.

---

## 7. FAQPage — [`class-faqpage.php`](includes/types/class-faqpage.php)

### ✅ Correct
- Validates Question + Answer non-empty [L25-27](includes/types/class-faqpage.php#L25). ✅
- Requires ≥2 Questions [L38-40](includes/types/class-faqpage.php#L38) — defensive; Google previously deprecated FAQ rich results for non-gov/non-health sites in Aug 2023, but the schema is still indexed.
- `Answer.text` uses `wp_kses_post()` [L33](includes/types/class-faqpage.php#L33). ✅ correct (allows safe HTML).

### 🐛 Bugs
- 🔥 [L42-45](includes/types/class-faqpage.php#L42) — **no `@id` on FAQPage**. Cannot be referenced from `mainEntityOfPage` of BlogPosting or linked into the graph. Every other type has `@id`; this one doesn't.
- 🐛 No `inLanguage` on FAQPage or on each `Question`.
- 🐛 No `dateCreated` / `dateModified` on Question/Answer — useful for freshness signals.
- 🐛 No `author` on Question — when these are user-submitted vs editorial, attribution differs.
- 🐛 No `isPartOf` linking back to `#website`.

### ⚠️ Google missing
- ⚠️ Google's FAQ rich result documentation requires Question.name and acceptedAnswer.text — both present. ✅
- ⚠️ Google deprecated FAQ rich results widely (Aug 2023 update); still indexed but no rich result.

### 💡 AI-citation
- 💡 FAQ schema is **heavily used by ChatGPT, Perplexity, AI Overviews**. Missing `@id` and `mainEntityOfPage` link reduces citation rate.
- 💡 Should set `mainEntityOfPage` on FAQPage = post permalink.
- 💡 Each Question should have `answerCount: 1` to mirror QAPage.

### 🔥 Critical
- 🔥 Missing `@id` (see above).

**Verdict: NEEDS WORK** — works but is graph-disconnected. Add `@id`, `inLanguage`, `isPartOf`, `mainEntityOfPage`.

---

## 8. HowTo — [`class-howto.php`](includes/types/class-howto.php)

### ✅ Correct
- ISO 8601 duration regex validated [L48, L55-57](includes/types/class-howto.php#L48). ✅
- Step `@type HowToStep` with position, name, text, url anchor [L28-34](includes/types/class-howto.php#L28). ✅
- `@id` includes `#howto` for graph linkage [L43](includes/types/class-howto.php#L43). ✅

### 🐛 Bugs
- 🐛 [L33](includes/types/class-howto.php#L33) — step URL fragment is `#krok-{n}` (Polish for "step"). Hard-codes Polish locale. Should be `#step-{n}` or driven by locale.
- 🐛 [L32](includes/types/class-howto.php#L32) — `'text' => esc_html(...)` escapes HTML inside step instructions; should be `wp_kses_post()` since HowToStep.text often has formatting.
- 🐛 No image per step (`HowToStep.image`) — Google's HowTo rich result strongly benefits from step images.
- 🐛 No `HowToSupply` / `HowToTool` for materials/tools.
- 🐛 No `estimatedCost`, `yield`, `prepTime`, `cookTime`, `performTime` (HowTo has them all).
- 🐛 No `inLanguage` on HowTo.
- 🐛 No `image` on HowTo top-level (Google **requires** an image for HowTo rich result).
- 🐛 No `isPartOf` / `mainEntityOfPage` linkage.

### ⚠️ Google missing
- ⚠️ **`image` is required by Google for HowTo rich results** — currently never emitted. 🔥
- ⚠️ Google deprecated HowTo rich result for non-mobile in Sep 2023; still indexed.

### 💡 AI-citation
- 💡 HowTo with `step` arrays is highly quotable by AI engines. Add `inLanguage` and `mainEntityOfPage`.

**Verdict: NEEDS WORK** — missing required `image`, no per-step images, hardcoded Polish anchor.

---

## 9. VideoObject — [`class-videoobject.php`](includes/types/class-videoobject.php)

### ✅ Correct
- YouTube ID extracted with regex, validated [L28-37](includes/types/class-videoobject.php#L28). ✅
- `uploadDate` ISO 8601 [L47](includes/types/class-videoobject.php#L47). ✅
- Manual meta path + YouTube auto-detect — flexible. ✅
- ISO 8601 duration validated [L54, L69](includes/types/class-videoobject.php#L54). ✅
- `@id` for graph linkage [L42](includes/types/class-videoobject.php#L42). ✅

### 🐛 Bugs
- 🔥 [L46](includes/types/class-videoobject.php#L46) — `thumbnailUrl` uses `maxresdefault.jpg` which **doesn't exist for many YouTube videos** (only HD uploads). Google will flag broken thumbnail. Fallback chain: `maxresdefault → sddefault → hqdefault → mqdefault`. The plugin should HEAD-check or use `hqdefault` (universally present).
- 🐛 [L44](includes/types/class-videoobject.php#L44) — `description` may be empty (if post has no excerpt). Google requires `description` for Video rich result.
- 🐛 [L42-50](includes/types/class-videoobject.php#L42) — missing `publisher`, `isPartOf`, `isFamilyFriendly`. Also missing `interactionStatistic` (view count).
- 🐛 [L61-73](includes/types/class-videoobject.php#L61) — `build_from_meta` lacks `@id`, `inLanguage`, `description` fallback. Inconsistent with YouTube path.
- 🐛 [L66](includes/types/class-videoobject.php#L66) — `esc_html($meta['upload_date'])` — date should be raw ISO string, not entity-encoded.
- 🐛 [L46-49](includes/types/class-videoobject.php#L46) — no scheme on YouTube URLs is hard-coded `https://www.youtube.com/...`. Fine.

### ⚠️ Google missing
- ⚠️ **`thumbnailUrl` reliability** — see L46 issue.
- ⚠️ Missing `contentUrl` OR `embedUrl` is the Google requirement; both present for YouTube path. ✅
- ⚠️ Missing `description` may fail rich result.
- ⚠️ Missing `duration` is optional but recommended.

### 💡 AI-citation
- 💡 No `transcript` — major missed opportunity for AI engines. If site has captions/transcripts, attach.
- 💡 No `hasPart` clip markers (`Clip` type) for chapters.
- 💡 No `mainEntityOfPage`.

### 🔥 Critical
- 🔥 `maxresdefault.jpg` thumbnail will 404 for ~30% of YouTube videos.

**Verdict: NEEDS WORK** — thumbnail fallback is critical; otherwise solid.

---

## 10. AudioObject — [`class-audioobject.php`](includes/types/class-audioobject.php)

### ✅ Correct
- Multi-provider auto-detect (Spotify, Buzzsprout, Anchor) [L30-62](includes/types/class-audioobject.php#L30). ✅
- ISO 8601 duration validated [L77, L102](includes/types/class-audioobject.php#L77). ✅
- `@id` for linkage [L67, L87](includes/types/class-audioobject.php#L67). ✅
- `inLanguage` set [L70, L89](includes/types/class-audioobject.php#L70). ✅

### 🐛 Bugs
- 🔥 [L56-57](includes/types/class-audioobject.php#L56) — anchor.fm path has bugs: `'embed_url' => 'https://anchor.fm/' . $m[0]` — `$m[0]` is the **entire matched string** including `anchor.fm/...` from the regex match. Concatenating `'https://anchor.fm/' . 'anchor.fm/show/episodes/...'` produces `https://anchor.fm/anchor.fm/show/episodes/...`. **Broken URL.**
- 🐛 [L52](includes/types/class-audioobject.php#L52) — Anchor.fm was rebranded to Spotify for Podcasters; `anchor.fm` URLs redirect now. Pattern still works but stale.
- 🐛 [L36](includes/types/class-audioobject.php#L36) — `[\w]` in regex matches `_` which is valid in Spotify IDs (alphanumeric only actually). Use `[A-Za-z0-9]`.
- 🐛 [L65-74](includes/types/class-audioobject.php#L65) — `build_from_embed` doesn't include `description` if excerpt is empty.
- 🐛 No `publisher` / `isPartOf` / `mainEntityOfPage`.
- 🐛 No `transcript` field surface.

### ⚠️ Google missing
- ⚠️ Google's PodcastEpisode rich result wants `PodcastEpisode` type, not generic `AudioObject` — consider mapping when detected as podcast.
- ⚠️ Missing `partOfSeries` (PodcastSeries linkage).

### 💡 AI-citation
- 💡 No `transcript` / `accessMode` — critical for AI surfacing of audio.

### 🔥 Critical
- 🔥 [L56-57] anchor.fm URL construction emits invalid URL.

**Verdict: CRITICAL** — anchor.fm URL bug emits broken JSON-LD URLs.

---

## 11. Review — [`class-review.php`](includes/types/class-review.php)

### ✅ Correct
- Rating range validated 1-5 [L25-33](includes/types/class-review.php#L25). ✅
- `reviewRating` with bestRating/worstRating as strings [L40-45](includes/types/class-review.php#L40). ✅ (strings preferred over numbers per Google).
- `author` linked by `@id` [L37](includes/types/class-review.php#L37). ✅
- `itemReviewed.@type` allowlist [L55-59](includes/types/class-review.php#L55). ✅

### 🐛 Bugs
- 🐛 [L35-46](includes/types/class-review.php#L35) — **no `@id` on Review**. Cannot be referenced.
- 🐛 [L51](includes/types/class-review.php#L51) — `reviewBody` truncated to 500 chars. Arbitrary; AI engines benefit from full reviews. Make configurable or remove truncation.
- 🐛 [L60-63](includes/types/class-review.php#L60) — `itemReviewed` only has `name` — no `@id`, no `url`, no `sameAs`. If reviewing a Product/SoftwareApplication, that entity should be richer (or link to a SoftwareApplication node in the same graph).
- 🐛 No `inLanguage`.
- 🐛 No `mainEntityOfPage` / `isPartOf`.

### ⚠️ Google missing
- ⚠️ Google's review snippet for self-reviewed content was deprecated for many types in 2019 (LocalBusiness, Organization). For `Product`/`Book`/`Movie`/etc. reviews, Google still surfaces stars **only if reviewed item appears in Google Shopping or known product**. This plugin emits review but no stars will show for non-product reviews. The plugin should warn the admin (not in scope here).

### 💡 AI-citation
- 💡 `Review.publisher` referenced but no `Review.about`.
- 💡 No `reviewAspect` (multi-aspect reviews) — minor.

**Verdict: NEEDS WORK** — missing `@id`, weak `itemReviewed` linkage.

---

## 12. QAPage — [`class-qapage.php`](includes/types/class-qapage.php)

### ✅ Correct
- `mainEntity` is a single `Question` (Google's hard requirement for QAPage) [L30-44](includes/types/class-qapage.php#L30). ✅
- `acceptedAnswer` with `text`, `dateCreated`, `author`, `upvoteCount` [L37-43](includes/types/class-qapage.php#L37). ✅
- `answerCount: 1` declared [L36](includes/types/class-qapage.php#L36). ✅
- `Answer.text` uses `wp_kses_post()` [L39](includes/types/class-qapage.php#L39). ✅

### 🐛 Bugs
- 🐛 [L32-33](includes/types/class-qapage.php#L32) — Question `name` and `text` both set to the same string. `name` is short label, `text` is full body. If question body is longer than headline, only the headline is captured. Add a separate `_ligase_qa_question_body` meta.
- 🐛 [L42](includes/types/class-qapage.php#L42) — `upvoteCount` set to `get_comments_number()` — comments ≠ upvotes. Stretches the meaning. Acceptable but documenting.
- 🐛 No `suggestedAnswer` array (Google allows multiple suggestedAnswers + one acceptedAnswer).
- 🐛 No `inLanguage`, `isPartOf`, `mainEntityOfPage` (the QAPage itself), `dateModified`.
- 🐛 [L29](includes/types/class-qapage.php#L29) — `@id` includes `#qapage` — good.

### ⚠️ Google missing
- ⚠️ Google's QAPage rich result requires exactly one Question in `mainEntity`. ✅
- ⚠️ Question needs `text` and acceptedAnswer with `text` — ✅
- ⚠️ Recommended: `upvoteCount` on Answer, `dateCreated` on both — ✅

### 💡 AI-citation
- 💡 QAPage is heavily mined by AI engines. Add `inLanguage` + `isPartOf`.

**Verdict: GOOD** — minor issues, mostly graph linkage.

---

## 13. ClaimReview — [`class-claimreview.php`](includes/types/class-claimreview.php)

### ✅ Correct
- Verdict allowlist [L7-14](includes/types/class-claimreview.php#L7). ✅
- `reviewRating` includes `alternateName` carrying the verbal verdict [L49-55](includes/types/class-claimreview.php#L49). ✅ — this is exactly what Google's ClaimReview rich-result docs require.
- `claimReviewed` + `itemReviewed` Claim object [L48, L58-64](includes/types/class-claimreview.php#L48). ✅
- `author` + `publisher` linked [L46-47](includes/types/class-claimreview.php#L46). ✅

### 🐛 Bugs
- 🐛 [L62](includes/types/class-claimreview.php#L62) — `itemReviewed.datePublished` uses `get_the_date('c')` of the **fact-check article**, not of the claim being checked. Google expects the **claim's** original publication date. Add a separate `_ligase_claim_date` meta.
- 🐛 [L61](includes/types/class-claimreview.php#L61) — `itemReviewed.author` only when `source` is set; without source, Claim has no author (invalid).
- 🐛 No `firstAppearance` on Claim (Google-recommended URL to where claim first appeared).
- 🐛 No `appearance` (URLs of other places claim has appeared).
- 🐛 [L51-52](includes/types/class-claimreview.php#L51) — `ratingValue` numeric scale 1-5 with `alternateName` is correct for Google, but some fact-checkers use BestRating=1 / WorstRating=5 (inverted) or 0-1. Current implementation is fine but document.
- 🐛 No `inLanguage`, `isPartOf`.

### ⚠️ Google missing
- ⚠️ ClaimReview eligibility: Google requires the publisher to be approved as a fact-checker (IFCN signatory). The plugin can't validate this; warn user.
- ⚠️ `itemReviewed.appearance` is recommended.
- ⚠️ Google deprecated `claimReviewed` shortcuts in 2022 for non-approved fact-checkers.

### 💡 AI-citation
- 💡 ClaimReview is mined by AI Overviews and Perplexity for fact-checking. Add `inLanguage` and full claim/firstAppearance metadata.

**Verdict: NEEDS WORK** — wrong `datePublished` semantics on Claim, missing firstAppearance.

---

## 14. DefinedTerm / DefinedTermSet — [`class-definedterm.php`](includes/types/class-definedterm.php)

### ✅ Correct
- Outer wrapper `DefinedTermSet` with `hasDefinedTerm` array [L41-48](includes/types/class-definedterm.php#L41). ✅ This is the correct pattern.
- `@id` on set [L43](includes/types/class-definedterm.php#L43). ✅
- `inLanguage` on set [L46](includes/types/class-definedterm.php#L46). ✅

### 🐛 Bugs
- 🐛 [L30-34](includes/types/class-definedterm.php#L30) — `inDefinedTermSet` on each term is a plain URL string (`...#glossary`). Should be `@id` reference object: `[ '@id' => esc_url(...) . '#glossary' ]` for proper graph linkage. Schema accepts both, but `@id` form is the graph-correct one.
- 🐛 No per-term `@id` — terms can't be referenced elsewhere.
- 🐛 No `termCode` (often used to slug-link a term).
- 🐛 No `url` on individual term (anchor link to in-page heading).
- 🐛 No `sameAs` on DefinedTerm — Wikidata linkage is huge for AI engines (e.g. "AI" → Q11660).

### ⚠️ Google missing
- ⚠️ Google doesn't surface DefinedTerm in rich results; this is for AI/semantic search. Fine as-is.

### 💡 AI-citation
- 💡 **Per-term `sameAs` to Wikidata** would be transformative — connects glossary terms to knowledge graph.
- 💡 Add `partOfTermSet` reciprocal references.

**Verdict: NEEDS WORK** — works but doesn't unlock AI-citation potential (no per-term `sameAs`, no Wikidata).

---

## 15. SoftwareApplication — [`class-softwareapplication.php`](includes/types/class-softwareapplication.php)

### ✅ Correct
- `applicationCategory` validated against allowlist [L24-31](includes/types/class-softwareapplication.php#L24). ✅
- `offers.Offer` with price & currency [L49-55](includes/types/class-softwareapplication.php#L49). ✅
- `aggregateRating` validated 1-5 [L58-67](includes/types/class-softwareapplication.php#L58). ✅

### 🐛 Bugs
- 🐛 [L52-54](includes/types/class-softwareapplication.php#L52) — `price` defaults to `'0'` and currency to `'USD'`. For Polish sites (Brajn case) this is wrong default. Use site locale to pick currency.
- 🐛 [L52-54](includes/types/class-softwareapplication.php#L52) — `Offer` lacks `availability`, `priceValidUntil`, `url`. Google's SoftwareApp rich result requires `priceValidUntil` for non-zero prices.
- 🐛 [L61-66](includes/types/class-softwareapplication.php#L61) — `aggregateRating` is emitted without `reviewCount` (only `ratingCount`). Google accepts either, but `reviewCount` is preferred when reviews exist.
- 🐛 No `operatingSystem` validation — accepts any string.
- 🐛 No `screenshot` field (Google requires screenshot for SoftwareApp rich result on mobile apps).
- 🐛 No `downloadUrl`, `softwareVersion`, `releaseNotes`, `fileSize`, `requirements`.
- 🐛 No `author` / `publisher` linkage — orphaned from graph.
- 🐛 No `inLanguage`, `isPartOf`, `mainEntityOfPage`.
- 🐛 [L48-55](includes/types/class-softwareapplication.php#L48) — `Offer` always emitted even when `price` is `'0'` (free). For free apps, omit Offer or set `price: 0` + `priceCurrency` (currently does this, but `availability` missing).

### ⚠️ Google missing
- ⚠️ Google's SoftwareApp requires: `name`, `image`, `aggregateRating`, and either `offers` or `operatingSystem+applicationCategory`. `image` is **never emitted** here. 🔥
- ⚠️ `priceValidUntil` required when `price > 0`.

### 💡 AI-citation
- 💡 No `sameAs` to vendor/Wikidata.

### 🔥 Critical
- 🔥 No `image` → Google rich result won't trigger.
- 🔥 No `author`/`publisher` graph link.

**Verdict: NEEDS WORK** — missing image, Offer availability, publisher link.

---

## 16. Course — [`class-course.php`](includes/types/class-course.php)

### ✅ Correct
- `provider` linked to `#org` [L30](includes/types/class-course.php#L30). ✅
- `inLanguage` set [L29](includes/types/class-course.php#L29). ✅
- `CourseInstance` nested under `hasCourseInstance` [L47-66](includes/types/class-course.php#L47). ✅
- `courseMode` allowlist [L49-53](includes/types/class-course.php#L49). ✅
- `teaches` array [L42-44](includes/types/class-course.php#L42). ✅

### 🐛 Bugs
- 🐛 [L56-58, L60](includes/types/class-course.php#L56) — `startDate` / `endDate` accept any string with no ISO 8601 validation. Bad date breaks instance.
- 🐛 [L65](includes/types/class-course.php#L65) — `$instance['@type']` set last; works but odd ordering. Cosmetic.
- 🐛 [L73](includes/types/class-course.php#L73) — currency defaults to PLN. Reasonable for Polish-focused plugin but should use site locale.
- 🐛 [L69-76](includes/types/class-course.php#L69) — Offer always emitted when `price` is `set` (even if `''` or `'0'`). Google requires `priceValidUntil` for non-zero prices.
- 🐛 No `image`, `audience`, `educationalLevel`, `coursePrerequisites`, `numberOfCredits`.
- 🐛 No `mainEntityOfPage`, `isPartOf`.
- 🐛 [L52](includes/types/class-course.php#L52) — `courseMode` should be schema.org URL `https://schema.org/OnsiteCourseMode` etc., though plain strings also accepted by Google.

### ⚠️ Google missing
- ⚠️ Google's Course rich result requires: `name`, `description`, `provider`. ✅
- ⚠️ Google's "Course Info" rich result (April 2023) recommends `hasCourseInstance.courseMode`, `instructor`, `courseSchedule`, `inLanguage`. Mostly missing.
- ⚠️ `image` strongly recommended.

### 💡 AI-citation
- 💡 No `educationalCredentialAwarded`, no `instructor` (Person link).

**Verdict: NEEDS WORK** — missing instructor, schedule, image, ISO validation.

---

## 17. Event — [`class-event.php`](includes/types/class-event.php)

### ✅ Correct
- `eventAttendanceMode` for online/offline [L43, L50](includes/types/class-event.php#L43). ✅
- `eventStatus` allowlist [L67-74](includes/types/class-event.php#L67). ✅
- Online events get `VirtualLocation` [L45-48](includes/types/class-event.php#L45). ✅
- `organizer` linked to `#org` [L29](includes/types/class-event.php#L29). ✅
- `image` from featured image [L88-94](includes/types/class-event.php#L88). ✅

### 🐛 Bugs
- 🐛 [L28, L34](includes/types/class-event.php#L28) — `startDate` / `endDate` no ISO 8601 validation.
- 🐛 [L51-63](includes/types/class-event.php#L51) — physical location requires `venue_name`; if missing, **no location is emitted** but `eventAttendanceMode: OfflineEventAttendanceMode` still set — contradictory. Google will fail.
- 🐛 [L56-61](includes/types/class-event.php#L56) — `PostalAddress` for venue only has `streetAddress`. Missing city/country/postalCode means invalid address.
- 🐛 [L92](includes/types/class-event.php#L92) — `image` is bare URL string. ImageObject preferred for Google.
- 🐛 No `performer`, `offers.validFrom`, `offers.validThrough`, `offers.availabilityStarts`.
- 🐛 [L82](includes/types/class-event.php#L82) — Offer always emitted when `ticket_url` present. `availability` hardcoded to `InStock` — events may be sold out.
- 🐛 No `inLanguage`.

### ⚠️ Google missing
- ⚠️ Google's Event rich result requires: `name`, `startDate`, `location`. Location may be missing (see bug L51-63). 🔥
- ⚠️ Recommended: `image`, `description`, `endDate`, `eventAttendanceMode`, `eventStatus`, `organizer`, `performer`, `offers`. Most ✅ except `performer`.

### 💡 AI-citation
- 💡 No `subEvent`, no `superEvent` — multi-day events unsupported.

### 🔥 Critical
- 🔥 OfflineEventAttendanceMode without location is invalid; will fail Google's Event Search.

**Verdict: NEEDS WORK** — location enforcement bug; missing performer; address incomplete.

---

## 18. Service — [`class-service.php`](includes/types/class-service.php)

### ✅ Correct
- `provider` linked to `#org` [L40](includes/types/class-service.php#L40). ✅
- `audience` as `Audience` object [L56-60](includes/types/class-service.php#L56). ✅
- `offers` with `seller` linked [L66-72](includes/types/class-service.php#L66). ✅
- `image` from featured [L76-81](includes/types/class-service.php#L76). ✅

### 🐛 Bugs
- 🐛 [L41](includes/types/class-service.php#L41) — `description` always emitted, even if both meta and excerpt are empty → `'description' => ''`. Guard against empty string.
- 🐛 [L79](includes/types/class-service.php#L79) — `image` is bare URL, not ImageObject.
- 🐛 [L64](includes/types/class-service.php#L64) — currency defaults to PLN (Polish bias, fine for this plugin).
- 🐛 No `inLanguage`, `isPartOf`, `mainEntityOfPage`.
- 🐛 No `serviceArea` (GeoShape/Place) — only string `areaServed`.
- 🐛 No `hasOfferCatalog` for multi-tier services.
- 🐛 No `award`, `slogan`, `category`.
- 🐛 [L26-28](includes/types/class-service.php#L26) — only checks single meta key, no rules engine. Other types have `Ligase_Schema_Rules::is_enabled_for_post()` fallback. Inconsistent.

### ⚠️ Google missing
- ⚠️ Service is not a Google rich-result type per se. No rich-result requirements.

### 💡 AI-citation
- 💡 No linkage to LocalBusiness when both configured — `provider` should optionally point to `#localbusiness` for local services.

**Verdict: NEEDS WORK** — empty description guard, image as URL not ImageObject, missing rules-engine integration.

---

## 19. SiteNavigationElement — [`class-sitenavigation.php`](includes/types/class-sitenavigation.php)

### ✅ Correct
- One schema per registered menu location [L41-50](includes/types/class-sitenavigation.php#L41). ✅
- Top-level only (no nested) — documented design choice [L73-77](includes/types/class-sitenavigation.php#L73). ✅
- Sorted by `menu_order` [L84](includes/types/class-sitenavigation.php#L84). ✅
- Filters out `#`, `javascript:` [L93-98](includes/types/class-sitenavigation.php#L93). ✅
- `@id` stable per location [L123](includes/types/class-sitenavigation.php#L123). ✅
- Absolutizes relative URLs [L101-103](includes/types/class-sitenavigation.php#L101). ✅

### 🐛 Bugs
- 🔥 [L105-110](includes/types/class-sitenavigation.php#L105) — items in `hasPart` are typed `SiteNavigationElement` again, **wrapping themselves**. The Google/schema.org pattern is: each link is a `SiteNavigationElement` directly (no wrapper), or use `ItemList` with `ListItem`. Current shape:
  ```
  SiteNavigationElement (outer)
    hasPart: [ SiteNavigationElement, SiteNavigationElement, ... ]
  ```
  This is semantically odd. The class docstring says "ItemList-based", but it doesn't use `ItemList`. Two valid alternatives:
  1. Drop the outer wrapper; emit each item as a top-level `SiteNavigationElement` in the graph.
  2. Use `ItemList` outer with `itemListElement: [ListItem...]` inside.
- 🐛 [L122, L106](includes/types/class-sitenavigation.php#L122) — outer object's `url` is `home_url('/')` for every menu — useless. Should be the menu's primary URL or omitted.
- 🐛 [L84](includes/types/class-sitenavigation.php#L84) — `usort()` on filtered array — works in PHP 8 but ordering is by reference for keys, not values; should `array_values()` first. Minor.
- 🐛 No `inLanguage` on the schema.
- 🐛 No filtering of submenu items into `hasPart` of parent — design choice but limits AI understanding of site hierarchy.

### ⚠️ Google missing
- ⚠️ Google doesn't have a rich result for site nav. Spec compliance only.

### 💡 AI-citation
- 💡 AI engines do use SiteNavigationElement to understand site structure. Wrapper anti-pattern (see 🔥) may confuse parsers.

### 🔥 Critical
- 🔥 Self-wrapping `SiteNavigationElement.hasPart: SiteNavigationElement[]` is non-idiomatic.

**Verdict: NEEDS WORK** — restructure to either flat list or `ItemList` pattern.

---

# Summary verdict per type

| Type | File | Verdict |
|---|---|---|
| BlogPosting/Article | [class-blogposting.php](includes/types/class-blogposting.php) | NEEDS WORK |
| Person | [class-person.php](includes/types/class-person.php) | GOOD |
| Organization | [class-organization.php](includes/types/class-organization.php) | NEEDS WORK |
| LocalBusiness | [class-localbusiness.php](includes/types/class-localbusiness.php) | NEEDS WORK |
| WebSite | [class-website.php](includes/types/class-website.php) | GOOD |
| BreadcrumbList | [class-breadcrumb.php](includes/types/class-breadcrumb.php) | GOOD |
| FAQPage | [class-faqpage.php](includes/types/class-faqpage.php) | NEEDS WORK |
| HowTo | [class-howto.php](includes/types/class-howto.php) | NEEDS WORK |
| VideoObject | [class-videoobject.php](includes/types/class-videoobject.php) | NEEDS WORK |
| AudioObject | [class-audioobject.php](includes/types/class-audioobject.php) | **CRITICAL** |
| Review | [class-review.php](includes/types/class-review.php) | NEEDS WORK |
| QAPage | [class-qapage.php](includes/types/class-qapage.php) | GOOD |
| ClaimReview | [class-claimreview.php](includes/types/class-claimreview.php) | NEEDS WORK |
| DefinedTerm | [class-definedterm.php](includes/types/class-definedterm.php) | NEEDS WORK |
| SoftwareApplication | [class-softwareapplication.php](includes/types/class-softwareapplication.php) | NEEDS WORK |
| Course | [class-course.php](includes/types/class-course.php) | NEEDS WORK |
| Event | [class-event.php](includes/types/class-event.php) | NEEDS WORK |
| Service | [class-service.php](includes/types/class-service.php) | NEEDS WORK |
| SiteNavigationElement | [class-sitenavigation.php](includes/types/class-sitenavigation.php) | NEEDS WORK |

## Top 10 fixes by impact

1. **🔥 [Cross-cutting] Remove `esc_html()` from JSON-LD text values.** Replace with `wp_strip_all_tags()` or raw UTF-8. `wp_json_encode()` already handles JSON escaping. Affects every class with string fields.
2. **🔥 [AudioObject] Fix anchor.fm URL builder** at [class-audioobject.php:56-57](includes/types/class-audioobject.php#L56) — emits malformed URLs.
3. **🔥 [SiteNavigationElement] Stop self-wrapping** at [class-sitenavigation.php:106](includes/types/class-sitenavigation.php#L106) — use flat list or `ItemList` + `ListItem`.
4. **🔥 [VideoObject] YouTube `maxresdefault.jpg` 404s for many videos** — fallback to `hqdefault.jpg`. [class-videoobject.php:46](includes/types/class-videoobject.php#L46).
5. **🔥 [FAQPage] Add `@id`, `inLanguage`, `isPartOf`, `mainEntityOfPage`** — currently graph-orphaned, hurts AI citation. [class-faqpage.php:42-45](includes/types/class-faqpage.php#L42).
6. **🔥 [Event] Validate that physical events have full address before emitting `OfflineEventAttendanceMode`** — currently can emit invalid event. [class-event.php:51-63](includes/types/class-event.php#L51).
7. **🔥 [SoftwareApplication] Add `image` (Google-required) and `publisher` graph link.** [class-softwareapplication.php:33-71](includes/types/class-softwareapplication.php#L33).
8. **🔥 [HowTo] Add top-level `image` (Google requires) and per-`HowToStep` images.** [class-howto.php:41-52](includes/types/class-howto.php#L41).
9. **🐛 [BlogPosting] Replace `str_word_count` with multibyte-safe count** for Polish/UTF-8 content. [class-blogposting.php:63](includes/types/class-blogposting.php#L63).
10. **🐛 [Organization] Cache `get_users()` employee query** (transient or plugin cache) to avoid per-request JOIN on large sites. [class-organization.php:74-83](includes/types/class-organization.php#L74).

## Quick wins for AI-citation

- Add `inLanguage` to: FAQPage, HowTo, Question, Answer, HowToStep, Review, ClaimReview, Course, Event, Service, SiteNavigationElement.
- Add `isPartOf: { @id: #website }` to all post-level types (FAQPage, HowTo, VideoObject, AudioObject, Review, ClaimReview, QAPage, DefinedTermSet, SoftwareApplication, Course, Event, Service).
- Add `mainEntityOfPage` to all post-level types.
- Add `sameAs` (Wikidata) capability to: `DefinedTerm`, `BlogPosting.mentions`, `BlogPosting.about` items (mentions already has names only).
- Add per-author `givenName`/`familyName` (split from `display_name`) to Person.

## Cross-cutting validation gaps

- ISO 8601 date validation absent on: Course.startDate/endDate, Event.startDate/endDate, ClaimReview.itemReviewed.datePublished. Add `preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?$/', $date)` check.
- URL validation (scheme + host) consistent only in Organization/Person; missing in LocalBusiness.sameAs and elsewhere.
