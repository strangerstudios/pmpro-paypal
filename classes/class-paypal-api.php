<?php
defined( 'ABSPATH' ) || exit;

/**
 * PayPal REST API Client.
 *
 * Handles all communication with PayPal's REST APIs:
 * - OAuth2 token management
 * - Orders V2 (one-time payments)
 * - Subscriptions API v1 (recurring)
 * - Catalog Products / Billing Plans
 * - Webhooks
 */
class PMPro_PayPal_API {

	/**
	 * @var string PayPal API base URL.
	 */
	private $base_url;

	/**
	 * @var string Client ID.
	 */
	private $client_id;

	/**
	 * @var string Client secret.
	 */
	private $client_secret;

	/**
	 * Constructor — set credentials and base URL from PMPro settings.
	 */
	public function __construct() {
		$environment = get_option( 'pmpro_gateway_environment', 'sandbox' );
		$suffix      = 'sandbox' === $environment ? '_sandbox' : '_live';

		$this->client_id     = get_option( 'pmpro_paypal_client_id' . $suffix, '' );
		$this->client_secret = get_option( 'pmpro_paypal_client_secret' . $suffix, '' );

		if ( 'sandbox' === $environment ) {
			$this->base_url = 'https://api-m.sandbox.paypal.com';
		} else {
			$this->base_url = 'https://api-m.paypal.com';
		}
	}

	// ---------------------------------------------------------------
	// Authentication
	// ---------------------------------------------------------------

	/**
	 * Get an OAuth2 access token, cached via transient.
	 *
	 * @return string|WP_Error Access token or error.
	 */
	public function get_access_token() {
		$environment   = get_option( 'pmpro_gateway_environment', 'sandbox' );
		$transient_key = 'pmpro_paypal_token_' . $environment;
		$token         = get_transient( $transient_key );

		if ( ! empty( $token ) ) {
			return $token;
		}

		$response = wp_remote_post( $this->base_url . '/v1/oauth2/token', array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => 'grant_type=client_credentials',
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $body['access_token'] ) ) {
			$error_msg = $body['error_description'] ?? $body['message'] ?? 'Failed to authenticate with PayPal.';
			return new WP_Error( 'pmpro_paypal_auth_error', $error_msg );
		}

		$token     = $body['access_token'];
		$expires   = intval( $body['expires_in'] ?? 32400 );
		// Cache for slightly less than expiry (8 hours max).
		$cache_ttl = min( $expires - 300, 28800 );

		set_transient( $transient_key, $token, $cache_ttl );

