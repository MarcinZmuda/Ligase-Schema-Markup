<?php
/**
 * Ligase_Field_Contract
 *
 * Declarative single-source-of-truth for schema field requirements, autofill
 * source chains, and sanitization rules per @type. The contract drives:
 *   - Ligase_Field_Resolver (auto-population + eligibility gating)
 *   - Ligase_Readiness (editor panel + validator)
 *   - JSON-LD generation (single place defining "what does Google need").
 *
 * Field keys with dots = nested path (e.g. 'offers.price' → schema.offers.price).
 * The `_containers` map tells the resolver which @type to stamp on each nested
 * object during graph assembly.
 *
 * Extend per-type via the `ligase_field_contract` filter.
 *
 * @package Ligase
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

final class Ligase_Field_Contract {

	/**
	 * Resolve the contract for a given schema type. Unknown types return an empty
	 * shell so callers can branch without null-checking.
	 *
	 * @param string $type Schema.org type, e.g. 'Product', 'BlogPosting'.
	 * @return array{_meta:array,_containers:array,fields:array}
	 */
	public static function get( string $type ): array {
		$contracts = self::all();
		$contract  = $contracts[ $type ] ?? array(
			'_meta'       => array( 'label' => $type, 'experience' => null, 'deprecated' => false ),
			'_containers' => array(),
			'fields'      => array(),
		);

		/**
		 * Filter the contract for a specific @type. Use to add/remove fields,
		 * change required level, or extend source chains from a theme/plugin.
		 *
		 * @param array  $contract The contract structure.
		 * @param string $type     The schema type.
		 */
		return apply_filters( 'ligase_field_contract', $contract, $type );
	}

	/**
	 * @return string[]
	 */
	public static function types(): array {
		return array_keys( self::all() );
	}

	/**
	 * All built-in contracts.
	 *
	 * @return array<string,array>
	 */
	private static function all(): array {
		return array(

			'Product' => array(
				'_meta'       => array(
					'label'      => 'Product (merchant listing / snippet)',
					'experience' => 'merchant_listing',
					'deprecated' => false,
				),
				'_containers' => array(
					'offers'                                    => 'Offer',
					'offers.hasMerchantReturnPolicy'            => 'MerchantReturnPolicy',
					// returnShippingFeesAmount only emits when returnFees=ReturnShippingFees;
					// declared here so when present it carries proper @type.
					'offers.hasMerchantReturnPolicy.returnShippingFeesAmount' => 'MonetaryAmount',
					'offers.shippingDetails'                    => 'OfferShippingDetails',
					'offers.shippingDetails.shippingRate'       => 'MonetaryAmount',
					'offers.shippingDetails.shippingDestination' => 'DefinedRegion',
					// deliveryTime + nested handling/transit need explicit @type so the
					// resolver emits "@type": "ShippingDeliveryTime" / "QuantitativeValue"
					// instead of an untyped object that Validator flags as
					// "Nieprawidłowy typ obiektu w polu handlingTime".
					'offers.shippingDetails.deliveryTime'                => 'ShippingDeliveryTime',
					'offers.shippingDetails.deliveryTime.handlingTime'   => 'QuantitativeValue',
					'offers.shippingDetails.deliveryTime.transitTime'    => 'QuantitativeValue',
					'aggregateRating'                           => 'AggregateRating',
					'brand'                                     => 'Brand',
				),
				'fields'      => array(
					'name' => array(
						'label'   => 'Nazwa produktu',
						'level'   => 'required',
						'sources' => array( 'manual:', 'wc:name', 'post:title' ),
						'sanitize' => 'text',
					),
					'image' => array(
						'label'    => 'Zdjęcie',
						'level'    => 'required',
						'sources'  => array( 'manual:', 'wc:image', 'post:thumbnail' ),
						'sanitize' => 'url',
					),
					'description' => array(
						'label'    => 'Opis',
						'level'    => 'recommended',
						'sources'  => array( 'manual:', 'wc:description', 'post:excerpt' ),
						'sanitize' => 'text',
						'maxlen'   => 5000,
					),
					'sku' => array(
						'label' => 'SKU', 'level' => 'recommended',
						'sources' => array( 'manual:', 'wc:sku' ), 'sanitize' => 'text',
					),
					'gtin' => array(
						'label' => 'GTIN', 'level' => 'recommended',
						'sources' => array( 'manual:', 'wc:gtin' ), 'sanitize' => 'text',
					),
					'mpn' => array(
						'label' => 'MPN', 'level' => 'optional',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'brand.name' => array(
						'label' => 'Marka', 'level' => 'recommended',
						'sources' => array( 'manual:', 'opt:org_name' ), 'sanitize' => 'text',
					),

					// --- Offer (merchant listing) ---
					'offers.price' => array(
						'label' => 'Cena', 'level' => 'required',
						'sources' => array( 'manual:', 'wc:price' ), 'sanitize' => 'float',
					),
					'offers.priceCurrency' => array(
						'label' => 'Waluta', 'level' => 'required',
						'sources' => array( 'manual:', 'wc:currency', 'opt:store_currency' ),
						'sanitize' => 'currency',
					),
					'offers.availability' => array(
						'label' => 'Dostępność', 'level' => 'required',
						'sources' => array( 'manual:', 'wc:availability' ),
						'sanitize' => 'passthrough',
					),
					'offers.priceValidUntil' => array(
						'label' => 'Cena ważna do',
						'level' => 'recommended',
						'sources' => array( 'manual:' ),
						'sanitize' => 'date',
					),
					'offers.url' => array(
						'label' => 'URL oferty', 'level' => 'recommended',
						'sources' => array( 'manual:', 'post:permalink' ), 'sanitize' => 'url',
					),
					'offers.itemCondition' => array(
						'label' => 'Stan',
						'level' => 'recommended',
						'sources' => array( 'manual:' ),
						'sanitize' => 'passthrough',
					),

					// --- MerchantReturnPolicy (returnPolicyCountry wymagane od III 2025) ---
					'offers.hasMerchantReturnPolicy.returnPolicyCountry' => array(
						'label'    => 'Kraj polityki zwrotów',
						'level'    => 'required',
						'sources'  => array( 'manual:', 'opt:store_return_country' ),
						'sanitize' => 'country',
					),
					'offers.hasMerchantReturnPolicy.applicableCountry' => array(
						'label' => 'Kraj obowiązywania zwrotów',
						'level' => 'recommended',
						'sources' => array( 'manual:', 'opt:store_return_country' ),
						'sanitize' => 'country',
					),
					'offers.hasMerchantReturnPolicy.merchantReturnDays' => array(
						'label' => 'Dni na zwrot',
						'level' => 'recommended',
						'sources' => array( 'manual:', 'opt:store_return_days' ),
						'sanitize' => 'int',
					),
					// returnPolicyCategory — schema.org enum. Window is finite by default
					// because merchantReturnDays is always > 0 when the policy is emitted.
					// Without this property Google flags "Brakujące pole returnPolicyCategory".
					'offers.hasMerchantReturnPolicy.returnPolicyCategory' => array(
						'label'   => 'Kategoria polityki zwrotów',
						'level'   => 'recommended',
						'sources' => array( 'manual:', 'derive:return_policy_category' ),
						'sanitize' => 'url',
					),
					'offers.hasMerchantReturnPolicy.returnMethod' => array(
						'label'   => 'Sposób zwrotu',
						'level'   => 'optional',
						'sources' => array( 'manual:', 'derive:return_method' ),
						'sanitize' => 'url',
					),
					'offers.hasMerchantReturnPolicy.returnFees' => array(
						'label'   => 'Opłaty za zwrot',
						'level'   => 'recommended',
						// store_return_fees is a bare enum string (e.g. 'FreeReturn'); the
						// derive: helper reads it from options and prepends https://schema.org/.
						// Using 'opt:' directly would route the raw enum through the 'url'
						// sanitizer, which esc_url_raw's into an empty string.
						'sources' => array( 'manual:', 'derive:return_fees_default' ),
						'sanitize' => 'url',
					),
					// returnShippingFeesAmount — schema.org requires when returnFees is
					// ReturnShippingFees (klient płaci za zwrot) so Google knows the cost.
					// derived helpers read store_shipping_rate + store_currency from options.
					'offers.hasMerchantReturnPolicy.returnShippingFeesAmount.value' => array(
						'label'    => 'Koszt zwrotu',
						'level'    => 'optional',
						'sources'  => array( 'manual:', 'derive:return_fees_amount_value' ),
						'sanitize' => 'float',
					),
					'offers.hasMerchantReturnPolicy.returnShippingFeesAmount.currency' => array(
						'label'    => 'Waluta zwrotu',
						'level'    => 'optional',
						'sources'  => array( 'manual:', 'opt:store_currency' ),
						'sanitize' => 'text',
					),

					// --- shippingDetails (Google Merchant Listings — wymaga peł nej struktury) ---
					// shippingDetails NIE może być @id ref do OnlineStore (schema.org tego
					// nie pozwala). Dlatego inline w każdy Offer, z opt: site-level fallback.
					'offers.shippingDetails.shippingRate.value' => array(
						'label'    => 'Stawka wysyłki',
						'level'    => 'recommended',
						'sources'  => array( 'manual:', 'opt:store_shipping_rate' ),
						'sanitize' => 'float',
					),
					'offers.shippingDetails.shippingRate.currency' => array(
						'label'    => 'Waluta wysyłki',
						'level'    => 'recommended',
						'sources'  => array( 'manual:', 'opt:store_currency' ),
						'sanitize' => 'text',
					),
					'offers.shippingDetails.shippingDestination.addressCountry' => array(
						'label'    => 'Kraj wysyłki',
						'level'    => 'recommended',
						'sources'  => array( 'manual:', 'opt:store_shipping_country' ),
						'sanitize' => 'country',
					),
					'offers.shippingDetails.deliveryTime.handlingTime.minValue' => array(
						'label'    => 'Handling time min (dni)',
						'level'    => 'optional',
						'sources'  => array( 'manual:', 'opt:store_handling_min' ),
						'sanitize' => 'int',
					),
					'offers.shippingDetails.deliveryTime.handlingTime.maxValue' => array(
						'label'    => 'Handling time max (dni)',
						'level'    => 'optional',
						'sources'  => array( 'manual:', 'opt:store_handling_max' ),
						'sanitize' => 'int',
					),
					'offers.shippingDetails.deliveryTime.handlingTime.unitCode' => array(
						'label'    => 'Jednostka handlingTime',
						'level'    => 'optional',
						'sources'  => array( 'derive:unit_code_day' ),
						'sanitize' => 'text',
					),
					'offers.shippingDetails.deliveryTime.transitTime.minValue' => array(
						'label'    => 'Transit time min (dni)',
						'level'    => 'optional',
						'sources'  => array( 'manual:', 'opt:store_transit_min' ),
						'sanitize' => 'int',
					),
					'offers.shippingDetails.deliveryTime.transitTime.maxValue' => array(
						'label'    => 'Transit time max (dni)',
						'level'    => 'optional',
						'sources'  => array( 'manual:', 'opt:store_transit_max' ),
						'sanitize' => 'int',
					),
					'offers.shippingDetails.deliveryTime.transitTime.unitCode' => array(
						'label'    => 'Jednostka transitTime',
						'level'    => 'optional',
						'sources'  => array( 'derive:unit_code_day' ),
						'sanitize' => 'text',
					),

					// --- AggregateRating (tylko z prawdziwych opinii — manual action ryzyko) ---
					'aggregateRating.ratingValue' => array(
						'label' => 'Średnia ocena',
						'level' => 'optional',
						'sources' => array( 'manual:', 'wc:rating_value' ),
						'sanitize' => 'float',
					),
					'aggregateRating.reviewCount' => array(
						'label' => 'Liczba opinii',
						'level' => 'optional',
						'sources' => array( 'manual:', 'wc:rating_count' ),
						'sanitize' => 'int',
					),
				),
			),

			'BlogPosting' => array(
				'_meta'       => array(
					'label'      => 'BlogPosting / Article',
					'experience' => 'article',
					'deprecated' => false,
				),
				'_containers' => array(),
				'fields'      => array(
					'headline' => array(
						'label' => 'Tytuł (≤110)',
						'level' => 'required',
						'sources' => array( 'post:title' ),
						'sanitize' => 'text',
						'maxlen' => 110,
					),
					'image' => array(
						'label' => 'Obraz (1:1, 4:3, 16:9)',
						'level' => 'recommended',
						'sources' => array( 'post:thumbnail_set' ),
						'sanitize' => 'passthrough',
					),
					'author' => array(
						'label' => 'Autor (referencja @id)',
						'level' => 'required',
						'sources' => array( 'ref:author_id' ),
						'sanitize' => 'passthrough',
					),
					'datePublished' => array(
						'label' => 'Data publikacji',
						'level' => 'required',
						'sources' => array( 'post:date' ),
						'sanitize' => 'date',
					),
					'dateModified' => array(
						'label' => 'Data modyfikacji',
						'level' => 'recommended',
						'sources' => array( 'post:modified' ),
						'sanitize' => 'date',
					),
					'articleSection' => array(
						'label' => 'Sekcja/kategoria',
						'level' => 'recommended',
						'sources' => array( 'post:primary_category' ),
						'sanitize' => 'text',
					),
					'wordCount' => array(
						'label' => 'Liczba słów',
						'level' => 'optional',
						'sources' => array( 'derive:wordcount' ),
						'sanitize' => 'int',
					),
				),
			),

			// ---------------------------------------------------------------
			// RECIPE (host-carousel-eligible)
			// ---------------------------------------------------------------
			'Recipe' => array(
				'_meta'       => array(
					'label'      => 'Recipe (host carousel eligible)',
					'experience' => 'recipe',
					'deprecated' => false,
				),
				'_containers' => array(
					'nutrition'       => 'NutritionInformation',
					'aggregateRating' => 'AggregateRating',
				),
				'fields'      => array(
					'name' => array(
						'label' => 'Nazwa przepisu', 'level' => 'required',
						'sources' => array( 'manual:', 'post:title' ), 'sanitize' => 'text',
					),
					'image' => array(
						'label' => 'Zdjęcie (1:1, 4:3, 16:9)', 'level' => 'required',
						'sources' => array( 'manual:', 'post:thumbnail_set' ), 'sanitize' => 'passthrough',
					),
					'author' => array(
						'label' => 'Autor (referencja @id)', 'level' => 'recommended',
						'sources' => array( 'ref:author_id' ), 'sanitize' => 'passthrough',
					),
					'datePublished' => array(
						'label' => 'Data publikacji', 'level' => 'recommended',
						'sources' => array( 'post:date' ), 'sanitize' => 'date',
					),
					'description' => array(
						'label' => 'Opis', 'level' => 'recommended',
						'sources' => array( 'manual:', 'post:excerpt' ), 'sanitize' => 'text', 'maxlen' => 5000,
					),
					'recipeIngredient' => array(
						'label' => 'Składniki (lista)', 'level' => 'required',
						'sources' => array( 'manual:' ), 'sanitize' => 'passthrough',
					),
					'recipeInstructions' => array(
						'label' => 'Instrukcje (kroki HowToStep)', 'level' => 'required',
						'sources' => array( 'manual:' ), 'sanitize' => 'passthrough',
					),
					'prepTime' => array(
						'label' => 'Czas przygotowania (ISO 8601 np. PT15M)', 'level' => 'recommended',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'cookTime' => array(
						'label' => 'Czas gotowania (ISO 8601)', 'level' => 'recommended',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'totalTime' => array(
						'label' => 'Czas łączny (ISO 8601)', 'level' => 'recommended',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'recipeYield' => array(
						'label' => 'Liczba porcji', 'level' => 'recommended',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'recipeCategory' => array(
						'label' => 'Kategoria (śniadanie/obiad/deser)', 'level' => 'recommended',
						'sources' => array( 'manual:', 'post:primary_category' ), 'sanitize' => 'text',
					),
					'recipeCuisine' => array(
						'label' => 'Kuchnia (polska, włoska...)', 'level' => 'recommended',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'nutrition.calories' => array(
						'label' => 'Kalorie (np. 350 kcal)', 'level' => 'recommended',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'suitableForDiet' => array(
						'label' => 'Dieta (URL schema.org)', 'level' => 'optional',
						'sources' => array( 'manual:' ), 'sanitize' => 'passthrough',
					),
					'aggregateRating.ratingValue' => array(
						'label' => 'Średnia ocena', 'level' => 'optional',
						'sources' => array( 'manual:' ), 'sanitize' => 'float',
					),
					'aggregateRating.ratingCount' => array(
						'label' => 'Liczba ocen', 'level' => 'optional',
						'sources' => array( 'manual:' ), 'sanitize' => 'int',
					),
				),
			),

			// ---------------------------------------------------------------
			// JOBPOSTING (Google Jobs rich result)
			// ---------------------------------------------------------------
			'JobPosting' => array(
				'_meta'       => array(
					'label'      => 'JobPosting (Google Jobs)',
					'experience' => 'jobposting',
					'deprecated' => false,
				),
				'_containers' => array(
					'hiringOrganization' => 'Organization',
					'jobLocation'        => 'Place',
					'jobLocation.address' => 'PostalAddress',
					'baseSalary'         => 'MonetaryAmount',
					'baseSalary.value'   => 'QuantitativeValue',
				),
				'fields'      => array(
					'title' => array(
						'label' => 'Stanowisko', 'level' => 'required',
						'sources' => array( 'manual:', 'post:title' ), 'sanitize' => 'text',
					),
					'description' => array(
						'label' => 'Opis stanowiska (pełny HTML)', 'level' => 'required',
						'sources' => array( 'manual:' ), 'sanitize' => 'html',
					),
					'datePosted' => array(
						'label' => 'Data publikacji oferty', 'level' => 'required',
						'sources' => array( 'manual:', 'post:date' ), 'sanitize' => 'date',
					),
					'validThrough' => array(
						'label' => 'Ważne do', 'level' => 'required',
						'sources' => array( 'manual:' ), 'sanitize' => 'date',
					),
					'hiringOrganization.name' => array(
						'label' => 'Nazwa pracodawcy', 'level' => 'required',
						'sources' => array( 'manual:', 'opt:org_name' ), 'sanitize' => 'text',
					),
					'hiringOrganization.sameAs' => array(
						'label' => 'URL pracodawcy', 'level' => 'recommended',
						'sources' => array( 'manual:' ), 'sanitize' => 'url',
					),
					'jobLocation.address.streetAddress' => array(
						'label' => 'Adres (ulica)', 'level' => 'recommended',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'jobLocation.address.addressLocality' => array(
						'label' => 'Miasto', 'level' => 'required',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'jobLocation.address.addressCountry' => array(
						'label' => 'Kraj (ISO 3166-1)', 'level' => 'required',
						'sources' => array( 'manual:', 'opt:store_return_country' ), 'sanitize' => 'country',
					),
					'jobLocationType' => array(
						'label' => 'Typ lokalizacji (TELECOMMUTE dla zdalnej)', 'level' => 'optional',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'employmentType' => array(
						'label' => 'Wymiar (FULL_TIME / PART_TIME / CONTRACTOR / TEMPORARY / INTERN / VOLUNTEER)',
						'level' => 'recommended',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'baseSalary.currency' => array(
						'label' => 'Waluta wynagrodzenia', 'level' => 'recommended',
						'sources' => array( 'manual:', 'opt:store_currency' ), 'sanitize' => 'currency',
					),
					'baseSalary.value.value' => array(
						'label' => 'Wartość wynagrodzenia', 'level' => 'recommended',
						'sources' => array( 'manual:' ), 'sanitize' => 'float',
					),
					'baseSalary.value.unitText' => array(
						'label' => 'Jednostka (HOUR / DAY / WEEK / MONTH / YEAR)', 'level' => 'recommended',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'directApply' => array(
						'label' => 'Direct Apply (true gdy aplikacja na tej stronie)',
						'level' => 'optional',
						'sources' => array( 'manual:' ), 'sanitize' => 'passthrough',
					),
				),
			),

			// ---------------------------------------------------------------
			// DISCUSSION FORUM POSTING (Discussions & Forums SERP)
			// ---------------------------------------------------------------
			'DiscussionForumPosting' => array(
				'_meta'       => array(
					'label'      => 'DiscussionForumPosting (Discussions & Forums)',
					'experience' => 'forum',
					'deprecated' => false,
				),
				'_containers' => array(
					'author'              => 'Person',
					'interactionStatistic' => 'InteractionCounter',
				),
				'fields'      => array(
					'headline' => array(
						'label' => 'Tytuł wątku', 'level' => 'required',
						'sources' => array( 'manual:', 'post:title' ), 'sanitize' => 'text', 'maxlen' => 110,
					),
					'text' => array(
						'label' => 'Treść posta (HTML)', 'level' => 'required',
						'sources' => array( 'manual:' ), 'sanitize' => 'html',
					),
					'datePublished' => array(
						'label' => 'Data publikacji', 'level' => 'required',
						'sources' => array( 'post:date' ), 'sanitize' => 'date',
					),
					'dateModified' => array(
						'label' => 'Data modyfikacji', 'level' => 'recommended',
						'sources' => array( 'post:modified' ), 'sanitize' => 'date',
					),
					'author' => array(
						'label' => 'Autor (referencja @id)', 'level' => 'required',
						'sources' => array( 'ref:author_id' ), 'sanitize' => 'passthrough',
					),
					'url' => array(
						'label' => 'URL wątku', 'level' => 'required',
						'sources' => array( 'post:permalink' ), 'sanitize' => 'url',
					),
					'interactionStatistic.interactionType' => array(
						'label' => 'Typ interakcji (CommentAction / LikeAction / WatchAction)',
						'level' => 'recommended',
						'sources' => array( 'manual:' ), 'sanitize' => 'text',
					),
					'interactionStatistic.userInteractionCount' => array(
						'label' => 'Liczba interakcji (komentarzy/polubień/wyświetleń)',
						'level' => 'recommended',
						'sources' => array( 'manual:', 'derive:comment_count' ), 'sanitize' => 'int',
					),
				),
			),

			'NewsArticle' => array(
				'_meta'       => array(
					'label'      => 'NewsArticle (Top Stories eligible)',
					'experience' => 'news',
					'deprecated' => false,
				),
				'_containers' => array(),
				'fields'      => array(
					'headline' => array(
						'label' => 'Tytuł (≤110)',
						'level' => 'required',
						'sources' => array( 'post:title' ),
						'sanitize' => 'text',
						'maxlen' => 110,
					),
					'image' => array(
						'label' => 'Obraz (1:1, 4:3, 16:9)',
						'level' => 'required', // dla Top Stories obraz jest realnie konieczny
						'sources' => array( 'post:thumbnail_set' ),
						'sanitize' => 'passthrough',
					),
					'author' => array(
						'label' => 'Autor (referencja @id)',
						'level' => 'required',
						'sources' => array( 'ref:author_id' ),
						'sanitize' => 'passthrough',
					),
					'datePublished' => array(
						'label' => 'Data publikacji',
						'level' => 'required',
						'sources' => array( 'post:date' ),
						'sanitize' => 'date',
					),
					'dateline' => array(
						'label' => 'Dateline (np. „WARSZAWA")',
						'level' => 'recommended',
						'sources' => array( 'manual:' ),
						'sanitize' => 'text',
					),
				),
			),
		);
	}
}
