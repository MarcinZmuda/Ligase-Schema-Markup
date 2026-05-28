<?php
/**
 * Ligase - PHPUnit Bootstrap
 *
 * Defines WordPress stub functions and loads plugin classes for unit testing.
 *
 * @package Ligase\Tests
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Plugin constants
// ---------------------------------------------------------------------------

define( 'ABSPATH', '/tmp/wordpress/' );
define( 'LIGASE_DIR', dirname( __DIR__ ) . '/' );
define( 'LIGASE_VERSION', '1.0.0' );
define( 'LIGASE_URL', 'https://example.com/wp-content/plugins/ligase/' );

// ---------------------------------------------------------------------------
// MockData - central store for test data returned by WordPress stubs
// ---------------------------------------------------------------------------

final class MockData {

	/** @var array<string, mixed> */
	private static array $options = [];

	/** @var array<string, mixed> */
	private static array $post_meta = [];

	/** @var array<string, mixed> */
	private static array $user_meta = [];

	/** @var array<string, mixed> */
	private static array $data = [];

	/**
	 * Reset all mock data between tests.
	 */
	public static function reset(): void {
		self::$options   = [];
		self::$post_meta = [];
		self::$user_meta = [];
		self::$data      = [];
	}

	// -- Options ------------------------------------------------------------

	public static function set_option( string $key, mixed $value ): void {
		self::$options[ $key ] = $value;
	}

	public static function get_option( string $key, mixed $default = false ): mixed {
		return self::$options[ $key ] ?? $default;
	}

	// -- Post meta ----------------------------------------------------------

	public static function set_post_meta( int $post_id, string $key, mixed $value ): void {
		self::$post_meta[ $post_id . ':' . $key ] = $value;
	}

	public static function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
		$val = self::$post_meta[ $post_id . ':' . $key ] ?? ( $single ? '' : [] );
		return $val;
	}

	// -- User meta ----------------------------------------------------------

	public static function set_user_meta( int $user_id, string $key, mixed $value ): void {
		self::$user_meta[ $user_id . ':' . $key ] = $value;
	}

	public static function get_user_meta( int $user_id, string $key, bool $single = false ): mixed {
		$val = self::$user_meta[ $user_id . ':' . $key ] ?? ( $single ? '' : [] );
		return $val;
	}

	// -- Arbitrary keyed data -----------------------------------------------

	public static function set( string $key, mixed $value ): void {
		self::$data[ $key ] = $value;
	}

	public static function get( string $key, mixed $default = null ): mixed {
		return self::$data[ $key ] ?? $default;
	}
}

// ---------------------------------------------------------------------------
// WordPress stub functions
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		return MockData::get_option( $option, $default );
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		return MockData::get_post_meta( $post_id, $key, $single );
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( int $user_id, string $key = '', bool $single = false ): mixed {
		return MockData::get_user_meta( $user_id, $key, $single );
	}
}

if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID(): int {
		return (int) MockData::get( 'the_id', 1 );
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int|object $post = 0 ): string {
		return (string) MockData::get( 'the_title', 'Test Post Title' );
	}
}

if ( ! function_exists( 'get_the_date' ) ) {
	function get_the_date( string $format = '', int|object $post = null ): string {
		return (string) MockData::get( 'the_date', '2025-01-15' );
	}
}

if ( ! function_exists( 'get_the_modified_date' ) ) {
	function get_the_modified_date( string $format = '', int|object $post = null ): string {
		return (string) MockData::get( 'the_modified_date', '2025-01-20' );
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int|object $post = 0 ): string {
		return (string) MockData::get( 'permalink', 'https://example.com/test-post/' );
	}
}

if ( ! function_exists( 'get_the_excerpt' ) ) {
	function get_the_excerpt( int|object $post = null ): string {
		return (string) MockData::get( 'the_excerpt', 'This is a test excerpt for the post.' );
	}
}

if ( ! function_exists( 'get_the_content' ) ) {
	function get_the_content( string $more_link_text = null, bool $strip_teaser = false, int|object $post = null ): string {
		return (string) MockData::get( 'the_content', '<p>This is the full post content for testing purposes.</p>' );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
		$map = [
			'name'        => 'Test Blog',
			'description' => 'A test blog description',
			'language'    => 'pl-PL',
			'url'         => 'https://example.com',
		];
		$custom = MockData::get( 'bloginfo_' . $show );
		return (string) ( $custom ?? ( $map[ $show ] ?? '' ) );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		$base = (string) MockData::get( 'home_url', 'https://example.com' );
		return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
		$text = strip_tags( $text );
		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}
		return trim( $text );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $data ): string {
		return $data;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return (string) MockData::get( 'locale', 'pl_PL' );
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, int|object $post = null ): string {
		return (string) MockData::get( 'post_field_' . $field, '' );
	}
}

