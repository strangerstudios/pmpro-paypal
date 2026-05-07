<?php
/**
 * Plugin Name: Paid Memberships Pro - PayPal Gateway
 * Plugin URI: https://www.paidmembershipspro.com/add-ons/pmpro-paypal/
 * Description: Modern PayPal integration using Orders V2 and Subscriptions API v1 with offsite redirect checkout.
 * Version: 1.1.1
 * Author: Paid Memberships Pro
 * Author URI: https://www.paidmembershipspro.com
 * Text Domain: pmpro-paypal
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'PMPRO_PAYPAL_VERSION', '1.1.1' );
define( 'PMPRO_PAYPAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMPRO_PAYPAL_URL', plugin_dir_url( __FILE__ ) );
define( 'PMPRO_PAYPAL_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Bootstrap the plugin after PMPro is loaded.
 */
function pmpro_paypal_init() {
	// Gate on PMPro being active.
	if ( ! defined( 'PMPRO_DIR' ) ) {
		return;
	}

	// Require PMPro 3.7.1+ which renames the old 'paypal' (Website Payments Pro) slug
	// to 'paypalwpp', freeing the 'paypal' slug for this add-on.
	if ( defined( 'PMPRO_VERSION' ) && version_compare( PMPRO_VERSION, '3.7.1', '<' ) ) {
		add_action( 'admin_notices', 'pmpro_paypal_needs_core_upgrade_notice' );
		return;
	}

	// Load classes.
	require_once PMPRO_PAYPAL_DIR . 'classes/class-paypal-api.php';
	require_once PMPRO_PAYPAL_DIR . 'classes/class-pmprogateway-paypal.php';

	// Secondary-gateway checkout UI.
	require_once PMPRO_PAYPAL_DIR . 'includes/checkout.php';

	// Register webhook REST route.
	add_action( 'rest_api_init', 'pmpro_paypal_register_webhook_route' );

	// Set gateway_ready based on credentials.
	add_filter( 'pmpro_is_ready', 'pmpro_paypal_gateway_ready' );
}
add_action( 'plugins_loaded', 'pmpro_paypal_init', 20 );

/**
 * Register webhook REST route.
 *
 * GET is accepted alongside POST so the URL can be opened in a browser
 * to confirm the endpoint is reachable, matching the convention used by
 * the other PMPro gateway webhook handlers. The callback no-ops on GET.
 */
function pmpro_paypal_register_webhook_route() {
	register_rest_route( 'pmpro-paypal/v1', '/webhook', array(
		'methods'             => array( 'GET', 'POST' ),
		'callback'            => 'pmpro_paypal_webhook_callback',
		'permission_callback' => '__return_true',
	) );
}

/**
 * Webhook callback — delegates POST requests to the handler. GET requests
 * return a static "ok" response so the URL is browser-pingable; routing
 * them through the handler would just log a signature-verification failure
 * on every bot crawl.
 */
function pmpro_paypal_webhook_callback( $request ) {
	if ( 'POST' !== $request->get_method() ) {
		return new WP_REST_Response(
			array(
				'status'  => 'ok',
				'message' => 'PMPro PayPal webhook endpoint is reachable.',
			),
			200
		);
	}
	require_once PMPRO_PAYPAL_DIR . 'includes/webhook-handler.php';
	return pmpro_paypal_handle_webhook( $request );
}

/**
 * Set gateway_ready based on credentials.
 *
 * PMPro core doesn't know about add-on gateways, so the gateway_ready
 * global defaults to false. We hook pmpro_is_ready to set it.
 */
function pmpro_paypal_gateway_ready( $r ) {
	global $pmpro_gateway_ready;
	$gateway = pmpro_getGateway();
	if ( 'paypal' === $gateway ) {
		$pmpro_gateway_ready = pmpro_paypal_has_credentials();
		if ( $pmpro_gateway_ready ) {
			$r = true;
		}
	}
	return $r;
}

/**
 * Whether PayPal credentials are configured for the active environment.
 *
 * @return bool
 */
function pmpro_paypal_has_credentials() {
	$environment = get_option( 'pmpro_gateway_environment', 'sandbox' );
	$suffix      = 'sandbox' === $environment ? '_sandbox' : '_live';
	$client_id   = get_option( 'pmpro_paypal_client_id' . $suffix );
	$secret      = get_option( 'pmpro_paypal_client_secret' . $suffix );
	return ! empty( $client_id ) && ! empty( $secret );
}

/**
 * Show admin notice when PMPro core is too old for this add-on.
 *
 * @since 1.0
 */
function pmpro_paypal_needs_core_upgrade_notice() {
	// Only show on PMPro admin pages.
	if ( ! isset( $_REQUEST['page'] ) || strpos( sanitize_text_field( $_REQUEST['page'] ), 'pmpro-' ) !== 0 ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Paid Memberships Pro - PayPal Gateway', 'pmpro-paypal' ); ?>:</strong>
			<?php esc_html_e( 'This add-on requires Paid Memberships Pro version 3.7.1 or later. Please update Paid Memberships Pro to use the PayPal Gateway.', 'pmpro-paypal' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Add links to the plugin row meta.
 *
 * @param array  $links the links array
 * @param string $file the file name
 * @return array $links the links array
 * @since 1.0
 */
function pmpro_paypal_plugin_row_meta( $links, $file ) {
	if ( strpos( $file, 'pmpro-paypal.php' ) !== false ) {
		$new_links = array(
			'<a href="' . esc_url( 'https://www.paidmembershipspro.com/add-ons/pmpro-paypal/' ) . '" title="' . esc_attr__( 'View Documentation', 'pmpro-paypal' ) . '">' . esc_html__( 'Docs', 'pmpro-paypal' ) . '</a>',
			'<a href="' . esc_url( 'https://www.paidmembershipspro.com/support/' ) . '" title="' . esc_attr__( 'Visit Customer Support Forum', 'pmpro-paypal' ) . '">' . esc_html__( 'Support', 'pmpro-paypal' ) . '</a>',
		);
		$links = array_merge( $links, $new_links );
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'pmpro_paypal_plugin_row_meta', 10, 2 );
