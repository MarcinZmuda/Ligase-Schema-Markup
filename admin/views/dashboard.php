<?php
/**
 * Ligase Dashboard View
 *
 * Main admin dashboard showing AI Search Readiness Score,
 * schema coverage stats, and quick actions.
 *
 * @package Ligase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$score_calculator = new Ligase_Score();
$site_score       = $score_calculator->calculate();
$score_value      = $site_score['score'];
$checks           = $site_score['checks'];
$recommendations  = $site_score['recommendations'];

// Conflict detection
$opts            = get_option( 'ligase_options', [] );
$standalone      = ! empty( $opts['standalone_mode'] );
$suppressor      = new Ligase_Suppressor();
$active_seo      = $suppressor->get_active_seo_plugins();

// Post stats
$total_posts = wp_count_posts( 'post' );
$published   = (int) $total_posts->publish;

// Quick counts via transient (populated by AJAX on first load)
$stats_cache = get_transient( 'ligase_dashboard_stats' );
$complete    = $stats_cache['complete'] ?? '—';
$warnings    = $stats_cache['warnings'] ?? '—';
$missing     = $stats_cache['missing'] ?? '—';

// Score color
if ( $score_value >= 70 ) {
	$score_color = '#10B981';
} elseif ( $score_value >= 40 ) {
	$score_color = '#F59E0B';
} else {
	$score_color = '#EF4444';
}
?>

<h1><?php esc_html_e( 'Ligase — Dashboard', 'ligase' ); ?></h1>

<?php if ( ! empty( $active_seo ) && ! $standalone ) : ?>
	<div class="ligase-notice ligase-notice-warning" style="margin-bottom: 16px;">
		<strong><?php esc_html_e( 'Wykryto aktywne wtyczki SEO:', 'ligase' ); ?></strong>
		<?php echo esc_html( implode( ', ', array_column( $active_seo, 'name' ) ) ); ?>.
		<?php esc_html_e( 'Ligase nie generuje schema aby uniknac duplikatow. Wlacz tryb standalone w', 'ligase' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ligase-ustawienia' ) ); ?>">
			<?php esc_html_e( 'ustawieniach', 'ligase' ); ?>
		</a><?php esc_html_e( ', aby zastapic ich schema.', 'ligase' ); ?>
	</div>
<?php endif; ?>

<div class="ligase-dashboard-grid">

	<!-- AI Search Readiness Score -->
	<div class="ligase-card ligase-card-score">
		<h2><?php esc_html_e( 'AI Search Readiness Score', 'ligase' ); ?></h2>
		<div class="ligase-score-circle" style="--score-color: <?php echo esc_attr( $score_color ); ?>">
			<span class="ligase-score-value"><?php echo esc_html( $score_value ); ?></span>
			<span class="ligase-score-max">/100</span>
		</div>
		<?php if ( ! empty( $recommendations ) ) : ?>
			<div class="ligase-recommendations">
				<h3><?php esc_html_e( 'Rekomendacje', 'ligase' ); ?></h3>
				<ul>
					<?php
					// Map keywords to settings page tabs for actionable links
					$rec_links = array(
						'logo'        => admin_url( 'admin.php?page=ligase-ustawienia&tab=organization' ),
						'Logo'        => admin_url( 'admin.php?page=ligase-ustawienia&tab=organization' ),
						'org_name'    => admin_url( 'admin.php?page=ligase-ustawienia&tab=organization' ),
						'opis'        => admin_url( 'admin.php?page=ligase-ustawienia&tab=organization' ),
						'Wikidata'    => admin_url( 'admin.php?page=ligase-ustawienia&tab=social' ),
						'sameAs'      => admin_url( 'admin.php?page=ligase-ustawienia&tab=social' ),
						'Social'      => admin_url( 'admin.php?page=ligase-ustawienia&tab=social' ),
						'jobTitle'    => admin_url( 'admin.php?page=ligase-ustawienia&tab=organization' ),
						'stanowisko'  => admin_url( 'profile.php' ),
						'knowsAbout'  => admin_url( 'profile.php' ),
						'obraz'       => admin_url( 'admin.php?page=ligase-posty' ),
						'1200'        => admin_url( 'admin.php?page=ligase-posty' ),
						'image'       => admin_url( 'admin.php?page=ligase-posty' ),
					);
					foreach ( $recommendations as $rec ) :
						$link = '';
						foreach ( $rec_links as $keyword => $url ) {
							if ( false !== stripos( $rec, $keyword ) ) {
								$link = $url;
								break;
							}
						}
					?>
						<li>
							<?php echo esc_html( $rec ); ?>
							<?php if ( $link ) : ?>
								<a href="<?php echo esc_url( $link ); ?>" style="margin-left:6px;font-size:11px;">
									→ <?php esc_html_e( 'Napraw', 'ligase' ); ?>
								</a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>

	<!-- Schema Coverage -->
	<div class="ligase-card">
		<h2><?php esc_html_e( 'Pokrycie schema', 'ligase' ); ?></h2>
		<div class="ligase-stats-row">
			<div class="ligase-stat">
				<span class="ligase-stat-value ligase-stat-green"><?php echo esc_html( $complete ); ?></span>
				<span class="ligase-stat-label"><?php esc_html_e( 'Kompletne', 'ligase' ); ?></span>
			</div>
			<div class="ligase-stat">
				<span class="ligase-stat-value ligase-stat-yellow"><?php echo esc_html( $warnings ); ?></span>
				<span class="ligase-stat-label"><?php esc_html_e( 'Ostrzezenia', 'ligase' ); ?></span>
			</div>
			<div class="ligase-stat">
				<span class="ligase-stat-value ligase-stat-red"><?php echo esc_html( $missing ); ?></span>
				<span class="ligase-stat-label"><?php esc_html_e( 'Brak schema', 'ligase' ); ?></span>
			</div>
			<div class="ligase-stat">
				<span class="ligase-stat-value"><?php echo esc_html( $published ); ?></span>
				<span class="ligase-stat-label"><?php esc_html_e( 'Opublikowane', 'ligase' ); ?></span>
			</div>
		</div>
		<p class="ligase-stats-note" id="ligase-stats-loading">
			<?php esc_html_e( 'Ladowanie statystyk...', 'ligase' ); ?>
		</p>
	</div>

	<!-- Checks Detail -->
	<div class="ligase-card ligase-card-wide">
		<h2><?php esc_html_e( 'Szczegoly kontroli', 'ligase' ); ?></h2>
		<table class="ligase-checks-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Kontrola', 'ligase' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ligase' ); ?></th>
					<th><?php esc_html_e( 'Punkty', 'ligase' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<tr>
						<td><?php echo esc_html( $check['label'] ); ?></td>
						<td>
							<?php if ( $check['passed'] ) : ?>
								<span class="ligase-badge ligase-badge-pass">&#10003;</span>
							<?php else : ?>
								<span class="ligase-badge ligase-badge-fail">&#10007;</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $check['points'] . '/' . $check['max_points'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<!-- Google Search Console -->
	<div class="ligase-card ligase-card-wide">
		<h2><?php esc_html_e( 'Google Search Console', 'ligase' ); ?></h2>
		<?php if ( class_exists( 'Ligase_GSC' ) && Ligase_GSC::is_configured() ) : ?>
			<p class="ligase-notice ligase-notice-success" style="margin-bottom: 12px;">
				<?php esc_html_e( 'GSC polaczony.', 'ligase' ); ?>
				<button type="button" class="button button-small" id="ligase-gsc-sync" style="margin-left: 8px;">
					<?php esc_html_e( 'Synchronizuj dane', 'ligase' ); ?>
				</button>
				<button type="button" class="button button-small" id="ligase-gsc-rich-results" style="margin-left: 4px;">
					<?php esc_html_e( 'Pokaz rich results', 'ligase' ); ?>
				</button>
			</p>
			<div id="ligase-gsc-data"></div>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Polacz Google Search Console aby zobaczyc ktore posty maja rich results i jak wplywaja na CTR.', 'ligase' ); ?>
			</p>
			<div style="margin-top: 8px;">
				<textarea id="ligase-gsc-json" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Wklej Service Account JSON z Google Cloud Console...', 'ligase' ); ?>"></textarea>
				<div style="display: flex; gap: 8px; margin-top: 8px; align-items: center;">
					<input type="url" id="ligase-gsc-site-url" value="<?php echo esc_attr( home_url( '/' ) ); ?>" class="regular-text" placeholder="https://example.com/" />
					<button type="button" class="button button-primary" id="ligase-gsc-connect">
						<?php esc_html_e( 'Polacz', 'ligase' ); ?>
					</button>
				</div>
				<p class="description" style="margin-top: 6px;">
					<?php esc_html_e( 'Jak uzyskac Service Account: Google Cloud Console > IAM > Service Accounts > Create > Dodaj do GSC jako uzytkownik z uprawnieniem Full.', 'ligase' ); ?>
				</p>
			</div>
		<?php endif; ?>
	</div>

	<!-- Quick Actions -->
	<div class="ligase-card">
		<h2><?php esc_html_e( 'Szybkie akcje', 'ligase' ); ?></h2>
		<div class="ligase-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=ligase-posty' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Skanuj posty', 'ligase' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=ligase-audytor' ) ); ?>" class="button">
				<?php esc_html_e( 'Audytor schema', 'ligase' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=ligase-ustawienia' ) ); ?>" class="button">
				<?php esc_html_e( 'Ustawienia', 'ligase' ); ?>
			</a>
			<button type="button" class="button" id="ligase-refresh-stats">
				<?php esc_html_e( 'Odswiez statystyki', 'ligase' ); ?>
			</button>
		</div>
	</div>

</div>
