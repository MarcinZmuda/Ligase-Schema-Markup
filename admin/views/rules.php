<?php
/**
 * Ligase Schema Rules View
 *
 * Conditional schema automation — map categories, tags, post types
 * or authors to schema types without editing each post individually.
 *
 * @package Ligase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rules       = Ligase_Schema_Rules::get_rules();
$rule_count  = count( $rules );
$categories  = get_categories( array( 'hide_empty' => false, 'number' => 200, 'orderby' => 'name' ) );
$tags        = get_tags( array( 'hide_empty' => false, 'number' => 100, 'orderby' => 'name' ) );
$authors     = get_users( array( 'has_published_posts' => true ) );
$post_types  = get_post_types( array( 'public' => true ), 'objects' );
$schema_types = Ligase_Schema_Rules::SCHEMA_TYPES;
?>

<h1><?php esc_html_e( 'Schema Automation Rules', 'ligase' ); ?></h1>

<!-- Intro -->
<div style="margin:12px 0 20px;padding:14px 18px;background:#EFF6FF;border-left:4px solid #1E429F;border-radius:4px;max-width:860px;">
	<strong>⚡ <?php esc_html_e( 'What are rules?', 'ligase' ); ?></strong><br>
	<?php esc_html_e( 'Rules automatically enable schema types based on conditions — without editing each post. Example: all posts in the "Recipes" category get FAQPage schema; all posts tagged "review" get Review schema.', 'ligase' ); ?>
	<br><small style="color:#6B7280;">
		<?php esc_html_e( 'Rules are evaluated at render time. Per-post metabox settings always take priority over rules.', 'ligase' ); ?>
	</small>
</div>

<!-- Add new rule button -->
<div style="margin-bottom:16px;">
	<button type="button" class="button button-primary" id="ligase-rules-add-btn">
		＋ <?php esc_html_e( 'Add Rule', 'ligase' ); ?>
	</button>
	<span id="ligase-rules-count" style="margin-left:12px;color:#6B7280;font-size:13px;">
		<?php printf( esc_html( _n( '%d rule', '%d rules', $rule_count, 'ligase' ) ), $rule_count ); ?>
	</span>
</div>

<!-- Rules table -->
<div id="ligase-rules-list">
<?php if ( empty( $rules ) ) : ?>
	<div id="ligase-rules-empty" style="padding:32px;text-align:center;color:#9CA3AF;background:#F9FAFB;border:2px dashed #E5E7EB;border-radius:8px;max-width:860px;">
		<div style="font-size:28px;margin-bottom:8px;">⚙️</div>
		<strong><?php esc_html_e( 'No rules yet.', 'ligase' ); ?></strong><br>
		<?php esc_html_e( 'Add your first rule to automate schema across your blog.', 'ligase' ); ?>
	</div>
<?php else : ?>
	<table class="wp-list-table widefat fixed striped" style="max-width:860px;">
		<thead>
			<tr>
				<th style="width:40px;"><?php esc_html_e( 'On', 'ligase' ); ?></th>
				<th><?php esc_html_e( 'Rule name', 'ligase' ); ?></th>
				<th style="width:220px;"><?php esc_html_e( 'Condition', 'ligase' ); ?></th>
				<th><?php esc_html_e( 'Schema types enabled', 'ligase' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Actions', 'ligase' ); ?></th>
			</tr>
		</thead>
		<tbody id="ligase-rules-tbody">
		<?php foreach ( $rules as $rule ) :
			$type_labels = array();
			foreach ( (array) ( $rule['schema_keys'] ?? array() ) as $key ) {
				$label = array_search( $key, $schema_types, true );
				if ( $label ) {
					$type_labels[] = $label;
				}
			}
		?>
			<tr id="ligase-rule-row-<?php echo esc_attr( $rule['id'] ); ?>"
				class="<?php echo empty( $rule['enabled'] ) ? 'ligase-rule-disabled' : ''; ?>"
				data-rule="<?php echo esc_attr( wp_json_encode( $rule ) ); ?>">
				<td>
					<label class="ligase-toggle" title="<?php esc_attr_e( 'Enable/disable rule', 'ligase' ); ?>">
						<input type="checkbox" class="ligase-rule-toggle"
							data-rule-id="<?php echo esc_attr( $rule['id'] ); ?>"
							<?php checked( ! empty( $rule['enabled'] ) ); ?>>
						<span class="ligase-toggle-slider"></span>
					</label>
				</td>
				<td>
					<strong><?php echo esc_html( $rule['name'] ?: __( '(unnamed)', 'ligase' ) ); ?></strong>
				</td>
				<td>
					<span class="ligase-rule-condition">
						<?php echo esc_html( Ligase_Schema_Rules::describe_condition( $rule ) ); ?>
					</span>
				</td>
				<td>
					<?php foreach ( $type_labels as $lbl ) : ?>
						<span style="display:inline-block;background:#DBEAFE;color:#1E429F;padding:1px 8px;border-radius:10px;font-size:12px;margin:1px 2px;">
							<?php echo esc_html( $lbl ); ?>
						</span>
					<?php endforeach; ?>
				</td>
				<td>
					<button type="button" class="button button-small ligase-rule-edit-btn"
						data-rule-id="<?php echo esc_attr( $rule['id'] ); ?>">
						<?php esc_html_e( 'Edit', 'ligase' ); ?>
					</button>
					<button type="button" class="button button-small ligase-rule-delete-btn"
						data-rule-id="<?php echo esc_attr( $rule['id'] ); ?>"
						style="margin-left:4px;color:#dc2626;border-color:#dc2626;">
						<?php esc_html_e( 'Delete', 'ligase' ); ?>
					</button>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
</div>

<!-- ─── RULE EDITOR MODAL ─────────────────────────────────────────────────── -->
<div id="ligase-rule-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:99999;align-items:center;justify-content:center;">
	<div style="background:#fff;border-radius:8px;padding:28px 32px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
		<h2 style="margin:0 0 20px;font-size:18px;" id="ligase-modal-title">
			<?php esc_html_e( 'Add Rule', 'ligase' ); ?>
		</h2>

		<input type="hidden" id="ligase-rule-id">

		<!-- Rule name -->
		<div style="margin-bottom:16px;">
			<label style="display:block;font-weight:600;margin-bottom:4px;">
				<?php esc_html_e( 'Rule name', 'ligase' ); ?>
			</label>
			<input type="text" id="ligase-rule-name" class="regular-text"
				placeholder="<?php esc_attr_e( 'e.g. Recipes category → FAQPage', 'ligase' ); ?>">
		</div>

		<!-- Condition type -->
		<div style="margin-bottom:14px;">
			<label style="display:block;font-weight:600;margin-bottom:4px;">
				<?php esc_html_e( 'When', 'ligase' ); ?>
			</label>
			<div style="display:flex;gap:8px;flex-wrap:wrap;">
				<select id="ligase-rule-condition-type" style="min-width:180px;">
					<option value="always"><?php esc_html_e( 'Always (all posts)', 'ligase' ); ?></option>
					<option value="category"><?php esc_html_e( 'Category is', 'ligase' ); ?></option>
					<option value="tag"><?php esc_html_e( 'Tag is', 'ligase' ); ?></option>
					<option value="post_type"><?php esc_html_e( 'Post type is', 'ligase' ); ?></option>
					<option value="author"><?php esc_html_e( 'Author is', 'ligase' ); ?></option>
					<option value="slug_contains"><?php esc_html_e( 'Slug contains', 'ligase' ); ?></option>
				</select>

				<!-- Dynamic value selector -->
				<div id="ligase-rule-value-wrap" style="flex:1;min-width:160px;">

					<!-- Category select -->
					<select id="ligase-rule-value-category" class="ligase-condition-value regular-text" style="width:100%;display:none;">
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat->term_id ); ?>">
								<?php echo esc_html( $cat->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<!-- Tag select -->
					<select id="ligase-rule-value-tag" class="ligase-condition-value regular-text" style="width:100%;display:none;">
						<?php foreach ( $tags as $tag ) : ?>
							<option value="<?php echo esc_attr( $tag->term_id ); ?>">
								<?php echo esc_html( $tag->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<!-- Post type select -->
					<select id="ligase-rule-value-post_type" class="ligase-condition-value regular-text" style="width:100%;display:none;">
						<?php foreach ( $post_types as $pt ) : ?>
							<option value="<?php echo esc_attr( $pt->name ); ?>">
								<?php echo esc_html( $pt->label ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<!-- Author select -->
					<select id="ligase-rule-value-author" class="ligase-condition-value regular-text" style="width:100%;display:none;">
						<?php foreach ( $authors as $author ) : ?>
							<option value="<?php echo esc_attr( $author->ID ); ?>">
								<?php echo esc_html( $author->display_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<!-- Slug text input -->
					<input type="text" id="ligase-rule-value-slug_contains" class="ligase-condition-value regular-text"
						style="width:100%;display:none;"
						placeholder="<?php esc_attr_e( 'e.g. recipe, review, tutorial', 'ligase' ); ?>">

				</div><!-- /value-wrap -->
			</div>
		</div>

		<!-- Schema types -->
		<div style="margin-bottom:20px;">
			<label style="display:block;font-weight:600;margin-bottom:8px;">
				<?php esc_html_e( 'Then enable these schema types', 'ligase' ); ?>
			</label>
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
				<?php foreach ( $schema_types as $label => $meta_key ) : ?>
					<label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:6px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:13px;"
						   class="ligase-schema-type-checkbox">
						<input type="checkbox" class="ligase-rule-schema-key"
							   value="<?php echo esc_attr( $meta_key ); ?>">
						<span><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<p style="margin:8px 0 0;font-size:11px;color:#9CA3AF;">
				<?php esc_html_e( 'Note: schema types that require content (like FAQPage) will only appear if the post actually has that content.', 'ligase' ); ?>
			</p>
		</div>

		<!-- Enabled toggle -->
		<div style="margin-bottom:24px;display:flex;align-items:center;gap:10px;">
			<label class="ligase-toggle">
				<input type="checkbox" id="ligase-rule-enabled" checked>
				<span class="ligase-toggle-slider"></span>
			</label>
			<span style="font-size:13px;color:#374151;"><?php esc_html_e( 'Rule active', 'ligase' ); ?></span>
		</div>

		<!-- Buttons -->
		<div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #E5E7EB;padding-top:16px;">
			<button type="button" class="button" id="ligase-rule-modal-cancel">
				<?php esc_html_e( 'Cancel', 'ligase' ); ?>
			</button>
			<button type="button" class="button button-primary" id="ligase-rule-modal-save">
				<?php esc_html_e( 'Save Rule', 'ligase' ); ?>
			</button>
		</div>

		<div id="ligase-rule-modal-msg" style="display:none;margin-top:12px;padding:8px 12px;border-radius:4px;font-size:13px;"></div>
	</div>
</div>

<!-- ─── STYLES ──────────────────────────────────────────────────────────────── -->
<style>
.ligase-rule-disabled td { opacity: 0.45; }
.ligase-schema-type-checkbox:has(input:checked) {
	background:#EFF6FF;border-color:#93C5FD;
}
/* Toggle switch */
.ligase-toggle { position:relative;display:inline-block;width:36px;height:20px; }
.ligase-toggle input { opacity:0;width:0;height:0; }
.ligase-toggle-slider {
	position:absolute;inset:0;background:#D1D5DB;border-radius:20px;cursor:pointer;transition:.2s;
}
.ligase-toggle input:checked + .ligase-toggle-slider { background:#1E429F; }
.ligase-toggle-slider:before {
	content:'';position:absolute;width:14px;height:14px;left:3px;bottom:3px;
	background:#fff;border-radius:50%;transition:.2s;
}
.ligase-toggle input:checked + .ligase-toggle-slider:before { transform:translateX(16px); }
</style>

<!-- ─── JAVASCRIPT ───────────────────────────────────────────────────────────── -->
<script>
(function($) {
'use strict';

var SCHEMA_TYPES = <?php echo wp_json_encode( $schema_types ); ?>;

// ── Show/hide condition value selector ───────────────────────────────────────
function updateValueField() {
	var type = $('#ligase-rule-condition-type').val();
	$('.ligase-condition-value').hide();
	if (type !== 'always') {
		$('#ligase-rule-value-' + type).show();
	}
}
$('#ligase-rule-condition-type').on('change', updateValueField);

// ── Open modal (add or edit) ─────────────────────────────────────────────────
function openModal(rule) {
	var isNew = !rule;
	$('#ligase-modal-title').text(isNew
		? '<?php echo esc_js( __( 'Add Rule', 'ligase' ) ); ?>'
		: '<?php echo esc_js( __( 'Edit Rule', 'ligase' ) ); ?>'
	);

	// Reset
	$('#ligase-rule-id').val('');
	$('#ligase-rule-name').val('');
	$('#ligase-rule-condition-type').val('category');
	$('.ligase-rule-schema-key').prop('checked', false);
	$('.ligase-schema-type-checkbox').css({background:'',borderColor:''});
	$('#ligase-rule-enabled').prop('checked', true);
	$('#ligase-rule-modal-msg').hide();

	if (rule) {
		$('#ligase-rule-id').val(rule.id);
		$('#ligase-rule-name').val(rule.name);
		$('#ligase-rule-condition-type').val(rule.condition_type);
		$('#ligase-rule-value-' + rule.condition_type).val(rule.condition_value);
		$.each(rule.schema_keys || [], function(i, key) {
			$('.ligase-rule-schema-key[value="' + key + '"]').prop('checked', true);
		});
		$('#ligase-rule-enabled').prop('checked', rule.enabled);
	}

	updateValueField();
	$('#ligase-rule-modal').css('display', 'flex');
}

$('#ligase-rules-add-btn').on('click', function() { openModal(null); });
$('#ligase-rule-modal-cancel, #ligase-rule-modal').on('click', function(e) {
	if (e.target === this) $('#ligase-rule-modal').hide();
});

// ── Save rule ────────────────────────────────────────────────────────────────
$('#ligase-rule-modal-save').on('click', function() {
	var $btn = $(this).prop('disabled', true).text('<?php echo esc_js( __( 'Saving…', 'ligase' ) ); ?>');
	var $msg = $('#ligase-rule-modal-msg');

	var condType = $('#ligase-rule-condition-type').val();
	var condValue = condType === 'always' ? '' : $('#ligase-rule-value-' + condType).val();
	var keys = [];
	$('.ligase-rule-schema-key:checked').each(function() { keys.push($(this).val()); });

	if (!keys.length) {
		$msg.show().css({background:'#FEE2E2',color:'#991B1B'}).text('<?php echo esc_js( __( 'Select at least one schema type.', 'ligase' ) ); ?>');
		$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Save Rule', 'ligase' ) ); ?>');
		return;
	}

	$.post(LIGASE.ajaxUrl, {
		action:  'ligase_save_schema_rule',
		nonce:   LIGASE.nonce,
		rule: {
			id:               $('#ligase-rule-id').val() || '',
			name:             $('#ligase-rule-name').val(),
			condition_type:   condType,
			condition_value:  condValue,
			schema_keys:      keys,
			enabled:          $('#ligase-rule-enabled').is(':checked') ? 1 : 0,
		}
	}).done(function(res) {
		if (res.success) {
			$('#ligase-rule-modal').hide();
			location.reload();
		} else {
			$msg.show().css({background:'#FEE2E2',color:'#991B1B'}).text(res.data.message || 'Error');
			$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Save Rule', 'ligase' ) ); ?>');
		}
	}).fail(function() {
		$msg.show().css({background:'#FEE2E2',color:'#991B1B'}).text('Request failed.');
		$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Save Rule', 'ligase' ) ); ?>');
	});
});

