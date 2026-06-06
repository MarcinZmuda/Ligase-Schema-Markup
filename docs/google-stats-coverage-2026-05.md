# Ligase vs Google open-web schema crawl — coverage analysis

**Data source**: `schemaorg/schemaorg` → `data/public_stats/google/2026_05.csv` (3,750 properties + 958 types, 5,546 rows)
**Snapshot date**: 2026-05
**Generated**: 2026-06-06

## Methodology

Google publishes domain-bucket counts for every schema.org `Itemtype` and
`Predicate` observed by its open-web crawler. Buckets are coarse but
representative: a type used on `10M+` domains has decades of production tooling
and rich-result integration; a type in `<1K` is experimental or extremely
niche. We compare these buckets against the schema types Ligase currently
emits to surface coverage gaps and validate niche-type decisions.

## A. Coverage map: Ligase types per Google popularity tier

### Tier 1 — `10M+` domains (12 types in Google's dataset)

Ligase covers **7/12**:

| Type | Ligase status |
|---|---|
| BreadcrumbList | ✅ emitted on every page |
| ImageObject | ✅ via Person/Organization/Article |
| ListItem | ✅ via ItemList + BreadcrumbList |
| Organization | ✅ first-class type |
| Person | ✅ first-class type (+5 new fields in 2.4.18) |
| WebPage | ✅ default for pages |
| WebSite | ✅ first-class type |
| EntryPoint | ⚠ not emitted standalone (used inside SearchAction target) |
| PropertyValueSpecification | ⚠ used inside SearchAction query-input |
| ReadAction | ⚠ not emitted (potentialAction) |
| SearchAction | ⚠ emitted as WebSite.potentialAction (not standalone) |
| Thing | n/a — abstract base type, not directly emitted |

**Verdict**: complete on real-world types. The four "missing" are structural
sub-nodes that exist inside WebSite/SearchAction.

### Tier 2 — `1M-10M` domains (35 types)

Ligase covers **17/35**:

| Type | Status |
|---|---|
| AggregateRating | ✅ on Product when WC reviews exist |
| Article / Blog / BlogPosting | ✅ |
| Brand | ✅ on Product |
| CollectionPage | ✅ for category archives |
| ContactPoint | ✅ in Organization |
| FAQPage / Question / Answer | ✅ |
| ItemList | ✅ for archives |
| LocalBusiness | ✅ first-class type |
| Offer | ✅ in Product |
| Place | ⚠ via LocalBusiness |
| PostalAddress | ✅ in Organization + LocalBusiness |
| Product | ✅ first-class type |
| Rating / Review | ✅ first-class types |
| Service | ✅ first-class type |
| SiteNavigationElement | ✅ |
| VideoObject | ✅ |
| AggregateOffer | ❌ **gap** — price ranges (e.g. "od 100 zł") |
| ContactPoint variations | partial |
| Country | ⚠ via addressCountry only |
| GeoCoordinates | ✅ in LocalBusiness |
| ImageGallery | ❌ gap |
| ItemPage | ❌ gap — WP Product/Article uses Product/Article directly |
| OpeningHoursSpecification | ✅ in LocalBusiness |
| QuantitativeValue | ✅ in shipping/handling times |
| UnitPriceSpecification | ❌ gap |
| CreativeWork | n/a — abstract super-type |
| CommentAction | ❌ not used (engagement actions) |
| WPFooter / WPHeader / WPSideBar | n/a — theme-emitted, not schema engine's job |
| WebPageElement | n/a — abstract |

**Verdict**: solid coverage on user-facing types. Real gaps to address in 2.5.0:
**AggregateOffer**, **ItemPage**, **UnitPriceSpecification**.

### Tier 3 — `100K-1M` domains (39 types)

Ligase covers **18/39**:

| Type | Status |
|---|---|
| AboutPage / ContactPage / ProfilePage | ✅ via smart page detection |
| AdministrativeArea | ⚠ used inside Service.areaServed |
| Audience | ⚠ inside Service |
| City | ⚠ in addresses |
| Comment | ⚠ inside DiscussionForumPosting |
| DefinedRegion | ✅ in shippingDestination |
| EducationalOrganization | ✅ inside Person.alumniOf |
| Event | ✅ |
| HowTo + HowToStep | ✅ |
| InteractionCounter | ✅ new in 2.4.19 (agentInteractionStatistic) |
| JobPosting | ✅ |
| MerchantReturnPolicy | ✅ on Organization + Product |
| MonetaryAmount | ✅ |
| NewsArticle | ✅ via post meta type override |
| OfferShippingDetails | ✅ inline in Product |
| OnlineStore | ✅ Organization subtype when WC active |
| PriceSpecification | ✅ in Service |
| ProductGroup | ✅ for variant products |
| ProfessionalService | ✅ via lb_type dropdown |
| PropertyValue | ⚠ partial (could be wider for identifier) |
| ShippingDeliveryTime | ✅ |
| SoftwareApplication | ✅ |
| Store | ⚠ via lb_type |
| Corporation | ❌ gap (Organization subtype) |
| Game | ❌ niche, skip |
| GeoCircle | ❌ gap (service radius) |
| HomeAndConstructionBusiness | ❌ gap (LB subtype) |
| LocationFeatureSpecification | ❌ gap (LB features like wifi/parking) |
| MediaObject | n/a — abstract super-type |
| Menu | ❌ Restaurant-specific |
| OfferCatalog | ❌ **gap** — Organization makesOffer listing |
| RealEstateAgent | ❌ gap (LB subtype) |
| Restaurant | ⚠ via lb_type |
| SpeakableSpecification | ❌ gap — voice search optimization |
| State | ⚠ in addresses |
| WebApplication | ⚠ via SoftwareApplication |

