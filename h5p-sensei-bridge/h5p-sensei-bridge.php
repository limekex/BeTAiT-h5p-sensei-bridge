<?php
/**
 * Plugin Name: BeTAiT H5P → Sensei Bridge
 * Description: Registrerer H5P-resultater på innloggede brukere og oppdaterer progresjon i Sensei LMS (MVP).
 * Author: FagKlar / Bjørn-Tore
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * Text Domain: fkhs
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FKHS_VER', '0.1.0' );
define( 'FKHS_FILE', __FILE__ );
define( 'FKHS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FKHS_URL', plugin_dir_url( __FILE__ ) );
define( 'FKHS_TABLE', 'fkhs_attempts' );

require_once FKHS_DIR . 'includes/class-fkhs-plugin.php';
require_once FKHS_DIR . 'includes/class-fkhs-rest.php';
require_once FKHS_DIR . 'includes/class-fkhs-sensei.php';
require_once FKHS_DIR . 'includes/class-fkhs-reporter.php';
require_once FKHS_DIR . 'includes/class-fkhs-metabox.php';

register_activation_hook( __FILE__, ['FKHS_Plugin','activate'] );
register_uninstall_hook( __FILE__, 'fkhs_uninstall' );

function fkhs_uninstall() {
	// Slett tabell ved avinstallasjon? Beholder default for sikkerhet.
	// Om du vil droppe: implementer DROP TABLE her.
}

add_action('plugins_loaded', function() {
	// Boot
	FKHS_Plugin::init();
	FKHS_REST::init();
	FKHS_Sensei::init();
	FKHS_Reporter::init();
	FKHS_Metabox::init();
});
