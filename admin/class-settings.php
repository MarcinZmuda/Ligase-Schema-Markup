<?php
/**
 * Ligase Settings
 *
 * WordPress Settings API registration: sections, fields, and sanitization.
 *
 * @package Ligase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ligase_Settings {

	/**
	 * Settings group name.
	 *
	 * @var string
	 */
	const GROUP = 'ligase_settings_group';

	/**
	 * Option key in wp_options.
	 *
	 * @var string
	 */
	const KEY = 'ligase_options';

	/**
	 * Section IDs.
	 */
	const SECTION_ORG      = 'ligase_section_organization';
	const SECTION_SOCIAL   = 'ligase_section_social';
	const SECTION_BEHAVIOR = 'ligase_section_behavior';
	const SECTION_LOCAL    = 'ligase_section_local_business';

	/**
	 * Register settings, sections, and fields. Called from admin_init.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		// ----- Section: Organization -----
		add_settings_section(
			self::SECTION_ORG,
			__( 'Organizacja', 'ligase' ),
			array( __CLASS__, 'render_section_org' ),
			'ligase-ustawienia'
		);

		self::add_field( 'org_name', __( 'Nazwa organizacji', 'ligase' ), self::SECTION_ORG, 'text' );
		self::add_field( 'org_description', __( 'Opis organizacji', 'ligase' ), self::SECTION_ORG, 'text' );
		self::add_field( 'org_logo', __( 'URL logo organizacji', 'ligase' ), self::SECTION_ORG, 'url' );
		self::add_field( 'org_email', __( 'E-mail organizacji', 'ligase' ), self::SECTION_ORG, 'email' );
		self::add_field( 'org_phone', __( 'Telefon organizacji', 'ligase' ), self::SECTION_ORG, 'text' );
		self::add_field( 'knows_about', __( 'Ekspertyza (knowsAbout)', 'ligase' ), self::SECTION_ORG, 'text' );
		self::add_field( 'logo_width', __( 'Szerokość logo (px)', 'ligase' ), self::SECTION_ORG, 'number' );
		self::add_field( 'logo_height', __( 'Wysokość logo (px)', 'ligase' ), self::SECTION_ORG, 'number' );
		self::add_field( 'org_author_mode', __( 'Organizacja jako autor', 'ligase' ), self::SECTION_ORG, 'checkbox' );

		// ----- Section: Social & Entity -----
		add_settings_section(
			self::SECTION_SOCIAL,
			__( 'Social & Entity', 'ligase' ),
			array( __CLASS__, 'render_section_social' ),
			'ligase-ustawienia'
		);

		self::add_field( 'social_wikidata', __( 'Wikidata URL', 'ligase' ), self::SECTION_SOCIAL, 'url' );
		self::add_field( 'social_wikipedia', __( 'Wikipedia URL', 'ligase' ), self::SECTION_SOCIAL, 'url' );
		self::add_field( 'social_linkedin', __( 'LinkedIn URL', 'ligase' ), self::SECTION_SOCIAL, 'url' );
		self::add_field( 'social_facebook', __( 'Facebook URL', 'ligase' ), self::SECTION_SOCIAL, 'url' );
		self::add_field( 'social_twitter', __( 'Twitter / X URL', 'ligase' ), self::SECTION_SOCIAL, 'url' );
		self::add_field( 'social_youtube', __( 'YouTube URL', 'ligase' ), self::SECTION_SOCIAL, 'url' );

		// ----- Section: Behavior -----
		add_settings_section(
			self::SECTION_BEHAVIOR,
			__( 'Zachowanie', 'ligase' ),
			array( __CLASS__, 'render_section_behavior' ),
			'ligase-ustawienia'
		);

		self::add_field( 'speakable_selectors', __( 'Selektory CSS (Speakable)', 'ligase' ), self::SECTION_BEHAVIOR, 'text' );
		self::add_field( 'standalone_mode', __( 'Tryb samodzielny (standalone)', 'ligase' ), self::SECTION_BEHAVIOR, 'checkbox' );
		self::add_field( 'force_output', __( 'Wymuszaj generowanie schema', 'ligase' ), self::SECTION_BEHAVIOR, 'checkbox' );
		self::add_field( 'debug_mode', __( 'Tryb debugowania', 'ligase' ), self::SECTION_BEHAVIOR, 'checkbox' );

		// LocalBusiness section
		add_settings_section(
			self::SECTION_LOCAL,
			__( 'Local Business', 'ligase' ),
			array( __CLASS__, 'render_local_business_section_desc' ),
			'ligase-ustawienia'
		);
		self::add_field( 'lb_type',         __( 'Typ firmy', 'ligase' ),                                          self::SECTION_LOCAL, 'lb_select' );
		self::add_field( 'lb_service_area', __( 'Firma usługowa (bez adresu)', 'ligase' ),                             self::SECTION_LOCAL, 'checkbox' );
		self::add_field( 'lb_name',         __( 'Nazwa firmy (nadpisanie)', 'ligase' ),                                self::SECTION_LOCAL, 'text' );
		self::add_field( 'lb_description',  __( 'Opis firmy', 'ligase' ),                                    self::SECTION_LOCAL, 'text' );
		self::add_field( 'lb_street',       __( 'Ulica i numer', 'ligase' ),                                          self::SECTION_LOCAL, 'text' );
		self::add_field( 'lb_city',         __( 'Miasto', 'ligase' ),                                                    self::SECTION_LOCAL, 'text' );
		self::add_field( 'lb_region',       __( 'Województwo / Region', 'ligase' ),                               self::SECTION_LOCAL, 'text' );
		self::add_field( 'lb_postal',       __( 'Kod pocztowy', 'ligase' ),                                       self::SECTION_LOCAL, 'text' );
		self::add_field( 'lb_country',      __( 'Kod kraju (np. PL, DE, US)', 'ligase' ),                              self::SECTION_LOCAL, 'text' );
		self::add_field( 'lb_lat',          __( 'Szerokość geograficzna (GPS)', 'ligase' ),                                          self::SECTION_LOCAL, 'text' );
		self::add_field( 'lb_lng',          __( 'Długość geograficzna (GPS)', 'ligase' ),                                         self::SECTION_LOCAL, 'text' );
		self::add_field( 'lb_price_range',  __( 'Przedział cenowy (np. $$, 50-200 PLN)', 'ligase' ),                       self::SECTION_LOCAL, 'text' );
		self::add_field( 'lb_area_served',  __( 'Obsługiwany obszar', 'ligase' ),                                             self::SECTION_LOCAL, 'text' );
		self::add_field( 'lb_hours',        __( 'Godziny otwarcia', 'ligase' ),                                           self::SECTION_LOCAL, 'lb_hours' );

		// NER API section
		add_settings_section( 'ligase_ner_section', __( 'AI Entity Detection (NER)', 'ligase' ), array( __CLASS__, 'render_ner_section_desc' ), 'ligase-ustawienia' );
		self::add_field( 'ner_provider', __( 'AI Provider', 'ligase' ), 'ligase_ner_section', 'ner_select' );
		self::add_field( 'ner_api_key',  __( 'API Key', 'ligase' ),     'ligase_ner_section', 'password' );
	}

	// -------------------------------------------------------------------------
	// Section Descriptions
	// -------------------------------------------------------------------------

	/**
	 * Render Organization section description.
	 *
	 * @return void
	 */
	public static function render_section_org() {
		echo '<div style="margin:4px 0 16px;padding:12px 16px;background:#F0FDF4;border-left:4px solid #10B981;border-radius:4px;max-width:800px;">';
		echo '<strong>🏢 ' . esc_html__( 'Dane organizacji', 'ligase' ) . '</strong><br>';
		echo esc_html__( 'Te dane trafiają do schema Organization na każdej stronie Twojej witryny. Google używa ich do budowania Knowledge Graph — profilu Twojej firmy w wynikach wyszukiwania.', 'ligase' );
		echo '<br><small style="color:#6B7280;">';
		echo esc_html__( 'Minimum skuteczne: Nazwa + Logo + E-mail. Dla pełnego Knowledge Panel: dodaj Wikidata ID w sekcji Social & Entity.', 'ligase' );
		echo '</small></div>';
	}

	/**
	 * Render Social & Entity section description.
	 *
	 * @return void
	 */
	public static function render_section_social() {
		echo '<div style="margin:4px 0 16px;padding:12px 16px;background:#EFF6FF;border-left:4px solid #1E429F;border-radius:4px;max-width:800px;">';
		echo '<strong>🔗 ' . esc_html__( 'Linki sameAs — tożsamość encji', 'ligase' ) . '</strong><br>';
		echo esc_html__( 'sameAs mówi Google: "ten profil na LinkedIn, Facebooku i Wikidata to ta sama firma". Im więcej spójnych linków, tym silniejszy sygnał E-E-A-T i większa szansa na Knowledge Panel.', 'ligase' );
		echo '<br><small style="color:#6B7280;">';
		echo esc_html__( 'Priorytet: Wikidata &gt; LinkedIn &gt; Facebook. Wikidata to najsilniejszy sygnał — jeśli firma nie ma strony Wikidata, rozważ jej stworzenie.', 'ligase' );
		echo '</small></div>';
	}

	/**
	 * Render Behavior section description.
	 *
	 * @return void
	 */
	public static function render_section_behavior() {
		echo '<div style="margin:4px 0 16px;padding:12px 16px;background:#FFFBEB;border-left:4px solid #F59E0B;border-radius:4px;max-width:800px;">';
		echo '<strong>⚙️ ' . esc_html__( 'Zachowanie — kiedy i jak generować schema', 'ligase' ) . '</strong><br>';
		echo esc_html__( 'Domyślnie Ligase nie generuje schema gdy wykrywa Yoast lub RankMath (aby uniknąć duplikatów). Poniższe opcje pozwalają to zmienić.', 'ligase' );
		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Field Helpers
	// -------------------------------------------------------------------------

	/**
	 * Shorthand for add_settings_field.
	 *
	 * @param string $id      Field key inside the option array.
	 * @param string $title   Human-readable label.
	 * @param string $section Section ID.
	 * @param string $type    Field type: text | url | email | number | checkbox.
	 * @return void
	 */
	private static function add_field( $id, $title, $section, $type ) {
		$callback = match( $type ) {
			'checkbox'   => array( __CLASS__, 'render_checkbox' ),
			'ner_select' => array( __CLASS__, 'render_ner_select' ),
			'lb_select'  => array( __CLASS__, 'render_lb_type_select' ),
			'lb_hours'   => array( __CLASS__, 'render_lb_hours' ),
			default      => array( __CLASS__, 'render_field' ),
		};

		add_settings_field(
			'ligase_field_' . $id,
			$title,
			$callback,
			'ligase-ustawienia',
			$section,
			array(
				'id'        => $id,
				'type'      => $type,
				'label_for' => 'ligase_field_' . $id,
			)
		);
	}

	/**
	 * Render NER section description.
	 */
	public static function render_ner_section_desc(): void {
		echo '<div style="margin:8px 0;padding:12px 16px;background:#EFF6FF;border-left:4px solid #1E429F;border-radius:4px;">';
		echo '<strong>&#x1F4A1; ' . esc_html__( 'This feature is optional and has a small cost.', 'ligase' ) . '</strong><br>';
		echo esc_html__( 'AI NER sends your post content to an external API to extract named entities (persons, organizations, places). You pay only for what you use — typically under $1/year for an active blog.', 'ligase' );
		echo '<br><br><strong>' . esc_html__( 'Cost per post:', 'ligase' ) . '</strong> ';
		echo 'OpenAI GPT-4o-mini: ~$0.0004 &nbsp;&middot;&nbsp; Anthropic Claude Haiku: ~$0.0006 &nbsp;&middot;&nbsp; Google NLP: ~$0.010 &nbsp;&middot;&nbsp; Dandelion (EU): ~&euro;0.002';
		echo '<br><br><strong>' . esc_html__( 'Privacy:', 'ligase' ) . '</strong> ';
		echo esc_html__( 'Only post content is sent — no user data, no comments. Leave blank to use the built-in regex NER at no cost.', 'ligase' );
		echo '</div>';
	}

	/**
	 * Render NER provider select field.
	 */
	public static function render_ner_select( $args ): void {
		$options   = get_option( self::KEY, self::defaults() );
		$current   = $options[ $args['id'] ] ?? '';
		$providers = array(
			''           => __( '— Disabled (use built-in regex NER) —', 'ligase' ),
			'openai'     => 'OpenAI GPT-4o-mini  (~$0.0004 / post)',
			'anthropic'  => 'Anthropic Claude Haiku  (~$0.0006 / post)',
			'google_nlp' => 'Google Natural Language API  (~$0.010 / post)',
			'dandelion'  => 'Dandelion — EU / GDPR  (~EUR 0.002 / post)',
		);
		echo '<select id="' . esc_attr( 'ligase_field_' . $args['id'] ) . '" name="' . esc_attr( self::KEY ) . '[' . esc_attr( $args['id'] ) . ']">';
		foreach ( $providers as $val => $label ) {
			$sel = selected( $current, $val, false );
			echo '<option value="' . esc_attr( $val ) . '" ' . $sel . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Choose your AI provider. Add the API key below. Leave blank to disable.', 'ligase' ) . '</p>';
	}

	// =========================================================================
	// LocalBusiness render methods
	// =========================================================================

	public static function render_local_business_section_desc(): void {
		echo '<div style="margin:8px 0;padding:12px 16px;background:#F0FDF4;border-left:4px solid #10B981;border-radius:4px;">';
		echo '<strong>&#x1F3E2; ' . esc_html__( 'Local Business Schema', 'ligase' ) . '</strong><br>';
		echo esc_html__( 'Fill in your physical address and business details to activate LocalBusiness schema. This replaces the generic Organization entity with a location-specific schema — essential for Google Maps, local Knowledge Panels, and "near me" searches.', 'ligase' );
		echo '<br><br>';
		echo '<strong>' . esc_html__( 'When to use:', 'ligase' ) . '</strong> ';
		echo esc_html__( 'Any business with a physical location or defined service area. Leave blank to use the generic Organization schema (correct for online-only businesses and blogs).', 'ligase' );
		echo '<br><small style="color:#6B7280;">';
		echo esc_html__( 'Note: aggregateRating on LocalBusiness does not generate star ratings in Google Search (Google policy since 2019). Stars only appear for Product/Service reviews.', 'ligase' );
		echo '</small></div>';
	}

	/**
	 * Render LocalBusiness type select with optgroups.
	 */
	public static function render_lb_type_select( $args ): void {
		$options  = get_option( self::KEY, self::defaults() );
		$current  = $options['lb_type'] ?? 'LocalBusiness';
		$field_id = 'ligase_field_' . $args['id'];
		$name     = self::KEY . '[' . $args['id'] . ']';

		echo '<select id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '" style="min-width:280px;">';
		foreach ( Ligase_Type_LocalBusiness::SUBTYPES as $group => $types ) {
			echo '<optgroup label="' . esc_attr( $group ) . '">';
			foreach ( $types as $value => $label ) {
				$sel = selected( $current, $value, false );
				echo '<option value="' . esc_attr( $value ) . '" ' . $sel . '>' . esc_html( $label ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Use the most specific type. The more precise, the stronger the local signal.', 'ligase' ) . '</p>';
	}

	/**
	 * Render OpeningHoursSpecification repeater UI.
	 */
	public static function render_lb_hours( $args ): void {
		$options   = get_option( self::KEY, self::defaults() );
		$hours     = $options['lb_hours'] ?? array();
		if ( ! is_array( $hours ) ) {
			$hours = array();
		}
		$days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		$name = self::KEY . '[lb_hours]';

		echo '<div id="ligase-lb-hours-wrap">';
		foreach ( $hours as $i => $slot ) {
			self::render_lb_hours_row( $name, $i, $slot, $days );
		}
		echo '</div>';
		echo '<button type="button" id="ligase-lb-hours-add" class="button" style="margin-top:8px;">+ ' . esc_html__( 'Add hours', 'ligase' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Group days with the same hours. 24h: opens 00:00, closes 23:59. Closed all day: both 00:00.', 'ligase' ) . '</p>';

		// Hidden template row for JS cloning
		echo '<template id="ligase-lb-hours-template">';
		self::render_lb_hours_row( $name, '__INDEX__', array(), $days );
		echo '</template>';
		?>
		<script>
		(function() {
			var wrap = document.getElementById('ligase-lb-hours-wrap');
			var tmpl = document.getElementById('ligase-lb-hours-template');
			var addBtn = document.getElementById('ligase-lb-hours-add');
			var idx = wrap.querySelectorAll('.ligase-lb-hours-row').length;

			function bindRemove(row) {
				row.querySelector('.ligase-lb-hours-remove').addEventListener('click', function() {
					row.remove();
				});
			}

			wrap.querySelectorAll('.ligase-lb-hours-row').forEach(bindRemove);

			addBtn.addEventListener('click', function() {
				var html = tmpl.innerHTML.replace(/__INDEX__/g, idx++);
				var div = document.createElement('div');
				div.innerHTML = html;
				var row = div.firstElementChild;
				wrap.appendChild(row);
				bindRemove(row);
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render a single opening hours row.
	 */
	private static function render_lb_hours_row( string $name, $idx, array $slot, array $days ): void {
		$slot_days = (array) ( $slot['days'] ?? array() );
		$opens     = esc_attr( $slot['opens']  ?? '' );
		$closes    = esc_attr( $slot['closes'] ?? '' );

		echo '<div class="ligase-lb-hours-row" style="margin-bottom:8px;padding:10px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:6px;">';
		echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;">';
		foreach ( $days as $day ) {
			$chk = in_array( $day, $slot_days, true ) ? 'checked' : '';
			echo '<label style="font-size:12px;white-space:nowrap;">'
				. '<input type="checkbox" name="' . esc_attr( $name ) . '[' . esc_attr( $idx ) . '][days][]" value="' . esc_attr( $day ) . '" ' . $chk . '> '
				. esc_html( substr( $day, 0, 3 ) )
				. '</label>';
		}
		echo '</div>';
		echo '<input type="time" name="' . esc_attr( $name ) . '[' . esc_attr( $idx ) . '][opens]" value="' . $opens . '" style="width:100px;"> ';
		echo '<span style="line-height:28px;margin:0 4px;">&ndash;</span>';
		echo '<input type="time" name="' . esc_attr( $name ) . '[' . esc_attr( $idx ) . '][closes]" value="' . $closes . '" style="width:100px;"> ';
		echo '<button type="button" class="button button-small ligase-lb-hours-remove" style="margin-left:8px;color:#EF4444;">'
			. esc_html__( '✕ Remove', 'ligase' ) . '</button>';
		echo '</div>';
	}

	// =========================================================================
	// Standard field renderers
	// =========================================================================

	/**
	 * Per-field placeholder text.
	 */
	private static function field_placeholder( string $id ): string {
		return array(
			'org_name'            => __( 'np. Acme Sp. z o.o.', 'ligase' ),
			'org_description'     => __( 'np. Blog o marketingu cyfrowym i SEO.', 'ligase' ),
			'org_logo'            => 'https://example.com/wp-content/uploads/logo.png',
			'org_email'           => 'kontakt@example.com',
			'org_phone'           => '+48123456789',
			'knows_about'         => __( 'np. SEO, content marketing, WordPress', 'ligase' ),
			'logo_width'          => '600',
			'logo_height'         => '60',
			'lb_name'             => __( 'Zostaw puste aby użyć nazwy organizacji', 'ligase' ),
			'lb_description'      => __( 'np. Restauracja serwująca kuchnię śródziemnomorską w centrum Warszawy.', 'ligase' ),
			'lb_street'           => __( 'np. ul. Marszałkowska 10', 'ligase' ),
			'lb_city'             => __( 'np. Warszawa', 'ligase' ),
			'lb_region'           => __( 'np. Mazowieckie', 'ligase' ),
			'lb_postal'           => __( 'np. 00-001', 'ligase' ),
			'lb_country'          => 'PL',
			'lb_lat'              => '52.2297',
			'lb_lng'              => '21.0122',
			'lb_price_range'      => __( 'np. $$ lub 50-200 PLN', 'ligase' ),
			'lb_area_served'      => __( 'np. Warszawa, Mazowieckie lub cała Polska', 'ligase' ),
			'speakable_selectors' => __( 'np. .entry-content > p:first-of-type', 'ligase' ),
		)[ $id ] ?? '';
	}

	/**
	 * Per-field description (help text below the input).
	 */
	private static function field_description( string $id ): string {
		$descriptions = array(
			'org_name'        => __( 'Oficjalna nazwa organizacji. Używana w schema Organization na każdej stronie i jako fallback autora. Powinna być identyczna jak w Google Business Profile.', 'ligase' ),
			'org_description' => __( '1–2 zdania o profilu działalności. Musi pokrywać się z treścią widoczną na stronie głównej lub /o-nas — Google weryfikuje zgodność.', 'ligase' ),
			'org_logo'        => __( 'Pełny URL logo. Wymagania Google: min. 112×112 px, max. 1 MB, format JPG/PNG/WebP. Logo pojawia się w Knowledge Panel i wynikach wyszukiwania.', 'ligase' ),
			'org_email'       => __( 'Publiczny adres e-mail organizacji. Wpisz tylko jeśli jest widoczny na stronie kontaktowej.', 'ligase' ),
			'org_phone'       => __( 'Format E.164 zalecany przez schema.org: +48123456789 (kraj + numer bez spacji). Wymagane dla LocalBusiness rich results.', 'ligase' ),
			'knows_about'     => __( 'Tematy które organizacja zna i o których pisze. Oddzielone przecinkami. Wpływają na topical authority w Knowledge Graph.', 'ligase' ),
			'logo_width'      => __( 'Szerokość logo w px. Sprawdź w przeglądarce: kliknij logo prawym → "Inspect". Google wymaga min. 112 px.', 'ligase' ),
			'logo_height'     => __( 'Wysokość logo w px. Dla logo 600×60 wpisz 600 i 60.', 'ligase' ),
			// Social
			'social_wikidata'  => __( 'URL do strony Wikidata (np. https://www.wikidata.org/wiki/Q12345). Najsilniejszy sygnał tożsamości encji — łączy Twoją firmę z Knowledge Graph. Szukaj nazwy firmy na wikidata.org.', 'ligase' ),
			'social_wikipedia' => __( 'URL artykułu Wikipedia o Twojej firmie lub autorze. Opcjonalne, ale bardzo silny sygnał E-E-A-T.', 'ligase' ),
			'social_linkedin'  => __( 'URL profilu firmowego na LinkedIn (nie personalnego). np. https://www.linkedin.com/company/acme', 'ligase' ),
			'social_facebook'  => __( 'URL strony firmowej na Facebook. np. https://www.facebook.com/acmepl', 'ligase' ),
			'social_twitter'   => __( 'URL profilu na X/Twitter. np. https://x.com/acmepl', 'ligase' ),
			'social_youtube'   => __( 'URL kanału YouTube. np. https://www.youtube.com/@acmepl', 'ligase' ),
			// Behavior
			'standalone_mode' => __( 'Aktywuj jeśli masz już Yoast/RankMath i chcesz zastąpić ich schema lepszym markupem Ligase. Ligase wyłączy schema innych wtyczek i przejmie output.', 'ligase' ),
			'force_output'    => __( 'Generuj schema nawet gdy wykryto inne wtyczki SEO. Użyj gdy Ligase i np. Yoast mają nie pokrywające się typy schema (np. Yoast robi Article, Ligase dodaje FAQPage).', 'ligase' ),
			'debug_mode'      => __( 'Loguje wszystkie operacje schema do pliku /wp-content/uploads/ligase-logs/. Nie włączaj na produkcji — spowalnia i zajmuje miejsce.', 'ligase' ),
			'speakable_selectors' => __( 'Selektory CSS sekcji które AI i asystenci głosowi powinni cytować. Sprawdź klasę kontenera treści swojego motywu (F12 → Inspect element). Zostaw puste aby wyłączyć Speakable.', 'ligase' ),
			// LocalBusiness
			'lb_name'         => __( 'Tylko jeśli nazwa lokalizacji różni się od nazwy organizacji (np. "Kawiarnia u Mariana" vs "Marian Nowak Gastronomia Sp. z o.o.").', 'ligase' ),
			'lb_description'  => __( 'Opis lokalizacji — co oferujesz, gdzie jesteś. Musi być widoczny na stronie. Max. 300 znaków.', 'ligase' ),
			'lb_street'       => __( 'Ulica i numer. Zostaw puste jeśli jesteś firmą usługową bez stałej siedziby (np. hydraulik, agencja online) — użyj wtedy pola "Area served" poniżej.', 'ligase' ),
			'lb_city'         => __( 'Miasto siedziby. Dla firm bez adresu — puste.', 'ligase' ),
			'lb_region'       => __( 'Województwo, prowincja lub stan. Opcjonalne.', 'ligase' ),
			'lb_postal'       => __( 'Kod pocztowy w formacie krajowym (np. 00-001 dla PL).', 'ligase' ),
			'lb_country'      => __( 'Kod kraju ISO 3166 (PL, DE, US, GB itd.).', 'ligase' ),
			'lb_lat'          => __( 'Szerokość geograficzna (decimal degrees). Znajdź na Google Maps: kliknij prawym na lokalizację → skopiuj współrzędne. Pierwsza liczba to lat.', 'ligase' ),
			'lb_lng'          => __( 'Długość geograficzna (decimal degrees). Druga liczba z Google Maps.', 'ligase' ),
			'lb_price_range'  => __( '$ (najtaniej) do $$$$ (luksus) — international standard. Lub zakres cenowy np. "50–500 PLN". Google wyświetla to w Knowledge Panel.', 'ligase' ),
			'lb_area_served'  => __( 'Obszar obsługi — dla firm bez stałej lokalizacji (kurierzy, serwisanci, freelancerzy). Miasto, województwo lub "Polska". Aktywuje LocalBusiness nawet bez adresu.', 'ligase' ),
		);
		return $descriptions[ $id ] ?? '';
	}

	public static function render_field( $args ) {
		$options     = get_option( self::KEY, self::defaults() );
		$id          = $args['id'];
		$type        = $args['type'];
		$value       = isset( $options[ $id ] ) ? $options[ $id ] : '';
		$placeholder = self::field_placeholder( $id );
		$description = self::field_description( $id );

		printf(
			'<input type="%1$s" id="%2$s" name="%3$s[%4$s]" value="%5$s" class="regular-text" %6$s />',
			esc_attr( $type ),
			esc_attr( 'ligase_field_' . $id ),
			esc_attr( self::KEY ),
			esc_attr( $id ),
			esc_attr( $value ),
			$placeholder ? 'placeholder="' . esc_attr( $placeholder ) . '"' : ''
		);

		if ( $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
	}

	public static function render_checkbox( $args ) {
		$options = get_option( self::KEY, self::defaults() );
		$id      = $args['id'];
		$checked = ! empty( $options[ $id ] ) ? 'checked' : '';

		$descriptions = array(
			'standalone_mode' => __( 'Aktywuj jeśli chcesz zastąpić schema z Yoast/RankMath. Ligase wyłączy ich output i przejmie całe JSON-LD.', 'ligase' ),
			'force_output'    => __( 'Generuj schema równolegle z innymi wtyczkami SEO. Użyj gdy mają nie pokrywające się typy (np. Yoast robi Article, Ligase dodaje FAQPage).', 'ligase' ),
			'debug_mode'      => __( 'Loguje operacje do pliku. Nie włączaj na produkcji.', 'ligase' ),
			'lb_service_area' => __( 'Włącz dla firm bez stałej siedziby: agencje online, kurierzy, serwisanci. Ligase użyje pola "Area served" zamiast adresu.', 'ligase' ),
			'org_author_mode' => __( 'Włącz gdy posty nie mają konkretnego autora — np. redakcja, ghost writing. Ligase użyje Organizacji jako autora zamiast konta WordPress.', 'ligase' ),
		);

		printf(
			'<input type="checkbox" id="%1$s" name="%2$s[%3$s]" value="1" %4$s />',
			esc_attr( 'ligase_field_' . $id ),
			esc_attr( self::KEY ),
			esc_attr( $id ),
			esc_attr( $checked )
		);

		if ( isset( $descriptions[ $id ] ) ) {
			echo ' <span style="color:#646970;font-size:13px;">' . esc_html( $descriptions[ $id ] ) . '</span>';
		}
	}

	// =========================================================================
	// Sanitization
	// =========================================================================

	public static function sanitize( $input ) {
		$clean = self::defaults();

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		// Text fields
		$text_fields = array(
			'org_name', 'org_description', 'org_phone', 'knows_about',
			'speakable_selectors', 'ner_provider',
			'lb_type', 'lb_name', 'lb_description',
			'lb_street', 'lb_city', 'lb_region', 'lb_postal', 'lb_country',
			'lb_lat', 'lb_lng', 'lb_price_range', 'lb_area_served',
		);
		foreach ( $text_fields as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
			}
		}

		// Email
		if ( isset( $input['org_email'] ) ) {
			$clean['org_email'] = sanitize_email( wp_unslash( $input['org_email'] ) );
		}

		// NER API key (preserve)
		if ( isset( $input['ner_api_key'] ) ) {
			$clean['ner_api_key'] = sanitize_text_field( wp_unslash( $input['ner_api_key'] ) );
		}

		// URLs
		$url_fields = array(
			'org_logo', 'social_wikidata', 'social_wikipedia', 'social_linkedin',
			'social_facebook', 'social_twitter', 'social_youtube',
		);
		foreach ( $url_fields as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = esc_url_raw( wp_unslash( $input[ $key ] ) );
			}
		}

		// Numbers
		foreach ( array( 'logo_width', 'logo_height' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = absint( $input[ $key ] );
			}
		}

		// Checkboxes
		foreach ( array( 'standalone_mode', 'force_output', 'debug_mode' ) as $key ) {
			$clean[ $key ] = ! empty( $input[ $key ] ) ? '1' : '';
		}

		// Opening hours — nested array sanitize
		if ( isset( $input['lb_hours'] ) && is_array( $input['lb_hours'] ) ) {
			$clean_hours = array();
			$valid_days  = array( 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday' );
			foreach ( $input['lb_hours'] as $slot ) {
				if ( ! is_array( $slot ) ) {
					continue;
				}
				$days   = array_intersect( (array) ( $slot['days'] ?? array() ), $valid_days );
				$opens  = sanitize_text_field( $slot['opens']  ?? '' );
				$closes = sanitize_text_field( $slot['closes'] ?? '' );
				if ( empty( $days ) || $opens === '' || $closes === '' ) {
					continue;
				}
				if ( ! preg_match( '/^\d{2}:\d{2}$/', $opens )
					|| ! preg_match( '/^\d{2}:\d{2}$/', $closes ) ) {
					continue;
				}
				$clean_hours[] = array(
					'days'   => array_values( $days ),
					'opens'  => $opens,
					'closes' => $closes,
				);
			}
			$clean['lb_hours'] = $clean_hours;
		}

		return $clean;
	}

	// =========================================================================
	// Defaults
	// =========================================================================

	public static function defaults(): array {
		return array(
			// Organization
			'org_name'         => '',
			'org_description'  => '',
			'org_logo'         => '',
			'org_email'        => '',
			'org_phone'        => '',
			'knows_about'      => '',
			'logo_width'       => 0,
			'logo_height'      => 0,
			// Social
			'social_wikidata'  => '',
			'social_wikipedia' => '',
			'social_linkedin'  => '',
			'social_facebook'  => '',
			'social_twitter'   => '',
			'social_youtube'   => '',
			// Behavior
			'standalone_mode'     => '',
			'force_output'        => '',
			'debug_mode'          => '',
			'speakable_selectors' => '',
			'org_author_mode'     => '',
			// NER
			'ner_provider'     => '',
			'ner_api_key'      => '',
			// LocalBusiness
			'lb_type'          => 'LocalBusiness',
			'lb_service_area'  => '',
			'lb_name'          => '',
			'lb_description'   => '',
			'lb_street'        => '',
			'lb_city'          => '',
			'lb_region'        => '',
			'lb_postal'        => '',
			'lb_country'       => 'PL',
			'lb_lat'           => '',
			'lb_lng'           => '',
			'lb_price_range'   => '',
			'lb_area_served'   => '',
			'lb_hours'         => array(),
		);
	}
}


/**
 * Render a single settings section by ID.
 * WordPress's built-in do_settings_sections() renders all sections at once.
 * This helper renders only the requested section, used by the tabbed settings UI.
 *
 * @param string $page       Settings page slug (e.g. 'ligase-ustawienia').
 * @param string $section_id Section ID to render.
 */
function ligase_do_settings_section( string $page, string $section_id ): void {
	global $wp_settings_sections, $wp_settings_fields;

	if ( ! isset( $wp_settings_sections[ $page ] ) ) {
		return;
	}

	foreach ( (array) $wp_settings_sections[ $page ] as $section ) {
		if ( $section['id'] !== $section_id ) {
			continue;
		}

		if ( $section['title'] ) {
			echo '<h2>' . esc_html( $section['title'] ) . '</h2>';
		}

		if ( $section['callback'] ) {
			call_user_func( $section['callback'], $section );
		}

		if ( ! isset( $wp_settings_fields[ $page ][ $section['id'] ] )
			|| empty( $wp_settings_fields[ $page ][ $section['id'] ] )
		) {
			continue;
		}

		echo '<table class="form-table" role="presentation">';
		do_settings_fields( $page, $section['id'] );
		echo '</table>';
	}
}
