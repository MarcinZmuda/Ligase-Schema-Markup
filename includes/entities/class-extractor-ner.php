<?php
/**
 * Ligase - Keyword-based Named Entity Recognition Extractor
 *
 * Extracts persons, organizations, places, and products from post content
 * using pattern-based heuristics. No external API required.
 *
 * @package Ligase
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pattern-based Named Entity Recognition extractor.
 *
 * Analyses raw post content and returns categorised entities
 * (persons, organisations, places, products) with frequency and position data.
 */
final class Ligase_Entity_Extractor_NER {

	/**
	 * Common Polish stop-words that should never be treated as entities.
	 *
	 * @var string[]
	 */
	private const POLISH_STOP_WORDS = [
		'ale', 'aby', 'bez', 'bardzo', 'bez', 'bo', 'bowiem', 'byli',
		'by', 'co', 'czy', 'dla', 'do', 'dlatego', 'gdy', 'gdzie',
		'go', 'ich', 'jak', 'jako', 'jednak', 'jego', 'jej', 'jest',
		'jeszcze', 'już', 'jednak', 'każdy', 'kiedy', 'kilka',
		'kto', 'która', 'które', 'który', 'lub', 'lecz',
		'może', 'mi', 'między', 'miał', 'mnie', 'na', 'nad',
		'nie', 'niż', 'no', 'od', 'ona', 'one', 'oni', 'oraz',
		'pan', 'pani', 'po', 'pod', 'ponieważ', 'przed',
		'przez', 'przy', 'się', 'sobie', 'tak', 'także',
		'tam', 'ten', 'też', 'tylko', 'tego', 'tej', 'to',
		'tych', 'tym', 'tak', 'więc', 'właśnie', 'wszystko',
		'za', 'zaś', 'ze', 'że', 'żeby',
	];

	/**
	 * Common English words to filter out from entity candidates.
	 *
	 * @var string[]
	 */
	private const ENGLISH_STOP_WORDS = [
		'The', 'This', 'That', 'These', 'Those', 'There', 'Their', 'Then',
		'They', 'When', 'Where', 'Which', 'While', 'With', 'What', 'Will',
		'Would', 'Could', 'Should', 'Have', 'From', 'Into', 'About',
		'After', 'Before', 'Between', 'Under', 'Over', 'Some', 'Such',
		'Each', 'Every', 'Also', 'Here', 'Just', 'More', 'Most', 'Much',
		'Many', 'Other', 'Only', 'Still', 'Very', 'Well', 'However',
		'But', 'And', 'For', 'Not', 'You', 'All', 'Can', 'Her', 'His',
		'How', 'Its', 'May', 'New', 'Now', 'Old', 'See', 'Way', 'Who',
		'Did', 'Get', 'Has', 'Him', 'Let', 'Say', 'She', 'Too', 'Use',
		'Are', 'Our', 'Out', 'Was', 'One', 'Two',
	];

	/**
	 * Organisation suffixes used to detect company names.
	 *
	 * @var string[]
	 */
	private const ORG_SUFFIXES = [
		'Inc', 'Inc.', 'Ltd', 'Ltd.', 'LLC', 'SA', 'S.A.', 'S.A',
		'sp. z o.o.', 'Sp. z o.o.', 'sp.z o.o.', 'GmbH', 'AG',
		'Corp', 'Corp.', 'Corporation', 'Foundation', 'Group',
		'Company', 'Co.', 'Partners', 'Association', 'Institute',
	];

