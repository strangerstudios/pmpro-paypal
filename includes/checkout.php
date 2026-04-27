<?php
/**
 * Secondary-gateway checkout UI.
 *
 * When the primary gateway is something other than PayPal and PayPal
 * credentials are configured, these hooks add a "Choose Your Payment
 * Method" picker to the checkout page with a PayPal option.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pmpro_valid_gateways', 'pmpro_paypal_valid_gateways' );
add_action( 'wp_enqueue_scripts', 'pmpro_paypal_enqueue_styles' );
add_action( 'pmpro_checkout_boxes', 'pmpro_paypal_checkout_boxes', 20 );
add_action( 'pmpro_applydiscountcode_return_js', 'pmpro_paypal_applydiscountcode_return_js', 10, 4 );
add_action( 'init', 'pmpro_paypal_init_pbc_integration' );

/**
 * Whether PayPal should appear as a secondary option at checkout.
 *
 * True when the primary gateway is something else and credentials are
 * configured for the active environment.
 *
 * @return bool
 */
function pmpro_paypal_is_secondary() {
	if ( 'paypal' === get_option( 'pmpro_gateway' ) ) {
		return false;
	}
	return pmpro_paypal_has_credentials();
}

/**
 * Add PayPal to the list of valid gateways when it's available as a secondary option.
 *
 * @param array $gateways Valid gateway slugs.
 * @return array
 */
function pmpro_paypal_valid_gateways( $gateways ) {
	if ( pmpro_paypal_is_secondary() && ! in_array( 'paypal', $gateways, true ) ) {
		$gateways[] = 'paypal';
	}
	return $gateways;
}

/**
 * Enqueue styles for the secondary checkout UI.
 */
function pmpro_paypal_enqueue_styles() {
	if ( ! pmpro_paypal_is_secondary() ) {
		return;
	}
	wp_enqueue_style( 'pmpro-paypal', PMPRO_PAYPAL_URL . 'css/pmpro-paypal.css', array(), PMPRO_PAYPAL_VERSION );
}

/**
 * Render the payment method picker, a hidden PayPal submit button, and the
 * JS that swaps visibility as the user chooses a gateway.
 */