// ── Edit button ──────────────────────────────────────────────────────────────
$(document).on('click', '.ligase-rule-edit-btn', function() {
	var $row = $('#ligase-rule-row-' + $(this).data('rule-id'));
	var rule = $row.data('rule');
	openModal(typeof rule === 'string' ? JSON.parse(rule) : rule);
});

// ── Delete button ────────────────────────────────────────────────────────────
$(document).on('click', '.ligase-rule-delete-btn', function() {
	if (!confirm('<?php echo esc_js( __( 'Delete this rule?', 'ligase' ) ); ?>')) return;
	var ruleId = $(this).data('rule-id');
	$.post(LIGASE.ajaxUrl, {
		action:  'ligase_delete_schema_rule',
		nonce:   LIGASE.nonce,
		rule_id: ruleId
	}).done(function(res) {
		if (res.success) {
			$('#ligase-rule-row-' + ruleId).remove();
			$('#ligase-rules-count').text(res.data.total + ' <?php echo esc_js( __( 'rules', 'ligase' ) ); ?>');
			if (res.data.total === 0) location.reload();
		}
	});
});

// ── Toggle switch ────────────────────────────────────────────────────────────
$(document).on('change', '.ligase-rule-toggle', function() {
	var ruleId = $(this).data('rule-id');
	var $row   = $('#ligase-rule-row-' + ruleId);
	$.post(LIGASE.ajaxUrl, {
		action:  'ligase_toggle_schema_rule',
		nonce:   LIGASE.nonce,
		rule_id: ruleId
	}).done(function(res) {
		if (res.success) {
			$row.toggleClass('ligase-rule-disabled');
		}
	});
});

})(jQuery);
</script>
