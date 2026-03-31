<?php
/**
 * Ligase - AJAX Handler
 *
 * Handles all AJAX endpoints for the React admin panel.
 *
 * @package Ligase
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ligase_Ajax
 *
 * Registers and processes all wp_ajax_ endpoints used by the
 * Ligase React admin interface.
 */
class Ligase_Ajax {

	/**
	 * Constructor. Registers all AJAX action hooks.
	 */
	public function __construct() {
		$actions = array(
			'ligase_dashboard_stats',
			'ligase_scan_post',
			'ligase_scan_all_posts',
			'ligase_fix_post',
			'ligase_fix_all_posts',
			'ligase_preview_json',
			'ligase_apply_audit_replacements',
			'ligase_wikidata',
			'ligase_get_readiness_score',
			'ligase_get_author_scores',
			'ligase_get_plugin_conflicts',
			'ligase_export_settings',
			'ligase_import_settings',
			'ligase_auto_repair',
			'ligase_clear_cache',
			'ligase_detect_import_sources',
			'ligase_run_import',
			'ligase_validate_post',
			'ligase_run_health_report',
			'ligase_gsc_save_credentials',
			'ligase_gsc_disconnect',
			'ligase_gsc_test_connection',
			'ligase_gsc_sync',
			'ligase_gsc_rich_results',
			'ligase_ner_run_post',
			'ligase_ner_run_bulk',
			'ligase_ner_bulk_status',
			'ligase_ner_save_entities',
			'ligase_get_schema_rules',
			'ligase_save_schema_rule',
			'ligase_delete_schema_rule',
			'ligase_toggle_schema_rule',
		);

		foreach ( $actions as $action ) {
			add_action( "wp_ajax_{$action}", array( $this, "handle_{$action}" ) );
		}
	}