function pmpro_paypal_checkout_boxes() {
	if ( ! pmpro_paypal_is_secondary() ) {
		return;
	}

	global $pmpro_requirebilling, $gateway, $pmpro_review, $pmpro_level;

	$setting_gateway = get_option( 'pmpro_gateway' );

	// Review flow — the user has been sent offsite and returned. Keep the
	// fields hidden so nothing shifts around under them.
	if ( ! empty( $pmpro_review ) ) {
		?>
		<script>
			jQuery(document).ready(function() {
				jQuery('#pmpro_billing_address_fields').hide();
				jQuery('#pmpro_payment_information_fields').hide();
			});
		</script>
		<?php
		return;
	}
	?>
	<fieldset id="pmpro_payment_method" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fieldset', 'pmpro_payment_method' ) ); ?>"<?php if ( ! $pmpro_requirebilling ) { ?> style="display: none;"<?php } ?>>
		<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card' ) ); ?>">
			<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card_content' ) ); ?>">
				<legend class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_legend' ) ); ?>">
					<h2 class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_heading pmpro_font-large' ) ); ?>">
						<?php esc_html_e( 'Choose Your Payment Method', 'pmpro-paypal' ); ?>
					</h2>
				</legend>
				<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fields' ) ); ?>">
					<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_field pmpro_form_field-radio' ) ); ?>">
						<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_field-radio-items' ) ); ?>">
							<?php if ( 'check' !== $setting_gateway ) { ?>
								<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_field pmpro_form_field-radio-item' ) ); ?> gateway_<?php echo esc_attr( $setting_gateway ); ?>">
									<input type="radio" id="gateway_<?php echo esc_attr( $setting_gateway ); ?>" name="gateway" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_input pmpro_form_input-radio' ) ); ?>" value="<?php echo esc_attr( $setting_gateway ); ?>" <?php checked( empty( $gateway ) || $gateway === $setting_gateway ); ?> />
									<label for="gateway_<?php echo esc_attr( $setting_gateway ); ?>" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_label pmpro_form_label-inline pmpro_clickable' ) ); ?>">
										<?php esc_html_e( 'Check Out with a Credit Card', 'pmpro-paypal' ); ?>
									</label>
								</div>
							<?php } ?>

							<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_field pmpro_form_field-radio-item' ) ); ?> gateway_paypal">
								<input type="radio" id="gateway_paypal" name="gateway" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_input pmpro_form_input-radio' ) ); ?>" value="paypal" <?php checked( 'paypal' === $gateway ); ?> />
								<label for="gateway_paypal" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_label pmpro_form_label-inline pmpro_clickable' ) ); ?>">
									<?php esc_html_e( 'Check Out with PayPal', 'pmpro-paypal' ); ?>
								</label>
							</div>

							<?php
							// Integrate with the PMPro Pay by Check Add On.
							if ( function_exists( 'pmpropbc_checkout_boxes' ) && ! empty( $pmpro_level ) ) {
								$options             = pmpropbc_getOptions( $pmpro_level->id );
								$check_gateway_label = get_option( 'pmpro_check_gateway_label' ) ?: __( 'Check', 'pmpro-paypal' );

								// Only show if the main gateway is not check and setting value == 1 (value == 2 means only do check payments).
								if ( 'check' !== $setting_gateway && isset( $options['setting'] ) && 1 == $options['setting'] ) {
									?>
									<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_field pmpro_form_field-radio-item' ) ); ?> gateway_check">
										<input type="radio" id="gateway_check" name="gateway" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_input pmpro_form_input-radio' ) ); ?>" value="check" <?php checked( 'check' === $gateway ); ?> />
										<label for="gateway_check" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_label pmpro_form_label-inline pmpro_clickable' ) ); ?>">
											<?php echo esc_html( sprintf( __( 'Pay by %s', 'pmpro-paypal' ), $check_gateway_label ) ); ?>
										</label>
									</div>
									<?php
								}
							}
							?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</fieldset>
	<?php
	// Hidden PayPal submit button. JS moves it into the submit area.
	?>
	<span id="pmpro_paypal_checkout" style="display: none;">
		<input type="hidden" name="submit-checkout" value="1" />
		<button type="submit" id="pmpro_btn-submit-paypal" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_btn pmpro_btn-submit-checkout pmpro_btn-submit-checkout-paypal' ) ); ?>">
			<?php
			printf(
				/* translators: %s is the PayPal logo */
				esc_html__( 'Check Out With %s', 'pmpro-paypal' ),
				'<span class="pmpro_btn-submit-checkout-paypal-image"></span>'
			);
			?>
			<span class="screen-reader-text"><?php esc_html_e( 'PayPal', 'pmpro-paypal' ); ?></span>
		</button>
	</span>
	<script>
		var pmpro_require_billing = <?php echo $pmpro_requirebilling ? 'true' : 'false'; ?>;

		function showPayPalCheckout() {
			jQuery('#pmpro_billing_address_fields').hide();
			jQuery('#pmpro_payment_information_fields').hide();
			jQuery('#pmpro_submit_span').hide();
			jQuery('#pmpro_paypal_checkout').show();
			pmpro_require_billing = false;
		}

		function showCreditCardCheckout() {
			jQuery('#pmpro_paypal_checkout').hide();
			jQuery('#pmpro_billing_address_fields').show();
			jQuery('#pmpro_payment_information_fields').show();
			jQuery('#pmpro_submit_span').show();
			pmpro_require_billing = true;
		}

		function showFreeCheckout() {
			jQuery('#pmpro_billing_address_fields').hide();
			jQuery('#pmpro_payment_information_fields').hide();
			jQuery('#pmpro_submit_span').show();
			jQuery('#pmpro_paypal_checkout').hide();
			pmpro_require_billing = false;
		}

		function showCheckCheckout() {
			jQuery('#pmpro_billing_address_fields').show();
			jQuery('#pmpro_payment_information_fields').hide();
			jQuery('#pmpro_submit_span').show();
			jQuery('#pmpro_paypal_checkout').hide();
			pmpro_require_billing = false;
		}

		jQuery(document).ready(function() {
			// Move the PayPal submit button into the form submit area.
			var pmpro_form_submit = jQuery('div.pmpro_form_submit');
			if (pmpro_form_submit.length) {
				jQuery('#pmpro_paypal_checkout').prependTo(pmpro_form_submit);
			} else {
				jQuery('#pmpro_paypal_checkout').appendTo('div.pmpro_submit');
				jQuery('#pmpro_btn-submit-paypal span.screen-reader-text').removeClass('screen-reader-text');
			}

			jQuery('input[name=gateway]').on('click', function() {
				var chosen = jQuery(this).val();
				if (chosen === 'paypal') {
					showPayPalCheckout();
				} else if (chosen === 'check') {
					showCheckCheckout();
				} else {
					showCreditCardCheckout();
				}
			});

			var checked_gateway = jQuery('input[name=gateway]:checked').val();
			if (checked_gateway === 'check') {
				showCheckCheckout();
			} else if (checked_gateway !== 'paypal' && pmpro_require_billing === true) {
				showCreditCardCheckout();
			} else if (pmpro_require_billing === true) {
				showPayPalCheckout();
			} else {
				showFreeCheckout();
			}
		});
	</script>
	<?php
}

