<?php
/**
 * Ligase NER API — LLM-powered Named Entity Recognition
 *
 * Sends post content to a chosen LLM provider and returns
 * structured entity data (persons, organizations, places, topics).
 *
 * Supported providers:
 *   - OpenAI (GPT-4o-mini)   ~$0.0004 / post
 *   - Anthropic (Haiku)      ~$0.0006 / post
 *   - Google NLP API         ~$0.010  / post
 *   - Dandelion (EU/GDPR)    ~€0.002  / post
 *
 * @package Ligase
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

class Ligase_NER_API {

	/** Cache duration for results (30 days). */
	const CACHE_TTL = 30 * DAY_IN_SECONDS;

	/** Max words sent to API — enough for NER, keeps cost low. */
	const MAX_WORDS = 3000;

	/** Supported providers. */
	const PROVIDERS = array(
		'openai'     => 'OpenAI GPT-4o-mini',
		'anthropic'  => 'Anthropic Claude Haiku',
		'google_nlp' => 'Google Natural Language API',
		'dandelion'  => 'Dandelion (EU / GDPR)',
	);

	/** @var string */
	private string $provider;

	/** @var string */
	private string $api_key;

	public function __construct() {
		$opts           = (array) get_option( 'ligase_options', array() );
		$this->provider = $opts['ner_provider'] ?? '';
		$this->api_key  = $opts['ner_api_key']  ?? '';
	}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Is an API provider configured?
	 */
	public function is_configured(): bool {
		return ! empty( $this->provider )
			&& ! empty( $this->api_key )
			&& array_key_exists( $this->provider, self::PROVIDERS );
	}

	/**
	 * Extract entities for a single post.
	 * Returns cached result if available, calls API otherwise.
	 *
	 * @param int $post_id
	 * @return array|null  Structured entity array or null on failure.
	 */
	public function extract( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || ! $this->is_configured() ) {
			return null;
		}

		// Cache key tied to post content (re-runs if post is updated)
		$cache_key = 'ligase_ner_api_' . $post_id . '_' . md5( $post->post_modified );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$title   = $post->post_title;
		$content = $this->prepare_content( $post->post_content );
		$result  = $this->call_provider( $title, $content );

		if ( is_array( $result ) ) {
			set_transient( $cache_key, $result, self::CACHE_TTL );
			update_post_meta( $post_id, '_ligase_ner_api_results',   $result );
			update_post_meta( $post_id, '_ligase_ner_api_provider',  $this->provider );
			update_post_meta( $post_id, '_ligase_ner_api_date',      current_time( 'mysql' ) );
		}

		return $result;
	}

	/**
	 * Schedule async extraction via WP-Cron (non-blocking).
	 */
	public function schedule( int $post_id ): void {
		if ( ! wp_next_scheduled( 'ligase_ner_api_extract', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'ligase_ner_api_extract', array( $post_id ) );
		}
	}

	/**
	 * Run async extraction — called by WP-Cron hook.
	 */
	public static function run_scheduled( int $post_id ): void {
		$instance = new self();
		if ( $instance->is_configured() ) {
			$instance->extract( $post_id );
		}
	}

	/**
	 * Bulk scan: schedule NER for all published posts that don't have results yet.
	 *
	 * @param bool $force  Re-run even for posts that already have results.
	 * @return int  Number of posts scheduled.
	 */
	public function schedule_bulk( bool $force = false ): int {
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$scheduled = 0;
		$delay     = 10; // stagger requests — 10s apart to avoid API rate limits

		foreach ( $posts as $post_id ) {
			if ( ! $force ) {
				$existing = get_post_meta( $post_id, '_ligase_ner_api_results', true );
				if ( ! empty( $existing ) ) {
					continue; // already processed
				}
			}

			wp_schedule_single_event(
				time() + $delay,
				'ligase_ner_api_extract',
				array( (int) $post_id )
			);

			$delay += 10;
			$scheduled++;
		}

		return $scheduled;
	}

	/**
	 * Get bulk scan status.
	 *
	 * @return array{total: int, done: int, pending: int, percent: int}
	 */
	public static function get_bulk_status(): array {
		$total = (int) wp_count_posts( 'post' )->publish;
		$done  = (int) get_option( 'ligase_ner_bulk_done', 0 );

		return array(
			'total'   => $total,
			'done'    => $done,
			'pending' => max( 0, $total - $done ),
			'percent' => $total > 0 ? (int) round( $done / $total * 100 ) : 0,
		);
	}

	/**
	 * Estimate cost for bulk scan.
	 *
	 * @param int $post_count
	 * @return array{provider: string, cost_usd: float, cost_formatted: string}
	 */
	public function estimate_cost( int $post_count ): array {
		$cost_per_post = array(
			'openai'     => 0.0004,
			'anthropic'  => 0.0006,
			'google_nlp' => 0.010,
			'dandelion'  => 0.002,
		);

		$per_post = $cost_per_post[ $this->provider ] ?? 0.001;
		$total    = $per_post * $post_count;

		return array(
			'provider'       => self::PROVIDERS[ $this->provider ] ?? $this->provider,
			'cost_usd'       => $total,
			'cost_formatted' => '$' . number_format( $total, 4 ),
		);
	}

	// =========================================================================
	// Provider calls
	// =========================================================================

	private function call_provider( string $title, string $content ): ?array {
		return match( $this->provider ) {
			'openai'     => $this->call_openai( $title, $content ),
			'anthropic'  => $this->call_anthropic( $title, $content ),
			'google_nlp' => $this->call_google_nlp( $content ),
			'dandelion'  => $this->call_dandelion( $title . ' ' . $content ),
			default      => null,
		};
	}

	// ── OpenAI (GPT-4o-mini) ─────────────────────────────────────────────────
	private function call_openai( string $title, string $content ): ?array {
		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'           => 'gpt-4o-mini',
				'max_tokens'      => 800,
				'temperature'     => 0,
				'response_format' => array( 'type' => 'json_object' ),
				'messages'        => array(
					array( 'role' => 'user', 'content' => $this->build_prompt( $title, $content ) ),
				),
			) ),
		) );

		return $this->parse_llm_response( $response, array( 'choices', 0, 'message', 'content' ) );
	}

	// ── Anthropic (Claude Haiku) ─────────────────────────────────────────────
	private function call_anthropic( string $title, string $content ): ?array {
		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 30,
			'headers' => array(
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'      => 'claude-haiku-4-5-20251001',
				'max_tokens' => 800,
				'messages'   => array(
					array( 'role' => 'user', 'content' => $this->build_prompt( $title, $content ) ),
				),
			) ),
		) );

		return $this->parse_llm_response( $response, array( 'content', 0, 'text' ) );
	}

	// ── Google Natural Language API ──────────────────────────────────────────
	private function call_google_nlp( string $content ): ?array {
		$response = wp_remote_post(
			'https://language.googleapis.com/v1/documents:analyzeEntities?key=' . $this->api_key,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'document'     => array( 'type' => 'PLAIN_TEXT', 'content' => substr( $content, 0, 10000 ) ),
					'encodingType' => 'UTF8',
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			Ligase_Logger::error( 'Google NLP API error', array( 'error' => $response->get_error_message() ) );
			return null;
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$result = array( 'persons' => array(), 'organizations' => array(), 'places' => array(), 'topics' => array(), 'products' => array() );

		foreach ( $data['entities'] ?? array() as $entity ) {
			$confidence = (float) ( $entity['salience'] ?? 0 );
			if ( $confidence < 0.05 ) {
				continue;
			}
			$name = sanitize_text_field( $entity['name'] );
			switch ( $entity['type'] ) {
				case 'PERSON':
					$result['persons'][] = array( 'name' => $name, 'confidence' => $confidence );
					break;
				case 'ORGANIZATION':
					$result['organizations'][] = array( 'name' => $name, 'confidence' => $confidence );
					break;
				case 'LOCATION':
					$result['places'][] = array( 'name' => $name, 'confidence' => $confidence );
					break;
				case 'CONSUMER_GOOD':
					$result['products'][] = array( 'name' => $name, 'confidence' => $confidence );
					break;
				default:
					$result['topics'][] = array( 'name' => $name, 'relevance' => 'secondary' );
					break;
			}
		}

		return $result;
	}

	// ── Dandelion (EU / GDPR-friendly) ──────────────────────────────────────
	private function call_dandelion( string $content ): ?array {
		$response = wp_remote_post( 'https://api.dandelion.eu/datatxt/nex/v1/', array(
			'timeout' => 30,
			'body'    => array(
				'token'          => $this->api_key,
				'text'           => substr( $content, 0, 4000 ),
				'lang'           => 'auto',
				'min_confidence' => '0.7',
				'include'        => 'types,categories',
			),
		) );

		if ( is_wp_error( $response ) ) {
			Ligase_Logger::error( 'Dandelion API error', array( 'error' => $response->get_error_message() ) );
			return null;
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$result = array( 'persons' => array(), 'organizations' => array(), 'places' => array(), 'topics' => array(), 'products' => array() );

		foreach ( $data['annotations'] ?? array() as $ann ) {
			$name  = sanitize_text_field( $ann['label'] );
			$types = implode( ' ', $ann['types'] ?? array() );
			$conf  = (float) ( $ann['confidence'] ?? 0 );

			if ( str_contains( $types, 'Person' ) ) {
				$result['persons'][] = array( 'name' => $name, 'confidence' => $conf );
			} elseif ( str_contains( $types, 'Organisation' ) || str_contains( $types, 'Organization' ) ) {
				$result['organizations'][] = array( 'name' => $name, 'confidence' => $conf );
			} elseif ( str_contains( $types, 'Place' ) ) {
				$result['places'][] = array( 'name' => $name, 'confidence' => $conf );
			} else {
				$result['topics'][] = array( 'name' => $name, 'relevance' => 'secondary' );
			}
		}

		return $result;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function prepare_content( string $raw ): string {
		$text  = wp_strip_all_tags( $raw );
		$words = str_word_count( $text, 1 );
		return implode( ' ', array_slice( $words, 0, self::MAX_WORDS ) );
	}

	private function build_prompt( string $title, string $content ): string {
		return 'You are a Named Entity Recognition system for a blog CMS. '
			. 'Analyze the blog post below and extract named entities. '
			. 'Return ONLY valid JSON with no explanation, no markdown backticks. '
			. 'JSON structure: '
			. '{"persons":[{"name":"...","role":"...","confidence":0.95}],'
			. '"organizations":[{"name":"...","type":"company|ngo|gov|media","confidence":0.90}],'
			. '"places":[{"name":"...","type":"city|country|region","confidence":0.85}],'
			. '"topics":[{"name":"...","relevance":"primary|secondary"}],'
			. '"products":[{"name":"...","brand":"..."}]} '
			. 'Rules: only explicitly mentioned entities; confidence 0.0-1.0; '
			. 'skip confidence < 0.7; max 5 per category; extract base form for inflected languages. '
			. "\n\nTitle: " . $title
			. "\n\nContent:\n" . $content;
	}

	/**
	 * Traverse nested array by key path and parse inner JSON string.
	 *
	 * @param mixed  $response  wp_remote_post() result.
	 * @param array  $path      Key path to the text content.
	 */
	private function parse_llm_response( $response, array $path ): ?array {
		if ( is_wp_error( $response ) ) {
			Ligase_Logger::error( 'NER API request failed', array( 'error' => $response->get_error_message() ) );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			Ligase_Logger::warning( 'NER API returned non-200', array( 'code' => $code, 'body' => substr( wp_remote_retrieve_body( $response ), 0, 300 ) ) );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}

		// Traverse dot path
		$node = $body;
		foreach ( $path as $key ) {
			$node = $node[ $key ] ?? null;
			if ( $node === null ) {
				Ligase_Logger::warning( 'NER API: unexpected response structure', array( 'path' => $path ) );
				return null;
			}
		}

		// $node is now a JSON string — parse it
		$parsed = json_decode( (string) $node, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $parsed ) ) {
			Ligase_Logger::warning( 'NER API: invalid JSON in response', array( 'raw' => substr( (string) $node, 0, 200 ) ) );
			return null;
		}

		return $parsed;
	}
}
