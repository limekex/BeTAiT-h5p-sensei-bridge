<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FKHS_Reporter {

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu']);
	}

	public static function menu() {
		add_menu_page(
			__('H5P → Sensei', 'fkhs'),
			__('H5P → Sensei', 'fkhs'),
			'manage_options',
			'fkhs-report',
			[__CLASS__, 'render'],
			'dashicons-yes',
			58
		);
	}
public static function render() {
	if ( ! current_user_can('manage_options') ) return;
	global $wpdb;
	$table = $wpdb->prefix . FKHS_TABLE;

	$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200", ARRAY_A );
	?>
	<div class="wrap">
		<h1>H5P → Sensei (siste 200 forsøk)</h1>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Dato</th>
					<th>User</th>
					<th>Lesson</th>
					<th>Kriterie</th>
					<th>H5P Content</th>
					<th>Score</th>
					<th>Passed</th>
					<th>Completed</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty($rows) ): ?>
				<tr><td colspan="8">Ingen data ennå.</td></tr>
			<?php else:
				foreach ( $rows as $r ):
					$user = get_user_by('id', (int)$r['user_id']);
					$lesson_id = (int) $r['lesson_id'];
					$lesson_link = get_edit_post_link( $lesson_id );
					$threshold = (float) get_post_meta( $lesson_id, '_fkhs_pass_threshold', true );
					if ( ! $threshold ) $threshold = 70.0;

					$h5p_id = $r['content_id'] ? (int)$r['content_id'] : 0;
					$h5p_link = $h5p_id ? admin_url( 'admin.php?page=h5p&task=edit&id=' . $h5p_id ) : '';
					?>
					<tr>
						<td><?php echo esc_html( $r['created_at'] ); ?></td>
						<td><?php echo $user ? esc_html( $user->user_login ) : ('#'.$r['user_id']); ?></td>
						<td><?php if ($lesson_link): ?><a href="<?php echo esc_url($lesson_link); ?>">#<?php echo $lesson_id; ?></a><?php else: echo $lesson_id; endif; ?></td>
						<td><?php echo esc_html( $threshold ) . '%'; ?></td>
						<td>
							<?php
							if ( $h5p_id ) {
								echo '<a href="' . esc_url( $h5p_link ) . '">#' . (int) $h5p_id . '</a>';
							} else {
								echo '—';
							}
							?>
						</td>
						<td><?php
							$raw = is_null($r['raw_score']) ? '—' : (float)$r['raw_score'];
							$max = is_null($r['max_score']) ? '—' : (float)$r['max_score'];
							echo esc_html("$raw / $max");
						?></td>
						<td><?php echo !empty($r['passed']) ? '✓' : '–'; ?></td>
						<td><?php echo !empty($r['completed']) ? '✓' : '–'; ?></td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}
/* 	public static function render() {
		if ( ! current_user_can('manage_options') ) return;
		global $wpdb;
		$table = $wpdb->prefix . FKHS_TABLE;

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200", ARRAY_A );
		?>
		<div class="wrap">
			<h1>H5P → Sensei (siste 200 forsøk)</h1>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Dato</th>
						<th>User</th>
						<th>Lesson</th>
						<th>H5P Content</th>
						<th>Criteria</th>
						<th>Score</th>
						<th>Passed</th>
						<th>Completed</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty($rows) ): ?>
					<tr><td colspan="7">Ingen data ennå.</td></tr>
				<?php else:
					foreach ( $rows as $r ):
						$user = get_user_by('id', (int)$r['user_id']);
						$lesson_link = get_edit_post_link( (int)$r['lesson_id'] );
						?>
						<tr>
							<td><?php echo esc_html( $r['created_at'] ); ?></td>
							<td><?php echo $user ? esc_html($user->user_login) : ('#'.$r['user_id']); ?></td>
							<td><?php if ($lesson_link): ?><a href="<?php echo esc_url($lesson_link); ?>">#<?php echo (int)$r['lesson_id']; ?></a><?php else: echo (int)$r['lesson_id']; endif; ?></td>
							<td>	<?php echo $r['content_id'] 
								? '<a href="'.admin_url('admin.php?page=h5p&task=edit&id='.(int)$r['content_id']).'">#'.(int)$r['content_id'].'</a>'
								: '—'; ?>
							</td>
							<td>
								<?php echo esc_html(
									(float) get_post_meta((int)$r['lesson_id'], '_fkhs_pass_threshold', true) ?: 70
								) . '%'; ?>
							</td>
							<td><?php
								$raw = is_null($r['raw_score']) ? '—' : (float)$r['raw_score'];
								$max = is_null($r['max_score']) ? '—' : (float)$r['max_score'];
								echo esc_html("$raw / $max");
							?></td>
							<td><?php echo $r['passed'] ? '✓' : '–'; ?></td>
							<td><?php echo $r['completed'] ? '✓' : '–'; ?></td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	} */
}