**Gaps to address in 2.5.0**: **OfferCatalog**, **GeoCircle**,
**LocationFeatureSpecification**, **SpeakableSpecification**, **Corporation**.

### Tier 4 — `10K-100K` domains (niche, but legitimate)

| Type | Status |
|---|---|
| AudioObject | ✅ |
| CheckoutPage | ✅ via smart detection |
| Course | ✅ |
| DefinedTerm | ✅ for glossaries |
| DiscussionForumPosting | ✅ |
| **PodcastSeries** | ✅ **new in 2.4.19** |
| QAPage | ✅ |
| Recipe | ✅ |

**Verdict**: Ligase is one of very few WP schema plugins covering all niche
types at this tier. The 2.4.19 PodcastSeries decision is validated: niche but
real, with `10K-100K` domains using it.

### Tier 5 — `1K-10K` domains (very niche)

| Type | Status |
|---|---|
| ClaimReview | ✅ |
| NewsMediaOrganization | ❌ **planned for 2.5.0 News/YMYL Pack** |

## B. 2.4.19 sanity check vs popularity data

We shipped two features in 2.4.19:

### PodcastSeries
- Popularity bucket: **10K-100K** (niche but real)
- Justified by personal-brand SEO need (marcinzmuda.com plan called for it)
- Not over-engineering: this many domains adoption = real Apple Podcasts /
  Spotify integration paths

### Person.agentInteractionStatistic (InteractionCounter)
- InteractionCounter bucket: **100K-1M** (established niche)
- interactionCount/interactionType also `100K-1M`
- Justified: not experimental, real-world adoption proven

**Verdict on 2.4.19**: both decisions land in tiers where production tooling
exists. Not premature.

### subjectOf (Person field added in 2.4.18)
- subjectOf bucket: **100K-1M** (established)
- Validated: not niche, used widely for entity-to-publication links

## C. Top properties Ligase emits, ranked by Google popularity

Tier 1 (10M+) properties — Ligase emits **all** of these:
`about, author, breadcrumb, datePublished, dateModified, description, headline,
image, inLanguage, isPartOf, item, itemListElement, logo, mainEntityOfPage,
name, position, potentialAction, publisher, sameAs, target, url, width, height`

Tier 2 (1M-10M) — Ligase emits most. Real gaps:
- `articleBody` (Article-level — we emit headline + description + image only)
- `wordCount` (could derive from post content)
- `alternateName` (Organization — planned)
- `legalName` (Organization — planned)

## Roadmap recommendations from this analysis

**2.5.0 News/YMYL Publisher Pack** — for infor.pl-class sites:
1. `NewsMediaOrganization` (`Tier 5 / 1K-10K` — niche but documented)
2. Organization identifier fields: KRS, NIP, REGON, taxID, vatID
3. Trust Project 8 policies (publishingPrinciples, ethicsPolicy etc.)
4. legalName, alternateName, foundingDate, slogan
5. parentOrganization / subOrganization repeater

**2.5.1 — Tier-2 gaps**:
6. `AggregateOffer` for price ranges (1M-10M adoption)
7. `OfferCatalog` for Organization.makesOffer listings (100K-1M)
8. `UnitPriceSpecification` for B2B per-unit pricing

**2.5.2 — LocalBusiness depth**:
9. `Corporation`, `HomeAndConstructionBusiness`, `RealEstateAgent` subtypes
10. `LocationFeatureSpecification` for amenityFeature (wifi, parking, etc.)
11. `GeoCircle` for service radius (`areaServed` enhancement)

**Skip / defer**:
- `Game`, `Menu`, `RealEstateAgent` — small market, focus elsewhere
- `WPFooter` / `WPHeader` etc — theme responsibility
- Pure abstract types (Thing, CreativeWork, MediaObject)

## Refresh cadence

Schema.org community drops a new Google stats CSV roughly every 6 months.
To refresh: download latest `data/public_stats/google/YYYY_MM.csv`, regenerate
`Ligase_Popularity_Stats::types()` map from Itemtype rows.

Generated by `2.4.20` analysis run; persistent dataset in
`includes/class-popularity-stats.php`.
