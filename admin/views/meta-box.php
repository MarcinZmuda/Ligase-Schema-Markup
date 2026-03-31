<?php
/**
 * Ligase Meta Box Template
 *
 * @package Ligase
 * @var WP_Post $post Current post object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_nonce_field( 'ligase_meta_save', 'ligase_meta_nonce' );

$schema_type = get_post_meta( $post->ID, '_ligase_schema_type', true ) ?: 'BlogPosting';

// All toggle flags
$toggles = array(
	'_ligase_enable_faq'         => array(
		'label' => __( 'FAQ (FAQPage)', 'ligase' ),
		'hint'  => __( 'Rich results ograniczone do gov/health od 2024. Schema nadal wartosciowa dla AI search.', 'ligase' ),
	),
	'_ligase_enable_howto'       => array(
		'label' => __( 'HowTo', 'ligase' ),
		'hint'  => __( 'Google wylaczyl rich results dla HowTo w 2024. Schema poprawia widocznosc w AI i voice search.', 'ligase' ),
	),
	'_ligase_enable_review'      => array(
		'label' => __( 'Review', 'ligase' ),
		'hint'  => '',
	),
	'_ligase_enable_qapage'      => array(
		'label' => __( 'QAPage (pytanie i odpowiedz)', 'ligase' ),
		'hint'  => __( '+58% cytowan AI vs Article. Dla artykulow odpowiadajacych na jedno pytanie.', 'ligase' ),
	),
	'_ligase_enable_glossary'    => array(
		'label' => __( 'Slownik (DefinedTermSet)', 'ligase' ),
		'hint'  => __( 'Dla stron slownikowych. AI preferuje DefinedTerm dla zapytan definicyjnych.', 'ligase' ),
	),
	'_ligase_enable_claimreview' => array(
		'label' => __( 'ClaimReview (weryfikacja faktu)', 'ligase' ),
		'hint'  => __( 'AI traktuje ClaimReview jako high-trust source. Dla artykulow "prawda czy mit".', 'ligase' ),
	),
	'_ligase_enable_software'    => array(
		'label' => __( 'SoftwareApplication', 'ligase' ),
		'hint'  => __( 'Dla recenzji narzedzi i aplikacji. Aktywny rich result.', 'ligase' ),
	),
	'_ligase_enable_course'      => array(
		'label' => __( 'Course (kurs online)', 'ligase' ),
		'hint'  => __( 'Aktywny rich result. Dla blogow z kursami.', 'ligase' ),
	),
	'_ligase_enable_event'       => array(
		'label' => __( 'Event (wydarzenie)', 'ligase' ),
		'hint'  => __( 'Aktywny rich result. Webinary, meetupy, konferencje.', 'ligase' ),
	),
	'_ligase_enable_service'     => array(
		'label' => __( 'Service (usluga)', 'ligase' ),
		'hint'  => __( 'Dla stron usług. Podlacza usluge do Organization entity.', 'ligase' ),
	),
);

$allowed_types = array(
	'BlogPosting'     => __( 'BlogPosting — personal blog, opinion, company blog', 'ligase' ),
	'Article'         => __( 'Article — evergreen guides, pillar content', 'ligase' ),
	'TechArticle'     => __( 'TechArticle — tutorials, developer docs, code guides', 'ligase' ),
	'NewsArticle'     => __( 'NewsArticle — news reporting ⚠️ requires Google Publisher Center', 'ligase' ),
	'LiveBlogPosting' => __( 'LiveBlogPosting — live coverage, real-time events', 'ligase' ),
);
?>

<div style="padding: 4px 0;">

	<p style="margin: 0 0 12px;">
		<label for="ligase_schema_type" style="display: block; font-weight: 600; margin-bottom: 4px;">
			<?php esc_html_e( 'Typ schematu', 'ligase' ); ?>
		</label>
		<select id="ligase_schema_type" name="ligase_schema_type" style="width: 100%;">
			<?php foreach ( $allowed_types as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $schema_type, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>

	<fieldset style="margin: 0 0 8px; padding: 8px 0 0; border-top: 1px solid #e0e0e0;">
		<legend style="font-weight: 600; padding: 0 4px 0 0;">
			<?php esc_html_e( 'Dodatkowe znaczniki', 'ligase' ); ?>
		</legend>

		<?php foreach ( $toggles as $key => $toggle ) : ?>
			<?php $enabled = get_post_meta( $post->ID, $key, true ); ?>
			<label style="display: block; margin: 6px 0; cursor: pointer;">
				<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $enabled, '1' ); ?> />
				<?php echo esc_html( $toggle['label'] ); ?>
				<?php if ( ! empty( $toggle['hint'] ) ) : ?>
					<span style="display: block; font-size: 11px; color: #646970; margin: 2px 0 0 22px;">
						<?php echo esc_html( $toggle['hint'] ); ?>
					</span>
				<?php endif; ?>
			</label>
		<?php endforeach; ?>
	</fieldset>

	<?php if ( class_exists( 'Ligase_Score' ) ) : ?>
		<?php
		$score_calc   = new Ligase_Score();
		$score_result = $score_calc->calculate_for_post( $post->ID );
		$score        = $score_result['score'];
		$score_color  = $score >= 70 ? '#10B981' : ( $score >= 40 ? '#F59E0B' : '#EF4444' );
		?>
		<div style="margin-top: 12px; padding: 8px 10px; background: #f7f7f7; border-left: 4px solid <?php echo esc_attr( $score_color ); ?>; font-size: 13px;">
			<strong><?php esc_html_e( 'Schema Score:', 'ligase' ); ?></strong>
			<?php echo esc_html( $score ); ?><span style="color: #888;">/100</span>
		</div>
	<?php endif; ?>

</div>
