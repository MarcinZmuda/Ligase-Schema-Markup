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
			array(
				'title' => __( 'Narz\u0119dzia', 'ligase' ),
				'slug'  => 'ligase-narzedzia',
				'cap'   => 'manage_options',
			),
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
			array( 'jquery' ),
			$this->version,
			true
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
				array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'ligase-admin' ),
				$this->version,
				true
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
			! wp_verify_nonce( $_POST['ligase_meta_nonce'], 'ligase_meta_save' )
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

		// Check permissions.
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! current_user_can( $post_type_obj->cap->edit_post, $post_id ) ) {
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
		);
		foreach ( $toggles as $key ) {
			$value = isset( $_POST[ $key ] ) ? '1' : '0';
			update_post_meta( $post_id, $key, $value );
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

		$fields = array(
			'ligase_honorific'   => __( 'Tytul (dr., prof., mgr.)', 'ligase' ),
			'ligase_job_title'   => __( 'Stanowisko (Job Title)', 'ligase' ),
			'ligase_knows_about' => __( 'Zna sie na (Knows About)', 'ligase' ),
			'ligase_alumni_of'   => __( 'Uczelnia (alumniOf)', 'ligase' ),
			'ligase_credential'  => __( 'Certyfikat / kwalifikacja', 'ligase' ),
			'ligase_linkedin'    => __( 'LinkedIn URL', 'ligase' ),
			'ligase_twitter'     => __( 'Twitter / X URL', 'ligase' ),
			'ligase_wikidata'    => __( 'Wikidata URL', 'ligase' ),
		);

		$url_fields = array( 'ligase_linkedin', 'ligase_twitter', 'ligase_wikidata' );

		?>
		<h3><?php esc_html_e( 'Ligase &mdash; Profil autora', 'ligase' ); ?></h3>
		<table class="form-table" role="presentation">
			<?php foreach ( $fields as $key => $label ) : ?>
				<tr>
					<th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<?php if ( in_array( $key, $url_fields, true ) ) : ?>
							<input
								type="url"
								id="<?php echo esc_attr( $key ); ?>"
								name="<?php echo esc_attr( $key ); ?>"
								value="<?php echo esc_url( get_user_meta( $user->ID, $key, true ) ); ?>"
								class="regular-text"
							/>
						<?php else : ?>
							<input
								type="text"
								id="<?php echo esc_attr( $key ); ?>"
								name="<?php echo esc_attr( $key ); ?>"
								value="<?php echo esc_attr( get_user_meta( $user->ID, $key, true ) ); ?>"
								class="regular-text"
							/>
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
			! wp_verify_nonce( $_POST['_wpnonce'], 'update-user_' . $user_id )
		) {
			return;
		}

		$text_fields = array( 'ligase_honorific', 'ligase_job_title', 'ligase_knows_about', 'ligase_alumni_of', 'ligase_credential' );
		$url_fields  = array( 'ligase_linkedin', 'ligase_twitter', 'ligase_wikidata' );

		foreach ( $text_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_user_meta( $user_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		foreach ( $url_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_user_meta( $user_id, $key, esc_url_raw( wp_unslash( $_POST[ $key ] ) ) );
			}
		}
	}
}