	/**
	 * Extract named entities from a WordPress post.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array{
	 *     persons:       list<array{name: string, type: string, frequency: int, positions: int}>,
	 *     organizations: list<array{name: string, type: string, frequency: int, positions: int}>,
	 *     places:        list<array{name: string, type: string, frequency: int, positions: int}>,
	 *     products:      list<array{name: string, type: string, frequency: int, positions: int}>,
	 * }
	 */
	/**
	 * Extract entities from raw content (no WP post required).
	 *
	 * Returns a flat list of entities with schema.org-style `@type` (Person /
	 * Organization / Place / Product). Each entity also retains its lowercase
	 * `type` for backwards compatibility with existing internal consumers.
	 *
	 * @param string $content
	 * @return list<array{name: string, '@type': string, type: string, frequency: int, positions: int}>
	 */
	public function extract( string $content ): array {
		$content = wp_strip_all_tags( $content );

		if ( '' === trim( $content ) ) {
			return [];
		}

		if ( mb_strlen( $content ) > 50000 ) {
			$content = mb_substr( $content, 0, 50000 );
		}

		$persons       = $this->detect_persons( $content );
		$organizations = $this->detect_organizations( $content );
		$places        = $this->detect_places( $content );
		$products      = $this->detect_products( $content );

		$org_names   = array_map( fn( array $e ): string => mb_strtolower( $e['name'] ), $organizations );
		$place_names = array_map( fn( array $e ): string => mb_strtolower( $e['name'] ), $places );
		$prod_names  = array_map( fn( array $e ): string => mb_strtolower( $e['name'] ), $products );

		$persons = array_values( array_filter(
			$persons,
			fn( array $e ): bool => ! in_array( mb_strtolower( $e['name'] ), [ ...$org_names, ...$place_names, ...$prod_names ], true ),
		) );

		$type_map = [
			'person'       => 'Person',
			'organization' => 'Organization',
			'place'        => 'Place',
			'product'      => 'Product',
		];

		$flat = [];
		foreach ( [ $persons, $organizations, $places, $products ] as $bucket ) {
			foreach ( $bucket as $entity ) {
				$entity['@type'] = $type_map[ $entity['type'] ] ?? ucfirst( $entity['type'] );
				$flat[] = $entity;
			}
		}
		return $flat;
	}

	public function extract_from_post( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return $this->empty_result();
		}

		$content = wp_strip_all_tags( $post->post_content );

		// Limit content to 50KB to prevent memory issues on very long posts
		if ( mb_strlen( $content ) > 50000 ) {
			$content = mb_substr( $content, 0, 50000 );
			if ( class_exists( 'Ligase_Logger' ) ) {
				Ligase_Logger::warning( 'NER content truncated to 50KB', [ 'post_id' => $post_id ] );
			}
		}

		if ( '' === trim( $content ) ) {
			return $this->empty_result();
		}

		$persons       = $this->detect_persons( $content );
		$organizations = $this->detect_organizations( $content );
		$places        = $this->detect_places( $content );
		$products      = $this->detect_products( $content );

		// Remove entities that were already captured in a more specific category.
		$org_names   = array_map( fn( array $e ): string => mb_strtolower( $e['name'] ), $organizations );
		$place_names = array_map( fn( array $e ): string => mb_strtolower( $e['name'] ), $places );
		$prod_names  = array_map( fn( array $e ): string => mb_strtolower( $e['name'] ), $products );

		$persons = array_values( array_filter(
			$persons,
			fn( array $e ): bool => ! in_array( mb_strtolower( $e['name'] ), [ ...$org_names, ...$place_names, ...$prod_names ], true ),
		) );

