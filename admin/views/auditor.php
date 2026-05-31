<?php
/**
 * Ligase Auditor View
 *
 * Schema auditor panel: detect conflicting plugins,
 * scan existing schema quality, and batch-replace weak schema.
 *
 * @package Ligase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$auditor  = new Ligase_Auditor();
$detected = $auditor->get_detected_plugins();
$options  = get_option( 'ligase_options', array() );
?>

<h1><?php esc_html_e( 'Ligase — Audytor schema', 'ligase' ); ?></h1>

<!-- Plugin Conflicts -->
<div class="ligase-card ligase-card-wide">
	<h2><?php esc_html_e( 'Wykryte wtyczki SEO', 'ligase' ); ?></h2>
	<?php
	$_lig_opts        = (array) get_option( 'ligase_options', array() );
	$_lig_standalone  = ! empty( $_lig_opts['standalone_mode'] );
	$_lig_force       = ! empty( $_lig_opts['force_output'] );
	?>
	<?php if ( ! empty( $detected ) ) : ?>
		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Wtyczka', 'ligase' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Wersja', 'ligase' ); ?></th>
					<th style="width:200px;"><?php esc_html_e( 'Status', 'ligase' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $detected as $name => $version ) : ?>
					<tr>
						<td><?php echo esc_html( $name ); ?></td>
						<td><?php echo esc_html( $version ); ?></td>
						<td>
							<?php if ( $_lig_force ) : ?>
								<span class="ligase-badge" style="background:#fef3c7;color:#78350f;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">⚠ FORCE — duplikat ryzyko</span>
							<?php elseif ( $_lig_standalone ) : ?>
								<span class="ligase-badge" style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">✓ Wyciszone</span>
							<?php else : ?>
								<span class="ligase-badge ligase-badge-warn">⚠ <?php esc_html_e( 'Aktywna', 'ligase' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $_lig_force ) : ?>
			<p class="description" style="background:#fef3c7;padding:8px 12px;border-left:3px solid #f59e0b;margin-top:8px;">
				<strong>⚠ <?php esc_html_e( 'Tryb FORCE OUTPUT aktywny.', 'ligase' ); ?></strong>
				<?php esc_html_e( 'Ligase emituje swoje JSON-LD niezależnie od wykrytych wtyczek SEO. Może to powodować DUPLIKATY schema (Ligase + Yoast = 2× Article). Sprawdź źródło strony — jeśli widzisz dwa <script type="application/ld+json">, wyłącz force i włącz standalone, albo wyłącz schema w konkurencyjnej wtyczce.', 'ligase' ); ?>
			</p>
		<?php elseif ( $_lig_standalone ) : ?>
			<p class="description" style="background:#d1fae5;padding:8px 12px;border-left:3px solid #10b981;margin-top:8px;">
				<strong>✓ <?php esc_html_e( 'Tryb Standalone aktywny.', 'ligase' ); ?></strong>
				<?php esc_html_e( 'Ligase próbuje wyciszać schema wymienionych wtyczek przez znane filtry. Sprawdź wynik na produkcie / wpisie — w źródle strony powinien być tylko JSON-LD Ligase. Jeśli widzisz duplikat (np. Yoast nadal emituje breadcrumbs), wyłącz odpowiednie schemy w ustawieniach tamtej wtyczki — niektóre wersje (np. Yoast 27.x) emitują przez nowe filtry których Ligase jeszcze nie zna.', 'ligase' ); ?>
			</p>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Te wtyczki mogą generować własną schema. Ligase domyślnie się wycofuje (żeby nie tworzyć duplikatów). Włącz tryb standalone w ustawieniach jeśli chcesz żeby Ligase zastępowała ich schema.', 'ligase' ); ?>
			</p>
			<p style="margin-top:6px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ligase-ustawienia' ) ); ?>" class="button button-primary button-small">
					<?php esc_html_e( 'Przejdź do ustawień → włącz Standalone', 'ligase' ); ?>
				</a>
			</p>
		<?php endif; ?>
	<?php else : ?>
		<p class="ligase-notice ligase-notice-success">
			<?php esc_html_e( 'Nie wykryto żadnych konfliktujących wtyczek SEO.', 'ligase' ); ?>
		</p>
	<?php endif; ?>
</div>

<!-- Auditor Controls -->
<div class="ligase-card ligase-card-wide">
	<h2><?php esc_html_e( 'Skanowanie i naprawa', 'ligase' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Audytor skanuje istniejaca schema na Twoich postach, ocenia ja w skali 0-100, i moze zastapic slaba schema lepsza wersja.', 'ligase' ); ?>
	</p>

	<div class="ligase-auditor-controls">
		<div class="ligase-control-group">
			<label for="ligase-audit-threshold">
				<?php esc_html_e( 'Prog zastepowania (score):', 'ligase' ); ?>
			</label>
			<input type="number" id="ligase-audit-threshold" value="50" min="0" max="100" step="5" class="small-text" />
		</div>

		<div class="ligase-control-group">
			<label for="ligase-audit-mode">
				<?php esc_html_e( 'Tryb:', 'ligase' ); ?>
			</label>
			<select id="ligase-audit-mode">
				<option value="scan"><?php esc_html_e( 'Tylko skan (nie zmienia nic)', 'ligase' ); ?></option>
				<option value="supplement"><?php esc_html_e( 'Uzupelniaj (dodaje brakujace pola)', 'ligase' ); ?></option>
				<option value="replace"><?php esc_html_e( 'Zastap (pelna zamiana)', 'ligase' ); ?></option>
			</select>
		</div>
	</div>

	<div class="ligase-actions" style="margin-top: 16px;">
		<button type="button" class="button button-primary" id="ligase-run-audit">
			<?php esc_html_e( 'Uruchom audyt', 'ligase' ); ?>
		</button>
		<span id="ligase-audit-status" class="ligase-status-text"></span>
	</div>
</div>

<!-- Audit Results -->
<div class="ligase-card ligase-card-wide" id="ligase-audit-results" style="display:none;">
	<h2><?php esc_html_e( 'Wyniki audytu', 'ligase' ); ?></h2>
	<div id="ligase-audit-summary" class="ligase-stats-row" style="margin-bottom:16px;"></div>
	<table class="wp-list-table widefat fixed striped" id="ligase-audit-table">
		<thead>
			<tr>
				<th style="width:40px;"><input type="checkbox" id="ligase-audit-check-all" /></th>
				<th><?php esc_html_e( 'Post', 'ligase' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Score', 'ligase' ); ?></th>
				<th><?php esc_html_e( 'Problemy', 'ligase' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Zrodlo', 'ligase' ); ?></th>
			</tr>
		</thead>
		<tbody></tbody>
	</table>

	<div class="ligase-actions" style="margin-top: 16px;">
		<button type="button" class="button button-primary" id="ligase-apply-audit">
			<?php esc_html_e( 'Zastosuj naprawy dla zaznaczonych', 'ligase' ); ?>
		</button>
	</div>
</div>