	/**
	 * Verify the request nonce and user capability.
	 *
	 * Sends a JSON error and terminates if verification fails.
	 *
	 * @return void
	 */
	private function verify_request(): void {
		if ( ! check_ajax_referer( 'ligase_admin', 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid security token.', 'ligase' ) ),
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'ligase' ) ),
				403
			);
		}
	}

	/**
	 * Endpoint: ligase_dashboard_stats
	 *
	 * Returns dashboard statistics including schema coverage counts
	 * and active rich result types.
	 */
	public function handle_ligase_dashboard_stats(): void {
		$this->verify_request();

		$cache_key = 'ligase_dashboard_stats';
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_send_json_success( $cached );
			return;
		}

		$complete = 0;
		$warnings = 0;
		$missing  = 0;
		$page     = 1;
		$per_page = 100;
		$score_calculator = new Ligase_Score();

		do {
			$posts = get_posts( array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			) );

			foreach ( $posts as $post_id ) {
				$result = $score_calculator->calculate_for_post( $post_id );
				$score  = $result['score'] ?? 0;

				if ( $score >= 70 ) {
					++$complete;
				} elseif ( $score > 0 ) {
					++$warnings;
				} else {
					++$missing;
				}
			}

			++$page;
		} while ( count( $posts ) === $per_page );

		$active_types = 4; // Article, BreadcrumbList, Organization, WebSite always active

		$data = array(
			'complete'     => $complete,
			'warnings'     => $warnings,
			'missing'      => $missing,
			'active_types' => $active_types,
		);

		set_transient( $cache_key, $data, HOUR_IN_SECONDS );
		wp_send_json_success( $data );
	}

	/**
	 * Endpoint: ligase_scan_post
	 *
	 * Scans a single post for entity hints and schema suggestions.
	 */
	public function handle_ligase_scan_post(): void {
		$this->verify_request();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'ligase' ) ) );
		}

		Ligase_Logger::info( sprintf( 'Scanning post %d.', $post_id ) );

		try {
			$pipeline = new Ligase_Entity_Pipeline();
			$result   = $pipeline->analyze( $post_id );

			Ligase_Logger::info( sprintf( 'Post %d scanned successfully.', $post_id ) );

			wp_send_json_success( $result );
		} catch ( \Exception $e ) {
			Ligase_Logger::error( sprintf( 'Scan post %d failed: %s', $post_id, $e->getMessage() ) );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_scan_all_posts
	 *
	 * Batch-scans all published posts and returns audit data.
	 */
	public function handle_ligase_scan_all_posts(): void {
		$this->verify_request();

		Ligase_Logger::info( 'Starting full site scan.' );

		try {
			$auditor = new Ligase_Auditor();
			$results = $auditor->scan_all_posts();

			Ligase_Logger::info( sprintf( 'Full site scan complete. %d posts scanned.', count( $results ) ) );

			wp_send_json_success( $results );
		} catch ( \Exception $e ) {
			Ligase_Logger::error( 'Full site scan failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_fix_post
	 *
	 * Applies schema replacement for a single post.
	 */
	public function handle_ligase_fix_post(): void {
		$this->verify_request();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'ligase' ) ) );
		}

		Ligase_Logger::info( sprintf( 'Fixing schema for post %d.', $post_id ) );

		try {
			$auditor = new Ligase_Auditor();
			$result  = $auditor->apply_replacement( $post_id );

			if ( $result ) {
				$score = ( new Ligase_Score() )->calculate_for_post( $post_id );

				Ligase_Logger::info( sprintf( 'Post %d fixed. New score: %d.', $post_id, $score ) );

				wp_send_json_success(
					array(
						'post_id'   => $post_id,
						'new_score' => $score,
					)
				);
			} else {
				Ligase_Logger::warning( sprintf( 'Post %d fix returned no changes.', $post_id ) );
				wp_send_json_error( array( 'message' => __( 'Could not apply replacement.', 'ligase' ) ) );
			}
		} catch ( \Exception $e ) {
			Ligase_Logger::error( sprintf( 'Fix post %d failed: %s', $post_id, $e->getMessage() ) );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_fix_all_posts
	 *
	 * Batch-fixes all posts with schema scores below a given threshold.
	 */
	public function handle_ligase_fix_all_posts(): void {
		$this->verify_request();

		$threshold = isset( $_POST['threshold'] ) ? absint( $_POST['threshold'] ) : 50;

		Ligase_Logger::info( sprintf( 'Batch fixing posts below threshold %d.', $threshold ) );

		try {
			// Find all posts below threshold first
			$score_calculator = new Ligase_Score();
			$all_posts = get_posts( array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );

			$post_ids_to_fix = array();
			foreach ( $all_posts as $pid ) {
				$result = $score_calculator->calculate_for_post( $pid );
				if ( $result['score'] < $threshold ) {
					$post_ids_to_fix[] = $pid;
				}
			}

			$auditor = new Ligase_Auditor();
			$results = $auditor->apply_all_replacements( $post_ids_to_fix );

			$fixed  = 0;
			$failed = 0;

			foreach ( $results as $post_id => $success ) {
				if ( $success ) {
					++$fixed;
				} else {
					++$failed;
				}
			}

			Ligase_Logger::info( sprintf( 'Batch fix complete. Fixed: %d, Failed: %d.', $fixed, $failed ) );

			wp_send_json_success(
				array(
					'fixed'   => $fixed,
					'failed'  => $failed,
					'results' => $results,
				)
			);
		} catch ( \Exception $e ) {
			Ligase_Logger::error( 'Batch fix failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_preview_json
	 *
	 * Previews JSON-LD output for a given post without rendering it.
	 */
	public function handle_ligase_preview_json(): void {
		$this->verify_request();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'ligase' ) ) );
		}

		Ligase_Logger::info( sprintf( 'Generating JSON-LD preview for post %d.', $post_id ) );

		try {
			$generator = new Ligase_Generator();
			$schema    = $generator->get_graph_for_post( $post_id );

			$json = wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Ligase_Logger::error( 'JSON encoding error', array( 'error' => json_last_error_msg() ) );
				wp_send_json_error( array( 'message' => 'JSON encoding failed: ' . json_last_error_msg() ) );
				return;
			}

			wp_send_json_success( array( 'json' => $json ) );
		} catch ( \Exception $e ) {
			Ligase_Logger::error( sprintf( 'JSON-LD preview for post %d failed: %s', $post_id, $e->getMessage() ) );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_apply_audit_replacements
	 *
	 * Applies auditor replacements for selected posts.
	 */
	public function handle_ligase_apply_audit_replacements(): void {
		$this->verify_request();

		$post_ids = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] )
			? array_map( 'absint', $_POST['post_ids'] )
			: array();
		$mode     = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'replace';

		if ( empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No post IDs provided.', 'ligase' ) ) );
		}

		if ( ! in_array( $mode, array( 'replace', 'supplement' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid mode. Use "replace" or "supplement".', 'ligase' ) ) );
		}

		Ligase_Logger::info( sprintf( 'Applying audit replacements to %d posts (mode: %s).', count( $post_ids ), $mode ) );

		try {
			$auditor = new Ligase_Auditor();
			$results = array();

			foreach ( $post_ids as $post_id ) {
				if ( ! get_post( $post_id ) ) {
					$results[] = array(
						'post_id' => $post_id,
						'success' => false,
						'message' => __( 'Post not found.', 'ligase' ),
					);
					continue;
				}

				$outcome = $auditor->apply_replacement( $post_id );

				$results[] = array(
					'post_id' => $post_id,
					'success' => (bool) $outcome,
					'message' => $outcome
						? __( 'Replacement applied.', 'ligase' )
						: __( 'No changes applied.', 'ligase' ),
				);
			}

			Ligase_Logger::info( sprintf( 'Audit replacements finished for %d posts.', count( $results ) ) );

			wp_send_json_success( array( 'results' => $results ) );
		} catch ( \Exception $e ) {
			Ligase_Logger::error( 'Audit replacements failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_wikidata
	 *
	 * Searches Wikidata for entity matches by name.
	 */
	public function handle_ligase_wikidata(): void {
		$this->verify_request();

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Name parameter is required.', 'ligase' ) ) );
		}

		Ligase_Logger::info( sprintf( 'Wikidata search: "%s".', $name ) );

		try {
			$lookup  = new Ligase_Wikidata_Lookup();
			$matches = $lookup->search( $name );

			wp_send_json_success( array( 'matches' => $matches ) );
		} catch ( \Exception $e ) {
			Ligase_Logger::error( 'Wikidata search failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_get_readiness_score
	 *
	 * Calculates the overall AI Search Readiness Score for the site.
	 */
	public function handle_ligase_get_readiness_score(): void {
		$this->verify_request();

		Ligase_Logger::info( 'Calculating AI Search Readiness Score.' );

		try {
			$score_calculator = new Ligase_Score();
			$result           = $score_calculator->calculate();

			wp_send_json_success(
				array(
					'score'           => $result['score'],
					'checks'          => $result['checks'],
					'recommendations' => $result['recommendations'],
				)
			);
		} catch ( \Exception $e ) {
			Ligase_Logger::error( 'Readiness score calculation failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_get_author_scores
	 *
	 * Returns E-E-A-T scores for all authors with published posts.
	 */
	public function handle_ligase_get_author_scores(): void {
		$this->verify_request();

		Ligase_Logger::info( 'Calculating author E-E-A-T scores.' );

		try {
			$score_calculator = new Ligase_Score();

			$authors = get_users(
				array(
					'has_published_posts' => true,
					'fields'             => array( 'ID', 'display_name' ),
				)
			);

			$results = array();

			foreach ( $authors as $author ) {
				$user_id = absint( $author->ID );
				$data    = $score_calculator->calculate_for_author( $user_id );
				$user    = get_userdata( $user_id );

				$results[] = array(
					'user_id' => $user_id,
					'name'    => sanitize_text_field( $author->display_name ),
					'role'    => $user ? implode( ', ', $user->roles ) : '',
					'score'   => $data['score'],
					'issues'  => $data['recommendations'],
				);
			}

			Ligase_Logger::info( sprintf( 'Author scores calculated for %d authors.', count( $results ) ) );

			wp_send_json_success( array( 'authors' => $results ) );
		} catch ( \Exception $e ) {
			Ligase_Logger::error( 'Author scores calculation failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_get_plugin_conflicts
	 *
	 * Detects other schema/SEO plugins that may conflict with Ligase.
	 */
	public function handle_ligase_get_plugin_conflicts(): void {
		$this->verify_request();

		Ligase_Logger::info( 'Detecting plugin conflicts.' );

		try {
			$auditor = new Ligase_Auditor();
			$plugins = $auditor->get_detected_plugins();

			wp_send_json_success( array( 'plugins' => $plugins ) );
		} catch ( \Exception $e ) {
			Ligase_Logger::error( 'Plugin conflict detection failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_export_settings
	 *
	 * Exports all plugin settings and author meta as a JSON string.
	 */
	public function handle_ligase_export_settings(): void {
		$this->verify_request();

		Ligase_Logger::info( 'Exporting plugin settings.' );

		try {
			$options = get_option( 'ligase_options', array() );

			$authors = get_users(
				array(
					'has_published_posts' => true,
					'fields'             => array( 'ID' ),
				)
			);

			$author_meta = array();

			foreach ( $authors as $author ) {
				$user_id  = absint( $author->ID );
				$all_meta = get_user_meta( $user_id );
				$ligase_meta = array();

				foreach ( $all_meta as $key => $values ) {
					if ( str_starts_with( $key, 'ligase_' ) ) {
						$ligase_meta[ $key ] = maybe_unserialize( $values[0] );
					}
				}

				if ( ! empty( $ligase_meta ) ) {
					$author_meta[ $user_id ] = $ligase_meta;
				}
			}

			$export = array(
				'version'     => LIGASE_VERSION ?? '1.0.0',
				'exported_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'options'     => $options,
				'author_meta' => $author_meta,
			);

			$json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			Ligase_Logger::info( 'Settings exported successfully.' );

			wp_send_json_success( array( 'json' => $json ) );
		} catch ( \Exception $e ) {
			Ligase_Logger::error( 'Settings export failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_import_settings
	 *
	 * Imports plugin settings from a JSON string.
	 */
	public function handle_ligase_import_settings(): void {
		$this->verify_request();

		$json_data = isset( $_POST['json_data'] ) ? wp_unslash( $_POST['json_data'] ) : '';

		if ( empty( $json_data ) ) {
			wp_send_json_error( array( 'message' => __( 'No JSON data provided.', 'ligase' ) ) );
		}

		Ligase_Logger::info( 'Importing plugin settings.' );

		try {
			$data = json_decode( $json_data, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				wp_send_json_error(
					array( 'message' => __( 'Invalid JSON format.', 'ligase' ) )
				);
			}

			if ( ! is_array( $data ) || ! isset( $data['options'] ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Invalid export file structure.', 'ligase' ) )
				);
			}

			// Whitelist allowed option keys
			$allowed_keys = array(
				'org_name', 'org_logo', 'org_email', 'knows_about',
				'social_wikidata', 'social_wikipedia', 'social_linkedin',
				'social_facebook', 'social_twitter', 'social_youtube',
				'standalone_mode', 'force_output', 'logo_width', 'logo_height',
				'debug_mode',
			);
			$url_keys = array( 'org_logo', 'social_wikidata', 'social_wikipedia', 'social_linkedin', 'social_facebook', 'social_twitter', 'social_youtube' );

			$sanitized_options = array();
			foreach ( (array) $data['options'] as $key => $value ) {
				if ( ! in_array( $key, $allowed_keys, true ) ) {
					continue;
				}
				if ( in_array( $key, $url_keys, true ) ) {
					$sanitized_options[ $key ] = esc_url_raw( $value );
				} elseif ( $key === 'org_email' ) {
					$sanitized_options[ $key ] = sanitize_email( $value );
				} elseif ( in_array( $key, array( 'standalone_mode', 'force_output', 'debug_mode' ), true ) ) {
					$sanitized_options[ $key ] = $value ? '1' : '0';
				} elseif ( in_array( $key, array( 'logo_width', 'logo_height' ), true ) ) {
					$sanitized_options[ $key ] = absint( $value );
				} else {
					$sanitized_options[ $key ] = sanitize_text_field( $value );
				}
			}
			update_option( 'ligase_options', $sanitized_options );

			// Import author meta.
			if ( ! empty( $data['author_meta'] ) && is_array( $data['author_meta'] ) ) {
				foreach ( $data['author_meta'] as $user_id => $meta_entries ) {
					$user_id = absint( $user_id );

					if ( ! get_userdata( $user_id ) ) {
						continue;
					}

					foreach ( $meta_entries as $key => $value ) {
						$key = sanitize_key( $key );

						if ( ! str_starts_with( $key, 'ligase_' ) ) {
							continue;
						}

						$value = is_array( $value )
							? array_map( 'sanitize_text_field', $value )
							: sanitize_text_field( $value );

						update_user_meta( $user_id, $key, $value );
					}
				}
			}

			Ligase_Logger::info( 'Settings imported successfully.' );

			wp_send_json_success( array( 'message' => __( 'Settings imported successfully.', 'ligase' ) ) );
		} catch ( \Exception $e ) {
			Ligase_Logger::error( 'Settings import failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_auto_repair
	 *
	 * Runs selected auto-repair operations across all published posts.
	 */
	public function handle_ligase_auto_repair(): void {
		$this->verify_request();

		$repairs = isset( $_POST['repairs'] ) && is_array( $_POST['repairs'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['repairs'] ) )
			: array();

		$allowed_repairs = array( 'fix_dates', 'truncate_headlines', 'convert_article_to_blogposting' );
		$repairs         = array_intersect( $repairs, $allowed_repairs );

		if ( empty( $repairs ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid repair operations specified.', 'ligase' ) ) );
		}

		Ligase_Logger::info( sprintf( 'Running auto-repair: %s.', implode( ', ', $repairs ) ) );

		try {
			$processed = 0;
			$fixed     = 0;
			$errors    = 0;
			$page      = 1;
			$per_page  = 100;

			do {
				$posts = get_posts( array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => $per_page,
					'paged'          => $page,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				) );

			foreach ( $posts as $post_id ) {
				++$processed;

				foreach ( $repairs as $repair ) {
					try {
						$schema_json = get_post_meta( $post_id, '_ligase_schema', true );

						if ( empty( $schema_json ) ) {
							continue;
						}

						$schema  = json_decode( $schema_json, true );
						$changed = false;

						switch ( $repair ) {
							case 'fix_dates':
								if ( ! empty( $schema['datePublished'] ) ) {
									$timestamp = strtotime( $schema['datePublished'] );
									if ( $timestamp ) {
										$iso_date = gmdate( 'Y-m-d\TH:i:sP', $timestamp );
										if ( $schema['datePublished'] !== $iso_date ) {
											$schema['datePublished'] = $iso_date;
											$changed                 = true;
										}
									}
								}
								if ( ! empty( $schema['dateModified'] ) ) {
									$timestamp = strtotime( $schema['dateModified'] );
									if ( $timestamp ) {
										$iso_date = gmdate( 'Y-m-d\TH:i:sP', $timestamp );
										if ( $schema['dateModified'] !== $iso_date ) {
											$schema['dateModified'] = $iso_date;
											$changed                = true;
										}
									}
								}
								break;

							case 'truncate_headlines':
								if ( ! empty( $schema['headline'] ) && mb_strlen( $schema['headline'] ) > 110 ) {
									$schema['headline'] = mb_substr( $schema['headline'], 0, 110 );
									$changed            = true;
								}
								break;

							case 'convert_article_to_blogposting':
								if ( ! empty( $schema['@type'] ) && 'Article' === $schema['@type'] ) {
									$schema['@type'] = 'BlogPosting';
									$changed         = true;
								}
								break;
						}

						if ( $changed ) {
							update_post_meta(
								$post_id,
								'_ligase_schema',
								wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
							);
							++$fixed;
						}
					} catch ( \Exception $e ) {
						Ligase_Logger::error( sprintf( 'Auto-repair error on post %d (%s): %s', $post_id, $repair, $e->getMessage() ) );
						++$errors;
					}
				}
			}

			++$page;
			} while ( count( $posts ) === $per_page );

			Ligase_Logger::info( sprintf( 'Auto-repair complete. Processed: %d, Fixed: %d, Errors: %d.', $processed, $fixed, $errors ) );

			wp_send_json_success(
				array(
					'processed' => $processed,
					'fixed'     => $fixed,
					'errors'    => $errors,
				)
			);
		} catch ( \Exception $e ) {
			Ligase_Logger::error( 'Auto-repair failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Endpoint: ligase_clear_cache
	 *
	 * Clears all schema-related caches.
	 */
	public function handle_ligase_clear_cache(): void {
		$this->verify_request();

		Ligase_Logger::info( 'Clearing schema cache.' );

		try {
			Ligase_Cache::invalidate_all();

			Ligase_Logger::info( 'Schema cache cleared successfully.' );

			wp_send_json_success( array( 'message' => __( 'Cache cleared successfully.', 'ligase' ) ) );
		} catch ( \Exception $e ) {
			Ligase_Logger::error( 'Cache clear failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	// =====================================================================
	// Importer
	// =====================================================================

	public function handle_ligase_detect_import_sources(): void {
		$this->verify_request();

		$importer = new Ligase_Importer();
		wp_send_json_success( array( 'sources' => $importer->detect_sources() ) );
	}

	public function handle_ligase_run_import(): void {
		$this->verify_request();

		$source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
		if ( empty( $source ) ) {
			wp_send_json_error( array( 'message' => 'Missing source parameter.' ) );
		}

		$importer = new Ligase_Importer();
		$result   = $importer->import( $source );

		wp_send_json_success( $result );
	}

	// =====================================================================
	// Validator
	// =====================================================================

	public function handle_ligase_validate_post(): void {
		$this->verify_request();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID.' ) );
		}

		$validator = new Ligase_Validator();
		$result    = $validator->validate_post( $post_id );

		wp_send_json_success( $result );
	}

	// =====================================================================
	// Health Report
	// =====================================================================

	public function handle_ligase_run_health_report(): void {
		$this->verify_request();

		Ligase_Health_Report::run();

		wp_send_json_success( array( 'message' => 'Raport zdrowia wyslany na email admina.' ) );
	}

	// =====================================================================
	// Google Search Console
	// =====================================================================

	public function handle_ligase_gsc_save_credentials(): void {
		$this->verify_request();

		$json = isset( $_POST['service_account_json'] ) ? wp_unslash( $_POST['service_account_json'] ) : '';
		if ( empty( $json ) ) {
			wp_send_json_error( array( 'message' => 'Brak danych JSON.' ) );
		}

		$result = Ligase_GSC::save_service_account( $json );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Optionally set site URL
		$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		if ( $site_url ) {
			Ligase_GSC::set_site_url( $site_url );
		}

		wp_send_json_success( array( 'message' => 'Credentials GSC zapisane i zaszyfrowane.' ) );
	}

	public function handle_ligase_gsc_disconnect(): void {
		$this->verify_request();

		Ligase_GSC::disconnect();

		wp_send_json_success( array( 'message' => 'GSC rozlaczony.' ) );
	}

	public function handle_ligase_gsc_test_connection(): void {
		$this->verify_request();

		$token = Ligase_GSC::get_access_token();
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( array( 'message' => $token->get_error_message() ) );
		}

		$sites = Ligase_GSC::list_sites();
		if ( is_wp_error( $sites ) ) {
			wp_send_json_error( array( 'message' => $sites->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => 'Polaczenie z GSC poprawne.',
			'sites'   => $sites['siteEntry'] ?? [],
		) );
	}

	public function handle_ligase_gsc_sync(): void {
		$this->verify_request();

		$result = Ligase_GSC::sync_to_posts();

		wp_send_json_success( array(
			'message' => sprintf( 'Zsynchronizowano %d postow.', $result['synced'] ),
			'synced'  => $result['synced'],
		) );
	}

	public function handle_ligase_gsc_rich_results(): void {
		$this->verify_request();

		$data = Ligase_GSC::get_rich_results_data();
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		wp_send_json_success( $data );
	}

	// =========================================================================
	// NER API handlers
	// =========================================================================

	/**
	 * Run AI NER for a single post (on-demand).
	 */
	public function handle_ligase_ner_run_post(): void {
		$this->verify_request();

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID.' ) );
		}

		$ner = new Ligase_NER_API();

		if ( ! $ner->is_configured() ) {
			wp_send_json_error( array(
				'message' => 'AI NER provider not configured. Go to Ligase → Settings → AI Entity Detection.',
				'code'    => 'not_configured',
			) );
		}

		// Force fresh result (clear cache)
		$post = get_post( $post_id );
		delete_transient( 'ligase_ner_api_' . $post_id . '_' . md5( $post->post_modified ) );

		$result = $ner->extract( $post_id );

		if ( ! $result ) {
			wp_send_json_error( array(
				'message' => 'AI NER failed. Check your API key and try again.',
				'code'    => 'api_error',
			) );
		}

		wp_send_json_success( array(
			'entities' => $result,
			'provider' => get_option( 'ligase_options', array() )['ner_provider'] ?? '',
			'post_id'  => $post_id,
		) );
	}

	/**
	 * Schedule bulk AI NER for all posts.
	 */
	public function handle_ligase_ner_run_bulk(): void {
		$this->verify_request();

		$ner = new Ligase_NER_API();

		if ( ! $ner->is_configured() ) {
			wp_send_json_error( array(
				'message' => 'AI NER provider not configured.',
				'code'    => 'not_configured',
			) );
		}

		$force     = ! empty( $_POST['force'] );
		$scheduled = $ner->schedule_bulk( $force );

		$post_count = (int) wp_count_posts( 'post' )->publish;
		$estimate   = $ner->estimate_cost( $scheduled );

		// Reset counter
		update_option( 'ligase_ner_bulk_done', 0 );

		wp_send_json_success( array(
			'scheduled'       => $scheduled,
			'total_posts'     => $post_count,
			'already_done'    => $post_count - $scheduled,
			'estimated_cost'  => $estimate['cost_formatted'],
			'provider'        => $estimate['provider'],
			'message'         => sprintf(
				'%d posts scheduled. Estimated cost: %s. Processing in background via WP-Cron.',
				$scheduled,
				$estimate['cost_formatted']
			),
		) );
	}

	/**
	 * Get bulk NER scan progress.
	 */
	public function handle_ligase_ner_bulk_status(): void {
		$this->verify_request();
		wp_send_json_success( Ligase_NER_API::get_bulk_status() );
	}

	/**
	 * Save selected entities to post meta (user accepts from UI).
	 */
	public function handle_ligase_ner_save_entities(): void {
		$this->verify_request();

		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$entities = wp_unslash( $_POST['entities'] ?? array() );

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID.' ) );
		}

		if ( ! is_array( $entities ) ) {
			wp_send_json_error( array( 'message' => 'Invalid entities data.' ) );
		}

		// Sanitize
		$about    = array();
		$mentions = array();

		foreach ( $entities as $entity ) {
			$name = sanitize_text_field( $entity['name'] ?? '' );
			if ( empty( $name ) ) {
				continue;
			}
			$type = sanitize_text_field( $entity['save_as'] ?? 'mention' );
			if ( $type === 'about' ) {
				$about[] = array(
					'name'   => $name,
					'sameAs' => esc_url_raw( $entity['wikidata_url'] ?? '' ),
				);
			} else {
				$mentions[] = array( 'name' => $name );
			}
		}

		update_post_meta( $post_id, '_ligase_about_entities', $about );
		update_post_meta( $post_id, '_ligase_mentions', $mentions );

		// Invalidate schema cache for this post
		Ligase_Cache::invalidate_post( $post_id );

		wp_send_json_success( array(
			'about'    => count( $about ),
			'mentions' => count( $mentions ),
			'message'  => sprintf(
				'Saved: %d about entities, %d mentions. Schema cache cleared.',
				count( $about ),
				count( $mentions )
			),
		) );
	}

	// =========================================================================
	// Schema Rules AJAX handlers
	// =========================================================================

	/**
	 * Get all schema automation rules.
	 */
	public function handle_ligase_get_schema_rules(): void {
		$this->verify_request();

		$rules      = Ligase_Schema_Rules::get_rules();
		$categories = get_categories( array( 'hide_empty' => false, 'number' => 200 ) );
		$tags       = get_tags( array( 'hide_empty' => false, 'number' => 200 ) );
		$authors    = get_users( array( 'has_published_posts' => true ) );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		wp_send_json_success( array(
			'rules'       => $rules,
			'schema_types'=> Ligase_Schema_Rules::SCHEMA_TYPES,
			'categories'  => array_map( fn( $c ) => array( 'id' => $c->term_id, 'name' => $c->name ), $categories ),
			'tags'        => array_map( fn( $t ) => array( 'id' => $t->term_id, 'name' => $t->name ), $tags ),
			'authors'     => array_map( fn( $u ) => array( 'id' => $u->ID, 'name' => $u->display_name ), $authors ),
			'post_types'  => array_map( fn( $pt ) => array( 'slug' => $pt->name, 'label' => $pt->label ), (array) $post_types ),
		) );
	}

	/**
	 * Save (create or update) a single schema rule.
	 */
	public function handle_ligase_save_schema_rule(): void {
		$this->verify_request();

		$rule_data = wp_unslash( $_POST['rule'] ?? array() );
		if ( ! is_array( $rule_data ) ) {
			wp_send_json_error( array( 'message' => 'Invalid rule data.' ) );
		}

		// Sanitize
		$rule = array(
			'id'              => sanitize_key( $rule_data['id'] ?? Ligase_Schema_Rules::generate_id() ),
			'name'            => sanitize_text_field( $rule_data['name'] ?? '' ),
			'condition_type'  => sanitize_key( $rule_data['condition_type'] ?? 'category' ),
			'condition_value' => sanitize_text_field( $rule_data['condition_value'] ?? '' ),
			'schema_keys'     => array_map( 'sanitize_key', (array) ( $rule_data['schema_keys'] ?? array() ) ),
			'enabled'         => ! empty( $rule_data['enabled'] ),
		);

		if ( empty( $rule['schema_keys'] ) ) {
			wp_send_json_error( array( 'message' => 'Select at least one schema type.' ) );
		}

		// Validate schema keys against whitelist
		$allowed = array_values( Ligase_Schema_Rules::SCHEMA_TYPES );
		$rule['schema_keys'] = array_values( array_filter(
			$rule['schema_keys'],
			fn( $k ) => in_array( $k, $allowed, true )
		) );

		if ( empty( $rule['schema_keys'] ) ) {
			wp_send_json_error( array( 'message' => 'Invalid schema type.' ) );
		}

		$rules = Ligase_Schema_Rules::get_rules();

		// Update existing or append
		$found = false;
		foreach ( $rules as $i => $existing ) {
			if ( $existing['id'] === $rule['id'] ) {
				$rules[ $i ] = $rule;
				$found        = true;
				break;
			}
		}
		if ( ! $found ) {
			$rules[] = $rule;
		}

		Ligase_Schema_Rules::save_rules( $rules );

		wp_send_json_success( array( 'rule' => $rule, 'total' => count( $rules ) ) );
	}

	/**
	 * Delete a schema rule by ID.
	 */
	public function handle_ligase_delete_schema_rule(): void {
		$this->verify_request();

		$rule_id = sanitize_key( $_POST['rule_id'] ?? '' );
		if ( ! $rule_id ) {
			wp_send_json_error( array( 'message' => 'Missing rule_id.' ) );
		}

		$rules   = Ligase_Schema_Rules::get_rules();
		$rules   = array_values( array_filter( $rules, fn( $r ) => $r['id'] !== $rule_id ) );
		Ligase_Schema_Rules::save_rules( $rules );

		wp_send_json_success( array( 'deleted' => $rule_id, 'total' => count( $rules ) ) );
	}

	/**
	 * Toggle a rule on/off.
	 */
	public function handle_ligase_toggle_schema_rule(): void {
		$this->verify_request();

		$rule_id = sanitize_key( $_POST['rule_id'] ?? '' );
		$rules   = Ligase_Schema_Rules::get_rules();

		foreach ( $rules as &$rule ) {
			if ( $rule['id'] === $rule_id ) {
				$rule['enabled'] = ! $rule['enabled'];
				break;
			}
		}
		unset( $rule );

		Ligase_Schema_Rules::save_rules( $rules );
		wp_send_json_success();
	}
}
