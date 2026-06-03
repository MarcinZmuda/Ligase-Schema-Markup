<?php
/**
 * Ligase Admin
 *
 * Handles all WordPress admin functionality: menus, meta boxes,
 * user profile fields, asset enqueueing, and settings registration.
 *
 * @package Ligase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ligase_Admin {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Plugin directory URL.
	 *
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Plugin directory path.
	 *
	 * @var string
	 */
	private $plugin_path;

	/**
	 * Admin page hook suffixes for asset loading.
	 *
	 * @var array
	 */
	private $page_hooks = array();

	/**
	 * Submenu page definitions.
	 *
	 * @var array
	 */
	private $submenus = array();

	/**
	 * Constructor.
	 *
	 * @param string $version     Plugin version.
	 * @param string $plugin_url  Plugin directory URL.
	 * @param string $plugin_path Plugin directory path.
	 */
	public function __construct( $version, $plugin_url, $plugin_path ) {
		$this->version     = $version;
		$this->plugin_url  = trailingslashit( $plugin_url );
		$this->plugin_path = trailingslashit( $plugin_path );

		$this->submenus = array(
			array(
				'title' => __( 'Dashboard', 'ligase' ),
				'slug'  => 'ligase',
				'cap'   => 'manage_options',
			),
			array(
				'title' => __( 'Ustawienia', 'ligase' ),
				'slug'  => 'ligase-ustawienia',
				'cap'   => 'manage_options',
			),
			array(
				'title' => __( 'Posty', 'ligase' ),
				'slug'  => 'ligase-posty',
				'cap'   => 'edit_posts',
			),
			array(
				'title' => __( 'Automatyzacja', 'ligase' ),
				'slug'  => 'ligase-rules',
				'cap'   => 'manage_options',
			),
			array(
				'title' => __( 'Audytor', 'ligase' ),
				'slug'  => 'ligase-audytor',
				'cap'   => 'manage_options',
			),
			array(
				'title' => __( 'AI Entities', 'ligase' ),
				'slug'  => 'ligase-encje',
				'cap'   => 'manage_options',
			),
			// "Narz\u0119dzia" submenu retired in 2.4.3 \u2014 actions moved to the Posty page
			// (bulk schema flags) and Ustawienia (clear cache, import/export). The
			// tools.php view itself stays on disk for back-compat with any deep links.
		);
	}

	/**
	 * Register all hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( 'Ligase_Settings', 'register' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'show_user_profile', array( $this, 'render_author_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_author_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_author_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_author_fields' ) );
	}

	// -------------------------------------------------------------------------
	// Menus
	// -------------------------------------------------------------------------

	/**
	 * Register the top-level menu and all submenu pages.
	 *
	 * @return void
	 */
	public function register_menus() {
		$hook = add_menu_page(
			__( 'Ligase', 'ligase' ),
			__( 'Ligase', 'ligase' ),
			'manage_options',
			'ligase',
			array( $this, 'render_admin_page' ),
			'dashicons-networking',
			99
		);

		$this->page_hooks[] = $hook;

		foreach ( $this->submenus as $index => $sub ) {
			$callback = ( 0 === $index )
				? array( $this, 'render_admin_page' )
				: array( $this, 'render_admin_page' );

			$sub_hook = add_submenu_page(
				'ligase',
				$sub['title'] . ' &mdash; Ligase',
				$sub['title'],
				$sub['cap'],
				$sub['slug'],
				$callback
			);

			$this->page_hooks[] = $sub_hook;
		}
	}

	/**
	 * Render the wrapper div where the React application mounts.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Nie masz uprawnien do wyswietlenia tej strony.', 'ligase' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page_slug = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'ligase';

		$view_map = array(
			'ligase'            => 'dashboard.php',
			'ligase-ustawienia' => 'settings.php',
			'ligase-posty'      => 'posts.php',
			'ligase-audytor'    => 'auditor.php',
			'ligase-encje'      => 'entities.php',
			'ligase-narzedzia'  => 'tools.php',
			'ligase-rules'      => 'rules.php',
		);

		$view_file = $view_map[ $page_slug ] ?? 'dashboard.php';
		$view_path = $this->plugin_path . 'admin/views/' . $view_file;

		echo '<div class="wrap">';

		if ( file_exists( $view_path ) ) {
			include $view_path;
		} else {
			printf(
				'<div id="ligase-admin-app" data-page="%s"></div>',
				esc_attr( $page_slug )
			);
		}

		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue admin CSS and JS on plugin pages and post edit screens.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$is_plugin_page = in_array( $hook_suffix, $this->page_hooks, true );
		$is_edit_screen = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );

		if ( ! $is_plugin_page && ! $is_edit_screen ) {
			return;
		}

		wp_enqueue_style(
			'ligase-admin',
			$this->plugin_url . 'assets/css/admin.css',
			array(),
			$this->version
		);

		wp_enqueue_script(
			'ligase-admin',
			$this->plugin_url . 'assets/js/admin.js',
			array( 'jquery', 'wp-i18n' ),
			$this->version,
			true
		);

		// Tell WP where to look for compiled JS translations (languages/ligase-{locale}-ligase-admin.json
		// produced via `wp i18n make-json languages/`).
		wp_set_script_translations(
			'ligase-admin',
			'ligase',
			$this->plugin_path . 'languages'
		);

		wp_localize_script( 'ligase-admin', 'LIGASE', array(
			'nonce'     => wp_create_nonce( 'ligase_admin' ),
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'version'   => $this->version,
			'pluginUrl' => $this->plugin_url,
		) );

		// Gutenberg sidebar panel (only on post edit screens)
		if ( $is_edit_screen ) {
			wp_enqueue_script(
				'ligase-gutenberg-sidebar',
				$this->plugin_url . 'assets/js/gutenberg-sidebar.js',
				array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'ligase-admin' ),
				$this->version,
				true
			);
			wp_set_script_translations(
				'ligase-gutenberg-sidebar',
				'ligase',
				$this->plugin_path . 'languages'
			);
		}
	}

	// -------------------------------------------------------------------------
	// Meta Box
	// -------------------------------------------------------------------------

	/**
	 * Register the Schema Markup meta box on all public post types.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'ligase_schema_markup',
				__( 'Schema Markup', 'ligase' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the meta box content.
	 *
	 * @param WP_Post $post The current post object.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		include $this->plugin_path . 'admin/views/meta-box.php';
	}

	/**
	 * Save meta box values on post save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta_box( $post_id, $post ) {
		// Verify nonce.
		if (
			! isset( $_POST['ligase_meta_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ligase_meta_nonce'] ) ), 'ligase_meta_save' )
		) {
			return;
		}

		// Skip autosaves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Check permissions. When the post_type is invalid / unregistered (e.g. CPT
		// plugin disabled but a stale post remains) get_post_type_object() returns null;
		// dereferencing ->cap fatals. Guard explicitly.
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! current_user_can( $post_type_obj->cap->edit_post, $post_id ) ) {
			return;
		}

		// Schema type.
		$allowed_types = array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'LiveBlogPosting' );
		if ( isset( $_POST['ligase_schema_type'] ) ) {
			$schema_type = sanitize_text_field( wp_unslash( $_POST['ligase_schema_type'] ) );
			if ( in_array( $schema_type, $allowed_types, true ) ) {
				update_post_meta( $post_id, '_ligase_schema_type', $schema_type );
			}
		}

		// Toggle flags (checkboxes).
		$toggles = array(
			'_ligase_enable_faq', '_ligase_enable_howto', '_ligase_enable_review',
			'_ligase_enable_qapage', '_ligase_enable_glossary', '_ligase_enable_claimreview',
			'_ligase_enable_software', '_ligase_enable_course', '_ligase_enable_event', '_ligase_enable_service',
			'_ligase_enable_product', '_ligase_enable_recipe', '_ligase_enable_jobposting', '_ligase_enable_forum',
			'_ligase_enable_podcast_series',
			'_ligase_paywalled', '_ligase_force_date_modified', '_ligase_enable_profile_page',
		);
		foreach ( $toggles as $key ) {
			$value = isset( $_POST[ $key ] ) ? '1' : '0';
			update_post_meta( $post_id, $key, $value );
		}

		// Single-value text/url post meta (paywall selector, dateline, image license).
		// Saved as standalone meta because they're not @type-scoped overrides.
		$text_meta = array(
			'_ligase_paywall_selector', '_ligase_dateline',
			'_ligase_image_credit',
		);
		foreach ( $text_meta as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				if ( $value === '' ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, $value );
				}
			}
		}
		$url_meta = array( '_ligase_image_license', '_ligase_image_acquire' );
		foreach ( $url_meta as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$value = esc_url_raw( wp_unslash( $_POST[ $key ] ) );
				if ( $value === '' ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, $value );
				}
			}
		}

		// _ligase_profile_user_id — explicit user reference for ProfilePage opt-in
		if ( isset( $_POST['_ligase_profile_user_id'] ) ) {
			$uid = absint( wp_unslash( $_POST['_ligase_profile_user_id'] ) );
			if ( $uid > 0 ) {
				update_post_meta( $post_id, '_ligase_profile_user_id', $uid );
			} else {
				delete_post_meta( $post_id, '_ligase_profile_user_id' );
			}
		}

		// FAQ items — textarea "Q | A" per line → array of {question, answer}.
		if ( isset( $_POST['ligase_faq_textarea'] ) ) {
			$raw   = wp_strip_all_tags( (string) wp_unslash( $_POST['ligase_faq_textarea'] ) );
			$items = array();
			foreach ( preg_split( "/\r\n|\r|\n/", $raw ) ?: array() as $line ) {
				$line = trim( $line );
				if ( $line === '' ) {
					continue;
				}
				$parts    = array_map( 'trim', explode( '|', $line, 2 ) );
				$question = (string) ( $parts[0] ?? '' );
				$answer   = (string) ( $parts[1] ?? '' );
				if ( $question !== '' && $answer !== '' ) {
					$items[] = array(
						'question' => sanitize_text_field( $question ),
						'answer'   => sanitize_text_field( $answer ),
					);
				}
			}
			if ( empty( $items ) ) {
				delete_post_meta( $post_id, '_ligase_faq_items' );
			} else {
				update_post_meta( $post_id, '_ligase_faq_items', $items );
			}
		}

		// HowTo — meta is array with 'name', 'totalTime', 'steps' (array of {name, text}).
		// Form sends ligase_howto[name|totalTime] + ligase_howto_textarea (pipe-separated).
		if ( isset( $_POST['ligase_howto'] ) || isset( $_POST['ligase_howto_textarea'] ) ) {
			$howto_meta = array();
			$ht         = isset( $_POST['ligase_howto'] ) && is_array( $_POST['ligase_howto'] )
				? wp_unslash( $_POST['ligase_howto'] )
				: array();
			if ( ! empty( $ht['name'] ) ) {
				$howto_meta['name'] = sanitize_text_field( (string) $ht['name'] );
			}
			if ( ! empty( $ht['totalTime'] ) && preg_match( '/^P(?:\d+[YMWD])*(?:T(?:\d+[HMS])*)?$/', (string) $ht['totalTime'] ) ) {
				$howto_meta['totalTime'] = sanitize_text_field( (string) $ht['totalTime'] );
			}
			$steps = array();
			$raw   = isset( $_POST['ligase_howto_textarea'] )
				? wp_strip_all_tags( (string) wp_unslash( $_POST['ligase_howto_textarea'] ) )
				: '';
			foreach ( preg_split( "/\r\n|\r|\n/", $raw ) ?: array() as $line ) {
				$line = trim( $line );
				if ( $line === '' ) {
					continue;
				}
				$parts = array_map( 'trim', explode( '|', $line, 2 ) );
				$name  = (string) ( $parts[0] ?? '' );
				$text  = (string) ( $parts[1] ?? '' );
				if ( $name !== '' && $text !== '' ) {
					$steps[] = array(
						'name' => sanitize_text_field( $name ),
						'text' => sanitize_text_field( $text ),
					);
				}
			}
			if ( ! empty( $steps ) ) {
				$howto_meta['steps'] = $steps;
			}
			if ( empty( $howto_meta ) ) {
				delete_post_meta( $post_id, '_ligase_howto' );
			} else {
				update_post_meta( $post_id, '_ligase_howto', $howto_meta );
			}
		}

		// Structured per-type meta arrays. Each comes from the metabox as
		// `ligase_<type>[field]` and is persisted as `_ligase_<type>` post meta.
		// Whitelisting field keys per type prevents arbitrary meta injection.
		$structured_meta = array(
			'ligase_service'    => array(
				'meta_key' => '_ligase_service',
				'fields'   => array(
					'name'           => 'text',
					'service_type'   => 'text',
					'category'       => 'text',
					'description'    => 'textarea',
					'area_served'    => 'textarea',
					'provider_id'    => 'text',
					'audience'       => 'text',
					'price'          => 'text', // stored as string; float-cast at render
					'price_low'      => 'text',
					'price_high'     => 'text',
					'price_currency' => 'text',
					'availability'   => 'text',
				),
			),
			'ligase_recipe'     => array(
				'meta_key' => '_ligase_recipe',
				'fields'   => array(
					'name'                => 'text',
					'description'         => 'textarea',
					'prepTime'            => 'text',
					'cookTime'            => 'text',
					'totalTime'           => 'text',
					'recipeYield'         => 'text',
					'recipeCategory'      => 'text',
					'recipeCuisine'       => 'text',
					'recipeIngredient'    => 'lines',  // textarea → array of lines
					'recipeInstructions'  => 'lines',
					'calories'            => 'text',
				),
			),
			'ligase_jobposting' => array(
				'meta_key' => '_ligase_jobposting',
				'fields'   => array(
					'title'              => 'text',
					'description'        => 'textarea',
					'datePosted'         => 'text',
					'validThrough'       => 'text',
					'employmentType'     => 'text',
					'hiringOrgName'      => 'text',
					'hiringOrgUrl'       => 'url',
					'jobLocationCity'    => 'text',
					'jobLocationCountry' => 'text',
					'jobLocationType'    => 'text',
					'salaryMin'          => 'text',
					'salaryMax'          => 'text',
					'salaryCurrency'     => 'text',
					'salaryUnit'         => 'text',
					'directApply'        => 'text',
				),
			),
		);

		foreach ( $structured_meta as $post_key => $cfg ) {
			if ( ! isset( $_POST[ $post_key ] ) || ! is_array( $_POST[ $post_key ] ) ) {
				continue;
			}
			$incoming = wp_unslash( $_POST[ $post_key ] );
			$clean    = array();
			foreach ( $cfg['fields'] as $field => $rule ) {
				$raw = $incoming[ $field ] ?? '';
				if ( $raw === '' || $raw === null ) {
					continue;
				}
				switch ( $rule ) {
					case 'url':
						$val = esc_url_raw( (string) $raw );
						break;
					case 'textarea':
						$val = wp_strip_all_tags( (string) $raw );
						$val = trim( (string) preg_replace( "/\r\n|\r/", "\n", $val ) );
						break;
					case 'lines':
						$val = array_values( array_filter( array_map(
							'trim',
							preg_split( "/\r\n|\r|\n/", wp_strip_all_tags( (string) $raw ) ) ?: array()
						) ) );
						break;
					case 'text':
					default:
						$val = sanitize_text_field( (string) $raw );
				}
				if ( $val === '' || $val === array() ) {
					continue;
				}
				$clean[ $field ] = $val;
			}
			if ( empty( $clean ) ) {
				delete_post_meta( $post_id, $cfg['meta_key'] );
			} else {
				update_post_meta( $post_id, $cfg['meta_key'], $clean );
			}
		}

		// _ligase_podcast: per-page PodcastSeries data. Keys: name, description,
		// same_as (textarea, one URL per line), feed_url, language, number_of_episodes.
		// Empty subarray gets stored as deleted so we don't bloat postmeta with [].
		if ( isset( $_POST['_ligase_podcast'] ) && is_array( $_POST['_ligase_podcast'] ) ) {
			$raw  = wp_unslash( $_POST['_ligase_podcast'] );
			$pod  = array();
			$pod['name']                = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );
			$pod['description']         = sanitize_textarea_field( (string) ( $raw['description'] ?? '' ) );
			// same_as: scrub each URL line through esc_url_raw to drop bad schemes.
			$lines = preg_split( '/\r\n|\r|\n/', (string) ( $raw['same_as'] ?? '' ) ) ?: array();
			$clean_lines = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( $line === '' ) { continue; }
				$u = esc_url_raw( $line );
				if ( $u !== '' ) { $clean_lines[] = $u; }
			}
			$pod['same_as']             = implode( "\n", $clean_lines );
			$pod['feed_url']            = esc_url_raw( (string) ( $raw['feed_url'] ?? '' ) );
			$pod['language']            = sanitize_text_field( (string) ( $raw['language'] ?? '' ) );
			$pod['number_of_episodes']  = (string) max( 0, (int) ( $raw['number_of_episodes'] ?? 0 ) );
			if ( $pod['number_of_episodes'] === '0' ) {
				$pod['number_of_episodes'] = '';
			}
			$has_any = ( $pod['name'] !== '' || $pod['description'] !== '' || $pod['same_as'] !== ''
				|| $pod['feed_url'] !== '' || $pod['language'] !== '' || $pod['number_of_episodes'] !== '' );
			if ( $has_any ) {
				update_post_meta( $post_id, '_ligase_podcast', $pod );
			} else {
				delete_post_meta( $post_id, '_ligase_podcast' );
			}
		}

		// _ligase_citations: array of [name, url] entries posted as ligase_citations[N][name|url].
		if ( isset( $_POST['ligase_citations'] ) && is_array( $_POST['ligase_citations'] ) ) {
			$incoming  = wp_unslash( $_POST['ligase_citations'] );
			$citations = array();
			foreach ( $incoming as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$url  = esc_url_raw( (string) ( $row['url'] ?? '' ) );
				$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
				if ( $url ) {
					$citations[] = array( 'name' => $name, 'url' => $url );
				}
			}
			if ( empty( $citations ) ) {
				delete_post_meta( $post_id, '_ligase_citations' );
			} else {
				update_post_meta( $post_id, '_ligase_citations', $citations );
			}
		}

		// Contract-driven manual overrides. Form posts `ligase_override[<Type>][<key>] = value`.
		// We sanitize per-field using the contract's sanitize rule so type-classes never see
		// unfiltered data. Storing only manual overrides — auto values stay in the resolver.
		if ( class_exists( 'Ligase_Field_Contract' ) && isset( $_POST['ligase_override'] ) && is_array( $_POST['ligase_override'] ) ) {
			$existing  = (array) get_post_meta( $post_id, '_ligase_override', true );
			$incoming  = wp_unslash( $_POST['ligase_override'] );
			$result    = $existing;

			// Whitelist `$type` keys against the registered contract types — without
			// this, the form could submit `ligase_override[Foo]` and persist arbitrary
			// keys that some future code path might read.
			$allowed_types = method_exists( 'Ligase_Field_Contract', 'types' )
				? (array) Ligase_Field_Contract::types()
				: array();

			foreach ( $incoming as $type => $fields ) {
				$type = sanitize_text_field( (string) $type );
				if ( ! in_array( $type, $allowed_types, true ) ) {
					continue;
				}
				if ( ! is_array( $fields ) ) {
					continue;
				}
				$contract = Ligase_Field_Contract::get( $type );
				$allowed  = array_keys( $contract['fields'] ?? array() );
				$type_overrides = is_array( $result[ $type ] ?? null ) ? $result[ $type ] : array();

				foreach ( $fields as $key => $raw ) {
					$key = (string) $key;
					if ( ! in_array( $key, $allowed, true ) ) {
						continue;
					}
					$raw = is_string( $raw ) ? trim( $raw ) : $raw;
					if ( $raw === '' || $raw === null ) {
						// Explicit clear → drop the override so auto wins again.
						unset( $type_overrides[ $key ] );
						continue;
					}
					$def     = $contract['fields'][ $key ];
					$sanitize = $def['sanitize'] ?? 'text';
					$type_overrides[ $key ] = self::sanitize_override_value( $raw, $sanitize );
				}

				if ( empty( $type_overrides ) ) {
					unset( $result[ $type ] );
				} else {
					$result[ $type ] = $type_overrides;
				}
			}

			if ( empty( $result ) ) {
				delete_post_meta( $post_id, '_ligase_override' );
			} else {
				update_post_meta( $post_id, '_ligase_override', $result );
			}

			// Bust schema cache for this post on override change.
			if ( class_exists( 'Ligase_Cache' ) ) {
				Ligase_Cache::invalidate_post( $post_id );
			}
		}
	}

	/**
	 * Sanitize a single override value using the contract's sanitize rule.
	 * Centralized here so save_meta_box stays linear.
	 *
	 * @param mixed  $value
	 * @param string $rule  One of: text|html|url|int|float|date|country|currency|passthrough
	 * @return mixed
	 */
	private static function sanitize_override_value( $value, string $rule ) {
		switch ( $rule ) {
			case 'text':
				return is_string( $value ) ? sanitize_text_field( $value ) : '';
			case 'html':
				return is_string( $value ) ? wp_kses_post( $value ) : '';
			case 'url':
				return is_string( $value ) ? esc_url_raw( $value ) : '';
			case 'int':
				return (int) $value;
			case 'float':
				return (float) $value;
			case 'date':
				if ( is_string( $value ) && $value !== '' ) {
					$ts = strtotime( $value );
					return $ts ? gmdate( 'c', $ts ) : '';
				}
				return '';
			case 'country':
				$c = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
				return ( strlen( $c ) === 2 ) ? $c : '';
			case 'currency':
				$c = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
				return ( strlen( $c ) === 3 ) ? $c : '';
			case 'passthrough':
			default:
				return is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}
	}

	// -------------------------------------------------------------------------
	// Author Profile Fields
	// -------------------------------------------------------------------------

	/**
	 * Render additional author profile fields.
	 *
	 * @param WP_User $user The user object being edited.
	 * @return void
	 */
	public function render_author_fields( $user ) {
		if ( ! current_user_can( 'edit_users' ) && get_current_user_id() !== $user->ID ) {
			return;
		}

		// Each field carries its own input type + hint so a single render loop
		// handles text / url / tel / textarea uniformly.
		$fields = array(
			// --- Author entity type (Person vs Organization for redakcja) ---
			'ligase_is_redakcja'  => array( 'label' => __( 'Ten użytkownik to redakcja / zespół (Organization, NIE Person)', 'ligase' ), 'type' => 'checkbox',
				'hint' => __( 'Zaznacz dla kont typu "Redakcja MAKUMI" / "Sales Team" — wpisy tego użytkownika dostaną author = Organization (#org), a osobny węzeł Person NIE zostanie wyemitowany. Wszystkie pola Person poniżej (givenName / hasCredential / itd.) są wtedy ignorowane.', 'ligase' ) ),

			// --- Identity ---
			'ligase_given_name'   => array( 'label' => __( 'Imię (givenName)', 'ligase' ), 'type' => 'text',
				'hint' => __( 'Domyślnie z pola WP "Imię". Wpisz tylko jeśli display_name nie da się sensownie rozbić.', 'ligase' ) ),
			'ligase_family_name'  => array( 'label' => __( 'Nazwisko (familyName)', 'ligase' ), 'type' => 'text',
				'hint' => __( 'Domyślnie z pola WP "Nazwisko".', 'ligase' ) ),
			'ligase_honorific'    => array( 'label' => __( 'Tytuł (honorificPrefix: dr / prof / mgr)', 'ligase' ), 'type' => 'text' ),
			'ligase_job_title'    => array( 'label' => __( 'Stanowisko (jobTitle)', 'ligase' ), 'type' => 'text',
				'hint' => __( 'np. Radca prawny / Senior PHP Developer / Editor-in-Chief', 'ligase' ) ),

			// --- Contact ---
			'ligase_telephone'    => array( 'label' => __( 'Telefon (Person.telephone)', 'ligase' ), 'type' => 'tel',
				'hint' => __( 'Telefon osobisty/służbowy. Inny niż telefon Organization.', 'ligase' ) ),
			'ligase_publish_email' => array( 'label' => __( 'Publikuj email konta WP w Person.email', 'ligase' ), 'type' => 'checkbox',
				'hint' => __( 'Domyślnie wyłączone — email nie wycieka do JSON-LD bez Twojej zgody.', 'ligase' ) ),

			// --- Languages + expertise ---
			'ligase_knows_language' => array( 'label' => __( 'Znane języki (knowsLanguage) — CSV', 'ligase' ), 'type' => 'text',
				'hint' => __( 'Kody ISO 639-1 oddzielone przecinkiem, np: pl, en, de', 'ligase' ) ),
			'ligase_knows_about'   => array( 'label' => __( 'Specjalizacje (knowsAbout) — CSV', 'ligase' ), 'type' => 'textarea',
				'hint' => __( 'Lista tematów rozdzielona przecinkami. To kluczowy sygnał E-E-A-T dla AI.', 'ligase' ) ),

			// --- Education ---
			'ligase_alumni_of'     => array( 'label' => __( 'Uczelnia (alumniOf — name)', 'ligase' ), 'type' => 'text',
				'hint' => __( 'Pełna nazwa uczelni, np: Uniwersytet Marii Curie-Skłodowskiej w Lublinie', 'ligase' ) ),
			'ligase_alumni_of_url' => array( 'label' => __( 'Uczelnia — URL', 'ligase' ), 'type' => 'url',
				'hint' => __( 'np. https://www.umcs.pl/', 'ligase' ) ),
			'ligase_alumni_of_dept' => array( 'label' => __( 'Wydział (department)', 'ligase' ), 'type' => 'text',
				'hint' => __( 'np. Wydział Prawa i Administracji', 'ligase' ) ),

			// --- Credentials (repeater) ---
			'ligase_credentials'   => array( 'label' => __( 'Uprawnienia / dyplomy (hasCredential)', 'ligase' ), 'type' => 'textarea',
				'hint' => __( 'Jeden wpis na linię. Format: Nazwa | category | Wydawca | URL wydawcy | identyfikator | rok\nCategory: license / degree / certification / membership / award\nPrzykład:\nWpis na listę Radców Prawnych | license | Okręgowa Izba Radców Prawnych w Lublinie | https://oirp.lublin.pl/ | LB-2187 | 2013\nMagister prawa | degree | Uniwersytet Marii Curie-Skłodowskiej | https://umcs.pl/ |  | 2010', 'ligase' ) ),

			// --- Membership ---
			'ligase_member_of'     => array( 'label' => __( 'Członek organizacji (memberOf)', 'ligase' ), 'type' => 'textarea',
				'hint' => __( 'Jeden wpis na linię, format: Nazwa | URL\nnp: Okręgowa Izba Radców Prawnych w Lublinie | https://oirp.lublin.pl/', 'ligase' ) ),

			// --- worksFor external (overrides the auto-link to site Organization) ---
			'ligase_works_for_name' => array( 'label' => __( 'worksFor — własna firma / kancelaria (nazwa)', 'ligase' ), 'type' => 'text',
				'hint' => __( 'Wypełnij gdy NIE pracujesz dla wydawcy tej strony, tylko prowadzisz własną kancelarię/firmę. Przykład: Kancelaria Doradztwa Podatkowego Rafał Styczyński. Pozostaw puste, żeby worksFor wskazywał na Organization tej strony.', 'ligase' ) ),
			'ligase_works_for_url' => array( 'label' => __( 'worksFor — URL', 'ligase' ), 'type' => 'url',
				'hint' => __( 'np. https://www.stytax.pl', 'ligase' ) ),

			// --- affiliation (luźne powiązania, w odróżnieniu od memberOf) ---
			'ligase_affiliation'   => array( 'label' => __( 'affiliation (luźne powiązania)', 'ligase' ), 'type' => 'textarea',
				'hint' => __( 'Organizacje z którymi współpracujesz ale nie jesteś formalnym członkiem. memberOf = formalne członkostwo, affiliation = luźne ties. Jeden wpis na linię: Nazwa | URL\nnp: Stowarzyszenie SEO Polska | https://seopolska.org/', 'ligase' ) ),

			// --- subjectOf (cross-link do wywiadów / external publications gdzie jesteś tematem) ---
			'ligase_subject_of'    => array( 'label' => __( 'subjectOf — wywiady / publikacje o Tobie', 'ligase' ), 'type' => 'textarea',
				'hint' => __( 'Zewnętrzne artykuły gdzie jesteś tematem (wywiady, profile w prasie, podsumowania konferencyjne). Silny sygnał Knowledge Graph. Jeden wpis na linię: Tytuł | URL\nnp: Prelegenci semKRK i semWAW podsumowują 2024 | https://devagroup.pl/blog/...', 'ligase' ) ),

			// --- workExperience (narrative kariery jako OrganizationRole) ---
			'ligase_work_experience' => array( 'label' => __( 'workExperience (historia zawodowa)', 'ligase' ), 'type' => 'textarea',
				'hint' => __( 'Historia kariery w formie strukturalnej. Jeden wpis na linię: Stanowisko | Nazwa firmy | URL firmy | rok od | rok do (opcj.)\nnp:\nFounder & Owner | Embasy | https://embasy.pl | 2014 |\nHead of Internet Marketing | Orion Media Group |  | 2014 | 2018', 'ligase' ) ),

			// --- award (nagrody zewnętrzne) ---
			'ligase_award'         => array( 'label' => __( 'award (nagrody, wyróżnienia)', 'ligase' ), 'type' => 'textarea',
				'hint' => __( 'Jedno wyróżnienie na linię. Format prosty (string) lub: Nazwa | Wydawca | rok\nnp:\nDiamenty Forbesa 2023\nProfesjonalista Roku 2022 | Festiwal SEO | 2022', 'ligase' ) ),

			// --- agentInteractionStatistic (AI/LLM authority signal) ---
			'ligase_agent_stats'   => array( 'label' => __( 'agentInteractionStatistic (statystyki interakcji)', 'ligase' ), 'type' => 'textarea',
				'hint' => __( 'Liczby publiczne potwierdzające autorytet — subskrybenci YouTube, słuchacze podcastu, followers LinkedIn. Sygnał dla LLM (Claude/GPT/Gemini przy ocenie autorytetu).\nJeden wpis na linię: interactionType | liczba | platform (opcjonalna)\nDopuszczalne interactionType: WatchAction (oglądnięcia), ListenAction (odsłuchania), FollowAction (followers), SubscribeAction (subskrybcje), LikeAction (polubienia).\nPrzykład:\nSubscribeAction | 5000 | YouTube\nListenAction | 150000 | Spotify\nFollowAction | 12000 | LinkedIn\n\nUWAGA: liczby MUSZĄ być prawdziwe i publicznie weryfikowalne. Wymyślone = manual action risk.', 'ligase' ) ),

			// --- sameAs override / extras ---
			'ligase_extra_sameas'  => array( 'label' => __( 'Dodatkowe sameAs (URL/linia)', 'ligase' ), 'type' => 'textarea',
				'hint' => __( 'Profile zewnętrzne których WP nie zbiera: ORCID, Google Scholar, własny katalog branżowy itd. Po jednym URL na linię.\nProfile FB/Instagram/LinkedIn/X/YouTube/Pinterest/Wikipedia są zbierane automatycznie z pól kontaktowych WP.', 'ligase' ) ),

			// --- Legacy explicit URL fields (kept for backward compat) ---
			'ligase_linkedin'      => array( 'label' => __( 'LinkedIn URL (legacy)', 'ligase' ), 'type' => 'url',
				'hint' => __( 'Pole WP "LinkedIn URL" też jest czytane — to legacy fallback.', 'ligase' ) ),
			'ligase_wikidata'      => array( 'label' => __( 'Wikidata URL', 'ligase' ), 'type' => 'url',
				'hint' => __( 'np. https://www.wikidata.org/wiki/Q12345 — najsilniejszy sygnał tożsamości encji.', 'ligase' ) ),

			// --- Image override ---
			'ligase_image_url'     => array( 'label' => __( 'Zdjęcie profilowe — URL', 'ligase' ), 'type' => 'url',
				'hint' => __( 'Override Gravatara. Min 400×400 px, kwadrat, twarz wycentrowana.', 'ligase' ) ),
		);

		?>
		<h3 id="ligase-author-section"><?php esc_html_e( 'Ligase — Profil autora (Person schema)', 'ligase' ); ?></h3>
		<p class="description" style="max-width:60em;">
			<?php esc_html_e( 'Pełniejsze dane Person = silniejszy sygnał E-E-A-T dla Google AI Overviews oraz cytowalności w LLM. Profile FB/Instagram/X/YouTube/Wikipedia z pól kontaktowych WordPress są zbierane automatycznie do sameAs.', 'ligase' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<?php foreach ( $fields as $key => $cfg ) :
				$value = get_user_meta( $user->ID, $key, true );
				$type  = $cfg['type'] ?? 'text';
				?>
				<tr>
					<th>
						<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $cfg['label'] ); ?></label>
					</th>
					<td>
						<?php if ( $type === 'textarea' ) : ?>
							<textarea
								id="<?php echo esc_attr( $key ); ?>"
								name="<?php echo esc_attr( $key ); ?>"
								rows="<?php echo $key === 'ligase_credentials' ? 6 : 3; ?>"
								class="large-text code"
							><?php echo esc_textarea( (string) $value ); ?></textarea>
						<?php elseif ( $type === 'checkbox' ) : ?>
							<label>
								<input
									type="checkbox"
									id="<?php echo esc_attr( $key ); ?>"
									name="<?php echo esc_attr( $key ); ?>"
									value="1"
									<?php checked( $value, '1' ); ?>
								/>
								<?php esc_html_e( 'Włącz', 'ligase' ); ?>
							</label>
						<?php else : ?>
							<input
								type="<?php echo esc_attr( $type ); ?>"
								id="<?php echo esc_attr( $key ); ?>"
								name="<?php echo esc_attr( $key ); ?>"
								value="<?php echo $type === 'url' ? esc_url( (string) $value ) : esc_attr( (string) $value ); ?>"
								class="regular-text"
							/>
						<?php endif; ?>
						<?php if ( ! empty( $cfg['hint'] ) ) : ?>
							<p class="description" style="white-space: pre-line;"><?php echo esc_html( $cfg['hint'] ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Save author profile fields.
	 *
	 * @param int $user_id The user ID being saved.
	 * @return void
	 */
	public function save_author_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// Check the user-edit nonce that WordPress sets on the profile page.
		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id )
		) {
			return;
		}

		// Short-text Person fields
		$text_fields = array(
			'ligase_given_name', 'ligase_family_name', 'ligase_honorific',
			'ligase_job_title', 'ligase_telephone', 'ligase_knows_language',
			'ligase_alumni_of', 'ligase_alumni_of_dept', 'ligase_credential', // ligase_credential kept for backward compat
			'ligase_works_for_name',
		);
		foreach ( $text_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_user_meta( $user_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		// URL fields
		$url_fields = array(
			'ligase_linkedin', 'ligase_twitter', 'ligase_wikidata',
			'ligase_alumni_of_url', 'ligase_image_url', 'ligase_works_for_url',
		);
		foreach ( $url_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_user_meta( $user_id, $key, esc_url_raw( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		// Multiline textarea fields — preserve newlines, strip tags. wp_kses_post would
		// allow inline HTML which we don't want in a structured-data feed.
		$textarea_fields = array(
			'ligase_knows_about',     // can be long CSV
			'ligase_credentials',     // repeater
			'ligase_member_of',       // repeater
			'ligase_extra_sameas',    // URL per line
			'ligase_affiliation',     // repeater Name | URL
			'ligase_subject_of',      // repeater Title | URL
			'ligase_work_experience', // repeater Role | Org | URL | start | end
			'ligase_award',           // one award per line (string or Name | Issuer | year)
			'ligase_agent_stats',     // repeater interactionType | count | platform
		);
		// Fields whose lines may contain URL tokens (separated by '|' or one per line).
		// wp_strip_all_tags scrubs HTML but does NOT drop `javascript:` / `data:` /
		// `vbscript:` schemes — pipe each URL-looking part through esc_url_raw() which
		// only allows whitelisted protocols (http/https/mailto/etc.).
		$url_bearing = array( 'ligase_credentials', 'ligase_member_of', 'ligase_extra_sameas',
			'ligase_affiliation', 'ligase_subject_of', 'ligase_work_experience', 'ligase_agent_stats' );
		foreach ( $textarea_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$raw = (string) wp_unslash( $_POST[ $key ] );
				// Normalize line endings, strip tags, keep newlines + pipes for repeaters.
				$raw = wp_strip_all_tags( $raw );
				$raw = preg_replace( "/\r\n|\r/", "\n", $raw );

				if ( in_array( $key, $url_bearing, true ) ) {
					$lines = explode( "\n", (string) $raw );
					foreach ( $lines as $idx => $line ) {
						if ( strpos( $line, '|' ) !== false ) {
							$parts = array_map( 'trim', explode( '|', $line ) );
							foreach ( $parts as $pi => $part ) {
								// Recognise tokens that look like URLs (contain '://' or start with `javascript:` / `data:` etc).
								if ( $part !== '' && preg_match( '#^([a-z][a-z0-9+\-.]*):#i', $part ) ) {
									$parts[ $pi ] = esc_url_raw( $part );
								}
							}
							$lines[ $idx ] = implode( ' | ', $parts );
						} else {
							$trimmed = trim( $line );
							if ( $trimmed !== '' && preg_match( '#^([a-z][a-z0-9+\-.]*):#i', $trimmed ) ) {
								$lines[ $idx ] = esc_url_raw( $trimmed );
							}
						}
					}
					$raw = implode( "\n", $lines );
				}

				update_user_meta( $user_id, $key, trim( (string) $raw ) );
			}
		}

		// Checkboxes — saved as '1' or '0' so the read-side checks behave consistently.
		foreach ( array( 'ligase_publish_email', 'ligase_is_redakcja' ) as $cb_key ) {
			update_user_meta(
				$user_id,
				$cb_key,
				isset( $_POST[ $cb_key ] ) && (string) $_POST[ $cb_key ] === '1' ? '1' : '0'
			);
		}
	}
}
