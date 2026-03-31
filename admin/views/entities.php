<?php
/**
 * Ligase Entities View
 *
 * Entity management: AI NER, Wikidata search, author E-E-A-T scores.
 *
 * @package Ligase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$score_calculator = new Ligase_Score();
$ner_api          = new Ligase_NER_API();
$ner_configured   = $ner_api->is_configured();
$opts             = (array) get_option( 'ligase_options', array() );
$ner_provider     = $opts['ner_provider'] ?? '';
$total_posts      = (int) wp_count_posts( 'post' )->publish;

$authors = get_users( array(
	'has_published_posts' => true,
	'orderby'            => 'display_name',
	'order'              => 'ASC',
) );

// Cost estimates per post
$cost_map = array(
	'openai'     => '$0.0004',
	'anthropic'  => '$0.0006',
	'google_nlp' => '$0.010',
	'dandelion'  => '€0.002',
);
$bulk_cost = $ner_configured ? sprintf(
	/* translators: 1: count, 2: cost per post, 3: estimated total */
	'~%s × %d posts = ~%s',
	$cost_map[ $ner_provider ] ?? '?',
	$total_posts,
	isset( $cost_map[ $ner_provider ] )
		? ( substr( $cost_map[ $ner_provider ], 0, 1 ) . number_format( (float) substr( $cost_map[ $ner_provider ], 1 ) * $total_posts, 4 ) )
		: '?'
) : '';
?>

<h1><?php esc_html_e( 'Entities — AI NER & Wikidata', 'ligase' ); ?></h1>

<?php if ( ! $ner_configured ) : ?>
<!-- ── NER NOT CONFIGURED NOTICE ─────────────────────────────────────── -->
<div class="notice notice-info" style="margin:0 0 20px;padding:14px 16px;">
	<p>
		<strong><?php esc_html_e( '🤖 AI Entity Detection (NER) is not configured.', 'ligase' ); ?></strong><br>
		<?php esc_html_e( 'Connect an AI provider to extract persons, organizations, places, and topics from your posts automatically — dramatically better than the built-in regex approach.', 'ligase' ); ?>
		<strong><?php esc_html_e( 'Cost: typically under $1/year for an active blog.', 'ligase' ); ?></strong>
	</p>
	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ligase-ustawienia#ligase_ner_section' ) ); ?>" class="button button-primary">
			<?php esc_html_e( 'Configure AI Provider in Settings →', 'ligase' ); ?>
		</a>
		<span style="margin-left:12px;color:#6B7280;font-size:12px;">
			<?php esc_html_e( 'Available: OpenAI GPT-4o-mini · Anthropic Claude Haiku · Google NLP · Dandelion (EU/GDPR)', 'ligase' ); ?>
		</span>
	</p>
</div>
<?php endif; ?>