if ( ! function_exists( 'wp_get_post_tags' ) ) {
	function wp_get_post_tags( int $post_id = 0, array $args = [] ): array {
		return (array) MockData::get( 'post_tags', [] );
	}
}

if ( ! function_exists( 'get_the_category' ) ) {
	function get_the_category( int $post_id = 0 ): array {
		return (array) MockData::get( 'the_category', [] );
	}
}

if ( ! function_exists( 'get_comments_number' ) ) {
	function get_comments_number( int|object $post = 0 ): int {
		return (int) MockData::get( 'comments_number', 0 );
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( int|object $post = null ): int {
		return (int) MockData::get( 'post_thumbnail_id', 0 );
	}
}

if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
	function wp_get_attachment_image_src( int $attachment_id, string|array $size = 'full' ): array|false {
		$src = MockData::get( 'attachment_image_src' );
		return $src !== null ? $src : false;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ): object|false {
		$data = MockData::get( 'userdata' );
		if ( $data !== null ) {
			return is_object( $data ) ? $data : (object) $data;
		}
		return (object) [
			'ID'           => $user_id,
			'display_name' => 'Test Author',
			'user_url'     => 'https://example.com/author/',
			'description'  => 'Test author biography.',
		];
	}
}

if ( ! function_exists( 'get_author_posts_url' ) ) {
	function get_author_posts_url( int $author_id ): string {
		return (string) MockData::get( 'author_posts_url', 'https://example.com/author/test/' );
	}
}

if ( ! function_exists( 'get_avatar_url' ) ) {
	function get_avatar_url( mixed $id_or_email, array $args = [] ): string|false {
		return (string) MockData::get( 'avatar_url', 'https://example.com/avatar.jpg' );
	}
}

if ( ! function_exists( 'get_site_icon_url' ) ) {
	function get_site_icon_url( int $size = 512 ): string {
		return (string) MockData::get( 'site_icon_url', 'https://example.com/icon.png' );
	}
}

if ( ! function_exists( 'get_category_link' ) ) {
	function get_category_link( int|object $category ): string {
		return (string) MockData::get( 'category_link', 'https://example.com/category/test/' );
	}
}

if ( ! function_exists( 'is_single' ) ) {
	function is_single( mixed $post = '' ): bool {
		return (bool) MockData::get( 'is_single', false );
	}
}

if ( ! function_exists( 'is_page' ) ) {
	function is_page( mixed $page = '' ): bool {
		return (bool) MockData::get( 'is_page', false );
	}
}

