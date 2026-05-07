<?php
defined( 'ABSPATH' ) || exit;

// Load base gateway class.
require_once PMPRO_DIR . '/classes/gateways/class.pmprogateway.php';

// Register on init.
add_action( 'init', array( 'PMProGateway_paypal', 'init' ) );

class PMProGateway_paypal extends PMProGateway {

	/**
	 * Constructor.
	 */
	public function __construct( $gateway = null ) {
		$this->gateway = $gateway;
		return $this->gateway;
	}

	/**
	 * Run on WP init.
	 */
	public static function init() {
		// Register gateway.
		add_filter( 'pmpro_gateways', array( 'PMProGateway_paypal', 'pmpro_gateways' ) );

		// Only add checkout hooks if this gateway is active.
		$gateway = pmpro_getGateway();
		if ( 'paypal' === $gateway ) {
			add_filter( 'pmpro_required_billing_fields', array( 'PMProGateway_paypal', 'pmpro_required_billing_fields' ) );

			// When PayPal is the primary gateway we own the entire checkout
			// and strip out billing/payment fields + swap the submit button.
			// When PayPal is running as a secondary option those fields stay
			// in the DOM so the user can switch back — the pmpro-paypal.php
			// checkout boxes hook renders the PayPal button in that case.
			if ( 'paypal' === get_option( 'pmpro_gateway' ) ) {
				add_filter( 'pmpro_include_billing_address_fields', '__return_false' );
				add_filter( 'pmpro_include_payment_information_fields', '__return_false' );
				add_filter( 'pmpro_checkout_default_submit_button', array( 'PMProGateway_paypal', 'pmpro_checkout_default_submit_button' ) );
			}
		}

		// Refund hooks.
		add_filter( 'pmpro_allowed_refunds_gateways', array( 'PMProGateway_paypal', 'allowed_refund_gateways' ) );
		add_filter( 'pmpro_process_refund_paypal', array( 'PMProGateway_paypal', 'process_refund' ), 10, 2 );

		// Admin scripts.
		add_action( 'admin_enqueue_scripts', array( 'PMProGateway_paypal', 'admin_enqueue_scripts' ) );
	}

	/**
	 * Add to gateways list.
	 */
	public static function pmpro_gateways( $gateways ) {
		if ( empty( $gateways['paypal'] ) ) {
			$gateways['paypal'] = __( 'PayPal', 'pmpro-paypal' );
		}
		return $gateways;
	}

	/**
	 * Description for gateway settings page.
	 */
	public static function get_description_for_gateway_settings() {
		return esc_html__( 'With PayPal, members can pay with their PayPal balance, credit/debit cards, or linked bank accounts. PayPal is accepted worldwide and offers multi-currency support for 200+ markets and 25+ currencies.', 'pmpro-paypal' );
	}