/**
 * Hide/show billing fields and the PayPal button when a discount code is applied.
 */
function pmpro_paypal_applydiscountcode_return_js( $discount_code, $discount_code_id, $level_id, $code_level ) {
	if ( ! pmpro_paypal_is_secondary() ) {
		return;
	}

	if ( pmpro_isLevelFree( $code_level ) ) {
		?>
		jQuery('#pmpro_payment_method').hide();
		jQuery('#pmpro_billing_address_fields').hide();
		jQuery('#pmpro_payment_information_fields').hide();
		jQuery('#pmpro_submit_span').show();
		jQuery('#pmpro_paypal_checkout').hide();
		pmpro_require_billing = false;
		<?php
	} else {
		?>
		jQuery('#pmpro_payment_method').show();
		if (jQuery('input[name=gateway]:checked').val() !== 'paypal' && pmpro_require_billing === true) {
			jQuery('#pmpro_paypal_checkout').hide();
			jQuery('#pmpro_billing_address_fields').show();
			jQuery('#pmpro_payment_information_fields').show();
			jQuery('#pmpro_submit_span').show();
			pmpro_require_billing = true;
		} else if (pmpro_require_billing === true) {
			jQuery('#pmpro_billing_address_fields').hide();
			jQuery('#pmpro_payment_information_fields').hide();
			jQuery('#pmpro_submit_span').hide();
			jQuery('#pmpro_paypal_checkout').show();
			pmpro_require_billing = false;
		} else {
			jQuery('#pmpro_billing_address_fields').hide();
			jQuery('#pmpro_payment_information_fields').hide();
			jQuery('#pmpro_submit_span').show();
			jQuery('#pmpro_paypal_checkout').hide();
			pmpro_require_billing = false;
		}
		<?php
	}
}

/**
 * Unhook Pay by Check's own checkout box — our picker already includes its option.
 */
function pmpro_paypal_init_pbc_integration() {
	if ( ! pmpro_paypal_is_secondary() ) {
		return;
	}
	remove_action( 'pmpro_checkout_boxes', 'pmpropbc_checkout_boxes', 20 );
}
