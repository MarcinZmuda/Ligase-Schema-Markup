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

$opts           = (array) get_option( 'ligase_options', array() );
$global_default = $opts['default_schema_type'] ?? 'BlogPosting';
$schema_type    = get_post_meta( $post->ID, '_ligase_schema_type', true ) ?: $global_default;

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
	'_ligase_enable_product'     => array(
		'label' => __( 'Product (Merchant listing)', 'ligase' ),
		'hint'  => __( 'WooCommerce wykrywane automatycznie. Aktywny rich result + Popular Products w Google Shopping.', 'ligase' ),
	),
	'_ligase_enable_recipe'      => array(
		'label' => __( 'Recipe (przepis kulinarny)', 'ligase' ),
		'hint'  => __( 'Aktywny rich result — jeden z 4 typów host carousel. Wymaga name + image + recipeIngredient + recipeInstructions.', 'ligase' ),
	),
	'_ligase_enable_jobposting'  => array(
		'label' => __( 'JobPosting (oferta pracy / Google Jobs)', 'ligase' ),
		'hint'  => __( 'Osobne search experience w Google Jobs. CPT job_listing wykrywany automatycznie. validThrough wymagane.', 'ligase' ),
	),
	'_ligase_enable_forum'       => array(
		'label' => __( 'DiscussionForumPosting (forum/wątek)', 'ligase' ),
		'hint'  => __( 'Discussions & Forums SERP od XI 2023. bbPress topics wykrywane automatycznie.', 'ligase' ),
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

	<?php
	// =====================================================================
	// ADVANCED FIELDS — paywall / dateline / image license / citations
	// =====================================================================
	$paywalled            = get_post_meta( $post->ID, '_ligase_paywalled', true ) === '1';
	$paywall_selector     = (string) get_post_meta( $post->ID, '_ligase_paywall_selector', true );
	$force_modified       = get_post_meta( $post->ID, '_ligase_force_date_modified', true ) === '1';
	$dateline             = (string) get_post_meta( $post->ID, '_ligase_dateline', true );
	$image_credit         = (string) get_post_meta( $post->ID, '_ligase_image_credit', true );
	$image_license        = (string) get_post_meta( $post->ID, '_ligase_image_license', true );
	$image_acquire        = (string) get_post_meta( $post->ID, '_ligase_image_acquire', true );
	$citations            = (array)  get_post_meta( $post->ID, '_ligase_citations', true );
	$override             = (array)  get_post_meta( $post->ID, '_ligase_override', true );
	$product_override     = is_array( $override['Product'] ?? null ) ? $override['Product'] : array();
	$post_type_obj        = get_post_type_object( $post->post_type );
	$show_product_section = $post->post_type === 'product' && function_exists( 'wc_get_product' );
	?>

	<details style="margin-top: 12px; padding-top: 8px; border-top: 1px solid #e0e0e0;">
		<summary style="font-weight: 600; cursor: pointer; padding: 4px 0;">
			<?php esc_html_e( 'Pola zaawansowane', 'ligase' ); ?>
		</summary>

		<fieldset style="margin: 8px 0;">
			<legend style="font-weight: 600; font-size: 12px; color: #444;">
				<?php esc_html_e( 'Paywall / Subscription', 'ligase' ); ?>
			</legend>
			<label style="display:block; margin:6px 0; cursor:pointer;">
				<input type="checkbox" name="_ligase_paywalled" value="1" <?php checked( $paywalled, true ); ?> />
				<?php esc_html_e( 'Treść za paywallem (isAccessibleForFree=false)', 'ligase' ); ?>
			</label>
			<label style="display:block; margin:6px 0;">
				<span style="display:block; font-size:11px; color:#646970;">
					<?php esc_html_e( 'CSS selector zablokowanej sekcji', 'ligase' ); ?>
				</span>
				<input type="text" name="_ligase_paywall_selector" value="<?php echo esc_attr( $paywall_selector ); ?>"
					placeholder=".paywall" style="width:100%;" />
			</label>
		</fieldset>

		<fieldset style="margin: 8px 0;">
			<legend style="font-weight: 600; font-size: 12px; color: #444;">
				<?php esc_html_e( 'Dyscyplina dateModified', 'ligase' ); ?>
			</legend>
			<label style="display:block; margin:6px 0; cursor:pointer;">
				<input type="checkbox" name="_ligase_force_date_modified" value="1" <?php checked( $force_modified, true ); ?> />
				<?php esc_html_e( 'Wymuś emisję dateModified (domyślnie pomijane gdy < 5 min od publikacji)', 'ligase' ); ?>
			</label>
		</fieldset>

		<fieldset style="margin: 8px 0;">
			<legend style="font-weight: 600; font-size: 12px; color: #444;">
				<?php esc_html_e( 'NewsArticle: dateline', 'ligase' ); ?>
			</legend>
			<label style="display:block; margin:6px 0;">
				<input type="text" name="_ligase_dateline" value="<?php echo esc_attr( $dateline ); ?>"
					placeholder="<?php esc_attr_e( 'np. WARSZAWA — 28 maj', 'ligase' ); ?>" style="width:100%;" />
			</label>
		</fieldset>

		<fieldset style="margin: 8px 0;">
			<legend style="font-weight: 600; font-size: 12px; color: #444;">
				<?php esc_html_e( 'Licencja obrazu wyróżniającego', 'ligase' ); ?>
			</legend>
			<label style="display:block; margin:6px 0;">
				<span style="display:block; font-size:11px; color:#646970;"><?php esc_html_e( 'Autor / credit', 'ligase' ); ?></span>
				<input type="text" name="_ligase_image_credit" value="<?php echo esc_attr( $image_credit ); ?>"
					placeholder="<?php esc_attr_e( 'np. Jan Kowalski / Unsplash', 'ligase' ); ?>" style="width:100%;" />
			</label>
			<label style="display:block; margin:6px 0;">
				<span style="display:block; font-size:11px; color:#646970;"><?php esc_html_e( 'URL licencji (np. CC BY)', 'ligase' ); ?></span>
				<input type="url" name="_ligase_image_license" value="<?php echo esc_attr( $image_license ); ?>"
					placeholder="https://creativecommons.org/licenses/by/4.0/" style="width:100%;" />
			</label>
			<label style="display:block; margin:6px 0;">
				<span style="display:block; font-size:11px; color:#646970;"><?php esc_html_e( 'URL strony zakupu licencji (acquireLicensePage)', 'ligase' ); ?></span>
				<input type="url" name="_ligase_image_acquire" value="<?php echo esc_attr( $image_acquire ); ?>"
					placeholder="https://example.com/licensing" style="width:100%;" />
			</label>
		</fieldset>

		<fieldset style="margin: 8px 0;" id="ligase-citations-section">
			<legend style="font-weight: 600; font-size: 12px; color: #444;">
				<?php esc_html_e( 'NewsArticle: citation (źródła)', 'ligase' ); ?>
			</legend>
			<?php
			$rows = ! empty( $citations ) ? $citations : array();
			// Always render at least one empty row so the user has somewhere to type.
			if ( empty( $rows ) ) {
				$rows = array( array( 'name' => '', 'url' => '' ) );
			}
			foreach ( $rows as $i => $row ) :
				$name = (string) ( $row['name'] ?? '' );
				$url  = (string) ( $row['url']  ?? '' );
				?>
				<div style="margin: 4px 0; display: grid; grid-template-columns: 1fr 1fr; gap: 4px;">
					<input type="text" name="ligase_citations[<?php echo (int) $i; ?>][name]"
						value="<?php echo esc_attr( $name ); ?>"
						placeholder="<?php esc_attr_e( 'Tytuł źródła', 'ligase' ); ?>" />
					<input type="url"  name="ligase_citations[<?php echo (int) $i; ?>][url]"
						value="<?php echo esc_attr( $url ); ?>"
						placeholder="https://…" />
				</div>
			<?php endforeach; ?>
			<p style="font-size:11px; color:#646970; margin:4px 0 0;">
				<?php esc_html_e( 'Wstaw więcej źródeł: zapisz wpis, edytor doda kolejne wiersze. Pusty wiersz jest ignorowany.', 'ligase' ); ?>
			</p>
		</fieldset>

		<?php if ( $show_product_section ) : ?>
			<fieldset style="margin: 8px 0;">
				<legend style="font-weight: 600; font-size: 12px; color: #444;">
					<?php esc_html_e( 'Product: ręczne nadpiski', 'ligase' ); ?>
				</legend>
				<p style="font-size:11px; color:#646970; margin:4px 0;">
					<?php esc_html_e( 'Wpisz wartość, by nadpisać automatyczne dane WooCommerce. Pusty input = używaj auto.', 'ligase' ); ?>
				</p>
				<?php
				$product_fields = array(
					'name'                                        => array( 'label' => __( 'Nazwa', 'ligase' ),            'type' => 'text', 'placeholder' => __( 'Auto: WooCommerce name', 'ligase' ) ),
					'gtin'                                        => array( 'label' => __( 'GTIN',  'ligase' ),            'type' => 'text', 'placeholder' => __( 'Auto: _global_unique_id', 'ligase' ) ),
					'mpn'                                         => array( 'label' => __( 'MPN',   'ligase' ),            'type' => 'text', 'placeholder' => '' ),
					'offers.price'                                => array( 'label' => __( 'Cena', 'ligase' ),             'type' => 'number', 'step' => '0.01', 'placeholder' => __( 'Auto: WooCommerce price', 'ligase' ) ),
					'offers.priceCurrency'                        => array( 'label' => __( 'Waluta (ISO 4217)', 'ligase' ),'type' => 'text', 'placeholder' => __( 'np. PLN', 'ligase' ) ),
					'offers.priceValidUntil'                      => array( 'label' => __( 'Cena ważna do', 'ligase' ),    'type' => 'date', 'placeholder' => '' ),
					'offers.hasMerchantReturnPolicy.returnPolicyCountry' => array( 'label' => __( 'Kraj polityki zwrotów (ISO 3166-1)', 'ligase' ), 'type' => 'text', 'placeholder' => 'PL' ),
				);
				foreach ( $product_fields as $key => $cfg ) :
					$val = (string) ( $product_override[ $key ] ?? '' );
					?>
					<label style="display:block; margin:6px 0;">
						<span style="display:block; font-size:11px; color:#646970;">
							<?php echo esc_html( $cfg['label'] ); ?>
						</span>
						<input
							type="<?php echo esc_attr( $cfg['type'] ); ?>"
							name="ligase_override[Product][<?php echo esc_attr( $key ); ?>]"
							value="<?php echo esc_attr( $val ); ?>"
							<?php if ( isset( $cfg['step'] ) ) : ?>step="<?php echo esc_attr( $cfg['step'] ); ?>"<?php endif; ?>
							<?php if ( $cfg['placeholder'] ) : ?>placeholder="<?php echo esc_attr( $cfg['placeholder'] ); ?>"<?php endif; ?>
							style="width:100%;"
						/>
					</label>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>
	</details>

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
