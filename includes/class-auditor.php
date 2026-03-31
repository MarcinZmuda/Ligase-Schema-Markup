<?php
/**
 * Ligase - Schema Auditor
 *
 * Intercepts wp_head output, scores existing JSON-LD schema blocks,
 * and acts based on the configured mode (scan / supplement / replace).
 *
 * @package Ligase
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ligase_Auditor
 *
 * Detects, scores, and optionally replaces or supplements third-party
 * JSON-LD schema output in wp_head.
 */
class Ligase_Auditor {

	/**
	 * Minimum acceptable score (0-100).
	 *
	 * @var int
	 */
	private int $threshold;

	/**
	 * Operating mode: 'scan', 'supplement', or 'replace'.
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * Allowed operating modes.
	 *
	 * @var string[]
	 */
	private const ALLOWED_MODES = array( 'scan', 'supplement', 'replace' );

	/**
	 * Results collected during a buffer processing pass.
	 *
	 * @var array
	 */
	private array $results = array();

	/**
	 * Known SEO plugin slugs mapped to their main file.
	 *
	 * @var array<string, string>
	 */
	private const KNOWN_SEO_PLUGINS = array(
		'Yoast SEO'              => 'wordpress-seo/wp-seo.php',
		'Rank Math'              => 'seo-by-rank-math/rank-math.php',
		'All in One SEO'         => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
		'Schema Pro'             => 'wp-schema-pro/wp-schema-pro.php',
		'SEOPress'               => 'wp-seopress/seopress.php',
		'The SEO Framework'      => 'autodescription/autodescription.php',
		'Slim SEO'               => 'slim-seo/slim-seo.php',
		'Schema & Structured Data for WP' => 'developer-flavor-schema/developer-flavor-schema.php',
	);

