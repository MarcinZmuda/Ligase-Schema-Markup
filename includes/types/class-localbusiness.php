<?php
/**
 * Ligase LocalBusiness Schema Type
 *
 * Generates LocalBusiness (or specific subtype) schema for physical
 * and service-area businesses. Enabled via Settings — shown on homepage
 * and contact/location pages.
 *
 * Key features:
 *  - 60+ subtypes grouped by category
 *  - Structured OpeningHoursSpecification (not plain text)
 *  - geo coordinates → auto hasMap
 *  - areaServed for service-area businesses
 *  - parentOrganization link to Organization entity
 *  - aggregateRating excluded — Google does NOT show stars for
 *    self-hosted LocalBusiness reviews (policy since 2019)
 *
 * @package Ligase
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Type_LocalBusiness {

	/**
	 * All supported subtypes grouped by category.
	 * Used in Settings UI <select optgroup>.
	 *
	 * @var array<string, array<string, string>>
	 */
	const SUBTYPES = array(
		'General' => array(
			'LocalBusiness'       => 'LocalBusiness (generic)',
			'ProfessionalService' => 'ProfessionalService — consultants, agencies',
		),
		'Food & Drink' => array(
			'Restaurant'          => 'Restaurant',
			'CafeOrCoffeeShop'    => 'Cafe / Coffee Shop',
			'Bakery'              => 'Bakery',
			'BarOrPub'            => 'Bar / Pub',
			'FastFoodRestaurant'  => 'Fast Food Restaurant',
			'IceCreamShop'        => 'Ice Cream Shop',
			'FoodEstablishment'   => 'FoodEstablishment (other)',
		),
		'Health & Medical' => array(
			'MedicalBusiness'     => 'MedicalBusiness (generic)',
			'Dentist'             => 'Dentist',
			'Physician'           => 'Physician / Doctor',
			'Pharmacy'            => 'Pharmacy',
			'Optician'            => 'Optician',
			'BeautySalon'         => 'Beauty Salon',
			'HairSalon'           => 'Hair Salon',
			'HealthClub'          => 'Health Club / Spa',
		),
		'Legal & Finance' => array(
			'LegalService'        => 'LegalService (generic)',
			'Attorney'            => 'Attorney / Lawyer',
			'Notary'              => 'Notary',
			'AccountingService'   => 'Accounting Service',
			'FinancialService'    => 'Financial Service',
			'InsuranceAgency'     => 'Insurance Agency',
		),
		'Home & Construction' => array(
			'HomeAndConstructionBusiness' => 'Home & Construction (generic)',
			'HVACBusiness'        => 'HVAC',
			'Electrician'         => 'Electrician',
			'Plumber'             => 'Plumber',
			'Locksmith'           => 'Locksmith',
			'RoofingContractor'   => 'Roofing Contractor',
			'MovingCompany'       => 'Moving Company',
		),
		'Automotive' => array(
			'AutomotiveBusiness'  => 'AutomotiveBusiness (generic)',
			'AutoRepair'          => 'Auto Repair / Mechanic',
			'AutoDealer'          => 'Auto Dealer',
			'GasStation'          => 'Gas Station',
		),
		'Retail' => array(
			'Store'               => 'Store (generic)',
			'ClothingStore'       => 'Clothing Store',
			'ElectronicsStore'    => 'Electronics Store',
			'GroceryStore'        => 'Grocery Store / Supermarket',
			'BookStore'           => 'Book Store',
			'FlowerShop'          => 'Flower Shop',
			'ShoeStore'           => 'Shoe Store',
			'JewelryStore'        => 'Jewelry Store',
			'FurnitureStore'      => 'Furniture Store',
			'PetStore'            => 'Pet Store',
			'ShoppingCenter'      => 'Shopping Center / Mall',
		),
		'Fitness & Sports' => array(
			'SportsActivityLocation' => 'Sports Activity Location',
			'ExerciseGym'         => 'Gym / Fitness Center',
			'SportsClub'          => 'Sports Club',
		),
		'Travel & Lodging' => array(
			'LodgingBusiness'     => 'LodgingBusiness (generic)',
			'Hotel'               => 'Hotel',
			'BedAndBreakfast'     => 'Bed & Breakfast',
			'Hostel'              => 'Hostel',
			'TravelAgency'        => 'Travel Agency',
		),
		'Real Estate' => array(
			'RealEstateAgent'     => 'Real Estate Agent',
		),
		'Education' => array(
			'EducationalOrganization' => 'Educational Organization',
			'School'              => 'School',
			'Library'             => 'Library',
		),
		'Other' => array(
			'EntertainmentBusiness' => 'Entertainment Business',
			'ChildCare'           => 'Child Care',
			'DryCleaningOrLaundry'=> 'Dry Cleaning / Laundry',
			'EmergencyService'    => 'Emergency Service',
			'EmploymentAgency'    => 'Employment Agency',
			'GovernmentOffice'    => 'Government Office',
			'AnimalShelter'       => 'Animal Shelter',
		),
	);

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Build LocalBusiness schema. Returns null if not configured.
	 *
	 * @return array|null
	 */
	public function build(): ?array {
		$opts = (array) get_option( 'ligase_options', array() );

		if ( ! self::is_configured() ) {
			return null;
		}

		// Resolve @type — validate against known subtypes
		$type     = sanitize_text_field( $opts['lb_type'] ?? 'LocalBusiness' );
		$all_types = self::get_all_subtypes();
		if ( ! isset( $all_types[ $type ] ) ) {
			$type = 'LocalBusiness';
		}

		$name = ! empty( $opts['lb_name'] )
			? $opts['lb_name']
			: ( ! empty( $opts['org_name'] ) ? $opts['org_name'] : get_bloginfo( 'name' ) );

		$schema = array(
			'@type' => $type,
			'@id'   => home_url( '/#localbusiness' ),
			'name'  => wp_strip_all_tags( $name ),
			'url'   => esc_url( home_url( '/' ) ),
		);

		// ── Description ──────────────────────────────────────────────────────
		$desc = ! empty( $opts['lb_description'] )
			? $opts['lb_description']
			: ( $opts['org_description'] ?? '' );
		if ( $desc ) {
			$schema['description'] = wp_strip_all_tags( $desc );
		}

		// ── Logo / Image ──────────────────────────────────────────────────────
		if ( ! empty( $opts['org_logo'] ) ) {
			$schema['image'] = array( esc_url( $opts['org_logo'] ) );
			$schema['logo']  = array(
				'@type' => 'ImageObject',
				'url'   => esc_url( $opts['org_logo'] ),
			);
		}

		// ── Contact ───────────────────────────────────────────────────────────
		$phone = ! empty( $opts['org_phone'] ) ? $opts['org_phone'] : '';
		$email = ! empty( $opts['org_email'] ) ? $opts['org_email'] : '';
		if ( $phone ) {
			$schema['telephone'] = wp_strip_all_tags( $phone );
		}
		if ( $email ) {
			$schema['email'] = sanitize_email( $email );
		}

		// ── Address (PostalAddress) ───────────────────────────────────────────
		// For service-area businesses (lb_service_area = '1'), omit `address` unless
		// at least addressLocality + addressCountry are filled — Google supports
		// service-area businesses without a public street address since 2018.
		$is_service_area = self::is_service_area_business();
		if ( $is_service_area ) {
			$partial = $this->build_address( $opts );
			// Only emit address when we have *some* useful locality/country signal.
			$has_partial = isset( $partial['addressLocality'] ) || isset( $partial['addressCountry'] );
			if ( $has_partial ) {
				// Drop streetAddress for service-area businesses (the whole point is no
				// public storefront); keep locality + country + region for context.
				unset( $partial['streetAddress'] );
				$schema['address'] = $partial;
			}
		} else {
			$schema['address'] = $this->build_address( $opts );
		}

		// ── Geo coordinates → hasMap ──────────────────────────────────────────
		$lat = (float) ( $opts['lb_lat'] ?? 0 );
		$lng = (float) ( $opts['lb_lng'] ?? 0 );
		if ( $lat !== 0.0 && $lng !== 0.0 ) {
			$schema['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $lat,
				'longitude' => $lng,
			);
			$schema['hasMap'] = 'https://maps.google.com/?q=' . $lat . ',' . $lng;
		}

		// ── Opening hours ─────────────────────────────────────────────────────
		$hours = $opts['lb_hours'] ?? array();
		if ( ! empty( $hours ) && is_array( $hours ) ) {
			$specs = $this->build_opening_hours( $hours );
			if ( ! empty( $specs ) ) {
				$schema['openingHoursSpecification'] = $specs;
			}
		}

		// ── Price range ───────────────────────────────────────────────────────
		if ( ! empty( $opts['lb_price_range'] ) ) {
			$schema['priceRange'] = wp_strip_all_tags( $opts['lb_price_range'] );
		}

		// ── Area served (for service-area businesses) ─────────────────────────
		if ( ! empty( $opts['lb_area_served'] ) ) {
			$schema['areaServed'] = wp_strip_all_tags( $opts['lb_area_served'] );
		}

		// ── sameAs — reuse social links from Organization settings ────────────
		$same_as = array();
		foreach ( array( 'social_wikidata', 'social_wikipedia', 'social_linkedin',
			'social_facebook', 'social_twitter', 'social_youtube' ) as $key ) {
			if ( ! empty( $opts[ $key ] ) ) {
				$same_as[] = esc_url( $opts[ $key ] );
			}
		}
		if ( ! empty( $same_as ) ) {
			$schema['sameAs'] = $same_as;
		}

		// ── Link back to parent Organization entity ───────────────────────────
		$schema['parentOrganization'] = array( '@id' => home_url( '/#org' ) );

		return apply_filters( 'ligase_localbusiness', $schema );
	}

	/**
	 * Check if LocalBusiness is configured.
	 *
	 * Two opt-in shapes are valid:
	 *   1. Physical address — street + city set (classic LocalBusiness).
	 *   2. Service-area business — `lb_service_area` checked AND `lb_area_served`
	 *      filled. Plumbers / electricians / mobile services that operate without a
	 *      public-facing address. Google supports this shape since 2018.
	 */
	public static function is_configured(): bool {
		$opts = (array) get_option( 'ligase_options', array() );

		// Mode 1 — physical address.
		if ( ! empty( $opts['lb_street'] ) && ! empty( $opts['lb_city'] ) ) {
			return true;
		}

		// Mode 2 — service-area business.
		if ( ! empty( $opts['lb_service_area'] ) && (string) $opts['lb_service_area'] === '1'
			&& ! empty( $opts['lb_area_served'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether this LocalBusiness operates as a service-area business (no fixed
	 * public address). Read by `build()` to decide whether to emit `address`.
	 */
	public static function is_service_area_business(): bool {
		$opts = (array) get_option( 'ligase_options', array() );
		return ! empty( $opts['lb_service_area'] ) && (string) $opts['lb_service_area'] === '1';
	}

	/**
	 * Flatten all subtypes into a single key → label array.
	 */
	public static function get_all_subtypes(): array {
		$flat = array();
		foreach ( self::SUBTYPES as $group ) {
			foreach ( $group as $key => $label ) {
				$flat[ $key ] = $label;
			}
		}
		return $flat;
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	private function build_address( array $opts ): array {
		$address = array( '@type' => 'PostalAddress' );

		$map = array(
			'lb_street'  => 'streetAddress',
			'lb_city'    => 'addressLocality',
			'lb_region'  => 'addressRegion',
			'lb_postal'  => 'postalCode',
			'lb_country' => 'addressCountry',
		);
		foreach ( $map as $opt_key => $schema_key ) {
			if ( ! empty( $opts[ $opt_key ] ) ) {
				$address[ $schema_key ] = wp_strip_all_tags( $opts[ $opt_key ] );
			}
		}
		return $address;
	}

	/**
	 * Build OpeningHoursSpecification objects from stored array.
	 *
	 * Stored format:
	 * [
	 *   ['days' => ['Monday','Tuesday'], 'opens' => '09:00', 'closes' => '17:00'],
	 *   ...
	 * ]
	 */
	private function build_opening_hours( array $hours ): array {
		$specs = array();
		foreach ( $hours as $slot ) {
			$days   = array_filter( (array) ( $slot['days']   ?? array() ) );
			$opens  = sanitize_text_field( $slot['opens']  ?? '' );
			$closes = sanitize_text_field( $slot['closes'] ?? '' );

			if ( empty( $days ) || $opens === '' || $closes === '' ) {
				continue;
			}
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $opens )
				|| ! preg_match( '/^\d{2}:\d{2}$/', $closes ) ) {
				continue;
			}

			$spec = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => array_values( array_map( 'sanitize_text_field', $days ) ),
				'opens'     => $opens,
				'closes'    => $closes,
			);

			if ( ! empty( $slot['valid_from'] ) ) {
				$spec['validFrom']    = sanitize_text_field( $slot['valid_from'] );
			}
			if ( ! empty( $slot['valid_through'] ) ) {
				$spec['validThrough'] = sanitize_text_field( $slot['valid_through'] );
			}

			$specs[] = $spec;
		}
		return $specs;
	}
}
