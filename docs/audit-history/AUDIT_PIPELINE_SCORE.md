# Ligase — Audit: Entity Detection Pipeline + AI Search Readiness Score

**Plugin version:** 2.0.0
**Audit date:** 2026-05-28
**Auditor scope:** entity detection pipeline (Levels 1–4) and the 0–100 AI Search Readiness Score.
**Files audited:**

- `includes/entities/class-pipeline.php`
- `includes/entities/class-extractor-native.php`
- `includes/entities/class-extractor-structure.php`
- `includes/entities/class-extractor-ner.php`
- `includes/entities/class-wikidata-lookup.php`
- `includes/class-ner-api.php`
- `includes/class-score.php`
- `tests/unit/NERTest.php`, `tests/unit/ScoreTest.php`
- supporting: `includes/class-plugin.php`, `includes/class-ajax.php`, `tests/bootstrap.php`

> Citations link to lines in this repo using paths relative to `C:\Users\marci\audits\ligase`.

---

## TL;DR

The "4-level pipeline" is real and reasonably wired, but it is mostly a marketing wrapper around two
genuinely different engines: a regex-only heuristic NER ("Level 3" in the README) and an LLM/API
NER (`Ligase_NER_API`) that exists outside the pipeline. **The pipeline never calls the LLM NER**,
and the LLM NER doesn't feed the schema hints — that's a substantial architectural gap.

The 0–100 score is **vanity-leaning**: it measures plugin configuration (10 checks) plus per-post WP
data presence, but **none of its checks actually use the entity pipeline output**, **none touch
Wikidata coverage of the article's mentioned entities**, and **none validate the JSON-LD that is
emitted**. It will read 100 for a site that emits broken schema, has zero Wikidata-linked
entities in `about`/`mentions`, and never resolved a single NER hit.

The test suite is broken at the API contract level: `NERTest` calls `extract($content)` but the
production class only exposes `extract_from_post(int $post_id)`; `ScoreTest` calls
`calculate_site_score()` / `calculate_post_score()` / `calculate_author_score()` but the production
class exposes `calculate()` / `calculate_for_post()` / `calculate_for_author()`. **Every test will
throw a fatal `BadMethodCallException` the moment it runs.**

Wikidata lookup is hardcoded to Polish (`'pl'`) without fallback to `en`, which would seem ideal for
this user (Polish SEO SaaS) — except that any Polish entity that doesn't have a `pl` sitelink will
silently return zero results and get cached as "no match" for 4 weeks.

---

## File-by-file findings

### `includes/entities/class-pipeline.php`

**Purpose:** Orchestrates Levels 1–4 and maps results into Schema.org hints (`keywords`, `about`,
`articleSection`, FAQ/HowTo/VideoObject suggestions, `_auto_sameas`).

#### Findings

