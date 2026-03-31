<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Plugin {
    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies(): void {
        $files = [
            'includes/class-logger.php',
            'includes/class-cache.php',
            'includes/class-suppressor.php',
            'includes/class-cache-bypass.php',
            'includes/class-score.php',
            'includes/types/class-localbusiness.php',
            'includes/types/class-blogposting.php',
            'includes/types/class-organization.php',
            'includes/types/class-person.php',
            'includes/types/class-website.php',
            'includes/types/class-sitenavigation.php',
            'includes/types/class-service.php',
            'includes/types/class-breadcrumb.php',
            'includes/types/class-faqpage.php',
            'includes/types/class-howto.php',
            'includes/types/class-videoobject.php',
            'includes/types/class-review.php',
            'includes/types/class-qapage.php',
            'includes/types/class-definedterm.php',
            'includes/types/class-claimreview.php',
            'includes/types/class-softwareapplication.php',
            'includes/types/class-audioobject.php',
            'includes/types/class-course.php',
            'includes/types/class-event.php',
            'includes/class-generator.php',
            'includes/class-output.php',
            'includes/class-auditor.php',
            'includes/entities/class-pipeline.php',
            'includes/entities/class-extractor-native.php',
            'includes/entities/class-extractor-structure.php',
            'includes/entities/class-extractor-ner.php',
            'includes/entities/class-wikidata-lookup.php',
            'includes/class-schema-rules.php',
            'includes/class-ner-api.php',
            'includes/class-ajax.php',
            'includes/class-importer.php',
            'includes/class-health-report.php',
            'includes/class-multilingual.php',
            'includes/class-validator.php',
            'includes/class-gsc.php',
        ];
        foreach ( $files as $file ) {
            $path = LIGASE_DIR . $file;
            if ( file_exists( $path ) ) {
                require_once $path;
            }
        }
        if ( is_admin() ) {
            $admin_files = [
                'admin/class-settings.php',
                'admin/class-admin.php',
            ];
            foreach ( $admin_files as $admin_file ) {
                $admin_path = LIGASE_DIR . $admin_file;
                if ( file_exists( $admin_path ) ) {
                    require_once $admin_path;
                }
            }
        }
    }

    private function init_hooks(): void {
        // Suppress other SEO plugins early (before they register wp_head output)
        add_action( 'wp_loaded', [ Ligase_Output::class, 'maybe_suppress_early' ] );

        add_action( 'wp_head', [ Ligase_Output::class, 'render' ], 5 );

        add_action( 'save_post',      [ Ligase_Cache::class, 'invalidate_post' ] );
        add_action( 'save_post',      function() { delete_transient( 'ligase_site_score' ); } );
        add_action( 'updated_option', [ Ligase_Cache::class, 'invalidate_all' ] );
        add_action( 'updated_option', function( string $option ) {
            if ( $option === 'ligase_options' ) {
                delete_transient( 'ligase_site_score' );
            }
        } );
        add_action( 'profile_update',      function( int $uid ) { delete_transient( 'ligase_author_score_' . $uid ); } );
        add_action( 'updated_user_meta',   function( $meta_id, $uid ) { delete_transient( 'ligase_author_score_' . $uid ); }, 10, 2 );

        add_action( 'ligase_ner_api_extract', array( 'Ligase_NER_API', 'run_scheduled' ), 10, 1 );
        add_action( 'ligase_wikidata_lookup', [ Ligase_Wikidata_Lookup::class, 'run_lookup' ], 10, 2 );

        // Onboarding notice after activation
        add_action( 'admin_notices', [ $this, 'maybe_show_onboarding' ] );
        add_action( 'wp_ajax_ligase_dismiss_onboarding', [ $this, 'dismiss_onboarding' ] );

        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_action( 'init', [ $this, 'register_blocks' ] );

        // Multilingual support (WPML / Polylang)
        if ( class_exists( 'Ligase_Multilingual' ) ) {
            add_action( 'init', [ Ligase_Multilingual::class, 'init' ] );
        }

        // Health report cron
        if ( class_exists( 'Ligase_Health_Report' ) ) {
            Ligase_Health_Report::schedule();
            add_action( Ligase_Health_Report::CRON_HOOK, [ Ligase_Health_Report::class, 'run' ] );
        }

        // AJAX endpoints
        if ( class_exists( 'Ligase_Ajax' ) ) {
            new Ligase_Ajax();
        }

        if ( is_admin() && class_exists( 'Ligase_Admin' ) ) {
            $admin = new Ligase_Admin( LIGASE_VERSION, LIGASE_URL, LIGASE_DIR );
            $admin->init();
        }
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'ligase',
            false,
            dirname( plugin_basename( LIGASE_FILE ) ) . '/languages/'
        );
    }

    public function register_blocks(): void {
        $faq_path = LIGASE_DIR . 'blocks/faq/block.json';
        if ( file_exists( $faq_path ) ) {
            register_block_type( LIGASE_DIR . 'blocks/faq/', [
                'uses_context' => [ 'postId' ],
                'render_callback' => function( array $attrs, string $content, $block ): string {
                    $post_id = $block->context['postId'] ?? get_the_ID();
                    if ( $post_id && ! empty( $attrs['items'] ) ) {
                        update_post_meta( $post_id, '_ligase_faq_items', $attrs['items'] );
                    }
                    return '';
                },
            ] );
        }

        $howto_path = LIGASE_DIR . 'blocks/howto/block.json';
        if ( file_exists( $howto_path ) ) {
            register_block_type( LIGASE_DIR . 'blocks/howto/', [
                'uses_context' => [ 'postId' ],
                'render_callback' => function( array $attrs, string $content, $block ): string {
                    $post_id = $block->context['postId'] ?? get_the_ID();
                    if ( $post_id && ! empty( $attrs['steps'] ) ) {
                        update_post_meta( $post_id, '_ligase_howto', $attrs );
                    }
                    return '';
                },
            ] );
        }
    }

    // =========================================================================
    // Onboarding notice
    // =========================================================================

    /**
     * Show the post-activation onboarding notice once.
     */
    public function maybe_show_onboarding(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( get_option( 'ligase_show_onboarding' ) !== '1' ) {
            return;
        }

        $ner   = new Ligase_NER_API();
        $total = (int) wp_count_posts( 'post' )->publish;
        $opts  = (array) get_option( 'ligase_options', [] );
        $setup_done = ! empty( $opts['org_name'] );

        ?>
        <div class="notice notice-info is-dismissible" id="ligase-onboarding-notice"
             style="padding:0;border-left-color:#1E429F;overflow:hidden;">

            <!-- Header -->
            <div style="display:flex;align-items:center;gap:14px;padding:16px 20px;background:#EFF6FF;border-bottom:1px solid #BFDBFE;">
                <img src="<?php echo esc_url( plugins_url( 'assets/images/icon-48.png', LIGASE_FILE ) ); ?>"
                     width="36" height="36" alt="Ligase" style="border-radius:6px;">
                <div>
                    <strong style="font-size:15px;color:#1E429F;">
                        <?php esc_html_e( '👋 Welcome to Ligase — Schema Markup for Blogs', 'ligase' ); ?>
                    </strong><br>
                    <span style="font-size:12px;color:#6B7280;">
                        <?php printf(
                            esc_html__( 'Version %s · %d published posts detected', 'ligase' ),
                            esc_html( LIGASE_VERSION ),
                            esc_html( $total )
                        ); ?>
                    </span>
                </div>
            </div>

            <!-- Steps -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0;border-bottom:1px solid #BFDBFE;">

                <!-- Step 1 -->
                <div style="padding:16px 20px;border-right:1px solid #BFDBFE;">
                    <div style="font-weight:600;margin-bottom:6px;">
                        <?php echo $setup_done ? '✅' : '1️⃣'; ?>
                        <?php esc_html_e( 'Configure your organization', 'ligase' ); ?>
                    </div>
                    <p style="margin:0 0 10px;font-size:12px;color:#374151;">
                        <?php esc_html_e( 'Add your name, logo, Wikidata ID and social links. This is the foundation of your entity graph.', 'ligase' ); ?>
                    </p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ligase-ustawienia' ) ); ?>"
                       class="button <?php echo $setup_done ? '' : 'button-primary'; ?>" style="font-size:12px;">
                        <?php esc_html_e( 'Go to Settings →', 'ligase' ); ?>
                    </a>
                </div>

                <!-- Step 2 -->
                <div style="padding:16px 20px;border-right:1px solid #BFDBFE;">
                    <div style="font-weight:600;margin-bottom:6px;">
                        2️⃣ <?php esc_html_e( 'Check your AI Search Score', 'ligase' ); ?>
                    </div>
                    <p style="margin:0 0 10px;font-size:12px;color:#374151;">
                        <?php esc_html_e( 'See your 0–100 readiness score and get specific recommendations for improvement.', 'ligase' ); ?>
                    </p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ligase' ) ); ?>"
                       class="button" style="font-size:12px;">
                        <?php esc_html_e( 'View Dashboard →', 'ligase' ); ?>
                    </a>
                </div>

                <!-- Step 3 — NER (paid / optional) -->
                <div style="padding:16px 20px;background:<?php echo $ner->is_configured() ? '#F0FDF4' : '#FAFAFA'; ?>;">
                    <div style="font-weight:600;margin-bottom:6px;">
                        <?php echo $ner->is_configured() ? '✅' : '3️⃣'; ?>
                        <?php esc_html_e( 'AI Entity Detection', 'ligase' ); ?>
                        <span style="display:inline-block;margin-left:6px;padding:1px 7px;background:#FEF3C7;color:#78350F;font-size:10px;font-weight:600;border-radius:10px;vertical-align:middle;">
                            <?php esc_html_e( 'OPTIONAL · PAID', 'ligase' ); ?>
                        </span>
                    </div>

                    <?php if ( $ner->is_configured() ) : ?>
                        <p style="margin:0 0 10px;font-size:12px;color:#374151;">
                            <?php esc_html_e( 'AI NER is configured. Run a bulk scan to extract entities from all your posts.', 'ligase' ); ?>
                        </p>

                        <!-- Bulk scan trigger inside notice -->
                        <div style="background:#fff;border:1px solid #D1FAE5;border-radius:6px;padding:10px 12px;margin-bottom:10px;">
                            <div style="font-size:11px;color:#6B7280;margin-bottom:8px;">
                                <?php printf(
                                    esc_html__( '%d posts · Estimated cost: %s', 'ligase' ),
                                    esc_html( $total ),
                                    esc_html( '$' . number_format( 0.0005 * $total, 4 ) )
                                ); ?>
                                <span style="margin-left:4px;color:#9CA3AF;"><?php esc_html_e( '(approx., varies by provider)', 'ligase' ); ?></span>
                            </div>
                            <button type="button" id="ligase-onboarding-bulk-btn" class="button button-primary" style="font-size:12px;">
                                <?php printf( esc_html__( 'Scan all %d posts now', 'ligase' ), esc_html( $total ) ); ?>
                            </button>
                            <button type="button" id="ligase-onboarding-bulk-later" class="button" style="font-size:12px;margin-left:6px;">
                                <?php esc_html_e( 'Later', 'ligase' ); ?>
                            </button>
                        </div>
                        <div id="ligase-onboarding-bulk-msg" style="font-size:12px;display:none;"></div>

                    <?php else : ?>
                        <p style="margin:0 0 6px;font-size:12px;color:#374151;">
                            <?php esc_html_e( 'Let AI extract persons, organizations, and topics from all your posts automatically — far more accurate than built-in regex.', 'ligase' ); ?>
                        </p>
                        <p style="margin:0 0 10px;font-size:11px;color:#6B7280;">
                            <strong><?php esc_html_e( 'Cost:', 'ligase' ); ?></strong>
                            <?php printf(
                                /* translators: cost estimate and post count */
                                esc_html__( '~$0.0004–$0.001 per post. For %d posts: ~%s total. You use your own API key.', 'ligase' ),
                                esc_html( $total ),
                                '$' . number_format( 0.0006 * $total, 4 )
                            ); ?>
                        </p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ligase-ustawienia#ligase_ner_section' ) ); ?>"
                           class="button" style="font-size:12px;">
                            <?php esc_html_e( 'Set up AI NER →', 'ligase' ); ?>
                        </a>
                        <span style="margin-left:8px;font-size:11px;color:#9CA3AF;">
                            <?php esc_html_e( 'OpenAI · Anthropic · Google NLP · Dandelion', 'ligase' ); ?>
                        </span>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Footer -->
            <div style="padding:10px 20px;display:flex;justify-content:space-between;align-items:center;background:#F9FAFB;">
                <span style="font-size:11px;color:#9CA3AF;">
                    <?php esc_html_e( 'Ligase generates schema automatically — no further action needed unless you want to improve your score.', 'ligase' ); ?>
                </span>
                <button type="button" id="ligase-onboarding-dismiss" class="button-link"
                        style="font-size:11px;color:#9CA3AF;text-decoration:underline;cursor:pointer;border:none;background:none;">
                    <?php esc_html_e( 'Dismiss this notice', 'ligase' ); ?>
                </button>
            </div>
        </div>

        <script>
        (function($) {
            // Dismiss
            $('#ligase-onboarding-dismiss, .ligase-onboarding-notice .notice-dismiss').on('click', function() {
                $.post('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    action: 'ligase_dismiss_onboarding',
                    nonce:  '<?php echo esc_js( wp_create_nonce( 'ligase_admin' ) ); ?>'
                });
                $('#ligase-onboarding-notice').fadeOut(300, function(){ $(this).remove(); });
            });

            // Bulk scan from notice
            $('#ligase-onboarding-bulk-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Scheduling...');
                $.post('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    action: 'ligase_ner_run_bulk',
                    nonce:  '<?php echo esc_js( wp_create_nonce( 'ligase_admin' ) ); ?>',
                    force:  0
                }).done(function(res) {
                    if (res.success) {
                        $('#ligase-onboarding-bulk-msg')
                            .show()
                            .html('✅ <strong>' + res.data.scheduled + ' posts scheduled.</strong> '
                                + 'Estimated cost: <strong>' + res.data.estimated_cost + '</strong>. '
                                + 'Processing in background — results appear in '
                                + '<a href="<?php echo esc_url( admin_url( 'admin.php?page=ligase-encje' ) ); ?>">Entities</a>.');
                        $btn.hide();
                        $('#ligase-onboarding-bulk-later').hide();
                    } else {
                        $btn.prop('disabled', false).text('Scan all posts now');
                        $('#ligase-onboarding-bulk-msg').show().html('❌ ' + (res.data.message || 'Error.'));
                    }
                });
            });

            $('#ligase-onboarding-bulk-later').on('click', function() {
                $('#ligase-onboarding-notice').fadeOut(200, function(){ $(this).remove(); });
                $.post('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    action: 'ligase_dismiss_onboarding',
                    nonce:  '<?php echo esc_js( wp_create_nonce( 'ligase_admin' ) ); ?>'
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Dismiss the onboarding notice (AJAX).
     */
    public function dismiss_onboarding(): void {
        check_ajax_referer( 'ligase_admin', 'nonce' );
        if ( current_user_can( 'manage_options' ) ) {
            delete_option( 'ligase_show_onboarding' );
        }
        wp_send_json_success();
    }
}
