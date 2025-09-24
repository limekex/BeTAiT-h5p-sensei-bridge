<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FKHS_Metabox {

	public static function init() {
		add_action('add_meta_boxes', [__CLASS__, 'add']);
		add_action('save_post_lesson', [__CLASS__, 'save']);
	}

	public static function add() {
		add_meta_box(
			'fkhs-lesson-settings',
			'FagKlar H5P → Sensei',
			[__CLASS__,'render'],
			'lesson',
			'side',
			'default'
		);
	}

	public static function render( WP_Post $post ) {
		wp_nonce_field('fkhs_lesson_meta','fkhs_lesson_meta_nonce');

		$threshold   = (float) get_post_meta( $post->ID, '_fkhs_pass_threshold', true );
		if ( ! $threshold ) $threshold = 70.0;

		$requirePass = (bool) get_post_meta( $post->ID, '_fkhs_require_pass', true );
		?>
		<p><strong>Bestått-terskel (%)</strong></p>
		<p>
			<input type="number" min="0" max="100" step="1" name="fkhs_pass_threshold" value="<?php echo esc_attr($threshold); ?>" style="width:120px;">
			<span class="description">Definerer “bestått” (score ≥ terskel). Default 70%.</span>
		</p>

		<p style="margin-top:.75rem">
			<label>
				<input type="checkbox" name="fkhs_require_pass" value="1" <?php checked( $requirePass ); ?>>
				<strong>Krev H5P bestått for å fullføre leksjon</strong>
			</label>
		</p>
		<p class="description">Når aktivert, rulles “Fullfør leksjon” tilbake hvis H5P-resultatet ikke når terskelen.</p>

		<p class="description" style="margin-top:.5rem">Tips: Plasser H5P-innholdet i selve leksjonen. Broen fanger xAPI fra H5P som lastes på siden.</p>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! isset($_POST['fkhs_lesson_meta_nonce']) || ! wp_verify_nonce($_POST['fkhs_lesson_meta_nonce'], 'fkhs_lesson_meta') ) return;
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
		if ( ! current_user_can('edit_post', $post_id) ) return;

		if ( isset($_POST['fkhs_pass_threshold']) ) {
			update_post_meta( $post_id, '_fkhs_pass_threshold', (float) $_POST['fkhs_pass_threshold'] );
		}

		// lagre checkbox (1/0)
		update_post_meta( $post_id, '_fkhs_require_pass', isset($_POST['fkhs_require_pass']) ? 1 : 0 );
	}
}