- **Mode flag is never enforced for the LLM NER.** Pipeline accepts `'standard' | 'deep' |
  'wikidata'` ([class-pipeline.php:10](includes/entities/class-pipeline.php#L10)), runs Level 3
  (regex NER) only in `deep`/`wikidata` mode
  ([class-pipeline.php:20-22](includes/entities/class-pipeline.php#L20)), but **never calls the
  LLM-based `Ligase_NER_API` extractor** that lives in `includes/class-ner-api.php`. The pipeline's
  "deep mode" therefore returns regex hits, not LLM hits, contradicting the README's promise that
  Level 3 = "NER (persons, organizations, products) ~20ms". A real LLM call is 500–3000ms; the
  ~20ms figure is realistic *only* for the regex extractor, which has very different precision.
- **Level N does not consume Level N-1 output.** Each level extracts independently; there is no
  cross-level deduplication. If "Microsoft" appears as a tag (Level 1) and the regex Level 3
  detects it, both copies survive into `_ner_entities` and `keywords` without merging. The
  pipeline's only cross-level interaction is "schedule Wikidata for NER persons/orgs/products with
  frequency ≥ 2 that aren't already in `_wikidata_suggestions`"
  ([class-pipeline.php:95-110](includes/entities/class-pipeline.php#L95)).
- **No confidence scoring at the hint level.** The pipeline trusts everything that comes out of any
  level. A regex false-positive person ("Bardzo Ważny" — two capitalized stop-ish words) becomes a
  scheduled Wikidata lookup if it appears twice.
- **`_auto_sameas` is dangerous.** Logic at
  [class-pipeline.php:78-91](includes/entities/class-pipeline.php#L78) auto-applies a Wikidata
  match when `count($matches) === 1`. Wikidata's `wbsearchentities` returns the **top 5 by string
  match score**, not by uniqueness — it routinely returns 1 result for ambiguous or rare strings,
  and that result is often the wrong entity (e.g. searching "Kraków" with Polish UI returns the
  city, but searching a Polish journalist's name often returns a disambiguation page or a homonym).
  Auto-applying breaks `sameAs` accuracy with no human review.
- **Truncation silently used twice.** `$entities['ner']` is passed to
  `map_to_schema_hints()` and iterated three times (one of which is `array_keys`), with no defensive
  check that NER output is structurally what the pipeline expects (the regex extractor returns
  `['persons','organizations','places','products']`; the LLM API returns the same shape *plus*
  `'topics'`). If you swap them in via filter, `topics` is silently dropped.
- **`save_post` doesn't trigger re-analysis.** Pipeline is called lazily (presumably from the
  generator/AJAX), but I can find no hook that invalidates or recomputes pipeline output on
  `save_post`. `Ligase_Cache::invalidate_post()` runs
  ([class-plugin.php:89](includes/class-plugin.php#L89)) but it only nukes a generic post-level
  cache; the persisted `_ligase_wikidata_suggestions` post meta lives forever even when content
  changes wholesale.
- **`get_the_content( null, false, $post_id )` works** on WP 5.2+ where the 3rd argument accepts
  `int|WP_Post`, but the upstream Level 2 extractor uses the same signature
  ([class-extractor-structure.php:12](includes/entities/class-extractor-structure.php#L12)). Note
  that calling outside the loop without `setup_postdata` returns the raw `post_content` (which is
  what's wanted), but inside the loop it can return only the current page of a multi-page post.
  Not a bug for typical blog posts; worth a comment.

**Verdict: NEEDS WORK.** The pipeline composition is shallow — no dedup, no confidence merging, no
LLM integration, dangerous auto-apply. It works as a glorified facade over a regex extractor.

---

### `includes/entities/class-extractor-native.php` (Level 1)

**Purpose:** Pull entities from WordPress-native sources: tags, categories, author, featured image.

#### Findings

- **Solid for what it claims.** Reads tags
  ([class-extractor-native.php:20](includes/entities/class-extractor-native.php#L20)), categories
  ([class-extractor-native.php:32](includes/entities/class-extractor-native.php#L32)), author with
  custom `ligase_job_title` and `ligase_knows_about` meta
  ([class-extractor-native.php:43-55](includes/entities/class-extractor-native.php#L43)), and
  featured image dimensions
  ([class-extractor-native.php:57-68](includes/entities/class-extractor-native.php#L57)).
- **Sources not normalized.** "tag" and "category" are useful as Schema.org `keywords` and
  `articleSection` respectively, but there is no signal that these are higher-confidence than
  regex-extracted entities. The pipeline doesn't propagate confidence either, so this distinction
  vanishes downstream.
- **Author has no `sameAs`.** Returns `display_name`, `job_title`, `knows_about` — but **no LinkedIn
  / Twitter / Wikidata URLs**, which `Ligase_Score::calculate_for_author()` reads from
  `ligase_linkedin`, `ligase_twitter`, `ligase_wikidata` meta. So the score check and the entity
  extraction are looking at the same user but reading different meta keys' worth of context. The
  generator presumably reassembles them, but the *pipeline* output that downstream consumers see is
  incomplete.
- **Featured image only.** `image` is just the post thumbnail. Inline images in content (`<img>`
  with width/height attributes that could feed a richer `ImageObject` graph) are not collected
  here, nor in Level 2.
- **No filter hook.** No `apply_filters( 'ligase_native_entities', $result, $post_id )`, so users
  can't extend this level to read e.g. WooCommerce product attributes, ACF fields, or custom
  taxonomies.

**Verdict: GOOD** for the claimed scope; **NEEDS WORK** if you treat the README's "Level 1" as
authoritative input to the schema graph (it isn't on its own).

---

### `includes/entities/class-extractor-structure.php` (Level 2)

**Purpose:** Detect structural patterns in post content: YouTube embeds, Wikipedia links, FAQ-like
H2/H3, HowTo-style ordered lists, tables, external links, headings.

#### Findings

- **YouTube regex is OK but incomplete.** Pattern at
  [class-extractor-structure.php:25-28](includes/entities/class-extractor-structure.php#L25)
  handles `youtube.com/watch?v=`, `embed/`, and `youtu.be/`. It misses `youtube.com/shorts/`,
  `youtube-nocookie.com/embed/`, and lite-embed `wp-block-embed__wrapper` placeholders that some
  themes render without an iframe. Realistic Polish blog content (mostly oEmbed-rendered iframes)
  will mostly hit, but Shorts is increasingly common and explicitly missed.
- **Wikipedia link regex.** Looks fine
  ([class-extractor-structure.php:38-42](includes/entities/class-extractor-structure.php#L38)). It
  matches any wikipedia.org subdomain, so `pl.wikipedia.org`, `en.wikipedia.org`,
  `commons.wikimedia.org` (no — commons isn't `wikipedia.org`, that's correctly excluded). Note the
  `[\w.]*` host part allows weird things like `pl..wikipedia.org` but in practice the URLs come
  from user content so this is mostly fine. The `text` field is unsanitized aside from `wp_strip_all_tags`,
  but that's enough for our use.
- **FAQ detection is bizarre.** Looks for H2/H3 ending in `?`
  ([class-extractor-structure.php:63](includes/entities/class-extractor-structure.php#L63)). In
  Polish this works fine. But the bigger issue: it only triggers if `count() >= 2`. A single
  question heading won't be flagged — even though the FAQPage schema legitimately supports a
  one-question page (and `QAPage` is *built* for that, separately).
- **HowTo detection requires both an `<ol>` and a heading containing `jak|how to|krok|step|instrukcja|poradnik`.**
  ([class-extractor-structure.php:70-75](includes/entities/class-extractor-structure.php#L70)).
  Reasonable hybrid heuristic. But: the regex `/<ol[^>]*>.*?<li.*?<\/ol>/si` is non-greedy *at the
  start* but the `<\/ol>` greediness combined with `.*?<li.*?` (`<li` not closed) means it will
  match the first `<ol>...<li` pair through to the *last* `</ol>` on the page. Functionally OK for
  detection-true/false, but the regex is sloppy.
- **`extract_headings()` is unused by the pipeline.** It's collected but the pipeline never maps it
  to anything. Dead extraction work in the hot path.
- **`find_external_links()` is unused by the pipeline.** Same as above. Also returns
  `array_unique()` *without* `array_values()`, leaving non-contiguous keys — a footgun if any
  downstream code does positional access.
- **Performance: regex over full post HTML.** The README's ~5ms estimate is plausible for short
  posts but for a 5000-word post with ~30KB HTML, 7 regex passes (`preg_match_all` x 6 + one
  `preg_match`) will be closer to 15–30ms. Not catastrophic, just not "5ms".
- **Polish HowTo trigger word "instrukcja" / "poradnik" works** — credit where it's due, this is
  one of the few places EN+PL coexists correctly.

**Verdict: NEEDS WORK.** Two extracted fields (`headings`, `external_links`) are computed and
never used. FAQ requires ≥2 questions arbitrarily. Misses YouTube Shorts. Otherwise functional.

---

### `includes/entities/class-extractor-ner.php` (Level 3 — regex NER)

**Purpose:** "Pattern-based" NER. Detects persons (2–3 capitalized words), organizations (cap
words + suffix), places (cap word after locative preposition), products (CamelCase / trademark
symbols).

#### Findings

- **Method name mismatch with tests (CRITICAL).** Production method is `extract_from_post( int $post_id )`
  ([class-extractor-ner.php:87](includes/entities/class-extractor-ner.php#L87)). Tests in
  `NERTest.php` call `$this->subject->extract( $content )` with a **string content argument**
  ([NERTest.php:37](tests/unit/NERTest.php#L37), repeated in every test). **The tests cannot
  possibly pass** — every test will fatal with "Call to undefined method
  Ligase_Entity_Extractor_NER::extract()". This means either (a) the tests have never been run
  successfully, or (b) the method was renamed without updating tests. Either way, the unit test
  coverage you think you have is zero for this class.
- **Test return shape mismatch.** Tests filter results by `@type` or `type` field looking for
  `'Person'`, `'Organization'`, `'Place'`, `'Product'`
  ([NERTest.php:184](tests/unit/NERTest.php#L184)). Production code labels them lowercase:
  `'person'`, `'organization'`, `'place'`, `'product'`
  ([class-extractor-ner.php:147,177,197](includes/entities/class-extractor-ner.php#L147)). Even if
  the method name were fixed, the filtering wouldn't find anything. Also, production groups results
  *by category at the top level* (return value is `['persons' => [...], ...]`), whereas tests
  expect a flat list — *another* contract mismatch.
- **Polish person detection works on diacritics** thanks to `\p{Lu}\p{L}+`
  ([class-extractor-ner.php:143](includes/entities/class-extractor-ner.php#L143)) — Unicode-aware
  regex, good. But:
  - **It also matches sentence openings.** "Konferencja odbyła się w Krakowie. Później dołączył"
    — "Później Dołączył" wouldn't match (since "później" is lowercase), but "W Warszawie Konferencja"
    would match "Warszawie Konferencja" as a "person" because both start with capitals. The stop
    word filter at [class-extractor-ner.php:337-369](includes/entities/class-extractor-ner.php#L337)
    only filters *exact* stop words, not phrases where a real noun is concatenated with a sentence-
    opener noun.
  - **Polish inflection not handled.** "Jan Kowalski", "Jana Kowalskiego", "Janowi Kowalskiemu",
    "Janem Kowalskim" are all the same person in Polish but are detected as four distinct entities,
    each with frequency 1. Dedup happens via `mb_strtolower` only
    ([class-extractor-ner.php:319](includes/entities/class-extractor-ner.php#L319)). For a Polish
    SEO SaaS this is a major precision problem.
- **Place detection over-matches.** Pattern at
  [class-extractor-ner.php:193](includes/entities/class-extractor-ner.php#L193) treats anything
  after a Polish/English preposition as a place. "z Microsoft Inc" → "Microsoft Inc" tagged as
  place. The dedup filter at line 114-121 strips entities also present in organizations, but only
  if the lowercase form matches *exactly*; "Microsoft Inc" (place candidate after `z`) vs.
  "Firma Microsoft" (org candidate with `Inc` suffix detected from elsewhere) won't dedup if the
  surface forms differ.
- **English preposition `to` is included for places** — `to` is also the Polish *demonstrative
  pronoun* ("To jest dobre"). Every sentence starting with "To" followed by a capitalized word
  will yield a false-positive place: `to Jana` → "Jana" tagged as place. Tests don't catch this
  because the tests don't run.
- **Products detection: three overlapping patterns.** Trademark, mixed-case, CamelCase
  ([class-extractor-ner.php:215-267](includes/entities/class-extractor-ner.php#L215)). Mixed-case
  pattern `(\p{L}+\p{Ll}\p{Lu}\p{L}*)` matches "iPhone", "WordPress", "YouTube" — good. But also
  matches "iPad" and "macOS" and (more troublingly) words like "wPolsce" which a typo or HTML
  artifact can produce. The third "brand pattern" duplicates the second on common cases; they
  both feed into the same dedup. Net effect: more work for the same output.
- **Stop word list contains duplicates.** "bez" twice ([line 32](includes/entities/class-extractor-ner.php#L32)),
  "jednak" twice (lines 34 & 35), "tak" twice (lines 41 & 42). Cosmetic; doesn't affect
  correctness (it's flipped into a hash).
- **No author/post context.** The NER works on raw post content only — it doesn't get a hint that
  "Jan Kowalski" is the post author. So the author themselves will appear in `_ner_entities.persons`
  and get scheduled for Wikidata lookup. Wasteful.
- **50KB truncation is silent for the user.** `mb_substr( $content, 0, 50000 )`
  ([class-extractor-ner.php:97-101](includes/entities/class-extractor-ner.php#L97)). For a Polish
  longform article (12–18k words = ~80–110KB UTF-8), the second half is dropped from NER. There's
  a logger warning but no admin notice.
- **`looks_like_organization()` does a `str_ends_with` check** against all 24 suffixes per
  candidate ([class-extractor-ner.php:376-384](includes/entities/class-extractor-ner.php#L376)).
  Per person candidate. On a long post with 100+ candidates this is ~2400 string comparisons.
  Minor, but it's the kind of thing that turns "20ms" into "60ms".

**Verdict: NEEDS WORK.** Polish inflection blindness + sentence-start over-matching + `to` false
positives + nonexistent test coverage. Functional for English-leaning content, problematic for
the user's actual use case (Polish SEO).

---

### `includes/entities/class-wikidata-lookup.php` (Level 4 async)

**Purpose:** Async lookup of entity names against Wikidata `wbsearchentities` API. Caches results
in transients, persists to post meta.

#### Findings

- **Hardcoded Polish, no English fallback.** `search( string $name, string $language = 'pl' )`
  ([class-wikidata-lookup.php:11](includes/entities/class-wikidata-lookup.php#L11)). For a Polish
  SaaS this seems right, *but* (a) `wbsearchentities` with `language=pl` only matches Polish
  labels and aliases on the Wikidata item — many tech-product entities (e.g. "Cloudflare", "Stripe")
  only have an English label. They will return zero results in `pl` mode. (b) The result is then
  cached as empty for 4 weeks ([line 61](includes/entities/class-wikidata-lookup.php#L61)). So
  English-only entities are silently un-resolvable for a month.
- **Cache key doesn't include `$language`** in the key obviously (it does — `md5( $name . $language )`,
  [line 17](includes/entities/class-wikidata-lookup.php#L17)). OK, fine.
- **Negative cache TTL: empty result cached 4 weeks; WP_Error cached 5 min.** Asymmetric. Probably
  intended (Wikidata is unlikely to add a brand new label for "Microsoft" in 4 weeks, but a 503
  could clear in 5 min). The 5-min negative cache for transport errors is fine; 4 weeks for "no
  match" is too long given how often these names are partial/typo'd by the regex NER.
- **`schedule()` uses `wp_schedule_single_event` with 20-entity cap and a 5-second delay**
  ([class-wikidata-lookup.php:99-108](includes/entities/class-wikidata-lookup.php#L99)).
  Reasonable, but:
  - **No `wp_next_scheduled` deduplication.** If you save a post 5 times in a minute, you queue 5
    cron jobs to do the same lookup. The LLM NER side (`Ligase_NER_API::schedule()`) does check
    `wp_next_scheduled` ([class-ner-api.php:99](includes/class-ner-api.php#L99)) — inconsistent.
  - **No rate limiting between API calls.** The cron handler loops `foreach ( $entity_names as
    $name )` and synchronously hits Wikidata for each, up to 20 in a row
    ([class-wikidata-lookup.php:80-85](includes/entities/class-wikidata-lookup.php#L80)). 20 ×
    3-second timeouts = 60-second worst case, blocking the cron thread. Wikidata's free tier
    accepts roughly 5 req/sec from one IP — fine in practice, but no defensive backoff.
- **Race condition: post meta overwrite.** `run_lookup()` does
  `update_post_meta( $post_id, '_ligase_wikidata_suggestions', $results )`
  ([class-wikidata-lookup.php:88](includes/entities/class-wikidata-lookup.php#L88)). If two
  pipeline analyses run concurrently (e.g. one from save_post, one from a manual AJAX scan), the
  second cron's `$results` clobbers the first — losing any earlier resolutions that were *not*
  re-requested in the second batch.
- **No error caching for JSON decode failures.** When `json_decode` fails
  ([class-wikidata-lookup.php:49-57](includes/entities/class-wikidata-lookup.php#L49)), the
  function returns `null` without setting a negative-cache transient. Next request immediately
  hits the API again. The WP_Error branch does cache; the JSON-decode branch does not.
- **No User-Agent compliance with WMF policy.** UA is `Ligase-WordPress-Plugin/1.0 (schema markup)`
  ([class-wikidata-lookup.php:32](includes/entities/class-wikidata-lookup.php#L32)). WMF requires a
  contact URL or email in the UA string. Currently this won't get the plugin blocked, but it's
  technically non-compliant with [meta:User-Agent_policy](https://meta.wikimedia.org/wiki/User-Agent_policy).
- **Uses `wbsearchentities`, not SPARQL.** Reasonable choice for fuzzy name → Q-ID lookup.
  `wbsearchentities` is fast and gives label+description+id. For disambiguation (e.g. "Apple" the
  company vs. the fruit), the API returns multiple matches and the pipeline currently *auto-applies*
  only when there's exactly 1. So the "Apple" case won't auto-apply (you'll get 5 results); but
  "Stripe" might return 1 result that is the wrong Stripe.
- **No language fallback chain.** Should attempt `pl → en → label-agnostic` (the API supports
  `uselang=en` separately from `language`). Currently single-language lookup, no retry.
- **`description` is captured but the auto-apply path drops it.** The mapped result includes
  `description` ([line 68](includes/entities/class-wikidata-lookup.php#L68)) but the pipeline
  `_auto_sameas` only stores `wikidata_id`, `wikidata_url`, `label`
  ([class-pipeline.php:83-87](includes/entities/class-pipeline.php#L83)). A description like
  "American film actor" vs. "Polish journalist" is exactly the disambiguation signal needed before
  auto-applying.
- **Filter cron payload for valid post_id?** `run_lookup( int $post_id, array $entity_names )`
  trusts the cron's post_id. WP cron args are persisted in the DB and not user-influenceable in
  practice, but a defensive `get_post( $post_id )` check at the top would prevent silently writing
  meta for deleted posts.

**Verdict: NEEDS WORK.** Hardcoded Polish + 4-week negative cache + no dedup of scheduled lookups
+ race-prone update_post_meta + non-compliant UA. The core API call is right; the orchestration
around it is half-baked.

---

### `includes/class-ner-api.php` (LLM-based NER — *not part of pipeline*)

**Purpose:** Send post content to OpenAI / Anthropic / Google NLP / Dandelion and parse a
structured entity response.

#### Findings

- **Not wired into `Ligase_Entity_Pipeline`.** This is the "Level 3 with real AI" the README
  implies, but `class-pipeline.php` never instantiates it. It's only called from AJAX endpoints
  (`ligase_ner_run_post`, `ligase_ner_run_bulk` —
  [class-ajax.php:951,991](includes/class-ajax.php#L951)) and via its own cron hook
  (`ligase_ner_api_extract`, [class-plugin.php:100](includes/class-plugin.php#L100)). Result is
  stored in post meta `_ligase_ner_api_results` and never read back by the pipeline. The pipeline
  uses the regex extractor for `$entities['ner']`. So the README's "Level 3 NER" presents two
  fundamentally different engines as one — false unification.
- **Cache key uses `post_modified`** ([class-ner-api.php:75](includes/class-ner-api.php#L75)).
  Good — guarantees re-extraction when content changes. But `post_modified` updates on metadata-
  only changes (e.g. setting categories), causing unnecessary re-extraction on cheap edits.
- **Bulk scan staggering is fragile.** `$delay += 10` per post
  ([class-ner-api.php:145](includes/class-ner-api.php#L145)). For 1,000 posts that's 10,000s ≈ 2.8h
  of scheduled cron events, all keyed to `time() + delay`. WP-Cron only fires on page loads (unless
  real cron is set up), so on a low-traffic site this gets compressed when a visitor finally
  triggers a cron run.
- **`schedule_bulk` never sets `ligase_ner_bulk_done`.** `get_bulk_status()`
  ([class-ner-api.php:157-167](includes/class-ner-api.php#L157)) reads
  `ligase_ner_bulk_done` from options, but the only place the option gets *reset* is
  `handle_ligase_ner_run_bulk` ([class-ajax.php:1010](includes/class-ajax.php#L1010)). The
  *increment* of "done" doesn't happen anywhere in this file. The async cron path
  (`run_scheduled`) does an extract and stores results in post meta but never increments
  `ligase_ner_bulk_done`. So the admin bulk status will always show 0% done — even after every
  scheduled post is processed. **Broken progress reporting.**
- **No locking for concurrent bulk runs.** Two admins click "Bulk NER" — second call overwrites
  the counter to 0, both fight over the same scheduled queue.
- **Anthropic model is `claude-haiku-4-5-20251001`** ([class-ner-api.php:239](includes/class-ner-api.php#L239)).
  Cost estimate is "$0.0006 / post" ([line 178](includes/class-ner-api.php#L178)). Haiku 4.5 pricing
  is roughly 0.6× Sonnet but actual cost per post depends on token count — with 3000 words ≈ 4000
  tokens in + 800 max out, that's about $0.005 at Haiku 4.5 rates as of late 2025. The 0.0006
  estimate is likely 10× low. (For OpenAI gpt-4o-mini: $0.0004 estimate, real ≈ $0.0009.)
- **Anthropic call lacks `system` parameter and uses user-message-only.** Workable but a system
  prompt with the JSON contract would improve adherence. Also no `cache_control` block — every
  call resends the (long) instruction prompt at full price. Easy win for a CMS that processes
  hundreds of posts in bulk.
- **`response_format: { "type": "json_object" }`** for OpenAI is good
  ([class-ner-api.php:219](includes/class-ner-api.php#L219)). Anthropic call doesn't enforce JSON
  ([line 240-244](includes/class-ner-api.php#L240)) — relies on prompt discipline. Should use
  `tool_use` or a "prefill" `"{"` assistant turn.
- **Google NLP confidence threshold is 0.05** ([class-ner-api.php:274](includes/class-ner-api.php#L274))
  — Google's `salience` is *relative importance in the document*, not a confidence; a primary
  entity in a 200-word post can be 0.5+, while a primary entity in a 5000-word post might be 0.08.
  A flat 0.05 floor is OK as noise filter but mislabels what it represents.
- **Dandelion `min_confidence=0.7` in the request** but the response is not filtered server-side
  beyond that — code at [line 308](includes/class-ner-api.php#L308) just passes through whatever
  the API returns.
- **Token security: API key stored in WP options without encryption.**
  `$opts['ner_api_key']` ([class-ner-api.php:45](includes/class-ner-api.php#L45)) is plain text in
  `wp_options`. Standard WP practice, but worth a note for users on shared hosting.
- **Prompt is single-language English** ([class-ner-api.php:350-364](includes/class-ner-api.php#L350)).
  Says "extract base form for inflected languages" but in Polish "base form" is ambiguous — for a
  person it's nominative singular, for a place it's nominative singular without preposition,
  for a verb it's infinitive. The LLM will mostly DTRT, but no Polish examples in the prompt means
  the model gets less help than it could.
- **Parse error path doesn't log raw response.** Truncates to 200 chars
  ([class-ner-api.php:402](includes/class-ner-api.php#L402)) — fine for log hygiene, painful for
  debugging.

**Verdict: NEEDS WORK.** Disconnected from pipeline; broken progress counter; cost estimates
optimistic; no prompt caching; no Polish prompt examples. Functional in isolation, marketing-
misleading in aggregate.

---

### `includes/class-score.php` (0–100 AI Search Readiness Score)

**Purpose:** Compute three scores — site (10 checks, max 100), post (12 checks, max 105 — yes,
that's wrong), author (9 checks, max 100).

#### Findings — formula correctness

- **Site checks total exactly 100** = 15 + 15 + 10 + 15 + 10 + 10 + 10 + 5 + 5 + 5 = 100. ✓
- **Post checks total 105**, not 100.
  - headline 15 + datePublished 10 + dateModified 10 + image 15 + author 10 + publisher 10 +
    breadcrumb 5 + description 5 + keywords 5 + articleSection 5 + wordCount 5 + inLanguage 5 =
    **100**. Recount: 15+10+10+15+10+10+5+5+5+5+5+5 = **100**. ✓ I miscounted on first pass.
  - Score is `max(0, min(100, $total))` ([class-score.php:384](includes/class-score.php#L384)). OK.
- **Author checks total exactly 100** = 10 + 15 + 10 + 15 + 10 + 5 + 20 + 10 + 5 = 100. ✓

So weights add up cleanly. Now the bigger question: **do they measure what matters for AI citation?**

#### Findings — does it measure citation readiness?

- **No check ever looks at entity pipeline output.** None of the 10 site checks, 12 post checks,
  or 9 author checks reads `_ligase_wikidata_suggestions`, `_ligase_ner_api_results`,
  `_ligase_about_entities`, or `_ligase_mentions`. A post with zero detected entities, zero
  Wikidata sameAs in `about`, and zero LLM extractions can score 100/100. **The score is
  decoupled from the pipeline.**
- **`check_sameas_wikidata`** (15 pts, site level) just checks if the *organization* sameAs list
  contains a wikidata.org URL ([class-score.php:619-625](includes/class-score.php#L619)). It does
  not check whether *any post's* `about` array has a Wikidata sameAs. A site with 500 posts and
  zero Wikidata-linked entities scores 15/15 here.
- **`check_images_1200`** (15 pts) breaks the score honesty: it scans **50 most recent posts** and
  the moment one of them fails, returns 0 ([class-score.php:661-678](includes/class-score.php#L661)).
  Pass/fail is binary. A site with 49 perfect 1500px images and one legacy 800px image scores 0/15.
  A site with 50 perfect 1500px images and 5,000 800px images (older than the 50-post window)
  scores 15/15. So this check is **non-monotonic and biased toward recent activity** — exactly
  the wrong incentive for a long-tail blog.
- **`check_date_modified_current`** (10 pts) similar — scans 20 posts and returns 0 if *any* is
  older than 1 year ([class-score.php:695-718](includes/class-score.php#L695)). For an evergreen
  niche, this is actively wrong: dateModified updates without real edits are a known Google
  anti-pattern, and this check punishes legit evergreen content.
- **`check_org_logo`** just checks the option exists, not whether it's a valid URL or whether the
  image is ≥112×112 (Google's minimum). 10 free points for typing anything in a field.
- **`check_breadcrumbs`** and **`check_search_action`** are option-toggle checks (5+5 pts) — they
  award points for *plugin configuration*, not for actual breadcrumb correctness.
- **Post score: `check_post_word_count > 300`** — 300 is a Yoast-era heuristic that has nothing to
  do with AI citation. Long, citation-worthy answers are often 100–300 words. This penalizes
  good answer-engine content.
- **Post score: `check_post_description` rewards `post_excerpt` not empty** — doesn't check that
  the description is unique per post, that it's ≤155 chars, or that it isn't auto-generated. Just
  "non-empty".
- **Post score: `check_post_publisher_id`** "publisher exists" check passes if the option *or*
  `get_bloginfo('name')` is non-empty. `get_bloginfo('name')` is virtually always non-empty.
  **Free 10 points.** ([class-score.php:266](includes/class-score.php#L266))
- **Post score: `check_post_in_language`** "locale exists" — `get_locale()` is *always* non-empty
  in WordPress. **Free 5 points, guaranteed.** ([class-score.php:367](includes/class-score.php#L367))
- **Post score: `check_post_author_id`** passes if author has a display_name — always true unless
  you explicitly broke your user table. **Effectively free 10 points.**
- **Post score: `check_post_article_section`** passes if any category. WP assigns "Uncategorized"
  by default. **Effectively free 5 points.**
- **Net: a default WordPress post with a featured image ≥1200px and a non-empty excerpt scores
  ~95/100** without doing *anything* the README claims this plugin is about. The Schema Auditor,
  the entity pipeline, the Wikidata linking, the LLM NER — none of it influences the post score.
- **Author score is more honest.** Reads custom meta (`ligase_job_title`, `ligase_knows_about`,
  `ligase_linkedin`, `ligase_twitter`, `ligase_wikidata`). The 20-point Wikidata check is the
  single most-weighted item in the entire score system, and it's measuring something real (author
  Q-ID linkage is a strong E-E-A-T signal). However:
  - **`check_author_avatar` accepts any Gravatar default** unless the URL contains the literal
    string `gravatar.com/avatar/?d=` ([class-score.php:542](includes/class-score.php#L542)). The
    actual Gravatar fallback URL pattern is
    `https://secure.gravatar.com/avatar/HASH?d=mm&r=g&s=96`. The check will pass for the default
    "mystery person" avatar.
- **No penalties anywhere.** Score is purely additive. There's no penalty for *bad* signals
  (e.g. organization name = "John Doe Photography" while WordPress site is registered as "Brajn
  SEO" — that's a real conflict for AI grounding).
- **Caching is wrong for site score on save_post.** `delete_transient( 'ligase_site_score' )`
  fires on save_post ([class-plugin.php:90](includes/class-plugin.php#L90)) — good. But site score
  depends on the *50 most recent posts'* image dimensions, which can change when an *image* is
  resized or replaced. There's no `add_action( 'attachment_updated', ... )` invalidation. Score
  goes stale until a post is saved.
- **`get_sample_posts()` static cache is per-request** ([class-score.php:864-877](includes/class-score.php#L864))
  — fine for a single AJAX call, but the `$cache` array is keyed by `$limit`, so two checks
  requesting different limits do two queries. Both checks currently use 50 and 20, so two queries
  per score calculation. Minor.

**Verdict: NEEDS WORK** for site score; **CRITICAL** for the implicit promise that this measures
AI citation readiness. A 95/100 score is achievable without any AI-citation work.

---

### `tests/unit/NERTest.php`

- **Cannot run as-is.** Calls `extract($content)` ([NERTest.php:37,54,70,88,104,124,146,164](tests/unit/NERTest.php#L37));
  the production class only has `extract_from_post(int)`. **Every test fatals.**
- **Filters by `@type === 'Person'` (capitalized)** ([NERTest.php:184](tests/unit/NERTest.php#L184));
  production returns `'type' => 'person'` (lowercase). The `@type` key is never set in the
  extractor output.
- **Expects flat list of entities**; production returns
  `['persons'=>[...], 'organizations'=>[...], ...]`.
- **No mock for `get_post()`** — even if the method name were fixed, `Ligase_Entity_Extractor_NER`
  calls `get_post( $post_id )` ([class-extractor-ner.php:88](includes/entities/class-extractor-ner.php#L88))
  and the bootstrap never defines a `get_post` stub.

**Verdict: CRITICAL — tests do not test the production code.**

---

### `tests/unit/ScoreTest.php`

- **Cannot run as-is.** Calls `calculate_site_score(array $data)` ([ScoreTest.php:94](tests/unit/ScoreTest.php#L94));
  production has `calculate(): array` (no args, reads `ligase_options`). Calls
  `calculate_post_score($data)` ([ScoreTest.php:131](tests/unit/ScoreTest.php#L131)); production
  has `calculate_for_post(int $post_id)`. Calls `calculate_author_score($data)`
  ([ScoreTest.php:176](tests/unit/ScoreTest.php#L176)); production has
  `calculate_for_author(int $user_id)`. **Every test fatals.**
- **`test_empty_site_score_returns_0`** — even if the method existed, the production scorer
  *always* awards 5 points for `inLanguage` (locale is always set) and other "always-true" checks.
  Empty options would yield ~5–15 baseline, not 0.
- **`test_perfect_site_score_returns_100`** — passes a flat data array
  (`site_name`, `social_profiles`, etc.). Production scoring reads from `wp_options`
  (`organization_same_as`, `organization_logo`, `use_graph`, etc.) and from WP users/posts. The
  data shape in the test doesn't match anything the production code reads.

**Verdict: CRITICAL — tests test a version of the API that doesn't exist in production.** Either
these tests are aspirational / written for a planned-but-not-implemented refactor, or the
production code was rewritten and the tests were never updated. Either way, CI signal here is
zero (or worse — green CI when no tests are actually wired up).

---

## Score formula evaluation — honest or vanity?

**Verdict: leaning vanity, with one honest sub-score (author/E-E-A-T).**

| Layer | Honest signal | Vanity signal | Notes |
|---|---|---|---|
| Site (100 pts) | 15 (sameAs Wikidata for Org) + 15 (@graph linking, if enabled) = **30** | 70 | Image/dateModified checks are non-monotonic; logo/breadcrumb/search checks are option toggles |
| Post (100 pts) | 15 (image ≥1200px) + 10 (dateModified ISO8601) = **25** | 75 | author/publisher/articleSection/inLanguage are guaranteed-pass on a default WP install |
| Author (100 pts) | 20 (Wikidata sameAs) + 15 (bio) + 15 (knowsAbout) + 10 (LinkedIn) + 10 (jobTitle) = **70** | 30 | Strongest of the three; avatar check has a false-positive on Gravatar default |

**What the score should measure but doesn't:**

1. **Entity coverage on emitted JSON-LD.** Count of `about` / `mentions` entries with `sameAs` to
   Wikidata, per post.
2. **`@id` linkage density.** Does every entity in the @graph reference back via `@id`? Plugin
   has the option (`use_graph`) but doesn't validate the output.
3. **Wikidata-resolved entity ratio.** Of NER-detected entities, how many resolved to a Q-ID?
   This is *the* AI-citation signal.
4. **Schema validity.** Does the emitted JSON-LD pass Schema.org validation? `Ligase_Validator`
   exists ([includes/class-validator.php](includes/class-validator.php)) but isn't used by the
   score.
5. **Author-org link presence.** Score has `check_person_org_link` site-side but doesn't propagate
   to post-level (BlogPosting → Author → Organization @id chain).
6. **Image dimensions for **inline** images**, not just featured.
7. **Description quality:** length 50–155 chars, uniqueness, not just non-empty.

**The two checks I'd label outright misleading:**

- `check_post_in_language` — guaranteed 5 free points.
- `check_post_publisher_id` — guaranteed 10 free points if you have either an option set or just a
  default `get_bloginfo('name')` (which is always non-empty).

Combined with the equally-free author display_name and articleSection checks, **20 points out of
100 on the post score are awarded for the act of having installed WordPress.**

---

## Pipeline architecture diagram (actual data flow, not the README claim)

```
                    ┌──────────────────────────────────────────────────────────┐
                    │                  Ligase_Entity_Pipeline                  │
                    │                       ::analyze()                        │
                    └──────────────────────────────────────────────────────────┘
                                              │
        ┌─────────────────────────────────────┼─────────────────────────────────────┐
        │                                     │                                     │
        ▼                                     ▼                                     ▼
┌───────────────┐                  ┌────────────────────┐                ┌─────────────────────┐
│   LEVEL 1     │                  │      LEVEL 2       │                │   LEVEL 3 (deep+)   │
│ Native (WP)   │                  │   Structural       │                │   REGEX NER         │
│  - tags       │                  │  - YouTube IDs     │                │  - persons (cap     │
│  - categories │                  │  - Wikipedia links │                │    words pattern)   │
│  - author     │                  │  - FAQ pattern     │                │  - orgs (suffix)    │
│  - featured   │                  │  - HowTo pattern   │                │  - places (after    │
│    image      │                  │  - headings (X)    │                │    preposition)     │
│               │                  │  - ext links (X)   │                │  - products         │
└───────┬───────┘                  └─────────┬──────────┘                └──────────┬──────────┘
        │                                    │                                      │
        │                                    │  (X) = computed but never used       │
        │                                    │                                      │
        ▼                                    ▼                                      ▼
┌─────────────────────────────────────────────────────────────────────────────────────────────┐
│                              map_to_schema_hints()                                          │
│                                                                                             │
│  keywords ← Level 1 tags                                                                    │
│  articleSection ← Level 1 first category                                                    │
│  about[] ← Level 2 Wikipedia links (already-formed sameAs)                                   │
│  _suggest_video ← Level 2 first YouTube ID                                                  │
│  _suggest_faq, _suggest_howto ← Level 2 block detection                                     │
│  _ner_entities ← Level 3 raw output                                                         │
│  _wikidata ← post meta from previous Level 4 run                                            │
│  _auto_sameas ← any Wikidata match with count===1   ◄── unsafe heuristic                    │
└────────────────────────────────────────────┬────────────────────────────────────────────────┘
                                             │
                                             │  schedule Level 4 for NER entities
                                             │  with frequency ≥ 2 and no existing
                                             │  Wikidata match
                                             ▼
                            ┌────────────────────────────────────┐
                            │   wp_schedule_single_event(+5s)    │
                            │   ligase_wikidata_lookup           │
                            └─────────────────┬──────────────────┘
                                              │
                                              ▼
                            ┌────────────────────────────────────┐
                            │   Ligase_Wikidata_Lookup           │
                            │     ::run_lookup() (cron)          │
                            │  - 20 names max                    │
                            │  - lang='pl' hardcoded             │
                            │  - 3s timeout per call             │
                            │  - 4-week positive cache           │
                            │  - 4-week negative cache           │
                            │  - 5-min WP_Error negative cache   │
                            │  - no JSON-decode negative cache   │
                            └─────────────────┬──────────────────┘
                                              │
                                              ▼
                            update_post_meta(
                                _ligase_wikidata_suggestions  ◄── overwrite, race-prone
                            )

                  ┌──────────────────────────────────────────────────────┐
                  │                  PARALLEL / DISCONNECTED              │
                  │                                                       │
                  │   AJAX: ligase_ner_run_post          ┌──────────────┐ │
                  │   ───────────────────────────────► │ Ligase_NER_API │ │
                  │                                    │   (LLM-based)  │ │
                  │   Cron: ligase_ner_api_extract     │  - OpenAI      │ │
                  │   ───────────────────────────────► │  - Anthropic   │ │
                  │                                    │  - Google NLP  │ │
                  │                                    │  - Dandelion   │ │
                  │                                    └───────┬────────┘ │
                  │                                            ▼          │
                  │                              _ligase_ner_api_results  │
                  │                              (post meta — never read  │
                  │                               by the pipeline)        │
                  └──────────────────────────────────────────────────────┘

                  ┌──────────────────────────────────────────────────────┐
                  │                       SCORING                         │
                  │                  Ligase_Score::calculate*             │
                  │                                                       │
                  │   ✗ Does not read pipeline output                     │
                  │   ✗ Does not read _ligase_wikidata_suggestions        │
                  │   ✗ Does not read _ligase_ner_api_results             │
                  │   ✗ Does not read _ligase_about_entities              │
                  │   ✗ Does not read _ligase_mentions                    │
                  │   ✗ Does not validate emitted JSON-LD                 │
                  │                                                       │
                  │   ✓ Reads wp_options (ligase_options)                 │
                  │   ✓ Reads user_meta (custom ligase_* keys)            │
                  │   ✓ Reads post thumbnail dimensions                   │
                  │   ✓ Reads tags / categories / excerpt                 │
                  └──────────────────────────────────────────────────────┘
```

**Key implications:**

1. **"4-level pipeline" is functionally a 2-level pipeline (native + structural) with a regex NER
   bolt-on and an async Wikidata lookup.** The LLM NER lives in its own subsystem.
2. **The scoring system is a parallel universe.** It scores the *configuration* of the plugin and
   the *raw WP fields* of posts and authors — not the *output* of the pipeline.

---

## Top 5 things to fix before next release

### 1. Fix the test suite (CRITICAL)

Tests in `NERTest.php` and `ScoreTest.php` call API methods that don't exist in production. Either
rename the production methods to match the tests' expectations, or update the tests. **Without
this, you have no regression safety net for the two most marketing-critical systems in the
plugin.** Suggested fix: align on the test method names (`extract`, `calculate_site_score`,
`calculate_post_score`, `calculate_author_score`) because they are more idiomatic, then add thin
adapters for the existing internal callers.

### 2. Tie the score to entity pipeline output (HIGH)

Today's score answers "did the admin fill in the Ligase settings?" — not "is this site
AI-citation-ready?" Add at least these checks:

- Per-post: count of `about[]` entries with `sameAs` containing `wikidata.org` (5–10 pts).
- Per-post: ratio of NER-detected entities resolved to Wikidata (5–10 pts).
- Site: % of posts that have at least 1 `about` entity (5 pts).
- Site: emitted JSON-LD validates against `Ligase_Validator` (10 pts) — replace the option-toggle
  vanity checks (`enable_breadcrumb`, `enable_search_action`) which currently give free points for
  ticking boxes.

Drop or de-weight the guaranteed-pass checks (`inLanguage`, `publisher_id`, `articleSection`,
`author_id`) — they add noise (20 free post-score points) without measuring anything.

### 3. Fix Wikidata lookup language strategy + auto-apply safety (HIGH)

- Try `language=pl`, fall back to `language=en` if zero results, fall back to `wbsearchentities`
  with `uselang=mul` for label-agnostic match.
- Shorten the empty-result negative cache from 4 weeks to 24 hours (the NER hits are noisy enough
  that a typo-y "no match" shouldn't persist for a month).
- Stop auto-applying single-match Wikidata results without a confidence signal. Either (a) require
  the entity to have appeared with frequency ≥ 3, (b) require Wikidata's `match` score to exceed a
  threshold, or (c) require a description that includes one of the post's category names. Right
  now `_auto_sameas` will happily link "Apple" to whatever the top single match is for a Polish
  query, and "Stripe" to the wrong Stripe.
- Add a contact email/URL to the User-Agent string per WMF policy.
- Cache JSON-decode failures with the same 5-minute TTL as WP_Error responses.
- Defensively check `wp_next_scheduled` in `schedule()` to prevent duplicate cron jobs.

### 4. Decide what "Level 3 NER" actually is — and integrate the LLM extractor (HIGH)

Either:

- **(a) Integrate `Ligase_NER_API` into `Ligase_Entity_Pipeline`** so that `mode='deep'` uses the
  LLM (when configured) and falls back to regex otherwise. Document the cost implications in the
  pipeline call.
- **(b) Rename the regex extractor to something honest** (e.g. "Pattern-based extraction") and
  market the LLM NER as a separate "AI NER" feature in the admin UI. Stop conflating them in the
  README's "4-level pipeline" diagram.

Either path also needs:

- Polish inflection normalization (lemmatization or at least a known-suffix stripper for
  nominative/genitive/dative person names).
- Author exclusion: don't NER-extract the post's own author.
- Fix the `to` Polish-pronoun false-positive in places detection.

### 5. Fix the broken NER bulk progress counter (MEDIUM)

`get_bulk_status()` reads `ligase_ner_bulk_done` from options
([class-ner-api.php:159](includes/class-ner-api.php#L159)) but the option is only ever *reset to
0* — never incremented. In `run_scheduled()`
([class-ner-api.php:107](includes/class-ner-api.php#L107)), after a successful extract,
`update_option( 'ligase_ner_bulk_done', get_option('ligase_ner_bulk_done', 0) + 1 )` (with
appropriate atomicity / option autoload considerations). Without this fix, admins see 0% progress
forever on bulk runs and have no way to know if the job finished or stalled.

---

## Bonus findings (not critical, worth noting)

- **`Ligase_Entity_Extractor_Structure` computes `headings` and `external_links` that nobody
  reads** ([class-extractor-structure.php:18-19](includes/entities/class-extractor-structure.php#L18)).
  Either expose them via a filter or drop the work.
- **`Ligase_Score::get_sample_posts()` per-request static cache** is unused as a real cache for
  multi-call scoring — the array key is the limit, so different checks calling different limits
  still hit the DB. Cheap to fix (cache by `'all'` and slice).
- **`Ligase_Wikidata_Lookup::TTL` uses `WEEK_IN_SECONDS * 4`** ([class-wikidata-lookup.php:9](includes/entities/class-wikidata-lookup.php#L9))
  but `WEEK_IN_SECONDS` isn't defined until WordPress 3.5. Plugin requires WP 6.0+ so this is fine,
  but worth flagging that the constant is WP-defined, not PHP.
- **`Ligase_Cache::invalidate_post` is called on every `save_post`** but the wikidata suggestions
  meta survives content changes. Either include `_ligase_wikidata_suggestions` and
  `_ligase_ner_api_results` in invalidation (with a content-hash gate so trivial edits don't
  trash hours of API work) or document that re-analysis is manual.
- **No nonce-less endpoints found.** All AJAX handlers reviewed call `$this->verify_request()`
  which checks both nonce + `manage_options` capability — good. Wikidata search
  ([class-ajax.php:402](includes/class-ajax.php#L402)) sanitizes the `name` param with
  `sanitize_text_field` + `wp_unslash` — good. No SQL injection surfaces found in the audited
  files; all DB access is via WP APIs (`get_post_meta`, `get_users`, `get_posts`).
- **`get_the_content( null, false, $post_id )`** at
  [class-extractor-structure.php:12](includes/entities/class-extractor-structure.php#L12) works on
  WP 5.2+ where the 3rd arg accepts `int|WP_Post`. Plugin requires WP 6.0+ so this is safe.
- **`get_the_modified_date` and `get_the_date`** in score methods are called *during AJAX* — these
  rely on `$GLOBALS['post']` for formatting and timezone, but since they receive a post object,
  it's OK. The site-score `check_date_modified_current` uses `'U'` (Unix timestamp) format which
  doesn't depend on locale-aware formatting — correct.

---

## Final verdict

| Area | Verdict |
|---|---|
| Pipeline orchestration | NEEDS WORK — no cross-level dedup, dangerous auto-apply, LLM NER not integrated |
| Level 1 (native) | GOOD for scope |
| Level 2 (structural) | NEEDS WORK — two extracted fields unused, YouTube Shorts missed, FAQ ≥2 arbitrary |
| Level 3 (regex NER) | NEEDS WORK — Polish inflection blindness, `to` false positives, broken test contract |
| Level 4 (Wikidata async) | NEEDS WORK — hardcoded Polish, no fallback, race-prone meta writes, non-compliant UA |
| LLM NER (`Ligase_NER_API`) | NEEDS WORK — not in pipeline, cost estimates 10× low, broken bulk progress |
| Score formula | NEEDS WORK (site/post) / GOOD-ish (author) — heavy vanity weighting, decoupled from pipeline |
| Tests | CRITICAL — both test files call non-existent methods, zero real coverage |

The plugin's marketing-strongest features (4-level pipeline, AI Readiness Score, Wikidata
integration) are also its weakest engineering surfaces. The schema-emission side of the plugin
(not in scope here, but visible from `includes/types/` and `class-generator.php`) appears more
mature; the pipeline + score system reads as a feature bolted on for a 2.0.0 release without a
full design pass.
