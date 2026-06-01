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
	 * @deprecated 2.4.9 Live wp_head intercept was never wired in plugin bootstrap and
	 *             produced more confusion than value. All production auditor needs are
	 *             served by the batch-AJAX scan flow (admin → Audytor page), which calls
	 *             scan_all_posts() / apply_replacement() directly. This method is kept as
	 *             a no-op stub for backward compatibility with any external code that
	 *             still references it; it intentionally does nothing.
	 *
	 * @return void
	 */
	public function intercept(): void {
		// Intentional no-op. See deprecation note above. Use the batch-scan AJAX
		// endpoints (ligase_scan_all_posts / ligase_apply_audit_replacements) instead.
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
					$new_json     = wp_json_encode( $supplemented, JSON_UNESCAPED_UNICODE );
					// Defense in depth: scrub any literal </script> from the encoded JSON
					// before wrapping it back into a <script> tag.
					$new_json     = str_replace( [ '</', '<!--' ], [ '<\/', '<\!--' ], $new_json );
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
	 * Scoring rubric is type-aware: Article-family types are scored against an
	 * Article rubric (headline/datePublished/author/image/publisher); Event/Product/
	 * LocalBusiness/Recipe/FAQ etc. are scored against their own required fields.
	 * Using the Article rubric for every type would unfairly score Event at 0 and
	 * trigger destructive auto-replacement.
	 *
	 * @param array $schema Decoded JSON-LD schema.
	 *
	 * @return int Score clamped between 0 and 100.
	 */
	public function score( array $schema ): int {
		$type = '';
		if ( ! empty( $schema['@type'] ) ) {
			$type = is_array( $schema['@type'] ) ? (string) reset( $schema['@type'] ) : (string) $schema['@type'];
		}

		$article_types = [ 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report' ];

		if ( in_array( $type, $article_types, true ) || $type === '' ) {
			return $this->score_article( $schema );
		}

		switch ( $type ) {
			case 'Event':
				return $this->score_event( $schema );
			case 'Product':
				return $this->score_product( $schema );
			case 'LocalBusiness':
			case 'Restaurant':
			case 'Store':
			case 'Hotel':
				return $this->score_local_business( $schema );
			case 'Recipe':
				return $this->score_recipe( $schema );
			case 'FAQPage':
				return $this->score_faqpage( $schema );
			case 'HowTo':
				return $this->score_howto( $schema );
			case 'VideoObject':
				return $this->score_video( $schema );
			case 'Organization':
			case 'Person':
			case 'WebSite':
			case 'BreadcrumbList':
				return $this->score_generic_entity( $schema );
		}

		return $this->score_generic_entity( $schema );
	}

	private function score_article( array $schema ): int {
		$points = 0;
		if ( ! empty( $schema['headline'] ) )      { $points += 15; }
		if ( ! empty( $schema['datePublished'] ) ) { $points += 15; }
		if ( ! empty( $schema['dateModified'] ) )  { $points += 10; }
		if ( ! empty( $schema['author']['name'] ) || $this->nested_has( $schema, 'author', 'name' ) ) { $points += 15; }
		if ( ! empty( $schema['image'] ) )         { $points += 15; }
		if ( ! empty( $schema['publisher'] ) )     { $points += 10; }
		if ( ! empty( $schema['author']['@id'] ) || $this->nested_has( $schema, 'author', '@id' ) )   { $points += 10; }
		if ( ! empty( $schema['@id'] ) )           { $points += 5; }
		if ( ! empty( $schema['description'] ) )   { $points += 5; }

		if ( ! empty( $schema['headline'] ) && mb_strlen( $schema['headline'] ) > 110 ) { $points -= 20; }
		if ( ! empty( $schema['datePublished'] ) && ! $this->is_valid_iso8601( $schema['datePublished'] ) ) { $points -= 20; }
		if ( ! empty( $schema['dateModified'] ) && ! $this->is_valid_iso8601( $schema['dateModified'] ) )   { $points -= 20; }
		if ( $this->image_width_below( $schema, 696 ) ) { $points -= 10; }

		return max( 0, min( 100, $points ) );
	}

	private function score_event( array $schema ): int {
		$points = 0;
		if ( ! empty( $schema['name'] ) )                 { $points += 15; }
		if ( ! empty( $schema['startDate'] ) )            { $points += 20; }
		if ( ! empty( $schema['location'] ) )             { $points += 20; }
		if ( ! empty( $schema['eventAttendanceMode'] ) )  { $points += 10; }
		if ( ! empty( $schema['organizer'] ) )            { $points += 10; }
		if ( ! empty( $schema['offers'] ) )               { $points += 10; }
		if ( ! empty( $schema['image'] ) )                { $points += 10; }
		if ( ! empty( $schema['description'] ) )          { $points += 5;  }
		if ( ! empty( $schema['startDate'] ) && ! $this->is_valid_iso8601( $schema['startDate'] ) ) { $points -= 20; }
		return max( 0, min( 100, $points ) );
	}

	private function score_product( array $schema ): int {
		$points = 0;
		if ( ! empty( $schema['name'] ) )            { $points += 15; }
		if ( ! empty( $schema['image'] ) )           { $points += 15; }
		if ( ! empty( $schema['description'] ) )     { $points += 10; }
		if ( ! empty( $schema['offers'] ) )          { $points += 20; }
		if ( ! empty( $schema['brand'] ) )           { $points += 10; }
		if ( ! empty( $schema['sku'] ) || ! empty( $schema['gtin'] ) || ! empty( $schema['mpn'] ) ) { $points += 15; }
		if ( ! empty( $schema['aggregateRating'] ) || ! empty( $schema['review'] ) ) { $points += 15; }
		return max( 0, min( 100, $points ) );
	}

	private function score_local_business( array $schema ): int {
		$points = 0;
		if ( ! empty( $schema['name'] ) )                  { $points += 15; }
		if ( ! empty( $schema['address'] ) )               { $points += 20; }
		if ( ! empty( $schema['telephone'] ) )             { $points += 10; }
		if ( ! empty( $schema['url'] ) )                   { $points += 10; }
		if ( ! empty( $schema['openingHoursSpecification'] ) || ! empty( $schema['openingHours'] ) ) { $points += 15; }
		if ( ! empty( $schema['geo'] ) )                   { $points += 10; }
		if ( ! empty( $schema['image'] ) )                 { $points += 10; }
		if ( ! empty( $schema['priceRange'] ) )            { $points += 5;  }
		if ( ! empty( $schema['sameAs'] ) )                { $points += 5;  }
		return max( 0, min( 100, $points ) );
	}

	private function score_recipe( array $schema ): int {
		$points = 0;
		if ( ! empty( $schema['name'] ) )               { $points += 10; }
		if ( ! empty( $schema['image'] ) )              { $points += 15; }
		if ( ! empty( $schema['recipeIngredient'] ) )   { $points += 20; }
		if ( ! empty( $schema['recipeInstructions'] ) ) { $points += 20; }
		if ( ! empty( $schema['totalTime'] ) || ! empty( $schema['cookTime'] ) || ! empty( $schema['prepTime'] ) ) { $points += 10; }
		if ( ! empty( $schema['author'] ) )             { $points += 10; }
		if ( ! empty( $schema['nutrition'] ) )          { $points += 5;  }
		if ( ! empty( $schema['aggregateRating'] ) )    { $points += 10; }
		return max( 0, min( 100, $points ) );
	}

	private function score_faqpage( array $schema ): int {
		$points = 0;
		if ( ! empty( $schema['mainEntity'] ) && is_array( $schema['mainEntity'] ) ) {
			$n = count( $schema['mainEntity'] );
			$points += min( 60, $n * 15 ); // up to 4 visible Q&A
			$with_answer = 0;
			foreach ( $schema['mainEntity'] as $q ) {
				if ( ! empty( $q['acceptedAnswer']['text'] ) ) { ++$with_answer; }
			}
			$points += min( 30, $with_answer * 10 );
		}
		if ( ! empty( $schema['@id'] ) )       { $points += 5; }
		if ( ! empty( $schema['inLanguage'] ) ){ $points += 5; }
		return max( 0, min( 100, $points ) );
	}

	private function score_howto( array $schema ): int {
		$points = 0;
		if ( ! empty( $schema['name'] ) )  { $points += 15; }
		if ( ! empty( $schema['image'] ) ) { $points += 15; }
		if ( ! empty( $schema['step'] ) && is_array( $schema['step'] ) ) {
			$points += min( 50, count( $schema['step'] ) * 10 );
		}
		if ( ! empty( $schema['totalTime'] ) ) { $points += 10; }
		if ( ! empty( $schema['@id'] ) )       { $points += 10; }
		return max( 0, min( 100, $points ) );
	}

	private function score_video( array $schema ): int {
		$points = 0;
		if ( ! empty( $schema['name'] ) )         { $points += 15; }
		if ( ! empty( $schema['description'] ) )  { $points += 15; }
		if ( ! empty( $schema['thumbnailUrl'] ) ) { $points += 20; }
		if ( ! empty( $schema['uploadDate'] ) )   { $points += 15; }
		if ( ! empty( $schema['contentUrl'] ) || ! empty( $schema['embedUrl'] ) ) { $points += 20; }
		if ( ! empty( $schema['duration'] ) )     { $points += 10; }
		if ( ! empty( $schema['@id'] ) )          { $points += 5; }
		return max( 0, min( 100, $points ) );
	}

	private function score_generic_entity( array $schema ): int {
		$points = 0;
		if ( ! empty( $schema['@type'] ) )       { $points += 15; }
		if ( ! empty( $schema['name'] ) )        { $points += 20; }
		if ( ! empty( $schema['@id'] ) )         { $points += 15; }
		if ( ! empty( $schema['url'] ) )         { $points += 15; }
		if ( ! empty( $schema['description'] ) ) { $points += 10; }
		if ( ! empty( $schema['sameAs'] ) )      { $points += 15; }
		if ( ! empty( $schema['image'] ) || ! empty( $schema['logo'] ) ) { $points += 10; }
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
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
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
		$backup_payload = ! empty( $schema_blocks[0] ) ? $schema_blocks[0] : [];

		// Atomic-ish update: store backup and flag together
		$backup_saved = update_post_meta( $post_id, '_ligase_replaced_schema', wp_json_encode( $backup_payload ) );
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
	 * Apply supplement mode for a single post — additive only, never overwrites.
	 *
	 * Stores the fields Ligase would add into a meta key. The Output class reads this
	 * and emits an additional <script type="application/ld+json"> block alongside the
	 * existing competitor schema. Because supplements use stable @id references, Google
	 * (and AI engines) will merge them with the existing graph rather than treat them
	 * as duplicates.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public function apply_supplement( int $post_id ): bool {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			Ligase_Logger::warning( "Insufficient permissions to supplement schema on post {$post_id}." );
			return false;
		}

		$scan = $this->scan_post( $post_id );
		if ( $scan['score'] >= $this->threshold ) {
			return false;
		}

		$existing = $this->get_jsonld_for_post( $post_id );
		$existing = ! empty( $existing[0] ) ? $existing[0] : [];

		// Compute the diff: which fields would Ligase add that the existing schema lacks?
		$supplemented = $this->supplement_schema( $existing );
		$additions    = [];
		foreach ( $supplemented as $key => $value ) {
			if ( empty( $existing[ $key ] ) && ! empty( $value ) ) {
				$additions[ $key ] = $value;
			}
		}

		if ( empty( $additions ) ) {
			return false;
		}

		update_post_meta( $post_id, '_ligase_supplement_additions', wp_json_encode( $additions ) );
		update_post_meta( $post_id, '_ligase_supplement_mode', '1' );

		Ligase_Logger::info( "Supplement queued for post {$post_id}: " . implode( ',', array_keys( $additions ) ) );

		return true;
	}

	/**
	 * Restore previously-replaced schema for a post: clears the "needs own schema"
	 * flag and removes the backup (so the next page render reverts to whatever
	 * other plugin's schema is now produced).
	 *
	 * The backup meta is preserved as a separate restore payload so the user can
	 * see what was replaced — admin UI surfaces it via the auditor view.
	 *
	 * @param int $post_id
	 * @return bool True if a replacement existed and was restored.
	 */
	public function restore_replacement( int $post_id ): bool {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			Ligase_Logger::warning( "Insufficient permissions to restore schema for post {$post_id}." );
			return false;
		}

		$had_flag = '1' === get_post_meta( $post_id, '_ligase_needs_own_schema', true );

		delete_post_meta( $post_id, '_ligase_needs_own_schema' );
		// Keep _ligase_replaced_schema as a record of what was replaced; users can clear it
		// from the auditor view explicitly.

		if ( $had_flag ) {
			Ligase_Logger::info( "Restored original schema for post {$post_id}." );
		}

		return $had_flag;
	}

	/**
	 * Get the previously-stored backup of replaced schema (for diff display in admin).
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public function get_replaced_backup( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, '_ligase_replaced_schema', true );
		if ( empty( $raw ) ) {
			return null;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : null;
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
	 * Pure-function audit of a schema array. WP-independent: takes a schema, returns the
	 * scored result and (for replace/supplement mode) the modified schema. Does NOT
	 * write to post meta or touch wp_head — that's reserved for the apply_*() methods.
	 *
	 * @param array  $schema The decoded JSON-LD to audit.
	 * @param string $mode   'scan' | 'supplement' | 'replace'
	 * @param array  $opts   ['threshold' => int]
	 * @return array{schema: array, score: int, mode: string, action: string, below_threshold: bool, issues: array}
	 */
	public function audit( array $schema, string $mode = 'scan', array $opts = [] ): array {
		$mode      = in_array( $mode, self::ALLOWED_MODES, true ) ? $mode : 'scan';
		$threshold = isset( $opts['threshold'] ) ? max( 0, min( 100, (int) $opts['threshold'] ) ) : $this->threshold;

		$score           = $this->score( $schema );
		$below_threshold = $score < $threshold;
		$issues          = $this->collect_issues( $schema );

		$action = 'none';
		$out    = $schema;
		if ( $below_threshold ) {
			switch ( $mode ) {
				case 'supplement':
					$out    = $this->supplement_schema( $schema );
					$action = 'supplement';
					break;
				case 'replace':
					$action = 'replace';
					break;
				case 'scan':
				default:
					$action = 'flag';
					break;
			}
		}

		return [
			'schema'          => $out,
			'score'           => $score,
			'mode'            => $mode,
			'action'          => $action,
			'below_threshold' => $below_threshold,
			'issues'          => $issues,
		];
	}

	/**
	 * Alias for get_detected_plugins(). Provided for API symmetry with the audit() method.
	 *
	 * @return array<string, string>
	 */
	public function detect_plugins(): array {
		return $this->get_detected_plugins();
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
		// Type-aware issue collection. Previously this method emitted Article-only
		// checks regardless of @type, producing 9 false-positive "Missing headline"
		// / "Missing publisher" / "Missing author" warnings on every Product,
		// Service, Person, LocalBusiness, etc. node. Now each type gets its own
		// rubric matching Google's per-type requirements; types we don't have a
		// rubric for fall back to generic checks (@id + name only).
		$type = '';
		if ( ! empty( $schema['@type'] ) ) {
			$type = is_array( $schema['@type'] ) ? (string) reset( $schema['@type'] ) : (string) $schema['@type'];
		}

		$article_types = array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report', 'LiveBlogPosting' );

		if ( in_array( $type, $article_types, true ) || $type === '' ) {
			return $this->issues_article( $schema );
		}
		switch ( $type ) {
			case 'Product':           return $this->issues_product( $schema );
			case 'Service':           return $this->issues_service( $schema );
			case 'Person':            return $this->issues_person( $schema );
			case 'Organization':
			case 'OnlineStore':       return $this->issues_organization( $schema );
			case 'LocalBusiness':
			case 'Attorney':
			case 'LegalService':
			case 'Restaurant':
			case 'Store':
			case 'Hotel':             return $this->issues_local_business( $schema );
			case 'Event':             return $this->issues_event( $schema );
			case 'Recipe':            return $this->issues_recipe( $schema );
			case 'JobPosting':        return $this->issues_jobposting( $schema );
			case 'FAQPage':           return $this->issues_faqpage( $schema );
			case 'HowTo':             return $this->issues_howto( $schema );
			case 'VideoObject':       return $this->issues_video( $schema );
			case 'WebSite':           return $this->issues_website( $schema );
			case 'BreadcrumbList':    return $this->issues_breadcrumb( $schema );
			case 'ItemList':          return $this->issues_itemlist( $schema );
			case 'WebPage':
			case 'CollectionPage':
			case 'ProfilePage':
			case 'Blog':              return $this->issues_webpage( $schema );
			case 'SiteNavigationElement': return array(); // Navigation has no semantic issues to flag
		}

		// Unknown type — minimal generic check
		return $this->issues_generic( $schema );
	}

	// -------------------------------------------------------------------------
	// Per-type issue rubrics. Each returns an array of Polish-language messages.
	// -------------------------------------------------------------------------

	private function issues_article( array $s ): array {
		$out = array();
		if ( empty( $s['headline'] ) ) {
			$out[] = 'Brak headline (tytułu).';
		} elseif ( mb_strlen( $s['headline'] ) > 110 ) {
			$out[] = 'Headline przekracza 110 znaków.';
		}
		if ( empty( $s['datePublished'] ) ) {
			$out[] = 'Brak datePublished.';
		} elseif ( ! $this->is_valid_iso8601( $s['datePublished'] ) ) {
			$out[] = 'datePublished niepoprawny format ISO 8601.';
		}
		if ( empty( $s['dateModified'] ) ) {
			$out[] = 'Brak dateModified (zalecane).';
		} elseif ( ! $this->is_valid_iso8601( $s['dateModified'] ) ) {
			$out[] = 'dateModified niepoprawny format ISO 8601.';
		}
		if ( empty( $s['author']['name'] ) && ! $this->nested_has( $s, 'author', 'name' ) && ! $this->nested_has( $s, 'author', '@id' ) ) {
			$out[] = 'Brak author (zalecane name lub @id reference).';
		}
		if ( empty( $s['image'] ) ) {
			$out[] = 'Brak image (zalecane).';
		} elseif ( $this->image_width_below( $s, 696 ) ) {
			$out[] = 'Image szerokość < 696px (Google minimum).';
		}
		if ( empty( $s['publisher'] ) ) {
			$out[] = 'Brak publisher (zalecane @id reference do Organization).';
		}
		if ( empty( $s['@id'] ) ) {
			$out[] = 'Brak @id (utrudnia graph linking).';
		}
		return $out;
	}

	private function issues_product( array $s ): array {
		$out = array();
		if ( empty( $s['name'] ) )  { $out[] = 'Product: brak name (wymagane).'; }
		if ( empty( $s['image'] ) ) { $out[] = 'Product: brak image (wymagane dla merchant listing).'; }
		$has_review = ! empty( $s['review'] );
		$has_rating = ! empty( $s['aggregateRating'] );
		$has_offer  = ! empty( $s['offers'] );
		if ( ! $has_review && ! $has_rating && ! $has_offer ) {
			$out[] = 'Product: wymagane jedno z review / aggregateRating / offers.';
		}
		if ( $has_offer ) {
			$o = $s['offers'];
			if ( is_array( $o ) ) {
				if ( ! isset( $o['price'] ) ) { $out[] = 'Product › offers: brak price.'; }
				if ( empty( $o['priceCurrency'] ) ) { $out[] = 'Product › offers: brak priceCurrency.'; }
				if ( empty( $o['availability'] ) ) { $out[] = 'Product › offers: brak availability.'; }
				if ( empty( $o['hasMerchantReturnPolicy'] ) ) { $out[] = 'Product › offers: brak hasMerchantReturnPolicy (wymagane dla merchant listing od marca 2025).'; }
				if ( empty( $o['shippingDetails'] ) ) { $out[] = 'Product › offers: brak shippingDetails (zalecane dla Shopping).'; }
			}
		}
		if ( empty( $s['brand'] ) && empty( $s['sku'] ) && empty( $s['gtin'] ) && empty( $s['mpn'] ) ) {
			$out[] = 'Product: brak identyfikatora (brand / sku / gtin / mpn — zalecane).';
		}
		return $out;
	}

	private function issues_service( array $s ): array {
		$out = array();
		if ( empty( $s['name'] ) )        { $out[] = 'Service: brak name (wymagane).'; }
		if ( empty( $s['provider'] ) )    { $out[] = 'Service: brak provider (@id reference do Organization/LocalBusiness).'; }
		if ( empty( $s['serviceType'] ) ) { $out[] = 'Service: brak serviceType (zalecane).'; }
		if ( empty( $s['areaServed'] ) )  { $out[] = 'Service: brak areaServed (zalecane dla local SEO).'; }
		return $out;
	}

	private function issues_person( array $s ): array {
		$out = array();
		if ( empty( $s['name'] ) )  { $out[] = 'Person: brak name (wymagane).'; }
		if ( empty( $s['@id'] ) )   { $out[] = 'Person: brak @id (utrudnia graph linking).'; }
		if ( empty( $s['image'] ) ) { $out[] = 'Person: brak image (zalecane dla E-E-A-T).'; }
		if ( empty( $s['sameAs'] ) ){ $out[] = 'Person: brak sameAs (silny sygnał tożsamości dla AI — LinkedIn / Wikidata).'; }
		return $out;
	}

	private function issues_organization( array $s ): array {
		$out = array();
		if ( empty( $s['name'] ) ) { $out[] = 'Organization: brak name (wymagane).'; }
		if ( empty( $s['url'] ) )  { $out[] = 'Organization: brak url (zalecane).'; }
		if ( empty( $s['logo'] ) ) { $out[] = 'Organization: brak logo (Google 2025: min. 112×112 kwadrat).'; }
		if ( empty( $s['sameAs'] ) ) { $out[] = 'Organization: brak sameAs (Wikidata / social profiles — sygnał encji).'; }
		return $out;
	}

	private function issues_local_business( array $s ): array {
		$out = array();
		if ( empty( $s['name'] ) )      { $out[] = 'LocalBusiness: brak name.'; }
		if ( empty( $s['address'] ) )   { $out[] = 'LocalBusiness: brak address (wymagane dla Knowledge Panel).'; }
		if ( empty( $s['telephone'] ) ) { $out[] = 'LocalBusiness: brak telephone (zalecane).'; }
		if ( empty( $s['openingHoursSpecification'] ) && empty( $s['openingHours'] ) ) {
			$out[] = 'LocalBusiness: brak openingHoursSpecification (zalecane).';
		}
		if ( empty( $s['geo'] ) ) { $out[] = 'LocalBusiness: brak geo (latitude/longitude — zalecane dla map).'; }
		return $out;
	}

	private function issues_event( array $s ): array {
		$out = array();
		if ( empty( $s['name'] ) )      { $out[] = 'Event: brak name.'; }
		if ( empty( $s['startDate'] ) ) { $out[] = 'Event: brak startDate (wymagane).'; }
		elseif ( ! $this->is_valid_iso8601( $s['startDate'] ) ) { $out[] = 'Event: startDate niepoprawny ISO 8601.'; }
		if ( empty( $s['location'] ) && empty( $s['eventAttendanceMode'] ) ) {
			$out[] = 'Event: brak location lub eventAttendanceMode=OnlineEventAttendanceMode.';
		}
		if ( empty( $s['organizer'] ) ) { $out[] = 'Event: brak organizer (zalecane).'; }
		return $out;
	}

	private function issues_recipe( array $s ): array {
		$out = array();
		if ( empty( $s['name'] ) )                { $out[] = 'Recipe: brak name (wymagane).'; }
		if ( empty( $s['image'] ) )               { $out[] = 'Recipe: brak image (wymagane).'; }
		if ( empty( $s['recipeIngredient'] ) )    { $out[] = 'Recipe: brak recipeIngredient (rich result wymaga listy składników).'; }
		if ( empty( $s['recipeInstructions'] ) )  { $out[] = 'Recipe: brak recipeInstructions (rich result wymaga kroków).'; }
		return $out;
	}

	private function issues_jobposting( array $s ): array {
		$out = array();
		foreach ( array( 'title', 'description', 'datePosted', 'validThrough', 'hiringOrganization' ) as $k ) {
			if ( empty( $s[ $k ] ) ) { $out[] = "JobPosting: brak $k (wymagane)."; }
		}
		if ( empty( $s['jobLocation'] ) && empty( $s['jobLocationType'] ) ) {
			$out[] = 'JobPosting: wymagane jobLocation lub jobLocationType=TELECOMMUTE.';
		}
		return $out;
	}

	private function issues_faqpage( array $s ): array {
		$out = array();
		$count = is_array( $s['mainEntity'] ?? null ) ? count( $s['mainEntity'] ) : 0;
		if ( $count < 2 ) { $out[] = "FAQPage: tylko $count pytań — Google wymaga min. 2 do rich result."; }
		return $out;
	}

	private function issues_howto( array $s ): array {
		$out = array();
		if ( empty( $s['name'] ) )  { $out[] = 'HowTo: brak name.'; }
		if ( empty( $s['image'] ) ) { $out[] = 'HowTo: brak image (wymagane przez Google).'; }
		$steps = is_array( $s['step'] ?? null ) ? count( $s['step'] ) : 0;
		if ( $steps < 2 ) { $out[] = "HowTo: tylko $steps kroków — rich result oczekuje min. 2."; }
		return $out;
	}

	private function issues_video( array $s ): array {
		$out = array();
		foreach ( array( 'name', 'thumbnailUrl', 'uploadDate' ) as $k ) {
			if ( empty( $s[ $k ] ) ) { $out[] = "VideoObject: brak $k (wymagane)."; }
		}
		if ( empty( $s['contentUrl'] ) && empty( $s['embedUrl'] ) ) {
			$out[] = 'VideoObject: brak contentUrl lub embedUrl.';
		}
		return $out;
	}

	private function issues_website( array $s ): array {
		$out = array();
		if ( empty( $s['name'] ) ) { $out[] = 'WebSite: brak name.'; }
		if ( empty( $s['url'] ) )  { $out[] = 'WebSite: brak url.'; }
		return $out;
	}

	private function issues_breadcrumb( array $s ): array {
		$out = array();
		$items = is_array( $s['itemListElement'] ?? null ) ? $s['itemListElement'] : array();
		if ( count( $items ) < 1 ) { $out[] = 'BreadcrumbList: brak itemListElement.'; }
		return $out;
	}

	private function issues_itemlist( array $s ): array {
		$out = array();
		$items = is_array( $s['itemListElement'] ?? null ) ? $s['itemListElement'] : array();
		if ( count( $items ) < 1 ) { $out[] = 'ItemList: pusta lista itemListElement.'; }
		return $out;
	}

	private function issues_webpage( array $s ): array {
		$out = array();
		if ( empty( $s['name'] ) ) { $out[] = 'WebPage: brak name.'; }
		if ( empty( $s['url'] ) )  { $out[] = 'WebPage: brak url.'; }
		return $out;
	}

	private function issues_generic( array $s ): array {
		$out = array();
		if ( empty( $s['name'] ) && empty( $s['headline'] ) ) {
			$out[] = ( $s['@type'] ?? 'Node' ) . ': brak name/headline.';
		}
		return $out;
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
