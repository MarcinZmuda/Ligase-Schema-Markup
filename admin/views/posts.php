<?php
/**
 * Ligase Posts View
 *
 * Lists all published posts with their schema score,
 * type, and quick actions (scan, fix, preview JSON-LD).
 *
 * @package Ligase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$per_page = 20;

$query = new WP_Query( array(
	'post_type'      => 'post',
	'post_status'    => 'publish',
	'posts_per_page' => $per_page,
	'paged'          => $paged,
	'orderby'        => 'date',
	'order'          => 'DESC',
) );

$total_posts = $query->found_posts;
$total_pages = $query->max_num_pages;

$score_calculator = new Ligase_Score();
?>

<h1><?php esc_html_e( 'Ligase — Posty', 'ligase' ); ?></h1>

<?php
$opts_toolbar = (array) get_option( 'ligase_options', array() );
$allowed_schema_types = array( 'BlogPosting', 'Article', 'NewsArticle', 'TechArticle', 'LiveBlogPosting' );
?>
<div class="ligase-toolbar" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px;">

	<!-- Scan & fix -->
	<button type="button" class="button button-primary" id="ligase-scan-all">
		<?php esc_html_e( 'Skanuj wszystkie', 'ligase' ); ?>
	</button>
	<button type="button" class="button" id="ligase-fix-all" data-threshold="50">
		<?php esc_html_e( 'Napraw poniżej 50 pkt', 'ligase' ); ?>
	</button>

	<!-- Bulk validate -->
	<button type="button" class="button" id="ligase-validate-all-btn">
		🔍 <?php esc_html_e( 'Waliduj schema — wszystkie', 'ligase' ); ?>
	</button>

	<!-- Bulk schema type change -->
	<div style="display:flex;align-items:center;gap:6px;margin-left:8px;padding-left:12px;border-left:1px solid #ddd;">
		<label style="font-size:13px;white-space:nowrap;"><?php esc_html_e( 'Zmień typ dla wszystkich na:', 'ligase' ); ?></label>
		<select id="ligase-bulk-type-select" style="height:30px;">
			<?php foreach ( $allowed_schema_types as $t ) : ?>
				<option value="<?php echo esc_attr( $t ); ?>"
					<?php selected( $t, $opts_toolbar['default_schema_type'] ?? 'BlogPosting' ); ?>>
					<?php echo esc_html( $t ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<button type="button" class="button" id="ligase-bulk-type-btn">
			<?php esc_html_e( 'Zastosuj', 'ligase' ); ?>
		</button>
		<span id="ligase-bulk-type-status" style="font-size:12px;color:#666;"></span>
	</div>

	<button type="button" class="button" id="ligase-bulk-fix" style="display:none;">
		<?php esc_html_e( 'Napraw zaznaczone', 'ligase' ); ?>
	</button>

	<span class="ligase-toolbar-info" style="margin-left:auto;color:#666;font-size:13px;">
		<?php printf( esc_html__( 'Znaleziono %d postów', 'ligase' ), $total_posts ); ?>
	</span>
</div>

<!-- Bulk validate results -->
<div id="ligase-validate-all-results" style="display:none;margin-bottom:16px;padding:14px 18px;background:#fff;border:1px solid #ddd;border-radius:4px;max-height:300px;overflow-y:auto;font-size:13px;">
	<strong><?php esc_html_e( 'Wyniki walidacji schema:', 'ligase' ); ?></strong>
	<div id="ligase-validate-all-list" style="margin-top:8px;"></div>
</div>

<!-- =====================================================================
	BULK SCHEMA FLAGS — set Service/FAQ/HowTo/Product/Recipe/... on many posts at once
	===================================================================== -->
<details style="margin-bottom:16px;padding:14px 18px;background:#fff;border:1px solid #ddd;border-radius:4px;">
	<summary style="font-weight:600;font-size:14px;cursor:pointer;">
		⚡ <?php esc_html_e( 'Masowe ustawianie znaczników schema (Service, FAQ, HowTo, Product…)', 'ligase' ); ?>
	</summary>

	<p style="margin:10px 0 6px;color:#646970;font-size:12px;">
		<?php esc_html_e( 'Wybierz typ wpisu i (opcjonalnie) kategorię/tag, a następnie zaznacz znaczniki które chcesz włączyć lub wyłączyć dla wszystkich pasujących pozycji.', 'ligase' ); ?>
	</p>

	<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start;">

		<!-- LEFT: scope filters -->
		<div>
			<h4 style="margin:8px 0 6px;font-size:13px;"><?php esc_html_e( 'Zakres', 'ligase' ); ?></h4>

			<label style="display:block;margin:6px 0;font-size:12px;">
				<span style="display:block;color:#646970;margin-bottom:2px;"><?php esc_html_e( 'Typ wpisu', 'ligase' ); ?></span>
				<select id="ligase-bulk-flags-posttype" style="width:100%;">
					<?php
					$post_types_args   = array( 'public' => true );
					$bulk_post_types   = get_post_types( $post_types_args, 'objects' );
					foreach ( $bulk_post_types as $slug => $pt_obj ) :
						if ( $slug === 'attachment' ) { continue; }
						?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, 'post' ); ?>>
							<?php echo esc_html( $pt_obj->labels->name . ' (' . $slug . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label style="display:block;margin:6px 0;font-size:12px;">
				<span style="display:block;color:#646970;margin-bottom:2px;"><?php esc_html_e( 'Tylko w kategorii / tagu (opcjonalne)', 'ligase' ); ?></span>
				<input type="text" id="ligase-bulk-flags-term" placeholder="<?php esc_attr_e( 'slug taksonomii, np. adwokat-rozwod', 'ligase' ); ?>" style="width:100%;" />
				<small style="color:#9a9a9a;font-size:11px;">
					<?php esc_html_e( 'Wpisz slug terminu (z dowolnej taksonomii). Puste = wszystkie wpisy danego typu.', 'ligase' ); ?>
				</small>
			</label>

			<h4 style="margin:14px 0 6px;font-size:13px;"><?php esc_html_e( 'Wariant Article (tylko dla post)', 'ligase' ); ?></h4>
			<label style="display:block;margin:6px 0;font-size:12px;">
				<select id="ligase-bulk-flags-variant" style="width:100%;">
					<option value=""><?php esc_html_e( '— nie zmieniaj —', 'ligase' ); ?></option>
					<option value="BlogPosting">BlogPosting</option>
					<option value="Article">Article</option>
					<option value="NewsArticle">NewsArticle</option>
					<option value="TechArticle">TechArticle</option>
					<option value="LiveBlogPosting">LiveBlogPosting</option>
				</select>
			</label>
		</div>

		<!-- RIGHT: schema flag checkboxes -->
		<div>
			<h4 style="margin:8px 0 6px;font-size:13px;"><?php esc_html_e( 'Znaczniki schema do włączenia / wyłączenia', 'ligase' ); ?></h4>
			<?php
			$bulk_flags = array(
				'_ligase_enable_service'      => 'Service',
				'_ligase_enable_faq'          => 'FAQPage',
				'_ligase_enable_howto'        => 'HowTo',
				'_ligase_enable_review'       => 'Review',
				'_ligase_enable_qapage'       => 'QAPage',
				'_ligase_enable_product'      => 'Product',
				'_ligase_enable_recipe'       => 'Recipe',
				'_ligase_enable_jobposting'   => 'JobPosting',
				'_ligase_enable_forum'        => 'DiscussionForumPosting',
				'_ligase_enable_course'       => 'Course',
				'_ligase_enable_event'        => 'Event',
				'_ligase_enable_software'     => 'SoftwareApplication',
				'_ligase_enable_glossary'     => 'DefinedTerm',
				'_ligase_enable_claimreview'  => 'ClaimReview (deprecated)',
				'_ligase_enable_profile_page' => 'ProfilePage',
				'_ligase_paywalled'           => 'Paywall (isAccessibleForFree=false)',
			);
			?>
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;font-size:12px;">
				<?php foreach ( $bulk_flags as $meta_key => $label ) : ?>
					<label style="display:flex;align-items:center;gap:4px;">
						<input type="checkbox" class="ligase-bulk-flag" data-meta-key="<?php echo esc_attr( $meta_key ); ?>" />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</div>

			<div style="margin-top:10px;font-size:12px;">
				<span style="color:#646970;"><?php esc_html_e( 'Akcja na zaznaczonych:', 'ligase' ); ?></span>
				<label style="margin-left:8px;"><input type="radio" name="ligase_bulk_action" value="enable" checked /> <?php esc_html_e( 'Włącz', 'ligase' ); ?></label>
				<label style="margin-left:8px;"><input type="radio" name="ligase_bulk_action" value="disable" /> <?php esc_html_e( 'Wyłącz', 'ligase' ); ?></label>
			</div>
		</div>
	</div>

	<div style="margin-top:14px;padding-top:10px;border-top:1px solid #eee;display:flex;gap:8px;align-items:center;">
		<button type="button" class="button button-primary" id="ligase-bulk-flags-apply">
			<?php esc_html_e( 'Zastosuj do pasujących wpisów', 'ligase' ); ?>
		</button>
		<button type="button" class="button" id="ligase-bulk-flags-preview">
			<?php esc_html_e( 'Najpierw pokaż ile pozycji', 'ligase' ); ?>
		</button>
		<span id="ligase-bulk-flags-status" style="font-size:12px;color:#666;"></span>
	</div>
</details>

<div id="ligase-posts-notice" class="ligase-notice" style="display:none;"></div>

<table class="wp-list-table widefat fixed striped ligase-posts-table">
	<thead>
		<tr>
			<th style="width:30px;"><input type="checkbox" id="ligase-posts-check-all" /></th>
			<th style="width:40px;"><?php esc_html_e( 'ID', 'ligase' ); ?></th>
			<th><?php esc_html_e( 'Tytul', 'ligase' ); ?></th>
			<th style="width:100px;"><?php esc_html_e( 'Schema Score', 'ligase' ); ?></th>
			<th style="width:120px;"><?php esc_html_e( 'Typ schema', 'ligase' ); ?></th>
			<th style="width:100px;"><?php esc_html_e( 'Data', 'ligase' ); ?></th>
			<th style="width:200px;"><?php esc_html_e( 'Akcje', 'ligase' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( $query->have_posts() ) : ?>
			<?php while ( $query->have_posts() ) : $query->the_post(); ?>
				<?php
				$post_id     = get_the_ID();
				$post_score  = $score_calculator->calculate_for_post( $post_id );
				$score_val   = $post_score['score'];
				$opts_global  = (array) get_option( 'ligase_options', array() );
				$schema_type = get_post_meta( $post_id, '_ligase_schema_type', true ) ?: ( $opts_global['default_schema_type'] ?? 'BlogPosting' );

				if ( $score_val >= 70 ) {
					$score_class = 'ligase-score-good';
				} elseif ( $score_val >= 40 ) {
					$score_class = 'ligase-score-warn';
				} else {
					$score_class = 'ligase-score-bad';
				}
				?>
				<tr data-post-id="<?php echo esc_attr( $post_id ); ?>">
					<td><input type="checkbox" class="ligase-post-check" value="<?php echo esc_attr( $post_id ); ?>" /></td>
					<td><?php echo esc_html( $post_id ); ?></td>
					<td>
						<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>">
							<?php echo esc_html( get_the_title() ); ?>
						</a>
					</td>
					<td>
						<span class="ligase-score-badge <?php echo esc_attr( $score_class ); ?>">
							<?php echo esc_html( $score_val ); ?>/100
						</span>
					</td>
					<td><code><?php echo esc_html( $schema_type ); ?></code></td>
					<td><?php echo esc_html( get_the_date( 'Y-m-d' ) ); ?></td>
					<td>
						<button type="button" class="button button-small ligase-btn-scan" data-post-id="<?php echo esc_attr( $post_id ); ?>">
							<?php esc_html_e( 'Skanuj', 'ligase' ); ?>
						</button>
						<button type="button" class="button button-small ligase-btn-fix" data-post-id="<?php echo esc_attr( $post_id ); ?>">
							<?php esc_html_e( 'Napraw', 'ligase' ); ?>
						</button>
						<button type="button" class="button button-small ligase-btn-preview" data-post-id="<?php echo esc_attr( $post_id ); ?>">
							<?php esc_html_e( 'JSON-LD', 'ligase' ); ?>
						</button>
					</td>
				</tr>
			<?php endwhile; ?>
			<?php wp_reset_postdata(); ?>
		<?php else : ?>
			<tr>
				<td colspan="7"><?php esc_html_e( 'Brak opublikowanych postow.', 'ligase' ); ?></td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>

<?php if ( $total_pages > 1 ) : ?>
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<?php
			$page_links = paginate_links( array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total'     => $total_pages,
				'current'   => $paged,
			) );
			if ( $page_links ) {
				echo wp_kses_post( $page_links );
			}
			?>
		</div>
	</div>
<?php endif; ?>

<!-- JSON-LD Preview Modal -->
<div id="ligase-json-modal" class="ligase-modal" style="display:none;">
	<div class="ligase-modal-content">
		<div class="ligase-modal-header">
			<h3><?php esc_html_e( 'Podglad JSON-LD', 'ligase' ); ?></h3>
			<button type="button" class="ligase-modal-close">&times;</button>
		</div>
		<pre id="ligase-json-output" class="ligase-json-preview"></pre>
	</div>
</div>

<script>
(function($) {
'use strict';

// ── Bulk validate all posts ──────────────────────────────────────────────────
$('#ligase-validate-all-btn').on('click', function() {
	var $btn = $(this).prop('disabled', true).text('⏳ Walidacja...');
	var $results = $('#ligase-validate-all-results').show();
	var $list = $('#ligase-validate-all-list').html('<em>Pobieranie listy postów...</em>');
	var postIds = [];

	// Collect all post IDs from table rows
	$('tr[data-post-id]').each(function() {
		postIds.push($(this).data('post-id'));
	});

	if (!postIds.length) {
		$list.html('<span style="color:#999;">Brak postów na tej stronie.</span>');
		$btn.prop('disabled', false).text('🔍 Waliduj schema — wszystkie');
		return;
	}

	var done = 0;
	var html = '';
	$list.html('<em>Walidowanie ' + postIds.length + ' postów...</em>');

	function validateNext(ids) {
		if (!ids.length) {
			$list.html(html || '<span style="color:#10B981;">✅ Wszystkie posty bez błędów.</span>');
			$btn.prop('disabled', false).text('🔍 Waliduj schema — wszystkie');
			return;
		}
		var id = ids.shift();
		var title = $('tr[data-post-id="' + id + '"] td:nth-child(3) a').text().trim();

		$.post(LIGASE.ajaxUrl, {
			action:  'ligase_validate_post',
			nonce:   LIGASE.nonce,
			post_id: id
		}).done(function(res) {
			done++;
			if (res.success) {
				var d = res.data;
				if (d.errors && d.errors.length) {
					html += '<div style="margin:4px 0;padding:6px 10px;background:#FEF2F2;border-left:3px solid #EF4444;border-radius:2px;">'
						+ '<strong><a href="' + (window.location.origin + '/wp-admin/post.php?post=' + id + '&action=edit') + '">' + $('<div>').text(title).html() + '</a></strong> — '
						+ d.errors.length + ' błąd(y): ' + d.errors.map(function(e){ return $('<div>').text(e).html(); }).join(', ')
						+ '</div>';
				} else if (d.warnings && d.warnings.length) {
					html += '<div style="margin:4px 0;padding:6px 10px;background:#FFFBEB;border-left:3px solid #F59E0B;border-radius:2px;">'
						+ '<strong><a href="' + (window.location.origin + '/wp-admin/post.php?post=' + id + '&action=edit') + '">' + $('<div>').text(title).html() + '</a></strong>'
						+ '<ul style="margin:4px 0 0 16px;padding:0;">'
						+ d.warnings.map(function(w){ return '<li style="font-size:12px;color:#92400E;">' + w + '</li>'; }).join('')
						+ '</ul></div>';
				}
				$list.html('<em>(' + done + '/' + (done + ids.length) + ') — ' + (html || '✅ Brak błędów') + '</em>');
			}
			validateNext(ids);
		}).fail(function() {
			done++;
			validateNext(ids);
		});
	}

	validateNext(postIds.slice());
});

// ── Bulk schema type change ──────────────────────────────────────────────────
$('#ligase-bulk-type-btn').on('click', function() {
	var type = $('#ligase-bulk-type-select').val();
	var $status = $('#ligase-bulk-type-status');

	if (!confirm('Zmienić typ schema na "' + type + '" dla WSZYSTKICH postów w bazie? Tej operacji nie można cofnąć.')) {
		return;
	}

	var $btn = $(this).prop('disabled', true).text('Zmieniam...');
	$status.text('');

	$.post(LIGASE.ajaxUrl, {
		action:      'ligase_bulk_change_schema_type',
		nonce:       LIGASE.nonce,
		schema_type: type
	}).done(function(res) {
		if (res.success) {
			$status.css('color', '#10B981').text('✅ Zaktualizowano ' + res.data.updated + ' postów.');
			// Update badges in table
			$('td code').text(type);
		} else {
			$status.css('color', '#EF4444').text('❌ ' + (res.data.message || 'Błąd'));
		}
		$btn.prop('disabled', false).text('Zastosuj');
	}).fail(function() {
		$status.css('color', '#EF4444').text('❌ Błąd połączenia');
		$btn.prop('disabled', false).text('Zastosuj');
	});
});

// =====================================================================
// Bulk schema flags — handlers for the new "Masowe ustawianie znaczników" panel
// =====================================================================
function ligaseBulkCollectFlags() {
	var flags = [];
	$('.ligase-bulk-flag:checked').each(function() {
		flags.push($(this).data('meta-key'));
	});
	return flags;
}

function ligaseBulkPayload() {
	return {
		nonce:     LIGASE.nonce,
		post_type: $('#ligase-bulk-flags-posttype').val() || 'post',
		term_slug: $.trim($('#ligase-bulk-flags-term').val() || ''),
		variant:   $('#ligase-bulk-flags-variant').val() || '',
		flags:     ligaseBulkCollectFlags(),
		action_kind: $('input[name="ligase_bulk_action"]:checked').val() || 'enable'
	};
}

// Preview — count matching posts
$('#ligase-bulk-flags-preview').on('click', function() {
	var $btn    = $(this);
	var $status = $('#ligase-bulk-flags-status');
	$btn.prop('disabled', true);
	$status.css('color', '#666').text('Liczenie…');

	var payload = ligaseBulkPayload();
	$.post(LIGASE.ajaxUrl, {
		action:    'ligase_bulk_count_targets',
		nonce:     payload.nonce,
		post_type: payload.post_type,
		term_slug: payload.term_slug
	}).done(function(res) {
		if (res.success) {
			$status.css('color', '#10B981').text('Znaleziono ' + res.data.count + ' pozycji typu "' + res.data.post_type + '"' + (res.data.term_slug ? ' w terminie "' + res.data.term_slug + '"' : ''));
		} else {
			$status.css('color', '#EF4444').text('❌ ' + (res.data.message || 'Błąd'));
		}
		$btn.prop('disabled', false);
	}).fail(function() {
		$status.css('color', '#EF4444').text('❌ Błąd połączenia');
		$btn.prop('disabled', false);
	});
});

// Apply — actually update meta
$('#ligase-bulk-flags-apply').on('click', function() {
	var payload = ligaseBulkPayload();
	if (payload.flags.length === 0 && !payload.variant) {
		alert('Zaznacz przynajmniej jeden znacznik schema albo wybierz wariant Article.');
		return;
	}
	var verb = payload.action_kind === 'disable' ? 'WYŁĄCZYĆ' : 'WŁĄCZYĆ';
	var flagSummary = payload.flags.length ? (verb + ' znaczniki: ' + payload.flags.join(', ')) : '';
	var variantSummary = payload.variant ? ('Ustawić wariant Article: ' + payload.variant) : '';
	var msg = 'Czy na pewno?\n\nTyp: ' + payload.post_type + (payload.term_slug ? ' (termin: ' + payload.term_slug + ')' : '') + '\n' + flagSummary + (flagSummary && variantSummary ? '\n' : '') + variantSummary;
	if (!confirm(msg)) { return; }

	var $btn    = $(this);
	var $status = $('#ligase-bulk-flags-status');
	$btn.prop('disabled', true).text('Aktualizuję…');
	$status.css('color', '#666').text('');

	$.post(LIGASE.ajaxUrl, {
		action:      'ligase_bulk_set_flags',
		nonce:       payload.nonce,
		post_type:   payload.post_type,
		term_slug:   payload.term_slug,
		variant:     payload.variant,
		flags:       payload.flags,
		action_kind: payload.action_kind
	}).done(function(res) {
		if (res.success) {
			$status.css('color', '#10B981').text(
				'✅ Zaktualizowano ' + res.data.updated + ' z ' + res.data.matched + ' pozycji.' +
				(res.data.flags && res.data.flags.length ? ' Wartość znaczników: ' + res.data.value : '')
			);
		} else {
			$status.css('color', '#EF4444').text('❌ ' + (res.data.message || 'Błąd'));
		}
		$btn.prop('disabled', false).text('Zastosuj do pasujących wpisów');
	}).fail(function() {
		$status.css('color', '#EF4444').text('❌ Błąd połączenia');
		$btn.prop('disabled', false).text('Zastosuj do pasujących wpisów');
	});
});

})(jQuery);
</script>