		return $token;
	}

	// ---------------------------------------------------------------
	// HTTP Transport
	// ---------------------------------------------------------------

	/**
	 * Make an authenticated API request.
	 *
	 * @param string $method HTTP method (GET, POST, DELETE, PATCH).
	 * @param string $endpoint API endpoint path (e.g., /v2/checkout/orders).
	 * @param array  $body Request body (for POST/PATCH).
	 * @param int    $retries Number of retry attempts on 5xx errors.
	 * @return array|WP_Error Decoded response body or error.
	 */
	private function request( $method, $endpoint, $body = array(), $retries = 3 ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->base_url . $endpoint;

		$args = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array(
				'Authorization'               => 'Bearer ' . $token,
				'Content-Type'                => 'application/json',
				'PayPal-Partner-Attribution-Id' => 'PaidMembershipsPro_SP',
				'Prefer'                      => 'return=representation',
			),
		);

		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$attempt = 0;
		$last_error = null;

		while ( $attempt < $retries ) {
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				$attempt++;
				if ( $attempt < $retries ) {
					usleep( pow( 2, $attempt ) * 100000 ); // Exponential backoff.
				}
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

			// Success.
			if ( $code >= 200 && $code < 300 ) {
				return $response_body ?? array();
			}

			// 204 No Content (e.g., successful cancel).
			if ( 204 === $code ) {
				return array( 'status' => 'success' );
			}

			// 5xx — retry.
			if ( $code >= 500 ) {
				$last_error = new WP_Error(
					'pmpro_paypal_api_error',
					sprintf( 'PayPal API returned %d: %s', $code, $response_body['message'] ?? 'Server error' )
				);
				$attempt++;
				if ( $attempt < $retries ) {
					usleep( pow( 2, $attempt ) * 100000 );
				}
				continue;
			}

			// 4xx — client error, don't retry.
			$error_message = $this->extract_error_message( $response_body );
			return new WP_Error( 'pmpro_paypal_api_error', $error_message );
		}

		return $last_error ?? new WP_Error( 'pmpro_paypal_api_error', 'PayPal API request failed after retries.' );
	}

	/**
	 * Extract a human-readable error message from PayPal error response.
	 */
	private function extract_error_message( $body ) {
		if ( ! empty( $body['details'] ) && is_array( $body['details'] ) ) {
			$messages = array();
			foreach ( $body['details'] as $detail ) {
				if ( ! empty( $detail['description'] ) ) {
					$messages[] = $detail['description'];
				} elseif ( ! empty( $detail['issue'] ) ) {
					$messages[] = $detail['issue'];
				}
			}
			if ( ! empty( $messages ) ) {
				return implode( ' ', $messages );
			}
		}

		return $body['message'] ?? $body['error_description'] ?? 'Unknown PayPal API error.';
	}

	// ---------------------------------------------------------------
	// Orders V2 (One-Time Payments)
	// ---------------------------------------------------------------

	/**
	 * Create a PayPal order.
	 */
	public function create_order( $args ) {
		return $this->request( 'POST', '/v2/checkout/orders', $args );
	}

	/**
	 * Capture an approved order.
	 */
	public function capture_order( $order_id ) {
		return $this->request( 'POST', '/v2/checkout/orders/' . urlencode( $order_id ) . '/capture' );
	}

	/**
	 * Get order details.
	 */
	public function get_order( $order_id ) {
		return $this->request( 'GET', '/v2/checkout/orders/' . urlencode( $order_id ) );
	}

	/**
	 * Refund a captured payment.
	 *
	 * @param string     $capture_id Capture ID.
	 * @param float|null $amount Optional partial refund amount.
	 * @return array|WP_Error
	 */
	public function refund_capture( $capture_id, $amount = null ) {
		$body = array();
		if ( $amount !== null ) {
			global $pmpro_currency;
			$currency = ! empty( $pmpro_currency ) ? $pmpro_currency : 'USD';
			$body['amount'] = array(
				'value'         => pmpro_round_price_as_string( (float) $amount ),
				'currency_code' => $currency,
			);
		}
		return $this->request( 'POST', '/v2/payments/captures/' . urlencode( $capture_id ) . '/refund', $body );
	}

	/**
	 * Refund a sale (subscription renewal payment).
	 *
	 * Subscription renewals use the Payments v1 Sale resource,
	 * not the v2 Capture resource.
	 *
	 * @param string     $sale_id Sale ID.
	 * @param float|null $amount  Optional partial refund amount.
	 * @return array|WP_Error
	 */
	public function refund_sale( $sale_id, $amount = null ) {
		$body = array();
		if ( $amount !== null ) {
			global $pmpro_currency;
			$currency = ! empty( $pmpro_currency ) ? $pmpro_currency : 'USD';
			$body['amount'] = array(
				'total'    => pmpro_round_price_as_string( (float) $amount ),
				'currency' => $currency,
			);
		}
		return $this->request( 'POST', '/v1/payments/sale/' . urlencode( $sale_id ) . '/refund', $body );
	}

	// ---------------------------------------------------------------
	// Catalog Products
	// ---------------------------------------------------------------

	/**
	 * Create a catalog product.
	 */
	public function create_product( $args ) {
		return $this->request( 'POST', '/v1/catalogs/products', $args );
	}

	/**
	 * Get a product.
	 */
	public function get_product( $product_id ) {
		return $this->request( 'GET', '/v1/catalogs/products/' . urlencode( $product_id ) );
	}

	// ---------------------------------------------------------------
	// Billing Plans
	// ---------------------------------------------------------------

	/**
	 * Create a billing plan.
	 */
	public function create_plan( $args ) {
		return $this->request( 'POST', '/v1/billing/plans', $args );
	}

	/**
	 * Get a billing plan.
	 */
	public function get_plan( $plan_id ) {
		return $this->request( 'GET', '/v1/billing/plans/' . urlencode( $plan_id ) );
	}

	/**
	 * Deactivate a billing plan.
	 */
	public function deactivate_plan( $plan_id ) {
		return $this->request( 'POST', '/v1/billing/plans/' . urlencode( $plan_id ) . '/deactivate' );
	}

	// ---------------------------------------------------------------
	// Subscriptions
	// ---------------------------------------------------------------

	/**
	 * Create a subscription.
	 */
	public function create_subscription( $args ) {
		return $this->request( 'POST', '/v1/billing/subscriptions', $args );
	}

	/**
	 * Get subscription details.
	 */
	public function get_subscription( $subscription_id ) {
		return $this->request( 'GET', '/v1/billing/subscriptions/' . urlencode( $subscription_id ) );
	}

	/**
	 * Get subscription transactions within a time range.
	 *
	 * @param string $subscription_id Subscription ID.
	 * @param string $start_time      ISO 8601 start time.
	 * @param string $end_time        ISO 8601 end time.
	 * @return array|WP_Error
	 */
	public function get_subscription_transactions( $subscription_id, $start_time, $end_time ) {
		$query = http_build_query( array(
			'start_time' => $start_time,
			'end_time'   => $end_time,
		) );
		return $this->request( 'GET', '/v1/billing/subscriptions/' . urlencode( $subscription_id ) . '/transactions?' . $query );
	}

	/**
	 * Cancel a subscription.
	 *
	 * @param string $subscription_id Subscription ID.
	 * @param string $reason Cancellation reason.
	 * @return array|WP_Error
	 */
	public function cancel_subscription( $subscription_id, $reason = '' ) {
		$body = array( 'reason' => $reason );
		return $this->request( 'POST', '/v1/billing/subscriptions/' . urlencode( $subscription_id ) . '/cancel', $body );
	}

	// ---------------------------------------------------------------
	// Webhooks
	// ---------------------------------------------------------------

	/**
	 * Register a webhook.
	 *
	 * @param string $url Webhook URL.
	 * @param array  $events Array of event type names.
	 * @return array|WP_Error
	 */
	public function create_webhook( $url, $events ) {
		$event_types = array();
		foreach ( $events as $event ) {
			$event_types[] = array( 'name' => $event );
		}

		return $this->request( 'POST', '/v1/notifications/webhooks', array(
			'url'         => $url,
			'event_types' => $event_types,
		) );
	}

	/**
	 * Delete a webhook.
	 */
	public function delete_webhook( $webhook_id ) {
		return $this->request( 'DELETE', '/v1/notifications/webhooks/' . urlencode( $webhook_id ) );
	}

	/**
	 * Verify a webhook signature.
	 *
	 * The webhook_event value must be the original raw JSON body from PayPal,
	 * not a re-encoded PHP array. Re-encoding can alter key order, escaping,
	 * or float precision, which invalidates the signature PayPal computed
	 * against the original bytes.
	 *
	 * @param array  $args     Verification parameters (without webhook_event).
	 * @param string $raw_body The raw JSON webhook body from the incoming request.
	 * @return array|WP_Error
	 */
	public function verify_webhook_signature( $args, $raw_body ) {
		// Build the JSON payload manually so webhook_event uses the original
		// raw JSON instead of a round-tripped PHP array.
		$envelope = wp_json_encode( $args );

		// Insert the raw webhook body as the webhook_event value before the closing brace.
		$body = substr( $envelope, 0, -1 ) . ',"webhook_event":' . $raw_body . '}';

		return $this->raw_request( 'POST', '/v1/notifications/verify-webhook-signature', $body );
	}

	/**
	 * Make an authenticated API request with a pre-built JSON body string.
	 *
	 * Used when the body must preserve exact JSON formatting (e.g., webhook verification).
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint API endpoint path.
	 * @param string $body     Pre-encoded JSON body string.
	 * @return array|WP_Error Decoded response body or error.
	 */
	private function raw_request( $method, $endpoint, $body ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_request( $this->base_url . $endpoint, array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => $body,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return $response_body ?? array();
		}

		$error_message = $this->extract_error_message( $response_body );
		return new WP_Error( 'pmpro_paypal_api_error', $error_message );
	}
}