if ( ! function_exists( 'is_category' ) ) {
	function is_category( mixed $category = '' ): bool {
		return (bool) MockData::get( 'is_category', false );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return (bool) MockData::get( 'is_admin', false );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, mixed ...$args ): bool {
		return (bool) MockData::get( 'current_user_can', false );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		return [
			'basedir' => sys_get_temp_dir() . '/wp-uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
			'path'    => sys_get_temp_dir() . '/wp-uploads/' . date( 'Y/m' ),
			'url'     => 'https://example.com/wp-content/uploads/' . date( 'Y/m' ),
		];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		if ( is_dir( $target ) ) {
			return true;
		}
		return @mkdir( $target, 0755, true );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

// ---------------------------------------------------------------------------
// Additional WP function stubs used by Auditor / Score / NER / Importer
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( string $email ): string {
		return filter_var( $email, FILTER_SANITIZE_EMAIL ) ?: '';
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value ): bool {
		MockData::set_post_meta( $post_id, $key, $value );
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key ): bool {
		MockData::set_post_meta( $post_id, $key, null );
		return true;
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( int $user_id, string $key, mixed $value ): bool {
		MockData::set_user_meta( $user_id, $key, $value );
		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
		MockData::set_option( $key, $value );
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $key ): bool {
		MockData::set_option( $key, null );
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ): mixed {
		return MockData::get( 'transient_' . $key, false );
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $ttl = 0 ): bool {
		MockData::set( 'transient_' . $key, $value );
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		MockData::set( 'transient_' . $key, null );
		return true;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( mixed $post = null, string $output = 'OBJECT' ): ?object {
		$data = MockData::get( 'post' );
		if ( is_object( $data ) || is_array( $data ) ) {
			return (object) $data;
		}
		return null;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = [] ): array {
		return (array) MockData::get( 'get_posts', [] );
	}
}

if ( ! function_exists( 'has_post_thumbnail' ) ) {
	function has_post_thumbnail( mixed $post = null ): bool {
		return (bool) MockData::get( 'has_post_thumbnail', false );
	}
}

if ( ! function_exists( 'wp_get_post_categories' ) ) {
	function wp_get_post_categories( int $post_id = 0, array $args = [] ): array {
		return (array) MockData::get( 'post_categories', [] );
	}
}

if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( mixed $post_types = '' ): bool {
		return (bool) MockData::get( 'is_singular', false );
	}
}

if ( ! function_exists( 'wp_count_posts' ) ) {
	function wp_count_posts( string $type = 'post' ): object {
		return (object) [ 'publish' => (int) MockData::get( 'count_publish', 0 ) ];
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = [] ): array|object {
		$mock = MockData::get( 'wp_remote_get' );
		return $mock !== null ? $mock : [ 'body' => '', 'response' => [ 'code' => 200 ] ];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( mixed $response ): string {
		if ( is_array( $response ) ) {
			return (string) ( $response['body'] ?? '' );
		}
		return '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		$sep = str_contains( $url, '?' ) ? '&' : '?';
		return $url . $sep . http_build_query( $args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook_name, callable|string $callback, int $priority = 10 ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = [] ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = [] ): bool|int {
		return false;
	}
}

if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( int $attachment_id, string|array $size = 'full' ): string|false {
		$src = MockData::get( 'attachment_image_src' );
		return ( is_array( $src ) && ! empty( $src[0] ) ) ? (string) $src[0] : false;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( mixed $post ): bool {
		return false;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type = 'mysql', bool $gmt = false ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( mixed $value ): mixed {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$data = @unserialize( $value );
		return $data !== false || $value === 'b:0;' ? $data : $value;
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( string $content ): array {
		return [];
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( string $text, int $num_words = 55, string $more = '...' ): string {
		$words = preg_split( '/\s+/', wp_strip_all_tags( $text ) );
		if ( count( $words ) <= $num_words ) {
			return implode( ' ', $words );
		}
		return implode( ' ', array_slice( $words, 0, $num_words ) ) . $more;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_e' ) ) {
	function _e( string $text, string $domain = 'default' ): void {
		echo $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! defined( 'WEEK_IN_SECONDS' ) )  { define( 'WEEK_IN_SECONDS',  7 * 86400 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )   { define( 'DAY_IN_SECONDS',   86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) )  { define( 'HOUR_IN_SECONDS',  3600 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ){ define( 'MINUTE_IN_SECONDS',60 ); }

// ---------------------------------------------------------------------------
// Require plugin class files (only those that exist)
// ---------------------------------------------------------------------------

$ligase_class_files = [
	LIGASE_DIR . 'includes/class-logger.php',
	LIGASE_DIR . 'includes/class-cache.php',
	LIGASE_DIR . 'includes/class-suppressor.php',
	LIGASE_DIR . 'includes/class-cache-bypass.php',
	LIGASE_DIR . 'includes/class-score.php',
	LIGASE_DIR . 'includes/class-generator.php',
	LIGASE_DIR . 'includes/class-output.php',
	LIGASE_DIR . 'includes/class-auditor.php',
	LIGASE_DIR . 'includes/types/class-blogposting.php',
	LIGASE_DIR . 'includes/types/class-organization.php',
	LIGASE_DIR . 'includes/types/class-person.php',
	LIGASE_DIR . 'includes/types/class-website.php',
	LIGASE_DIR . 'includes/types/class-breadcrumb.php',
	LIGASE_DIR . 'includes/types/class-faqpage.php',
	LIGASE_DIR . 'includes/types/class-howto.php',
	LIGASE_DIR . 'includes/types/class-videoobject.php',
	LIGASE_DIR . 'includes/types/class-review.php',
	LIGASE_DIR . 'includes/entities/class-pipeline.php',
	LIGASE_DIR . 'includes/entities/class-extractor-native.php',
	LIGASE_DIR . 'includes/entities/class-extractor-structure.php',
	LIGASE_DIR . 'includes/entities/class-extractor-ner.php',
	LIGASE_DIR . 'includes/entities/class-wikidata-lookup.php',
];

foreach ( $ligase_class_files as $file ) {
	if ( file_exists( $file ) ) {
		require_once $file;
	}
}