	/**
	 * Feature support.
	 */
	public static function supports( $feature ) {
		$supports = array(
			'subscription_sync'        => true,
			'payment_method_updates'   => false,
			'check_token_orders'       => true,
		);
		return empty( $supports[ $feature ] ) ? false : $supports[ $feature ];
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Enqueue admin scripts on PMPro admin pages.
	 */
	public static function admin_enqueue_scripts() {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'pmpro' ) === false ) {
			return;
		}
		wp_enqueue_script(
			'pmpro-paypal-admin',
			PMPRO_PAYPAL_URL . 'js/pmpro-paypal-admin.js',
			array( 'jquery' ),
			PMPRO_PAYPAL_VERSION,
			true
		);
	}

	// ---------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------

	/**
	 * Display settings fields.
	 */
	public static function show_settings_fields() {
		$live_client_id      = get_option( 'pmpro_paypal_client_id_live' );
		$live_client_secret  = get_option( 'pmpro_paypal_client_secret_live' );
		$live_webhook_id     = get_option( 'pmpro_paypal_webhook_id_live' );
		$sb_client_id        = get_option( 'pmpro_paypal_client_id_sandbox' );
		$sb_client_secret    = get_option( 'pmpro_paypal_client_secret_sandbox' );
		$sb_webhook_id       = get_option( 'pmpro_paypal_webhook_id_sandbox' );
		$webhook_url         = rest_url( 'pmpro-paypal/v1/webhook' );
		?>
		<div id="pmpro_paypal_live" class="pmpro_section" data-visibility="shown" data-activated="true">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Live PayPal Settings', 'pmpro-paypal' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<table class="form-table">
					<tbody>
						<tr class="gateway gateway_paypal">
							<th scope="row" valign="top">
								<label for="paypal_client_id_live"><?php esc_html_e( 'Client ID', 'pmpro-paypal' ); ?></label>
							</th>
							<td>
								<input type="text" id="paypal_client_id_live" name="paypal_client_id_live" value="<?php echo esc_attr( $live_client_id ); ?>" class="regular-text code" />
							</td>
						</tr>
						<tr class="gateway gateway_paypal">
							<th scope="row" valign="top">
								<label for="paypal_client_secret_live"><?php esc_html_e( 'Client Secret', 'pmpro-paypal' ); ?></label>
							</th>
							<td>
								<input type="text" id="paypal_client_secret_live" name="paypal_client_secret_live" value="<?php echo esc_attr( $live_client_secret ); ?>" autocomplete="off" class="regular-text code pmpro-admin-secure-key" />
							</td>
						</tr>
						<?php if ( ! empty( $live_client_id ) || ! empty( $live_client_secret ) ) : ?>
						<tr class="gateway gateway_paypal">
							<th scope="row" valign="top">
								<label for="paypal_webhook_id_live"><?php esc_html_e( 'Live Webhook ID', 'pmpro-paypal' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="paypal_webhook_id_live"
									name="paypal_webhook_id_live"
									value="<?php echo esc_attr( $live_webhook_id ); ?>"
									class="regular-text code"
									<?php if ( ! empty( $live_webhook_id ) ) echo 'readonly'; ?>
								/>
								<?php if ( ! empty( $live_webhook_id ) ) : ?>
								<button type="button" class="button pmpro-paypal-edit-webhook-id" data-target="paypal_webhook_id_live">
									<?php esc_html_e( 'Edit', 'pmpro-paypal' ); ?>
								</button>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'The PayPal Webhook ID used to verify incoming webhook events.', 'pmpro-paypal' ); ?></p>
							</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<div id="pmpro_paypal_sandbox" class="pmpro_section" data-visibility="shown" data-activated="true">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Sandbox PayPal Settings', 'pmpro-paypal' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<table class="form-table">
					<tbody>
						<tr class="gateway gateway_paypal">
							<th scope="row" valign="top">
								<label for="paypal_client_id_sandbox"><?php esc_html_e( 'Client ID', 'pmpro-paypal' ); ?></label>
							</th>
							<td>
								<input type="text" id="paypal_client_id_sandbox" name="paypal_client_id_sandbox" value="<?php echo esc_attr( $sb_client_id ); ?>" class="regular-text code" />
							</td>
						</tr>
						<tr class="gateway gateway_paypal">
							<th scope="row" valign="top">
								<label for="paypal_client_secret_sandbox"><?php esc_html_e( 'Client Secret', 'pmpro-paypal' ); ?></label>
							</th>
							<td>
								<input type="text" id="paypal_client_secret_sandbox" name="paypal_client_secret_sandbox" value="<?php echo esc_attr( $sb_client_secret ); ?>" autocomplete="off" class="regular-text code pmpro-admin-secure-key" />
							</td>
						</tr>
						<?php if ( ! empty( $sb_client_id ) || ! empty( $sb_client_secret ) ) : ?>
						<tr class="gateway gateway_paypal">
							<th scope="row" valign="top">
								<label for="paypal_webhook_id_sandbox"><?php esc_html_e( 'Sandbox Webhook ID', 'pmpro-paypal' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="paypal_webhook_id_sandbox"
									name="paypal_webhook_id_sandbox"
									value="<?php echo esc_attr( $sb_webhook_id ); ?>"
									class="regular-text code"
									<?php if ( ! empty( $sb_webhook_id ) ) echo 'readonly'; ?>
								/>
								<?php if ( ! empty( $sb_webhook_id ) ) : ?>
								<button type="button" class="button pmpro-paypal-edit-webhook-id" data-target="paypal_webhook_id_sandbox">
									<?php esc_html_e( 'Edit', 'pmpro-paypal' ); ?>
								</button>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'The PayPal Webhook ID used to verify incoming webhook events.', 'pmpro-paypal' ); ?></p>
							</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<div id="pmpro_paypal_webhook" class="pmpro_section" data-visibility="shown" data-activated="true">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'PayPal Webhook', 'pmpro-paypal' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<table class="form-table">
					<tbody>
						<tr class="gateway gateway_paypal">
							<th scope="row" valign="top">
								<label><?php esc_html_e( 'Webhook URL', 'pmpro-paypal' ); ?></label>
							</th>
							<td>
								<p><code><?php echo esc_html( $webhook_url ); ?></code></p>
								<p class="description"><?php esc_html_e( 'Register this URL as a webhook endpoint in your PayPal dashboard, or save your API credentials to auto-register.', 'pmpro-paypal' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Save settings and auto-register webhook.
	 */
	public static function save_settings_fields() {
		// Save live credentials.
		if ( isset( $_REQUEST['paypal_client_id_live'] ) ) {
			update_option( 'pmpro_paypal_client_id_live', sanitize_text_field( $_REQUEST['paypal_client_id_live'] ) );
		}
		if ( isset( $_REQUEST['paypal_client_secret_live'] ) ) {
			update_option( 'pmpro_paypal_client_secret_live', sanitize_text_field( $_REQUEST['paypal_client_secret_live'] ) );
		}

		// Save sandbox credentials.
		if ( isset( $_REQUEST['paypal_client_id_sandbox'] ) ) {
			update_option( 'pmpro_paypal_client_id_sandbox', sanitize_text_field( $_REQUEST['paypal_client_id_sandbox'] ) );
		}
		if ( isset( $_REQUEST['paypal_client_secret_sandbox'] ) ) {
			update_option( 'pmpro_paypal_client_secret_sandbox', sanitize_text_field( $_REQUEST['paypal_client_secret_sandbox'] ) );
		}

		// Save manual webhook IDs (only if posted; empty value clears for re-registration).
		if ( isset( $_REQUEST['paypal_webhook_id_live'] ) ) {
			update_option( 'pmpro_paypal_webhook_id_live', sanitize_text_field( $_REQUEST['paypal_webhook_id_live'] ) );
		}
		if ( isset( $_REQUEST['paypal_webhook_id_sandbox'] ) ) {
			update_option( 'pmpro_paypal_webhook_id_sandbox', sanitize_text_field( $_REQUEST['paypal_webhook_id_sandbox'] ) );
		}

		// Clear cached OAuth tokens for both environments.
		delete_transient( 'pmpro_paypal_token_live' );
		delete_transient( 'pmpro_paypal_token_sandbox' );

		// Auto-register webhook for the active environment if no webhook ID is set.
		$environment = get_option( 'pmpro_gateway_environment', 'sandbox' );
		$suffix      = 'sandbox' === $environment ? '_sandbox' : '_live';
		$client_id   = get_option( 'pmpro_paypal_client_id' . $suffix );
		$secret      = get_option( 'pmpro_paypal_client_secret' . $suffix );
		if ( ! empty( $client_id ) && ! empty( $secret ) ) {
			self::maybe_register_webhook();
		}
	}

	/**
	 * Register webhook at PayPal if not already registered.
	 */
	public static function maybe_register_webhook() {
		$environment = get_option( 'pmpro_gateway_environment', 'sandbox' );
		$suffix      = 'sandbox' === $environment ? '_sandbox' : '_live';
		$webhook_id  = get_option( 'pmpro_paypal_webhook_id' . $suffix );
		if ( ! empty( $webhook_id ) ) {
			return;
		}

		$api = new PMPro_PayPal_API();
		$webhook_url = rest_url( 'pmpro-paypal/v1/webhook' );

		// Require HTTPS for PayPal webhooks.
		if ( strpos( $webhook_url, 'https://' ) !== 0 ) {
			return;
		}

		$events = array(
			'CHECKOUT.ORDER.APPROVED',
			'PAYMENT.SALE.COMPLETED',
			'PAYMENT.SALE.REFUNDED',
			'PAYMENT.CAPTURE.REFUNDED',
			'BILLING.SUBSCRIPTION.ACTIVATED',
			'BILLING.SUBSCRIPTION.CANCELLED',
			'BILLING.SUBSCRIPTION.SUSPENDED',
			'BILLING.SUBSCRIPTION.EXPIRED',
			'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
		);

		$result = $api->create_webhook( $webhook_url, $events );
		if ( ! is_wp_error( $result ) && ! empty( $result['id'] ) ) {
			update_option( 'pmpro_paypal_webhook_id' . $suffix, sanitize_text_field( $result['id'] ) );
		}
	}

	// ---------------------------------------------------------------
	// Checkout Modifications
	// ---------------------------------------------------------------

	/**
	 * Remove billing address fields from required fields.
	 */
	public static function pmpro_required_billing_fields( $fields ) {
		// Remove CC and billing address fields.
		$remove = array(
			'bfirstname', 'blastname', 'baddress1', 'bcity',
			'bstate', 'bzipcode', 'bcountry', 'bphone',
			'CardType', 'AccountNumber', 'ExpirationMonth',
			'ExpirationYear', 'CVV',
		);
		foreach ( $remove as $field ) {
			unset( $fields[ $field ] );
		}
		return $fields;
	}

	/**
	 * Show a "Check Out with PayPal" submit button.
	 */
	public static function pmpro_checkout_default_submit_button( $show ) {
		global $gateway, $pmpro_requirebilling;
		?>
		<span id="pmpro_paypal_checkout" <?php if ( 'paypal' !== $gateway || ! $pmpro_requirebilling ) { ?>style="display: none;"<?php } ?>>
			<input type="hidden" name="submit-checkout" value="1" />
			<button type="submit" id="pmpro_btn-submit-paypal" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_btn pmpro_btn-submit-checkout pmpro_btn-submit-checkout-paypal' ) ); ?>">
				<?php esc_html_e( 'Check Out with PayPal', 'pmpro-paypal' ); ?>
			</button>
		</span>

		<span id="pmpro_submit_span" <?php if ( 'paypal' === $gateway && $pmpro_requirebilling ) { ?>style="display: none;"<?php } ?>>
			<input type="hidden" name="submit-checkout" value="1" />
			<input type="submit" id="pmpro_btn-submit" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_btn pmpro_btn-submit-checkout', 'pmpro_btn-submit-checkout' ) ); ?>" value="<?php if ( $pmpro_requirebilling ) { esc_attr_e( 'Submit and Check Out', 'pmpro-paypal' ); } else { esc_attr_e( 'Submit and Confirm', 'pmpro-paypal' ); } ?>" />
		</span>
		<?php

		return false;
	}

	// ---------------------------------------------------------------
	// Plan Management (Lazy Creation)
	// ---------------------------------------------------------------

	/**
	 * Get or create a PayPal billing plan for the given level + price combo.
	 *
	 * @param object $level PMPro level with billing info.
	 * @param string $currency Currency code.
	 * @return string|WP_Error Plan ID or error.
	 */
	public static function get_or_create_plan( $level, $currency ) {
		$environment = get_option( 'pmpro_gateway_environment' );
		$api = new PMPro_PayPal_API();

		// Ensure product exists.
		$product_id = self::get_or_create_product( $level );
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		// Build params hash for this unique price/cycle combo.
		$initial    = pmpro_round_price_as_string( (float) $level->initial_payment );
		$recurring  = pmpro_round_price_as_string( (float) $level->billing_amount );
		$cycle_num  = intval( $level->cycle_number );
		$cycle_per  = strtoupper( $level->cycle_period ?? 'MONTH' );

		$hash_input = "{$recurring}_{$cycle_num}_{$cycle_per}_{$initial}_{$currency}";
		$plan_hash  = md5( $hash_input );

		// Check existing plans stored in level meta.
		$meta_key  = 'paypal_plans' . ( 'sandbox' === $environment ? '_sandbox' : '' );
		$plans_map = get_pmpro_membership_level_meta( $level->id, $meta_key, true );
		if ( ! is_array( $plans_map ) ) {
			$plans_map = array();
		}

		// If we have a cached plan ID for this hash, verify it exists at PayPal.
		if ( ! empty( $plans_map[ $plan_hash ] ) ) {
			$existing = $api->get_plan( $plans_map[ $plan_hash ] );
			if ( ! is_wp_error( $existing ) && ! empty( $existing['status'] ) && 'ACTIVE' === $existing['status'] ) {
				return $plans_map[ $plan_hash ];
			}
			// Plan no longer valid at PayPal — remove from map.
			unset( $plans_map[ $plan_hash ] );
		}

		// Build billing cycles — single regular cycle, infinite.
		$billing_cycles = array(
			array(
				'frequency'      => array(
					'interval_unit'  => self::map_cycle_period( $cycle_per ),
					'interval_count' => $cycle_num,
				),
				'tenure_type'    => 'REGULAR',
				'sequence'       => 1,
				'total_cycles'   => 0,
				'pricing_scheme' => array(
					'fixed_price' => array(
						'value'         => $recurring,
						'currency_code' => $currency,
					),
				),
			),
		);

		// Setup fee — charged immediately at subscription activation.
		// PayPal's first regular billing cycle starts at start_time (one period out),
		// so the setup fee is how the initial payment is collected.
		$setup_fee = array(
			'value'         => (float) $initial > 0 ? $initial : pmpro_round_price_as_string( 0, $currency ),
			'currency_code' => $currency,
		);

		// Plan description.
		$plan_name = substr( $level->name . ' - ' . get_bloginfo( 'name' ), 0, 127 );

		$plan_args = array(
			'product_id'          => $product_id,
			'name'                => $plan_name,
			'billing_cycles'      => $billing_cycles,
			'payment_preferences' => array(
				'auto_bill_outstanding'     => true,
				'setup_fee'                 => $setup_fee,
				'setup_fee_failure_action'  => 'CANCEL',
				'payment_failure_threshold' => 1,
			),
		);

		/**
		 * Filter the PayPal plan args before creation.
		 *
		 * @param array $plan_args PayPal plan arguments.
		 * @param object $level PMPro level object.
		 */
		$plan_args = apply_filters( 'pmpro_paypal_create_plan_args', $plan_args, $level );

		$result = $api->create_plan( $plan_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['id'] ) ) {
			return new WP_Error( 'pmpro_paypal_plan_error', __( 'Could not create PayPal plan.', 'pmpro-paypal' ) );
		}

		// Store in level meta.
		$plans_map[ $plan_hash ] = $result['id'];
		update_pmpro_membership_level_meta( $level->id, $meta_key, $plans_map );

		return $result['id'];
	}

	/**
	 * Get or create a PayPal catalog product for a membership level.
	 *
	 * @param object $level PMPro level object.
	 * @return string|WP_Error Product ID or error.
	 */
	public static function get_or_create_product( $level ) {
		$environment = get_option( 'pmpro_gateway_environment' );
		$meta_key    = 'paypal_product_id' . ( 'sandbox' === $environment ? '_sandbox' : '' );
		$product_id  = get_pmpro_membership_level_meta( $level->id, $meta_key, true );

		$api = new PMPro_PayPal_API();

		// Verify product still exists at PayPal.
		if ( ! empty( $product_id ) ) {
			$existing = $api->get_product( $product_id );
			if ( ! is_wp_error( $existing ) && ! empty( $existing['id'] ) ) {
				return $product_id;
			}
			// Product gone — fall through to create.
		}

		$product_args = array(
			'name'        => substr( $level->name, 0, 127 ),
			'type'        => 'SERVICE',
			'category'    => 'SOFTWARE',
			'description' => substr( __( 'Membership level at ', 'pmpro-paypal' ) . get_bloginfo( 'name' ), 0, 256 ),
		);

		/**
		 * Filter the PayPal product args before creation.
		 *
		 * @param array $product_args PayPal product arguments.
		 * @param object $level PMPro level object.
		 */
		$product_args = apply_filters( 'pmpro_paypal_create_product_args', $product_args, $level );

		$result = $api->create_product( $product_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['id'] ) ) {
			return new WP_Error( 'pmpro_paypal_product_error', __( 'Could not create PayPal product.', 'pmpro-paypal' ) );
		}

		update_pmpro_membership_level_meta( $level->id, $meta_key, $result['id'] );

		return $result['id'];
	}

	/**
	 * Map PMPro cycle period to PayPal interval unit.
	 *
	 * @param string $period PMPro period (Day, Week, Month, Year).
	 * @return string PayPal interval unit.
	 */
	private static function map_cycle_period( $period ) {
		$map = array(
			'DAY'   => 'DAY',
			'WEEK'  => 'WEEK',
			'MONTH' => 'MONTH',
			'YEAR'  => 'YEAR',
		);
		$period = strtoupper( $period );
		return $map[ $period ] ?? 'MONTH';
	}

	// ---------------------------------------------------------------
	// Process (checkout — offsite redirect)
	// ---------------------------------------------------------------

	/**
	 * Process checkout payment via offsite redirect to PayPal.
	 *
	 * The form submits first (user + order created via standard PMPro flow),
	 * then we redirect to PayPal for approval. Webhook completes checkout
	 * via pmpro_complete_async_checkout().
	 *
	 * @param MemberOrder $order The order to process.
	 * @return bool Always false (checkout completed async via webhook).
	 */
	public function process( &$order ) {
		// Free level — no payment needed.
		if ( (float) $order->subtotal <= 0 && ! pmpro_isLevelRecurring( $order->membership_level ) ) {
			$order->status = 'success';
			return true;
		}

		// Prepare for offsite async payment.
		$order->status = 'token';
		$order->saveOrder();
		pmpro_save_checkout_data_to_order( $order );

		$api   = new PMPro_PayPal_API();
		$level = $order->getMembershipLevelAtCheckout();

		global $pmpro_currency;
		$currency = ! empty( $pmpro_currency ) ? $pmpro_currency : 'USD';

		// Calculate initial payment amount with tax.
		$initial_subtotal       = $order->subtotal;
		$initial_tax            = $order->getTaxForPrice( $initial_subtotal );
		$initial_payment_amount = pmpro_round_price( (float) $initial_subtotal + (float) $initial_tax );

		if ( pmpro_isLevelRecurring( $level ) ) {
			// --- Recurring subscription flow ---

			// Calculate recurring amount with tax.
			$recurring_subtotal       = $level->billing_amount;
			$recurring_tax            = $order->getTaxForPrice( $recurring_subtotal );
			$recurring_payment_amount = pmpro_round_price( (float) $recurring_subtotal + (float) $recurring_tax );

			// Build a level clone with tax-inclusive amounts for plan creation.
			$plan_level = clone $level;
			$plan_level->initial_payment = $initial_payment_amount;
			$plan_level->billing_amount  = $recurring_payment_amount;

			// Get or create PayPal product and plan.
			$plan_id = self::get_or_create_plan( $plan_level, $currency );
			if ( is_wp_error( $plan_id ) ) {
				$order->error      = $plan_id->get_error_message();
				$order->shorterror = $plan_id->get_error_message();
				return false;
			}

			// Calculate profile start date (applies pmpro_set_profile_date filter).
			$profile_start_date = pmpro_calculate_profile_start_date( $order, 'c' );

			$user = get_userdata( $order->user_id );

			$subscription_args = array(
				'plan_id'    => $plan_id,
				'start_time' => $profile_start_date,
				'subscriber' => array(
					'email_address' => empty( $user->user_email ) ? '' : $user->user_email,
				),
				'application_context' => array(
					'brand_name'          => get_bloginfo( 'name' ),
					'shipping_preference' => 'NO_SHIPPING',
					'user_action'         => 'SUBSCRIBE_NOW',
					'return_url'          => apply_filters( 'pmpro_confirmation_url', add_query_arg( 'pmpro_level', $level->id, pmpro_url( 'confirmation' ) ), $order->user_id, $level ),
					'cancel_url'          => add_query_arg( 'pmpro_level', $level->id, pmpro_url( 'checkout' ) ),
				),
			);

			/**
			 * Filter the PayPal subscription args before creation.
			 *
			 * @param array  $subscription_args PayPal subscription arguments.
			 * @param object $level             PMPro level object.
			 */
			$subscription_args = apply_filters( 'pmpro_paypal_create_subscription_args', $subscription_args, $level );

			$result = $api->create_subscription( $subscription_args );
			if ( is_wp_error( $result ) ) {
				$order->error      = $result->get_error_message();
				$order->shorterror = $result->get_error_message();
				return false;
			}

			// Save subscription ID to order.
			$order->subscription_transaction_id = $result['id'];
			$order->saveOrder();

			// Find approve link and redirect.
			// PayPal's checkout page expects to be opened in a popup via their JS SDK.
			// Server-side redirects carry a Referer header that PayPal rejects with
			// INVALID_TOKEN. Stripping the referer simulates the clean popup context.
			$links = $result['links'] ?? array();
			foreach ( $links as $link ) {
				if ( 'approve' === ( $link['rel'] ?? '' ) ) {
					header( 'Referrer-Policy: no-referrer' );
					wp_redirect( $link['href'] );
					exit;
				}
			}

			$order->error      = __( 'Could not find PayPal approval link.', 'pmpro-paypal' );
			$order->shorterror = $order->error;
			return false;

		} else {
			// --- One-time payment flow ---

			$order_args = array(
				'intent'         => 'CAPTURE',
				'purchase_units' => array(
					array(
						'amount' => array(
							'currency_code' => $currency,
							'value'         => pmpro_round_price_as_string( $initial_payment_amount ),
						),
						'description' => substr( $level->name, 0, 127 ),
					),
				),
				'application_context' => array(
					'brand_name'          => substr( get_bloginfo( 'name' ), 0, 127 ),
					'shipping_preference' => 'NO_SHIPPING',
					'user_action'         => 'PAY_NOW',
					'return_url'          => apply_filters( 'pmpro_confirmation_url', add_query_arg( 'pmpro_level', $level->id, pmpro_url( 'confirmation' ) ), $order->user_id, $level ),
					'cancel_url'          => add_query_arg( 'pmpro_level', $level->id, pmpro_url( 'checkout' ) ),
				),
			);

			/**
			 * Filter the PayPal order args before creation.
			 *
			 * @param array  $order_args PayPal order arguments.
			 * @param object $level      PMPro level object.
			 */
			$order_args = apply_filters( 'pmpro_paypal_create_order_args', $order_args, $level );

			$result = $api->create_order( $order_args );
			if ( is_wp_error( $result ) ) {
				$order->error      = $result->get_error_message();
				$order->shorterror = $result->get_error_message();
				return false;
			}

			// Save PayPal order ID to order meta.
			update_pmpro_membership_order_meta( $order->id, 'paypal_order_id', $result['id'] );

			// Find approve link and redirect.
			$links = $result['links'] ?? array();
			foreach ( $links as $link ) {
				if ( 'approve' === ( $link['rel'] ?? '' ) ) {
					// PayPal rejects checkout requests that carry a third-party Referer header.
					header( 'Referrer-Policy: no-referrer' );
					wp_redirect( $link['href'] );
					exit;
				}
			}

			$order->error      = __( 'Could not find PayPal approval link.', 'pmpro-paypal' );
			$order->shorterror = $order->error;
			return false;
		}
	}

	// ---------------------------------------------------------------
	// Check Token Orders (async checkout completion)
	// ---------------------------------------------------------------

	/**
	 * Check whether the payment for a token order has been completed at PayPal.
	 *
	 * Called by PMPro core when the user returns from PayPal before the
	 * webhook fires. Polls PayPal to check order/subscription status
	 * and completes checkout if ready.
	 *
	 * @param MemberOrder $order The token order to check.
	 * @return true|string True on success, error message string on failure.
	 */
	public function check_token_order( $order ) {
		if ( 'token' !== $order->status ) {
			return __( 'This is not a token order.', 'pmpro-paypal' );
		}

		$api = new PMPro_PayPal_API();

		// Check for one-time payment via PayPal order ID in meta.
		$paypal_order_id = get_pmpro_membership_order_meta( $order->id, 'paypal_order_id', true );

		if ( empty( $paypal_order_id ) && empty( $order->subscription_transaction_id ) ) {
			return __( 'No PayPal order ID or subscription transaction ID found.', 'pmpro-paypal' );
		}

		if ( ! empty( $paypal_order_id ) ) {
			// --- One-time payment ---

			$paypal_order = $api->get_order( $paypal_order_id );
			if ( is_wp_error( $paypal_order ) ) {
				return __( 'Could not get order information.', 'pmpro-paypal' ) . ' ' . $paypal_order->get_error_message();
			}

			// If approved but not captured, capture it.
			if ( 'APPROVED' === ( $paypal_order['status'] ?? '' ) ) {
				$capture_result = $api->capture_order( $paypal_order_id );
				if ( is_wp_error( $capture_result ) ) {
					return __( 'Could not capture payment.', 'pmpro-paypal' ) . ' ' . $capture_result->get_error_message();
				}
				$paypal_order = $capture_result;
			}

			if ( 'COMPLETED' !== ( $paypal_order['status'] ?? '' ) ) {
				return __( 'Order is not yet completed.', 'pmpro-paypal' );
			}

			// Set payment transaction ID from capture.
			if ( ! empty( $paypal_order['purchase_units'][0]['payments']['captures'][0]['id'] ) ) {
				$order->payment_transaction_id = $paypal_order['purchase_units'][0]['payments']['captures'][0]['id'];
			}
		} else {
			// --- Subscription ---

			$result = $api->get_subscription( $order->subscription_transaction_id );
			if ( is_wp_error( $result ) ) {
				return __( 'Could not get subscription information.', 'pmpro-paypal' ) . ' ' . $result->get_error_message();
			}

			if ( 'ACTIVE' !== ( $result['status'] ?? '' ) ) {
				return __( 'Subscription is not yet active.', 'pmpro-paypal' );
			}

			// Try to get the initial payment transaction ID.
			$create_time = $result['create_time'] ?? '';
			if ( ! empty( $create_time ) ) {
				$start_time   = date( 'c', strtotime( $create_time ) - 3600 );
				$end_time     = date( 'c', strtotime( $create_time ) + 3600 );
				$transactions = $api->get_subscription_transactions( $order->subscription_transaction_id, $start_time, $end_time );
				if ( ! is_wp_error( $transactions ) && ! empty( $transactions['transactions'][0]['id'] ) ) {
					$order->payment_transaction_id = $transactions['transactions'][0]['id'];
				}
			}
		}

		// Complete the checkout.
		pmpro_pull_checkout_data_from_order( $order );
		return pmpro_complete_async_checkout( $order );
	}

	// ---------------------------------------------------------------
	// Cancel Subscription
	// ---------------------------------------------------------------

	/**
	 * Cancel a subscription at the PayPal gateway.
	 *
	 * @param PMPro_Subscription $subscription The subscription object.
	 * @return bool True on success.
	 */
	public function cancel_subscription( $subscription ) {
		$api = new PMPro_PayPal_API();
		$sub_id = $subscription->get_subscription_transaction_id();

		if ( empty( $sub_id ) ) {
			return false;
		}

		$result = $api->cancel_subscription( $sub_id, __( 'Cancelled by site admin or member.', 'pmpro-paypal' ) );

		if ( is_wp_error( $result ) ) {
			$error_message = $result->get_error_message();
			// Treat "already cancelled" as success.
			if ( strpos( strtolower( $error_message ), 'already' ) !== false
				|| strpos( strtolower( $error_message ), 'cancel' ) !== false ) {
				return true;
			}
			return false;
		}

		return true;
	}

	// ---------------------------------------------------------------
	// Update Subscription Info (Sync)
	// ---------------------------------------------------------------

	/**
	 * Sync subscription info from PayPal.
	 *
	 * @param PMPro_Subscription $subscription The subscription object.
	 * @return string|null Error message or null on success.
	 */
	public function update_subscription_info( $subscription ) {
		$api    = new PMPro_PayPal_API();
		$sub_id = $subscription->get_subscription_transaction_id();

		if ( empty( $sub_id ) ) {
			return __( 'No subscription transaction ID.', 'pmpro-paypal' );
		}

		$result = $api->get_subscription( $sub_id );
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		$update_array = array();

		// Map status.
		$paypal_status = $result['status'] ?? '';
		if ( in_array( $paypal_status, array( 'ACTIVE', 'APPROVED' ), true ) ) {
			$update_array['status'] = 'active';
		} else {
			$update_array['status'] = 'cancelled';
		}

		// Next payment date.
		if ( ! empty( $result['billing_info']['next_billing_time'] ) ) {
			$update_array['next_payment_date'] = date( 'Y-m-d H:i:s', strtotime( $result['billing_info']['next_billing_time'] ) );
		}

		// Pull cycle info and billing amount from the plan definition rather
		// than billing_info.last_payment which can reflect setup fees.
		if ( ! empty( $result['plan_id'] ) ) {
			$plan = $api->get_plan( $result['plan_id'] );
			if ( ! is_wp_error( $plan ) && ! empty( $plan['billing_cycles'][0] ) ) {
				$cycle = $plan['billing_cycles'][0];
				$update_array['cycle_number'] = $cycle['frequency']['interval_count'] ?? 1;
				$period = $cycle['frequency']['interval_unit'] ?? 'MONTH';
				$update_array['cycle_period'] = ucfirst( strtolower( $period ) );
				if ( ! empty( $cycle['pricing_scheme']['fixed_price']['value'] ) ) {
					$update_array['billing_amount'] = $cycle['pricing_scheme']['fixed_price']['value'];
				}
			}
		}

		if ( ! empty( $update_array ) ) {
			$subscription->set( $update_array );
		}

		return null;
	}

	// ---------------------------------------------------------------
	// Refund
	// ---------------------------------------------------------------

	/**
	 * Process a refund via PayPal.
	 *
	 * @param bool $refunded Whether the refund was already processed.
	 * @param MemberOrder $order The order to refund.
	 * @return bool True on success.
	 */
	/**
	 * Add PayPal to the list of gateways that support refunds.
	 */
	public static function allowed_refund_gateways( $gateways ) {
		$gateways[] = 'paypal';
		return $gateways;
	}

	public static function process_refund( $refunded, $order ) {
		if ( $refunded ) {
			return $refunded;
		}

		$api = new PMPro_PayPal_API();

		// Get the capture ID from order meta (set for one-time payments).
		$capture_id = '';
		if ( method_exists( $order, 'get_order_meta' ) ) {
			$capture_id = $order->get_order_meta( 'paypal_capture_id', true );
		}

		// Get the transaction ID (capture ID for one-time, sale ID for renewals).
		$transaction_id = $order->payment_transaction_id;

		if ( empty( $capture_id ) && empty( $transaction_id ) ) {
			$order->error = __( 'No transaction ID found for this order.', 'pmpro-paypal' );
			return false;
		}

		// If we have a capture ID from order meta, refund the capture.
		// Otherwise, try capture refund first (for one-time orders completed
		// via check_token_order which don't store paypal_capture_id meta),
		// then fall back to sale refund (for subscription renewal payments).
		if ( ! empty( $capture_id ) ) {
			$result = $api->refund_capture( $capture_id );
		} else {
			$result = $api->refund_capture( $transaction_id );
			if ( is_wp_error( $result ) ) {
				// Capture refund failed — try as a sale refund (subscription renewals).
				$result = $api->refund_sale( $transaction_id );
			}
		}

		if ( is_wp_error( $result ) ) {
			$order->error = $result->get_error_message();
			return false;
		}

		$order->status = 'refunded';

		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( sprintf(
				__( 'Refund processed successfully. PayPal refund ID: %s', 'pmpro-paypal' ),
				$result['id'] ?? 'N/A'
			) );
		}

		$order->saveOrder();

		// Send refund emails.
		$user = get_userdata( $order->user_id );
		if ( $user ) {
			$pmproemail = new PMProEmail();
			$pmproemail->sendRefundedEmail( $user, $order );

			$pmproemail = new PMProEmail();
			$pmproemail->sendRefundedAdminEmail( $user, $order );
		}

		return true;
	}
}