		return [
			'persons'       => $persons,
			'organizations' => $organizations,
			'places'        => $places,
			'products'      => $products,
		];
	}

	// ------------------------------------------------------------------
	// Detection strategies
	// ------------------------------------------------------------------

	/**
	 * Detect person names: 2-3 consecutive capitalised words.
	 *
	 * @param string $content Plain-text content.
	 * @return list<array{name: string, type: string, frequency: int, positions: int}>
	 */
	private function detect_persons( string $content ): array {
		// Match 2-3 consecutive capitalised words (Unicode-aware).
		$pattern = '/(?<![\\w\x{00C0}-\x{024F}])(\p{Lu}\p{L}+(?:\s+\p{Lu}\p{L}+){1,2})(?![\\w\x{00C0}-\x{024F}])/u';

		preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE );

		$candidates = $this->collect_matches( $matches[1], 'person' );
		$candidates = $this->filter_stop_phrases( $candidates );

		// Exclude candidates that look like organisation names.
		$candidates = array_filter(
			$candidates,
			fn( array $e ): bool => ! $this->looks_like_organization( $e['name'] ),
		);

		return array_values( $this->deduplicate( $candidates ) );
	}

	/**
	 * Detect organisation names by suffix keywords.
	 *
	 * @param string $content Plain-text content.
	 * @return list<array{name: string, type: string, frequency: int, positions: int}>
	 */
	private function detect_organizations( string $content ): array {
		$escaped_suffixes = array_map(
			fn( string $s ): string => preg_quote( $s, '/' ),
			self::ORG_SUFFIXES,
		);
		$suffix_alt = implode( '|', $escaped_suffixes );

		// One or more capitalised words followed by a known suffix.
		$pattern = '/(?<![\\w\x{00C0}-\x{024F}])((?:\p{Lu}\p{L}+\s+)+(?:' . $suffix_alt . '))(?![\\w\x{00C0}-\x{024F}])/u';

		preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE );

		$candidates = $this->collect_matches( $matches[1], 'organization' );

		return array_values( $this->deduplicate( $candidates ) );
	}

	/**
	 * Detect place names preceded by locative prepositions (Polish & English).
	 *
	 * @param string $content Plain-text content.
	 * @return list<array{name: string, type: string, frequency: int, positions: int}>
	 */
	private function detect_places( string $content ): array {
		// Polish prepositions: w, na, z, do, we, ze, nad, pod, przy
		// English prepositions: in, at, from, near
		// "to" intentionally REMOVED — in Polish it's a demonstrative pronoun ("this/that")
		// that triggers false-positive places on every sentence beginning with "To ...".
		// In English it's rarely a locative preposition anyway.
		$prepositions = 'w|we|na|z|ze|do|nad|pod|przy|in|at|from|near';

		$pattern = '/(?<=\b(?:' . $prepositions . ')\s)((?:\p{Lu}\p{L}+)(?:\s+\p{Lu}\p{L}+){0,2})/u';

		preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE );

		$candidates = $this->collect_matches( $matches[1], 'place' );
		$candidates = $this->filter_stop_phrases( $candidates );

		return array_values( $this->deduplicate( $candidates ) );
	}

	/**
	 * Detect product / tool mentions:
	 * - Words containing (R) or (TM) symbols
	 * - CamelCase / mixed-case brand names (e.g. WordPress, JavaScript, iPhone)
	 *
	 * @param string $content Plain-text content.
	 * @return list<array{name: string, type: string, frequency: int, positions: int}>
	 */
	private function detect_products( string $content ): array {
		$candidates = [];

		// 1. Trademarked names: word followed by or preceded by (R)/(TM).
		$tm_pattern = '/(?<![\\w\x{00C0}-\x{024F}])(\p{L}[\p{L}\p{N}]*[\x{00AE}\x{2122}]|\p{L}[\p{L}\p{N}]*\s?[\x{00AE}\x{2122}])(?![\\w\x{00C0}-\x{024F}])/u';
		preg_match_all( $tm_pattern, $content, $tm_matches, PREG_OFFSET_CAPTURE );

		foreach ( $tm_matches[1] as $match ) {
			$name = trim( (string) $match[0], " \t\n\r\0\x0B" );
			$name = rtrim( $name, "\u{00AE}\u{2122}" );
			$name = trim( $name );

			if ( mb_strlen( $name ) >= 2 ) {
				$candidates[] = [
					'name'      => $name,
					'type'      => 'product',
					'frequency' => 1,
					'positions' => (int) $match[1],
				];
			}
		}

		// 2. Mixed-case brand words: lowercase followed by uppercase inside the word.
		$camel_pattern = '/(?<![\\w\x{00C0}-\x{024F}])(\p{L}+\p{Ll}\p{Lu}\p{L}*)(?![\\w\x{00C0}-\x{024F}])/u';
		preg_match_all( $camel_pattern, $content, $camel_matches, PREG_OFFSET_CAPTURE );

		foreach ( $camel_matches[1] as $match ) {
			$name = (string) $match[0];

			if ( mb_strlen( $name ) >= 3 ) {
				$candidates[] = [
					'name'      => $name,
					'type'      => 'product',
					'frequency' => 1,
					'positions' => (int) $match[1],
				];
			}
		}

		// 3. Well-known tech brand patterns: capital letter followed by lowercase,
		//    appearing as standalone words of 3+ chars that start uppercase and
		//    contain at least one special pattern (e.g. "WordPress", "YouTube").
		$brand_pattern = '/(?<![\\w\x{00C0}-\x{024F}])([A-Z][a-z]+[A-Z][a-zA-Z]*)(?![\\w\x{00C0}-\x{024F}])/u';
		preg_match_all( $brand_pattern, $content, $brand_matches, PREG_OFFSET_CAPTURE );

		foreach ( $brand_matches[1] as $match ) {
			$name = (string) $match[0];

			if ( mb_strlen( $name ) >= 3 ) {
				$candidates[] = [
					'name'      => $name,
					'type'      => 'product',
					'frequency' => 1,
					'positions' => (int) $match[1],
				];
			}
		}

		$candidates = $this->filter_stop_phrases( $candidates );

		return array_values( $this->deduplicate( $candidates ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Convert raw PREG_OFFSET_CAPTURE matches into entity arrays.
	 *
	 * @param array<int, array{0: string, 1: int}> $matches  Regex matches with offsets.
	 * @param string                                $type     Entity type label.
	 * @return list<array{name: string, type: string, frequency: int, positions: int}>
	 */
	private function collect_matches( array $matches, string $type ): array {
		$entities = [];

		foreach ( $matches as $match ) {
			$name = trim( (string) $match[0] );

			if ( '' === $name || mb_strlen( $name ) < 2 ) {
				continue;
			}

			$entities[] = [
				'name'      => $name,
				'type'      => $type,
				'frequency' => 1,
				'positions' => (int) $match[1],
			];
		}

		return $entities;
	}

	/**
	 * Deduplicate entities by normalised (lowercased) name.
	 *
	 * Keeps the version that appeared first, sums frequencies.
	 *
	 * @param list<array{name: string, type: string, frequency: int, positions: int}> $entities Raw entity list.
	 * @return list<array{name: string, type: string, frequency: int, positions: int}>
	 */
	private function deduplicate( array $entities ): array {
		/** @var array<string, array{name: string, type: string, frequency: int, positions: int}> $seen */
		$seen = [];

		foreach ( $entities as $entity ) {
			// Use a Polish-stem-aware key so inflected variants of the same name
			// ("Jan Kowalski", "Jana Kowalskiego", "Janowi Kowalskiemu") deduplicate
			// to a single entity. Without this, the same person becomes 3 entities
			// with frequency=1 each, missing the threshold for Wikidata scheduling.
			$key = $this->stem_key( $entity['name'] );

			if ( isset( $seen[ $key ] ) ) {
				++$seen[ $key ]['frequency'];
			} else {
				$seen[ $key ] = $entity;
			}
		}

		return array_values( $seen );
	}

	/**
	 * Polish-aware normalisation key for deduplication. Lowercases, then strips a
	 * single common inflectional ending from each word. Returns the joined stems.
	 *
	 * Not a real lemmatizer — just enough to fold the most common cases:
	 * genitive -a/-i/-y/-ego/-iej, dative -owi/-emu, accusative -ę/-ą,
	 * instrumental -em/-ą/-ami, locative -u/-e/-ach.
	 */
	private function stem_key( string $name ): string {
		$lower = mb_strtolower( $name );
		$words = preg_split( '/\s+/u', $lower ) ?: [];
		$endings = [ 'owi', 'ego', 'emu', 'iej', 'ami', 'ach', 'om', 'em', 'ie', 'iu', 'ej', 'go', 'mu', 'ą', 'ę', 'a', 'i', 'y', 'o', 'u', 'e' ];
		$stems = [];
		foreach ( $words as $word ) {
			$stem = $word;
			foreach ( $endings as $ending ) {
				$elen = mb_strlen( $ending );
				if ( mb_strlen( $word ) - $elen >= 3 && mb_substr( $word, -$elen ) === $ending ) {
					$stem = mb_substr( $word, 0, -$elen );
					break;
				}
			}
			$stems[] = $stem;
		}
		return implode( ' ', $stems );
	}

	/**
	 * Remove candidates whose name is a known stop-word / stop-phrase.
	 *
	 * @param list<array{name: string, type: string, frequency: int, positions: int}> $entities Candidate entities.
	 * @return list<array{name: string, type: string, frequency: int, positions: int}>
	 */
	private function filter_stop_phrases( array $entities ): array {
		$stop_lower = array_map( 'mb_strtolower', self::POLISH_STOP_WORDS );
		$stop_lower = array_merge(
			$stop_lower,
			array_map( 'mb_strtolower', self::ENGLISH_STOP_WORDS ),
		);
		$stop_set = array_flip( $stop_lower );

		return array_values( array_filter(
			$entities,
			function ( array $entity ) use ( $stop_set ): bool {
				$lower = mb_strtolower( $entity['name'] );

				// Reject single stop-words.
				if ( isset( $stop_set[ $lower ] ) ) {
					return false;
				}

				// Reject multi-word phrases where every word is a stop-word.
				$words     = preg_split( '/\s+/u', $lower );
				$all_stops = true;

				foreach ( $words as $word ) {
					if ( ! isset( $stop_set[ $word ] ) ) {
						$all_stops = false;
						break;
					}
				}

				return ! $all_stops;
			},
		) );
	}

	/**
	 * Check whether a phrase looks like an organisation name.
	 *
	 * @param string $name The candidate name.
	 */
	private function looks_like_organization( string $name ): bool {
		foreach ( self::ORG_SUFFIXES as $suffix ) {
			if ( str_ends_with( $name, $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return an empty result set.
	 *
	 * @return array{persons: list<never>, organizations: list<never>, places: list<never>, products: list<never>}
	 */
	private function empty_result(): array {
		return [
			'persons'       => [],
			'organizations' => [],
			'places'        => [],
			'products'      => [],
		];
	}
}