	/**
	 * ISO 8601 pattern used for date validation.
	 *
	 * @var string
	 */
	private const ISO8601_PATTERN = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?([+-]\d{2}:\d{2}|Z)?)?$/';

	/**
	 * Constructor.
	 *
	 * @param int    $threshold Minimum acceptable score (0-100).
	 * @param string $mode      Operating mode: scan, supplement, or replace.
	 */
	public function __construct( int $threshold = 50, string $mode = 'supplement' ) {
		$this->threshold = max( 0, min( 100, $threshold ) );
		$this->mode      = in_array( $mode, self::ALLOWED_MODES, true ) ? $mode : 'supplement';
	}

	/**
	 * Hook into wp_head via output buffering.
	 *
	 * @return void
	 */
	public function intercept(): void {
		if ( ! is_singular() ) {
			return;
		}

		// Safety: don't nest output buffers
		if ( ob_get_level() > 3 ) {
			Ligase_Logger::warning( 'Too many output buffer levels, skipping auditor intercept' );
			return;
		}

		ob_start( array( $this, 'process_buffer' ) );
	}

	/**
	 * Process the captured wp_head buffer.
	 *
	 * Finds all JSON-LD script blocks, scores each one, and acts
	 * according to the current mode.
	 *
	 * @param string $buffer The captured output.
	 *
	 * @return string Modified (or unmodified) buffer.
	 */
	public function process_buffer( string $buffer ): string {
		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return $buffer;
		}

		$pattern = '/<script\s+type=["\']application\/ld\+json["\']\s*>(.*?)<\/script>/si';

		if ( ! preg_match_all( $pattern, $buffer, $matches, PREG_SET_ORDER ) ) {
			Ligase_Logger::info( "No JSON-LD blocks found for post {$post_id}." );
			return $buffer;
		}

		foreach ( $matches as $match ) {
			$full_tag = $match[0];
			$json_str = trim( $match[1] );
			$schema   = json_decode( $json_str, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Ligase_Logger::warning( "Invalid JSON-LD for post {$post_id}: " . json_last_error_msg() );
				continue;
			}

			$current_score = $this->score( $schema );
			$issues        = $this->collect_issues( $schema );
			$source_plugin = $this->detect_source_plugin( $schema );
			$schema_type   = $schema['@type'] ?? 'Unknown';

			$this->results[] = array(
				'post_id'       => $post_id,
				'score'         => $current_score,
				'issues'        => $issues,
				'source_plugin' => $source_plugin,
				'schema_type'   => $schema_type,
			);

			Ligase_Logger::info(
				sprintf(
					'Post %d: JSON-LD score %d/%d (threshold %d), type: %s, source: %s',
					$post_id,
					$current_score,
					100,
					$this->threshold,
					$schema_type,
					$source_plugin ?: 'unknown'
				)
			);

			if ( $current_score >= $this->threshold ) {
				continue;
			}

			switch ( $this->mode ) {
				case 'replace':
					// Store original for rollback.
					update_post_meta( $post_id, '_ligase_replaced_schema', wp_json_encode( $schema ) );
					// Signal Output class to generate its own schema.
					update_post_meta( $post_id, '_ligase_needs_own_schema', '1' );
					// Remove the old block from the buffer.
					$buffer = str_replace( $full_tag, '', $buffer );

					Ligase_Logger::info( "Replaced schema for post {$post_id} (score {$current_score})." );
					break;

				case 'supplement':
					$supplemented = $this->supplement_schema( $schema );
					$new_json     = wp_json_encode( $supplemented, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					$new_tag      = '<script type="application/ld+json">' . $new_json . '</script>';
					$buffer       = str_replace( $full_tag, $new_tag, $buffer );

					Ligase_Logger::info( "Supplemented schema for post {$post_id} (score {$current_score})." );
					break;

				case 'scan':
				default:
					// Read-only: results already collected above.
					Ligase_Logger::info( "Scan-only: post {$post_id} scored {$current_score}." );
					break;
			}
		}

		return $buffer;
	}

	/**
	 * Score a schema array on a 0-100 scale.
	 *
	 * @param array $schema Decoded JSON-LD schema.
	 *
	 * @return int Score clamped between 0 and 100.
	 */
	public function score( array $schema ): int {
		$points = 0;

		// Positive signals.
		if ( ! empty( $schema['headline'] ) ) {
			$points += 15;
		}

		if ( ! empty( $schema['datePublished'] ) ) {
			$points += 15;
		}

		if ( ! empty( $schema['dateModified'] ) ) {
			$points += 10;
		}

		if ( ! empty( $schema['author']['name'] ) || $this->nested_has( $schema, 'author', 'name' ) ) {
			$points += 15;
		}

		if ( ! empty( $schema['image'] ) ) {
			$points += 15;
		}

		if ( ! empty( $schema['publisher'] ) ) {
			$points += 10;
		}

		if ( ! empty( $schema['author']['@id'] ) || $this->nested_has( $schema, 'author', '@id' ) ) {
			$points += 10;
		}

		if ( ! empty( $schema['@id'] ) ) {
			$points += 5;
		}

		if ( ! empty( $schema['description'] ) ) {
			$points += 5;
		}

		// Penalties.
		if ( ! empty( $schema['headline'] ) && mb_strlen( $schema['headline'] ) > 110 ) {
			$points -= 20;
		}

		if ( ! empty( $schema['datePublished'] ) && ! $this->is_valid_iso8601( $schema['datePublished'] ) ) {
			$points -= 20;
		}

		if ( ! empty( $schema['dateModified'] ) && ! $this->is_valid_iso8601( $schema['dateModified'] ) ) {
			$points -= 20;
		}

		if ( $this->image_width_below( $schema, 696 ) ) {
			$points -= 10;
		}

		return max( 0, min( 100, $points ) );
	}

	/**
	 * Scan a single post and return audit results.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array{score: int, issues: array, source_plugin: string, schema_type: string}
	 */
	public function scan_post( int $post_id ): array {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			Ligase_Logger::warning( "Insufficient permissions to scan post {$post_id}." );
			return array(
				'score'         => 0,
				'issues'        => array( 'Insufficient permissions.' ),
				'source_plugin' => '',
				'schema_type'   => '',
			);
		}

		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return array(
				'score'         => 0,
				'issues'        => array( 'Post not found or not published.' ),
				'source_plugin' => '',
				'schema_type'   => '',
			);
		}

		// Render the post's head in isolation to capture JSON-LD.
		$schema_blocks = $this->get_jsonld_for_post( $post_id );

		if ( empty( $schema_blocks ) ) {
			Ligase_Logger::info( "No JSON-LD found for post {$post_id}." );
			return array(
				'score'         => 0,
				'issues'        => array( 'No JSON-LD schema found.' ),
				'source_plugin' => '',
				'schema_type'   => '',
			);
		}

		// Score the first (primary) schema block.
		$schema        = $schema_blocks[0];
		$current_score = $this->score( $schema );
		$issues        = $this->collect_issues( $schema );
		$source_plugin = $this->detect_source_plugin( $schema );
		$schema_type   = $schema['@type'] ?? 'Unknown';

		Ligase_Logger::info(
			sprintf( 'Scanned post %d: score %d, type %s, source %s.', $post_id, $current_score, $schema_type, $source_plugin ?: 'unknown' )
		);

		return array(
			'score'         => $current_score,
			'issues'        => $issues,
			'source_plugin' => $source_plugin,
			'schema_type'   => $schema_type,
		);
	}

	/**
	 * Scan all published posts.
	 *
	 * @return array Array of scan results keyed by post ID.
	 */
	public function scan_all_posts(): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			Ligase_Logger::warning( 'Insufficient permissions to scan all posts.' );
			return array();
		}

		$post_ids = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$results = array();

		foreach ( $post_ids as $pid ) {
			$results[ $pid ] = $this->scan_post( $pid );
		}

		Ligase_Logger::info( sprintf( 'Scanned %d published posts.', count( $results ) ) );

		return $results;
	}

	/**
	 * Replace schema for a single post.
	 *
	 * Stores original schema in post meta for rollback and sets the
	 * replacement flag consumed by the Output class.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool True on success.
	 */
	public function apply_replacement( int $post_id ): bool {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			Ligase_Logger::warning( "Insufficient permissions to replace schema on post {$post_id}." );
			return false;
		}

		$scan = $this->scan_post( $post_id );

		if ( $scan['score'] >= $this->threshold ) {
			Ligase_Logger::info( "Post {$post_id} score {$scan['score']} meets threshold; no replacement needed." );
			return false;
		}

		$schema_blocks = $this->get_jsonld_for_post( $post_id );

		// Atomic-ish update: store backup and flag together
		$backup_saved = update_post_meta( $post_id, '_ligase_replaced_schema', wp_json_encode( $schema_blocks[0] ?? $schema ) );
		if ( $backup_saved ) {
			update_post_meta( $post_id, '_ligase_needs_own_schema', '1' );
		} else {
			Ligase_Logger::error( 'Failed to save schema backup, skipping replacement flag', [ 'post_id' => $post_id ] );
			return false;
		}

		Ligase_Logger::info( "Marked post {$post_id} for schema replacement." );

		return true;
	}

	/**
	 * Batch-replace schema for multiple posts.
	 *
	 * @param int[] $post_ids Array of post IDs.
	 *
	 * @return array<int, bool> Results keyed by post ID.
	 */
	public function apply_all_replacements( array $post_ids ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			Ligase_Logger::warning( 'Insufficient permissions for batch replacement.' );
			return array();
		}

		$results = array();

		foreach ( $post_ids as $pid ) {
			$pid             = (int) $pid;
			$results[ $pid ] = $this->apply_replacement( $pid );
		}

		$success_count = count( array_filter( $results ) );
		Ligase_Logger::info( "Batch replacement complete: {$success_count}/" . count( $results ) . ' posts replaced.' );

		return $results;
	}

	/**
	 * Check and consume the replacement flag for a given post.
	 *
	 * Called by the Output class to determine whether Ligase should
	 * generate its own schema for this post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool True if the flag was set (and is now consumed).
	 */
	public function consume_replacement_flag( int $post_id ): bool {
		$flag = get_post_meta( $post_id, '_ligase_needs_own_schema', true );

		if ( '1' !== $flag ) {
			return false;
		}

		delete_post_meta( $post_id, '_ligase_needs_own_schema' );

		Ligase_Logger::info( "Consumed replacement flag for post {$post_id}." );

		return true;
	}

	/**
	 * Detect active SEO plugins that may output JSON-LD.
	 *
	 * @return array<string, string> Plugin name => version.
	 */
	public function get_detected_plugins(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$detected = array();

		foreach ( self::KNOWN_SEO_PLUGINS as $name => $file ) {
			if ( is_plugin_active( $file ) ) {
				$data              = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
				$detected[ $name ] = $data['Version'] ?? 'unknown';
			}
		}

		Ligase_Logger::info( 'Detected SEO plugins: ' . ( empty( $detected ) ? 'none' : implode( ', ', array_keys( $detected ) ) ) );

		return $detected;
	}

	/**
	 * Get collected results from the last buffer processing pass.
	 *
	 * @return array
	 */
	public function get_results(): array {
		return $this->results;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Supplement a schema array with missing fields from the current post.
	 *
	 * @param array $schema Original schema.
	 *
	 * @return array Merged schema.
	 */
	private function supplement_schema( array $schema ): array {
		$post = get_post();

		if ( ! $post ) {
			return $schema;
		}

		if ( empty( $schema['headline'] ) ) {
			$schema['headline'] = get_the_title( $post );
		}

		if ( empty( $schema['datePublished'] ) ) {
			$schema['datePublished'] = get_the_date( 'c', $post );
		}

		if ( empty( $schema['dateModified'] ) ) {
			$schema['dateModified'] = get_the_modified_date( 'c', $post );
		}

		if ( empty( $schema['description'] ) ) {
			$schema['description'] = wp_trim_words( $post->post_excerpt ?: $post->post_content, 30, '...' );
		}

		if ( empty( $schema['author'] ) ) {
			$author_id        = (int) $post->post_author;
			$schema['author'] = array(
				array(
					'@type' => 'Person',
					'@id'   => home_url( '/#author-' . $author_id ),
					'name'  => get_the_author_meta( 'display_name', $author_id ),
				),
			);
		}

		if ( empty( $schema['image'] ) && has_post_thumbnail( $post ) ) {
			$img_id  = get_post_thumbnail_id( $post );
			$img_src = wp_get_attachment_image_src( $img_id, 'full' );

			if ( $img_src ) {
				$schema['image'] = array(
					'@type'  => 'ImageObject',
					'url'    => $img_src[0],
					'width'  => $img_src[1],
					'height' => $img_src[2],
				);
			}
		}

		if ( empty( $schema['publisher'] ) ) {
			$schema['publisher'] = array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			);
		}

		return $schema;
	}

	/**
	 * Collect human-readable issues for a schema.
	 *
	 * @param array $schema Decoded JSON-LD.
	 *
	 * @return string[]
	 */
	private function collect_issues( array $schema ): array {
		$issues = array();

		if ( empty( $schema['headline'] ) ) {
			$issues[] = 'Missing headline.';
		} elseif ( mb_strlen( $schema['headline'] ) > 110 ) {
			$issues[] = 'Headline exceeds 110 characters.';
		}

		if ( empty( $schema['datePublished'] ) ) {
			$issues[] = 'Missing datePublished.';
		} elseif ( ! $this->is_valid_iso8601( $schema['datePublished'] ) ) {
			$issues[] = 'datePublished is not valid ISO 8601.';
		}

		if ( empty( $schema['dateModified'] ) ) {
			$issues[] = 'Missing dateModified.';
		} elseif ( ! $this->is_valid_iso8601( $schema['dateModified'] ) ) {
			$issues[] = 'dateModified is not valid ISO 8601.';
		}

		if ( empty( $schema['author']['name'] ) && ! $this->nested_has( $schema, 'author', 'name' ) ) {
			$issues[] = 'Missing author name.';
		}

		if ( empty( $schema['image'] ) ) {
			$issues[] = 'Missing image.';
		} elseif ( $this->image_width_below( $schema, 696 ) ) {
			$issues[] = 'Image width below 696px.';
		}

		if ( empty( $schema['publisher'] ) ) {
			$issues[] = 'Missing publisher.';
		}

		if ( empty( $schema['author']['@id'] ) && ! $this->nested_has( $schema, 'author', '@id' ) ) {
			$issues[] = 'Missing author @id.';
		}

		if ( empty( $schema['@id'] ) ) {
			$issues[] = 'Missing @id.';
		}

		if ( empty( $schema['description'] ) ) {
			$issues[] = 'Missing description.';
		}

		return $issues;
	}

	/**
	 * Try to detect which plugin generated a schema block.
	 *
	 * @param array $schema Decoded JSON-LD.
	 *
	 * @return string Plugin name or empty string.
	 */
	private function detect_source_plugin( array $schema ): string {
		$json = wp_json_encode( $schema );

		if ( str_contains( $json, 'yoast' ) || str_contains( $json, 'wpseo' ) ) {
			return 'Yoast SEO';
		}

		if ( str_contains( $json, 'rank-math' ) || str_contains( $json, 'rankmath' ) ) {
			return 'Rank Math';
		}

		if ( str_contains( $json, 'aioseo' ) ) {
			return 'All in One SEO';
		}

		if ( str_contains( $json, 'schema-pro' ) ) {
			return 'Schema Pro';
		}

		if ( str_contains( $json, 'seopress' ) ) {
			return 'SEOPress';
		}

		return '';
	}

	/**
	 * Get parsed JSON-LD blocks for a post by rendering its head.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array[] Array of decoded JSON-LD schemas.
	 */
	private function get_jsonld_for_post( int $post_id ): array {
		// Set up global post state.
		global $post;
		$original_post = $post;
		$post          = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		ob_start();
		do_action( 'wp_head' );
		$head = ob_get_clean();

		// Restore original post state.
		$post = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $original_post ) {
			setup_postdata( $original_post );
		} else {
			wp_reset_postdata();
		}

		$pattern = '/<script\s+type=["\']application\/ld\+json["\']\s*>(.*?)<\/script>/si';

		if ( ! preg_match_all( $pattern, $head, $matches ) ) {
			return array();
		}

		$schemas = array();

		foreach ( $matches[1] as $json_str ) {
			$decoded = json_decode( trim( $json_str ), true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				$schemas[] = $decoded;
			}
		}

		return $schemas;
	}

	/**
	 * Validate an ISO 8601 date string.
	 *
	 * @param string $date Date string.
	 *
	 * @return bool
	 */
	private function is_valid_iso8601( string $date ): bool {
		return (bool) preg_match( self::ISO8601_PATTERN, $date );
	}

	/**
	 * Check if image width is below a threshold.
	 *
	 * @param array $schema   Decoded schema.
	 * @param int   $min_width Minimum width in pixels.
	 *
	 * @return bool True if image exists and its width is below the minimum.
	 */
	private function image_width_below( array $schema, int $min_width ): bool {
		if ( empty( $schema['image'] ) ) {
			return false;
		}

		$image = $schema['image'];

		if ( is_array( $image ) && isset( $image['width'] ) ) {
			return (int) $image['width'] < $min_width;
		}

		return false;
	}

	/**
	 * Check for a nested key in author (handles array of authors).
	 *
	 * @param array  $schema The schema array.
	 * @param string $parent Parent key (e.g. 'author').
	 * @param string $key    Child key to look for.
	 *
	 * @return bool
	 */
	private function nested_has( array $schema, string $parent, string $key ): bool {
		if ( empty( $schema[ $parent ] ) ) {
			return false;
		}

		$value = $schema[ $parent ];

		// Single object.
		if ( isset( $value[ $key ] ) && '' !== $value[ $key ] ) {
			return true;
		}

		// Array of objects.
		if ( is_array( $value ) && isset( $value[0] ) ) {
			foreach ( $value as $item ) {
				if ( is_array( $item ) && ! empty( $item[ $key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
