<?php
/**
 * Ligase_Field_Resolver
 *
 * Walks a Ligase_Field_Contract for a given (type, post_id) and:
 *   - tries each `sources` chain in order until one yields a non-empty value,
 *   - sanitizes each value per the contract's `sanitize` rule,
 *   - assembles values into a nested node (using `_containers` for @type stamps),
 *   - reports per-field state (auto / manual / missing_required / missing_optional),
 *   - reports overall eligibility for rich result (any required field missing = false).
 *
 * Critical invariant: this is the ONLY place that knows how to fetch data from
 * WP/Woo/NER. Type-classes stay rendering-only; the resolver stays single source
 * of fetching logic.
 *
 * @package Ligase
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

final class Ligase_Field_Resolver {

	/**
	 * Resolve a schema node for a post.
	 *
	 * @return array{node:array,status:array<string,array>,eligible:bool,missing_required:string[]}
	 */
	public function resolve( string $type, int $post_id ): array {
		$contract = Ligase_Field_Contract::get( $type );
		$fields   = $contract['fields'] ?? array();
		$node     = array();
		$status   = array();
		$missing  = array();

		$overrides = (array) get_post_meta( $post_id, '_ligase_override', true );
		$type_overrides = is_array( $overrides[ $type ] ?? null ) ? $overrides[ $type ] : array();

		foreach ( $fields as $key => $def ) {
			[ $value, $source ] = $this->try_sources( $def['sources'] ?? array(), $key, $post_id, $type_overrides );
			$level = $def['level'] ?? 'optional';

			if ( $this->is_empty_value( $value ) ) {
				$state = ( $level === 'required' ) ? 'missing_required' : 'missing_' . $level;
				$status[ $key ] = array(
					'level'  => $level,
					'state'  => $state,
					'source' => null,
					'value'  => null,
					'label'  => $def['label'] ?? $key,
				);
				if ( $level === 'required' ) {
					$missing[] = $key;
				}
				continue;
			}

			$value = $this->sanitize( $value, $def );
			if ( isset( $def['maxlen'] ) && is_string( $value ) && mb_strlen( $value ) > (int) $def['maxlen'] ) {
				// Ellipsis indicator preserved from BlogPosting headline policy.
				$value = mb_substr( $value, 0, (int) $def['maxlen'] - 1 ) . '…';
			}

			$state = str_starts_with( (string) $source, 'manual:' ) ? 'manual' : 'auto';

			$this->set_by_path( $node, $key, $value );
			$status[ $key ] = array(
				'level'  => $level,
				'state'  => $state,
				'source' => $source,
				'value'  => is_array( $value ) ? '[…]' : (string) $value,
				'label'  => $def['label'] ?? $key,
			);
		}

		// Stamp @type on every container path that has been populated.
		foreach ( ( $contract['_containers'] ?? array() ) as $path => $container_type ) {
			if ( $this->path_has_content( $node, $path ) ) {
				$this->set_by_path( $node, $path . '.@type', $container_type, /* preserve */ true );
			}
		}

		// Top-level @type and @id (id is path-stable; type-classes append a fragment).
		$node = array_merge(
			array(
				'@type' => $type,
				'@id'   => esc_url( get_permalink( $post_id ) ) . '#' . strtolower( $type ),
			),
			$node
		);

		return array(
			'node'             => $node,
			'status'           => $status,
			'eligible'         => empty( $missing ),
			'missing_required' => $missing,
		);
	}

	// ------------------------------------------------------------------------
	// Source chain
	// ------------------------------------------------------------------------

	/**
	 * Try sources in order; return [ value, source_id ] for the first non-empty.
	 *
	 * @return array{0:mixed,1:?string}
	 */
	private function try_sources( array $sources, string $key, int $post_id, array $overrides ): array {
		foreach ( $sources as $src ) {
			$value = $this->resolve_source( $src, $key, $post_id, $overrides );
			if ( ! $this->is_empty_value( $value ) ) {
				return array( $value, $src );
			}
		}
		return array( null, null );
	}

	/**
	 * Resolve a single source token to a value. Returns null/empty on failure
	 * so the resolver moves to the next source in the chain.
	 *
	 * @return mixed
	 */
	private function resolve_source( string $src, string $key, int $post_id, array $overrides ) {
		// manual: — editor override stored in _ligase_override[$type][$key]
		if ( 'manual:' === $src ) {
			return $overrides[ $key ] ?? null;
		}

		// opt:KEY — Ligase global options (ligase_options array)
		if ( str_starts_with( $src, 'opt:' ) ) {
			return $this->opt_get( substr( $src, 4 ) );
		}

		// ref:author_id — author @id reference for graph linking
		if ( 'ref:author_id' === $src ) {
			$author_id = (int) get_post_field( 'post_author', $post_id );
			if ( $author_id <= 0 ) {
				return null;
			}
			return array( array( '@id' => home_url( '/#author-' . $author_id ) ) );
		}

		// post:* — WordPress core data
		if ( str_starts_with( $src, 'post:' ) ) {
			return $this->resolve_post_source( substr( $src, 5 ), $post_id );
		}

		// wc:* — WooCommerce product data (gracefully no-op when WC not active)
		if ( str_starts_with( $src, 'wc:' ) ) {
			return $this->resolve_wc_source( substr( $src, 3 ), $post_id );
		}

		// ner:entities — NER pipeline output (about/mentions with sameAs)
		if ( 'ner:entities' === $src ) {
			return $this->resolve_ner_entities( $post_id );
		}

		// derive:* — computed values
		if ( str_starts_with( $src, 'derive:' ) ) {
			return $this->resolve_derived( substr( $src, 7 ), $post_id );
		}

		return null;
	}

	private function resolve_post_source( string $what, int $post_id ) {
		switch ( $what ) {
			case 'title':     return get_the_title( $post_id );
			case 'excerpt':   return wp_strip_all_tags( get_the_excerpt( $post_id ) );
			case 'permalink': return get_permalink( $post_id );
			case 'date':      return get_post_time( 'c', true, $post_id );
			case 'modified':  return get_post_modified_time( 'c', true, $post_id );
			case 'thumbnail':
				$tid = get_post_thumbnail_id( $post_id );
				if ( ! $tid ) { return null; }
				$src = wp_get_attachment_image_src( $tid, 'full' );
				return $src ? $src[0] : null;
			case 'thumbnail_set':
				return $this->build_thumbnail_set( $post_id );
			case 'primary_category':
				$cats = get_the_category( $post_id );
				return ( ! empty( $cats ) && is_array( $cats ) ) ? (string) $cats[0]->name : null;
		}
		return null;
	}

	private function resolve_wc_source( string $what, int $post_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		switch ( $what ) {
			case 'name':         return $product->get_name();
			case 'price':
				$price = $product->get_price();
				return ( $price === '' || $price === null ) ? null : (float) $price;
			case 'currency':     return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null;
			case 'sku':          return $product->get_sku() ?: null;
			case 'gtin':
				// WC 9.4+ adds a global unique identifier field.
				$gtin = $product->get_meta( '_global_unique_id', true );
				return $gtin ?: null;
			case 'availability':
				$stock = $product->get_stock_status();
				$map   = array(
					'instock'     => 'https://schema.org/InStock',
					'outofstock'  => 'https://schema.org/OutOfStock',
					'onbackorder' => 'https://schema.org/BackOrder',
				);
				// Unknown stock status (3rd-party plugins add 'preorder', 'discontinued')
				// → return null. Previously defaulted to InStock which is misleading-info
				// → manual-action risk.
				return $map[ $stock ] ?? null;
			case 'image':
				$tid = $product->get_image_id();
				if ( ! $tid ) { return null; }
				$src = wp_get_attachment_image_src( $tid, 'full' );
				return $src ? $src[0] : null;
			case 'description':
				$d = $product->get_short_description() ?: $product->get_description();
				return $d ? wp_strip_all_tags( $d ) : null;
			case 'rating_value':
				$avg = $product->get_average_rating();
				return ( $avg === '' || $avg === '0' ) ? null : (float) $avg;
			case 'rating_count':
				$n = $product->get_review_count();
				return $n > 0 ? (int) $n : null;
		}
		return null;
	}

	/**
	 * NER pipeline integration.
	 *
	 * Merges entities from three places (in this order, dedup by lowercased name):
	 *   1. _ligase_about_entities   — manual/curated entities (highest trust)
	 *   2. _ligase_ner_api_results  — LLM NER cron output (Ligase_NER_API)
	 *   3. _ligase_wikidata_suggestions — entities with a single high-confidence Wikidata hit
	 *
	 * Each entity is reshaped to schema.org Thing with sameAs to Wikidata when known.
	 * Returns null when no entities exist so the resolver moves on to the next source.
	 *
	 * @return array<int,array{@type:string,name:string,sameAs?:string}>|null
	 */
	private function resolve_ner_entities( int $post_id ): ?array {
		$merged = array();

		// 1. Curated entities (existing pipeline output)
		foreach ( (array) get_post_meta( $post_id, '_ligase_about_entities', true ) as $e ) {
			if ( ! is_array( $e ) || empty( $e['name'] ) ) { continue; }
			$key = mb_strtolower( (string) $e['name'] );
			$merged[ $key ] = array(
				'@type'  => 'Thing',
				'name'   => wp_strip_all_tags( (string) $e['name'] ),
				'sameAs' => esc_url_raw( (string) ( $e['sameAs'] ?? '' ) ),
			);
		}

		// 2. LLM NER results (organisations + persons + places + products buckets)
		$api = get_post_meta( $post_id, '_ligase_ner_api_results', true );
		if ( is_array( $api ) ) {
			$type_map = array(
				'persons'       => 'Person',
				'organizations' => 'Organization',
				'places'        => 'Place',
				'products'      => 'Product',
			);
			foreach ( $type_map as $bucket => $schema_type ) {
				foreach ( (array) ( $api[ $bucket ] ?? array() ) as $entity ) {
					if ( ! is_array( $entity ) || empty( $entity['name'] ) ) { continue; }
					$key = mb_strtolower( (string) $entity['name'] );
					if ( ! isset( $merged[ $key ] ) ) {
						$merged[ $key ] = array(
							'@type' => $schema_type,
							'name'  => wp_strip_all_tags( (string) $entity['name'] ),
						);
					}
				}
			}
		}

		// 3. Wikidata suggestions — add sameAs for entities matched in steps 1-2.
		$wikidata = (array) get_post_meta( $post_id, '_ligase_wikidata_suggestions', true );
		foreach ( $wikidata as $name => $matches ) {
			$key = mb_strtolower( (string) $name );
			if ( ! isset( $merged[ $key ] ) || ! empty( $merged[ $key ]['sameAs'] ) ) {
				continue;
			}
			if ( ! is_array( $matches ) || count( $matches ) !== 1 ) {
				continue; // single-confidence only — multi-match = ambiguous
			}
			$label = mb_strtolower( (string) ( $matches[0]['label'] ?? '' ) );
			if ( $label !== $key ) {
				continue; // require exact label match to avoid wrong-entity linking
			}
			$merged[ $key ]['sameAs'] = esc_url_raw( (string) ( $matches[0]['url'] ?? '' ) );
		}

		// Strip empty sameAs to keep the JSON clean
		foreach ( $merged as $k => $node ) {
			if ( isset( $node['sameAs'] ) && $node['sameAs'] === '' ) {
				unset( $merged[ $k ]['sameAs'] );
			}
		}

		return ! empty( $merged ) ? array_values( $merged ) : null;
	}

	private function resolve_derived( string $what, int $post_id ) {
		if ( $what === 'wordcount' ) {
			$content = (string) get_post_field( 'post_content', $post_id );
			return $content ? preg_match_all( '/[\p{L}\p{N}_]+/u', wp_strip_all_tags( $content ) ) : null;
		}
		if ( $what === 'comment_count' ) {
			$n = (int) get_comments_number( $post_id );
			return $n > 0 ? $n : null;
		}
		// returnPolicyCategory — Google's enum. We always emit a finite window because
		// merchantReturnDays is itself > 0 when the policy exists. Sites can override
		// per-product to MerchantReturnNotPermitted / MerchantReturnUnlimitedWindow.
		if ( $what === 'return_policy_category' ) {
			return 'https://schema.org/MerchantReturnFiniteReturnWindow';
		}
		// returnMethod — Polish e-commerce defaults to ReturnByMail (kurier zwrotny).
		if ( $what === 'return_method' ) {
			return 'https://schema.org/ReturnByMail';
		}
		// returnFees default when site-level option isn't set yet. Conservative choice:
		// ReturnShippingFees (klient płaci za odesłanie) is the most common PL setup.
		// Sites that offer free returns will set store_return_fees=FreeReturn explicitly.
		if ( $what === 'return_fees_default' ) {
			$opts = (array) get_option( 'ligase_options', array() );
			$fee  = (string) ( $opts['store_return_fees'] ?? 'ReturnShippingFees' );
			$allowed = array( 'FreeReturn', 'ReturnFeesCustomerResponsibility', 'ReturnShippingFees', 'RestockingFees' );
			if ( ! in_array( $fee, $allowed, true ) ) {
				$fee = 'ReturnShippingFees';
			}
			return 'https://schema.org/' . $fee;
		}
		// returnShippingFeesAmount.value — only meaningful when the policy charges
		// the customer for return shipping. For FreeReturn this should not emit,
		// which is achieved by returning null (resolver drops null nodes).
		if ( $what === 'return_fees_amount_value' ) {
			$opts = (array) get_option( 'ligase_options', array() );
			$fee  = (string) ( $opts['store_return_fees'] ?? '' );
			if ( $fee !== 'ReturnShippingFees' ) {
				return null;
			}
			$rate = isset( $opts['store_shipping_rate'] ) ? (float) $opts['store_shipping_rate'] : 0.0;
			if ( $rate <= 0 ) {
				return null;
			}
			return $rate;
		}
		// unitCode for QuantitativeValue used in handlingTime/transitTime — always DAY
		// in our context (the field-contract values are integer days).
		if ( $what === 'unit_code_day' ) {
			return 'DAY';
		}
		// refundType default — FullRefund (money back) matches Polish consumer law
		// (UPK 14 days right of withdrawal = full refund). Sites that only exchange
		// goods or issue store credit should override per-product or via filter.
		if ( $what === 'refund_type_default' ) {
			return 'https://schema.org/FullRefund';
		}
		return null;
	}

	private function build_thumbnail_set( int $post_id ): ?array {
		$tid = get_post_thumbnail_id( $post_id );
		if ( ! $tid ) { return null; }
		$variants = array(
			'ligase_16x9' => array( 1200, 675 ),
			'ligase_4x3'  => array( 1200, 900 ),
			'ligase_1x1'  => array( 1200, 1200 ),
		);
		$out  = array();
		$seen = array();
		foreach ( $variants as $size => [ $w, $h ] ) {
			$src = wp_get_attachment_image_src( $tid, $size );
			if ( $src && ! empty( $src[0] ) && ! isset( $seen[ $src[0] ] ) ) {
				$out[] = array(
					'@type'  => 'ImageObject',
					'url'    => esc_url( $src[0] ),
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
				);
				$seen[ $src[0] ] = true;
			}
		}
		if ( empty( $out ) ) {
			$full = wp_get_attachment_image_src( $tid, 'full' );
			if ( $full && ! empty( $full[0] ) ) {
				$out[] = array(
					'@type'  => 'ImageObject',
					'url'    => esc_url( $full[0] ),
					'width'  => (int) $full[1],
					'height' => (int) $full[2],
				);
			}
		}
		return $out ?: null;
	}

	// ------------------------------------------------------------------------
	// Sanitization
	// ------------------------------------------------------------------------

	/**
	 * @return mixed
	 */
	private function sanitize( $value, array $def ) {
		if ( $value === null ) {
			return null;
		}
		switch ( $def['sanitize'] ?? 'passthrough' ) {
			case 'text':
				if ( is_string( $value ) ) {
					return wp_strip_all_tags( $value );
				}
				return $value;
			case 'html':
				return is_string( $value ) ? wp_kses_post( $value ) : $value;
			case 'url':
				return is_string( $value ) ? esc_url_raw( $value ) : $value;
			case 'int':
				// Polish/EU number formatting: "1 299" or "1.299" → 1299, not 1.
				// Strip everything that isn't a digit / minus / decimal separator first.
				if ( is_string( $value ) ) {
					$clean = preg_replace( '/[^\d\-]/', '', $value );
					return (int) ( $clean === '' || $clean === '-' ? 0 : $clean );
				}
				return (int) $value;
			case 'float':
				// "1 299,90 zł" / "1.299,90" → 1299.9 (not 1.0 from naïve cast).
				// Strategy: strip currency symbols/spaces/letters, then normalise the
				// decimal separator. We assume the LAST comma or dot is the decimal,
				// any earlier are thousands separators to remove.
				if ( is_string( $value ) ) {
					$clean = preg_replace( '/[^\d,\.\-]/', '', $value );
					if ( $clean === '' || $clean === '-' ) {
						return 0.0;
					}
					$last_comma = strrpos( $clean, ',' );
					$last_dot   = strrpos( $clean, '.' );
					$dec_pos = false;
					if ( $last_comma !== false && $last_dot !== false ) {
						$dec_pos = max( $last_comma, $last_dot );
					} elseif ( $last_comma !== false ) {
						$dec_pos = $last_comma;
					} elseif ( $last_dot !== false ) {
						$dec_pos = $last_dot;
					}
					if ( $dec_pos === false ) {
						return (float) $clean;
					}
					$int_part  = preg_replace( '/[^\d\-]/', '', substr( $clean, 0, $dec_pos ) );
					$frac_part = preg_replace( '/[^\d]/', '',  substr( $clean, $dec_pos + 1 ) );
					return (float) ( $int_part . '.' . $frac_part );
				}
				return (float) $value;
			case 'date':
				if ( is_string( $value ) && $value !== '' ) {
					$ts = strtotime( $value );
					return $ts ? gmdate( 'c', $ts ) : null;
				}
				return null;
			case 'country':
				if ( is_string( $value ) ) {
					$c = strtoupper( preg_replace( '/[^A-Za-z]/', '', $value ) );
					return ( strlen( $c ) === 2 ) ? $c : null;
				}
				return null;
			case 'currency':
				if ( is_string( $value ) ) {
					$c = strtoupper( preg_replace( '/[^A-Za-z]/', '', $value ) );
					return ( strlen( $c ) === 3 ) ? $c : null;
				}
				return null;
			case 'passthrough':
			default:
				return $value;
		}
	}

	// ------------------------------------------------------------------------
	// Path utilities
	// ------------------------------------------------------------------------

	private function set_by_path( array &$root, string $path, $value, bool $preserve_existing = false ): void {
		$parts = explode( '.', $path );
		$ref   = &$root;
		while ( count( $parts ) > 1 ) {
			$head = array_shift( $parts );
			if ( ! isset( $ref[ $head ] ) || ! is_array( $ref[ $head ] ) ) {
				$ref[ $head ] = array();
			}
			$ref = &$ref[ $head ];
		}
		$leaf = $parts[0];
		if ( $preserve_existing && array_key_exists( $leaf, $ref ) ) {
			return;
		}
		$ref[ $leaf ] = $value;
	}

	private function path_has_content( array $root, string $path ): bool {
		$parts = explode( '.', $path );
		$ref   = $root;
		foreach ( $parts as $p ) {
			if ( ! is_array( $ref ) || ! array_key_exists( $p, $ref ) ) {
				return false;
			}
			$ref = $ref[ $p ];
		}
		return is_array( $ref ) ? ! empty( $ref ) : ! $this->is_empty_value( $ref );
	}

	private function is_empty_value( $v ): bool {
		if ( $v === null || $v === '' ) { return true; }
		if ( is_array( $v ) && empty( $v ) ) { return true; }
		return false;
	}

	// ------------------------------------------------------------------------
	// Options
	// ------------------------------------------------------------------------

	private function opt_get( string $key ) {
		$opts = (array) get_option( 'ligase_options', array() );
		return $opts[ $key ] ?? null;
	}
}