<!-- ── AI NER — PER POST ─────────────────────────────────────────────────── -->
<div class="ligase-card ligase-card-wide">
	<h2><?php esc_html_e( '🤖 AI Entity Detection — Per Post', 'ligase' ); ?></h2>

	<?php if ( $ner_configured ) : ?>
		<p class="description">
			<?php printf(
				/* translators: provider name */
				esc_html__( 'Provider: %s. Select a post and click "Run AI Analysis" to extract entities on demand.', 'ligase' ),
				'<strong>' . esc_html( Ligase_NER_API::PROVIDERS[ $ner_provider ] ?? $ner_provider ) . '</strong>'
			); ?>
		</p>
	<?php else : ?>
		<p class="description" style="color:#9CA3AF;">
			<?php esc_html_e( 'Configure an AI provider in Settings to enable on-demand entity extraction.', 'ligase' ); ?>
		</p>
	<?php endif; ?>

	<div style="display:flex;gap:10px;align-items:flex-start;margin-top:12px;">
		<select id="ligase-ner-post-select" class="regular-text" <?php echo $ner_configured ? '' : esc_attr( 'disabled' ); ?>>
			<option value=""><?php esc_html_e( '— Select a post —', 'ligase' ); ?></option>
			<?php
			$posts = get_posts( array( 'posts_per_page' => 50, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC' ) );
			foreach ( $posts as $p ) {
				$has_results = ! empty( get_post_meta( $p->ID, '_ligase_ner_api_results', true ) );
				echo '<option value="' . esc_attr( $p->ID ) . '">'
					. esc_html( $p->post_title )
					. ( $has_results ? ' ✓' : '' )
					. '</option>';
			}
			?>
		</select>

		<button type="button" id="ligase-ner-run-btn" class="button button-primary"
			<?php echo $ner_configured ? '' : esc_attr( 'disabled' ); ?>>
			<?php esc_html_e( 'Run AI Analysis', 'ligase' ); ?>
		</button>
	</div>

	<!-- Results area -->
	<div id="ligase-ner-results" style="margin-top:16px;display:none;">
		<div id="ligase-ner-spinner" style="display:none;">
			<span class="spinner is-active" style="float:none;"></span>
			<em id="ligase-ner-status-msg" style="margin-left:8px;color:#6B7280;"></em>
		</div>

		<div id="ligase-ner-output"></div>

		<div id="ligase-ner-save-area" style="display:none;margin-top:16px;">
			<button type="button" id="ligase-ner-save-btn" class="button button-primary">
				<?php esc_html_e( 'Save selected to schema', 'ligase' ); ?>
			</button>
			<span style="margin-left:10px;font-size:12px;color:#6B7280;">
				<?php esc_html_e( '"About" = main topic (sameAs linkable) · "Mention" = named entity in content', 'ligase' ); ?>
			</span>
		</div>

		<div id="ligase-ner-saved-msg" style="display:none;" class="notice notice-success inline">
			<p><?php esc_html_e( 'Entities saved. Schema cache cleared.', 'ligase' ); ?></p>
		</div>
	</div>
</div>

<!-- ── AI NER — BULK BLOG SCAN ───────────────────────────────────────────── -->
<div class="ligase-card ligase-card-wide">
	<h2><?php esc_html_e( '🚀 Bulk Blog Scan — All Posts', 'ligase' ); ?></h2>

	<?php if ( ! $ner_configured ) : ?>
		<div style="padding:16px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:6px;color:#6B7280;">
			<strong><?php esc_html_e( 'Requires AI provider configuration.', 'ligase' ); ?></strong>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=ligase-ustawienia' ) ); ?>">
				<?php esc_html_e( 'Go to Settings →', 'ligase' ); ?>
			</a>
		</div>

	<?php else : ?>
		<p class="description">
			<?php printf(
				esc_html__( 'Runs AI entity extraction on all %d published posts. Processing happens in background via WP-Cron — your site stays responsive.', 'ligase' ),
				esc_html( $total_posts )
			); ?>
		</p>

		<!-- Cost warning -->
		<div style="margin:12px 0;padding:12px 16px;background:#FEF3C7;border-left:4px solid #F59E0B;border-radius:4px;">
			<strong>💰 <?php esc_html_e( 'Estimated cost:', 'ligase' ); ?></strong>
			<code style="font-size:14px;margin:0 8px;"><?php echo esc_html( $bulk_cost ); ?></code>
			<span style="color:#78350F;font-size:12px;">
				<?php printf(
					esc_html__( 'using %s. Posts already scanned are skipped unless you force re-scan.', 'ligase' ),
					esc_html( Ligase_NER_API::PROVIDERS[ $ner_provider ] ?? $ner_provider )
				); ?>
			</span>
		</div>

		<!-- Progress bar -->
		<?php $status = Ligase_NER_API::get_bulk_status(); ?>
		<?php if ( $status['done'] > 0 ) : ?>
		<div style="margin:12px 0;">
			<div style="display:flex;justify-content:space-between;font-size:12px;color:#6B7280;margin-bottom:4px;">
				<span><?php printf( esc_html__( '%d / %d posts processed', 'ligase' ), $status['done'], $status['total'] ); ?></span>
				<span><?php echo esc_html( $status['percent'] ); ?>%</span>
			</div>
			<div style="background:#E5E7EB;border-radius:4px;height:8px;overflow:hidden;">
				<div id="ligase-ner-bulk-bar"
					style="height:100%;background:#10B981;border-radius:4px;width:<?php echo esc_attr( $status['percent'] ); ?>%;transition:width .4s;">
				</div>
			</div>
		</div>
		<?php endif; ?>

		<div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
			<button type="button" id="ligase-ner-bulk-btn" class="button button-primary">
				<?php esc_html_e( 'Scan new posts only', 'ligase' ); ?>
			</button>
			<button type="button" id="ligase-ner-bulk-force-btn" class="button">
				<?php esc_html_e( 'Force re-scan all posts', 'ligase' ); ?>
			</button>
		</div>

		<div id="ligase-ner-bulk-result" style="margin-top:12px;"></div>

		<p style="margin-top:10px;font-size:11px;color:#9CA3AF;">
			<?php esc_html_e( 'After scheduling, go to any post → Schema Markup metabox to review and accept detected entities. Or use the per-post panel above.', 'ligase' ); ?>
		</p>
	<?php endif; ?>
</div>

<!-- ── WIKIDATA SEARCH ─────────────────────────────────────────────────── -->
<div class="ligase-card ligase-card-wide">
	<h2><?php esc_html_e( 'Wikidata Entity Search', 'ligase' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Search Wikidata to link authors or your organization to their Knowledge Graph ID (sameAs).', 'ligase' ); ?>
	</p>
	<div class="ligase-wikidata-search">
		<input type="text" id="ligase-wikidata-query"
			placeholder="<?php esc_attr_e( 'Type a name, organization, or topic...', 'ligase' ); ?>"
			class="regular-text" />
		<button type="button" class="button button-primary" id="ligase-wikidata-btn">
			<?php esc_html_e( 'Search', 'ligase' ); ?>
		</button>
	</div>
	<div id="ligase-wikidata-results" style="margin-top:12px;"></div>
</div>

<!-- ── AUTHOR E-E-A-T SCORES ─────────────────────────────────────────────── -->
<div class="ligase-card ligase-card-wide">
	<h2><?php esc_html_e( 'Author E-E-A-T Scores', 'ligase' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Profile completeness for E-E-A-T signals (Experience, Expertise, Authoritativeness, Trustworthiness).', 'ligase' ); ?>
	</p>

	<?php if ( ! empty( $authors ) ) : ?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Author', 'ligase' ); ?></th>
				<th style="width:110px;"><?php esc_html_e( 'E-E-A-T Score', 'ligase' ); ?></th>
				<th style="width:100px;"><?php esc_html_e( 'Role', 'ligase' ); ?></th>
				<th><?php esc_html_e( 'Missing', 'ligase' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Actions', 'ligase' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $authors as $author ) :
				$author_score = $score_calculator->calculate_for_author( $author->ID );
				$a_score      = $author_score['score'];
				$a_recs       = $author_score['recommendations'];
				$a_class      = $a_score >= 70 ? 'ligase-score-good' : ( $a_score >= 40 ? 'ligase-score-warn' : 'ligase-score-bad' );
			?>
			<tr>
				<td>
					<?php echo get_avatar( $author->ID, 24 ); ?>
					<?php echo esc_html( $author->display_name ); ?>
				</td>
				<td>
					<span class="ligase-score-badge <?php echo esc_attr( $a_class ); ?>">
						<?php echo esc_html( $a_score ); ?>/100
					</span>
				</td>
				<td><?php echo esc_html( implode( ', ', $author->roles ) ); ?></td>
				<td>
					<?php if ( ! empty( $a_recs ) ) : ?>
						<ul class="ligase-issues-list">
							<?php foreach ( array_slice( $a_recs, 0, 3 ) as $rec ) : ?>
								<li><?php echo esc_html( $rec ); ?></li>
							<?php endforeach; ?>
							<?php if ( count( $a_recs ) > 3 ) : ?>
								<li><em>+<?php echo esc_html( count( $a_recs ) - 3 ); ?> more</em></li>
							<?php endif; ?>
						</ul>
					<?php else : ?>
						<span class="ligase-badge ligase-badge-pass"><?php esc_html_e( 'Complete', 'ligase' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<a href="<?php echo esc_url( get_edit_user_link( $author->ID ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Edit', 'ligase' ); ?>
					</a>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?>
		<p><?php esc_html_e( 'No authors with published posts found.', 'ligase' ); ?></p>
	<?php endif; ?>
</div>

<script type="text/javascript">
(function($) {
	'use strict';

	// ── Per-post NER ──────────────────────────────────────────────────
	$('#ligase-ner-run-btn').on('click', function() {
		var postId = $('#ligase-ner-post-select').val();
		if (!postId) { alert('Please select a post.'); return; }

		$('#ligase-ner-results').show();
		$('#ligase-ner-spinner').show();
		$('#ligase-ner-status-msg').text('Sending to AI provider...');
		$('#ligase-ner-output').empty();
		$('#ligase-ner-save-area, #ligase-ner-saved-msg').hide();
		$(this).prop('disabled', true);

		$.post(LIGASE.ajaxUrl, {
			action:  'ligase_ner_run_post',
			nonce:   LIGASE.nonce,
			post_id: postId
		})
		.done(function(res) {
			$('#ligase-ner-spinner').hide();
			if (!res.success) {
				$('#ligase-ner-output').html(
					'<div class="notice notice-error inline"><p>' + (res.data.message || 'Error.') + '</p></div>'
				);
				return;
			}
			renderNERResults(res.data.entities, postId);
		})
		.fail(function() {
			$('#ligase-ner-spinner').hide();
			$('#ligase-ner-output').html('<div class="notice notice-error inline"><p>Request failed.</p></div>');
		})
		.always(function() {
			$('#ligase-ner-run-btn').prop('disabled', false);
		});
	});

	function renderNERResults(entities, postId) {
		if (!entities) { $('#ligase-ner-output').html('<p>No entities returned.</p>'); return; }

		var cats = {
			persons:       { label: '👤 Persons',       saveAs: 'about' },
			organizations: { label: '🏢 Organizations', saveAs: 'about' },
			places:        { label: '📍 Places',         saveAs: 'mention' },
			topics:        { label: '💡 Topics',         saveAs: 'mention' },
			products:      { label: '📦 Products',       saveAs: 'mention' }
		};

		var html = '<table class="wp-list-table widefat striped" style="margin-top:12px;">';
		html += '<thead><tr><th style="width:30px;"></th><th>Entity</th><th style="width:120px;">Type</th>'
			+ '<th style="width:90px;">Confidence</th><th style="width:110px;">Add as</th></tr></thead><tbody>';

		var hasAny = false;
		$.each(cats, function(key, meta) {
			var items = entities[key] || [];
			if (!items.length) return;
			hasAny = true;
			$.each(items, function(i, e) {
				var conf = e.confidence ? Math.round(e.confidence * 100) + '%' : (e.relevance || '—');
				html += '<tr data-name="' + $('<div>').text(e.name).html() + '" data-save-as="' + meta.saveAs + '">';
				html += '<td><input type="checkbox" class="ligase-ner-entity-check" checked></td>';
				html += '<td><strong>' + $('<div>').text(e.name).html() + '</strong>';
				if (e.role) html += ' <em style="color:#6B7280;">(' + $('<div>').text(e.role).html() + ')</em>';
				html += '</td>';
				html += '<td><span style="font-size:11px;">' + meta.label + '</span></td>';
				html += '<td>' + $('<div>').text(conf).html() + '</td>';
				html += '<td><select class="ligase-ner-save-as" style="width:100%;">'
					+ '<option value="about">about</option>'
					+ '<option value="mention"' + (meta.saveAs === 'mention' ? ' selected' : '') + '>mention</option>'
					+ '<option value="skip">skip</option>'
					+ '</select></td>';
				html += '</tr>';
			});
		});

		if (!hasAny) {
			html += '<tr><td colspan="5" style="text-align:center;color:#9CA3AF;">No entities detected with high confidence.</td></tr>';
		}

		html += '</tbody></table>';
		$('#ligase-ner-output').html(html);

		if (hasAny) {
			$('#ligase-ner-save-area').show().data('post-id', postId);
		}
	}

	// Save selected entities
	$('#ligase-ner-save-btn').on('click', function() {
		var postId = $('#ligase-ner-save-area').data('post-id');
		var entities = [];

		$('#ligase-ner-output tbody tr').each(function() {
			var $row = $(this);
			if (!$row.find('.ligase-ner-entity-check').is(':checked')) return;
			var saveAs = $row.find('.ligase-ner-save-as').val();
			if (saveAs === 'skip') return;
			entities.push({ name: $row.data('name'), save_as: saveAs });
		});

		$.post(LIGASE.ajaxUrl, {
			action:   'ligase_ner_save_entities',
			nonce:    LIGASE.nonce,
			post_id:  postId,
			entities: entities
		}).done(function(res) {
			if (res.success) {
				$('#ligase-ner-save-area').hide();
				$('#ligase-ner-saved-msg').show();
			}
		});
	});

	// ── Bulk scan ─────────────────────────────────────────────────────
	function runBulk(force) {
		$('#ligase-ner-bulk-result').html(
			'<span class="spinner is-active" style="float:none;"></span> <em>Scheduling posts...</em>'
		);
		$('#ligase-ner-bulk-btn, #ligase-ner-bulk-force-btn').prop('disabled', true);

		$.post(LIGASE.ajaxUrl, {
			action: 'ligase_ner_run_bulk',
			nonce:  LIGASE.nonce,
			force:  force ? 1 : 0
		}).done(function(res) {
			if (res.success) {
				var d = res.data;
				$('#ligase-ner-bulk-result').html(
					'<div class="notice notice-success inline"><p>'
					+ '<strong>' + d.scheduled + ' posts scheduled</strong> — '
					+ 'Estimated cost: <strong>' + d.estimated_cost + '</strong> ('
					+ d.provider + ')<br>'
					+ 'Running in background. Refresh this page to see progress.'
					+ '</p></div>'
				);
				pollBulkStatus();
			} else {
				$('#ligase-ner-bulk-result').html(
					'<div class="notice notice-error inline"><p>' + (res.data.message || 'Error.') + '</p></div>'
				);
			}
		}).always(function() {
			$('#ligase-ner-bulk-btn, #ligase-ner-bulk-force-btn').prop('disabled', false);
		});
	}

	$('#ligase-ner-bulk-btn').on('click',       function() { runBulk(false); });
	$('#ligase-ner-bulk-force-btn').on('click', function() { runBulk(true);  });

	function pollBulkStatus() {
		var interval = setInterval(function() {
			$.post(LIGASE.ajaxUrl, { action: 'ligase_ner_bulk_status', nonce: LIGASE.nonce })
			.done(function(res) {
				if (!res.success) return;
				var s = res.data;
				$('#ligase-ner-bulk-bar').css('width', s.percent + '%');
				if (s.pending === 0) clearInterval(interval);
			});
		}, 5000);
	}

})(jQuery);
</script>
