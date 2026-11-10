<?php
/**
 * EDD gateway registration and hosted checkout adapter.
 *
 * @package Finlstpa\EDD
 */

namespace Finlstpa\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Easy Digital Downloads gateway.
 */
final class Gateway {

	const ID = 'finlstpa_usdc';

	const SESSION_META = '_finlstpa_session';

	const LAST_META = '_finlstpa_last_status';

	const TX_META = '_finlstpa_transaction_hash';

	const STORE_META = '_finlstpa_store_order_id';

	const NOTE_PREFIX = '_finlstpa_note_';

	/**
	 * Register gateway hooks. The dynamic hook names come from EDD and this gateway id.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'edd_payment_gateways', array( __CLASS__, 'register' ) );
		add_filter( 'edd_enabled_payment_gateways', array( __CLASS__, 'available' ) );
		add_action( 'edd_' . self::ID . '_cc_form', array( __CLASS__, 'description' ) );
		add_action( 'edd_gateway_' . self::ID, array( __CLASS__, 'process' ) );
	}

	/**
	 * Register the gateway with Easy Digital Downloads.
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public static function register( $gateways ) {
		if ( ! is_array( $gateways ) ) {
			$gateways = array();
		}
		$gateways[ self::ID ] = array(
			'admin_label'    => __( 'FinLinks USDC', 'finlinks-stablecoin-payments-for-edd' ),
			'checkout_label' => __( 'Pay with USDC', 'finlinks-stablecoin-payments-for-edd' ),
			'supports'       => array(),
		);
		return $gateways;
	}

	/**
	 * Hide the gateway when the cart cannot be paid with USDC.
	 *
	 * @param array $gateways Enabled gateways.
	 * @return array
	 */
	public static function available( $gateways ) {
		if ( ! is_array( $gateways ) ) {
			return array();
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}
		if ( ! Settings::checkout_ready() || ! Amount::is_eligible( edd_get_currency(), Amount::format_usd( edd_get_cart_total() ) ) ) {
			unset( $gateways[ self::ID ] );
		}
		return $gateways;
	}

	/**
	 * Checkout description.
	 *
	 * @return void
	 */
	public static function description() {
		echo '<p>' . esc_html__( 'You will be redirected to the official FinLinks hosted page to pay with USDC. Your download becomes available only after payment confirmation.', 'finlinks-stablecoin-payments-for-edd' ) . '</p>';
	}

	/**
	 * Store order id sent to the payment service. This identifier is part of the API contract.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public static function store_id( $order_id ) {
		return 'edd-' . absint( $order_id );
	}

	/**
	 * Receipt URL for an order.
	 *
	 * @param object $order Order.
	 * @return string
	 */
	public static function receipt_url( $order ) {
		$base = function_exists( 'edd_get_confirmation_page_uri' ) ? edd_get_confirmation_page_uri() : edd_get_success_page_uri();
		$args = array( 'payment_key' => $order->payment_key );
		if ( method_exists( $order, 'get_receipt_hash' ) ) {
			$args['order'] = $order->get_receipt_hash();
			$args['id']    = $order->id;
		}
		return add_query_arg( $args, $base );
	}

	/**
	 * EDD checkout callback. Guests are allowed. The nonce is EDD's checkout nonce.
	 *
	 * @param array $purchase_data Purchase data prepared by Easy Digital Downloads.
	 * @return void
	 */
	public static function process( $purchase_data ) {
		$nonce = is_array( $purchase_data ) && isset( $purchase_data['gateway_nonce'] ) && is_string( $purchase_data['gateway_nonce'] ) ? wp_unslash( $purchase_data['gateway_nonce'] ) : '';
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'edd-gateway' ) ) {
			wp_die( esc_html__( 'Checkout verification failed. Reload the checkout page.', 'finlinks-stablecoin-payments-for-edd' ), '', array( 'response' => 403 ) );
		}
		$result = self::prepare( is_array( $purchase_data ) ? $purchase_data : array() );
		if ( is_wp_error( $result ) ) {
			edd_set_error( 'finlstpa_checkout', esc_html( $result->get_error_message() ) );
			edd_send_back_to_checkout( '?payment-mode=' . self::ID );
			return;
		}
		$host = strtolower( (string) wp_parse_url( $result, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( Hosted_Url::is_allowed( $result, Settings::test_mode() ) ) {
			if ( ! preg_match( '/^[a-z0-9.-]+$/', $host ) || Host_Policy::buyer_redirect_blocked( $host ) ) {
				Checkout_Diag::record( 'redirect_host', Host_Policy::resolution_state( $host ) );
				edd_set_error( 'finlstpa_checkout', esc_html__( 'USDC checkout could not be started. Please try again or choose another payment method. No payment confirmation has been recorded.', 'finlinks-stablecoin-payments-for-edd' ) );
				edd_send_back_to_checkout( '?payment-mode=' . self::ID );
				return;
			}
			add_filter(
				'allowed_redirect_hosts',
				static function ( $hosts ) use ( $host ) {
					if ( ! is_array( $hosts ) ) {
						$hosts = array();
					}
					$hosts[] = $host;
					return $hosts;
				}
			);
		} elseif ( '' === $host || $host !== $home ) {
			Checkout_Diag::record( 'redirect_host', 'not_store' );
			edd_set_error( 'finlstpa_checkout', esc_html__( 'USDC checkout could not be started. Please try again or choose another payment method. No payment confirmation has been recorded.', 'finlinks-stablecoin-payments-for-edd' ) );
			edd_send_back_to_checkout( '?payment-mode=' . self::ID );
			return;
		}
		wp_safe_redirect( $result, 303, 'finlstpa' );
		exit;
	}

	/**
	 * Create or reuse an order and return the hosted URL or receipt URL.
	 *
	 * @param array $purchase_data EDD checkout data, not a raw request body.
	 * @return string|\WP_Error
	 */
	public static function prepare( array $purchase_data ) {
		$amount = isset( $purchase_data['price'] ) ? Amount::format_usd( $purchase_data['price'] ) : null;
		$key    = isset( $purchase_data['purchase_key'] ) && is_string( $purchase_data['purchase_key'] ) ? $purchase_data['purchase_key'] : '';
		if ( ! Settings::checkout_ready() ) {
			return self::error( 'not_ready', self::ready_detail() );
		}
		if ( ! Amount::is_eligible( edd_get_currency(), $amount ) ) {
			$currency = strtoupper( (string) edd_get_currency() );
			return self::error( 'amount', 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'currency' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $key ) ) {
			return self::error( 'payment_key', strlen( $key ) < 16 ? 'short' : 'characters' );
		}
		$intent = Lock::acquire( 'checkout:' . $key );
		if ( ! $intent ) {
			return new \WP_Error( 'finlstpa_busy', __( 'This checkout is already being processed. Please wait before trying again.', 'finlinks-stablecoin-payments-for-edd' ) );
		}
		$owned = false;
		try {
			$order = edd_get_order_by( 'payment_key', $key );
			if ( ! $order ) {
				$data             = array_intersect_key( $purchase_data, array_flip( array( 'price', 'date', 'user_email', 'purchase_key', 'downloads', 'user_info', 'cart_details', 'fees', 'subtotal', 'discount', 'tax' ) ) );
				$data['status']   = 'pending';
				$data['gateway']  = self::ID;
				$data['currency'] = edd_get_currency();
				$id               = edd_insert_payment( $data );
				if ( ! $id ) {
					return self::error( 'order_insert', 'failed' );
				}
				$order = edd_get_order( $id );
				if ( class_exists( '\EDD\Discounts\OrderValidation' ) && is_callable( array( '\EDD\Discounts\OrderValidation', 'refuse_unclaimed' ) ) && \EDD\Discounts\OrderValidation::refuse_unclaimed( $id ) ) {
					return self::error( 'discount', 'unclaimed' );
				}
			}
			if ( is_object( $order ) && isset( $order->gateway, $order->payment_key ) && 'finlinks_usdc' === $order->gateway && hash_equals( (string) $order->payment_key, $key ) && function_exists( 'edd_update_order' ) ) {
				edd_update_order( (int) $order->id, array( 'gateway' => self::ID ) );
				$order = edd_get_order( $order->id );
			}
			$identity = self::identity_problem( $order, $key, $amount );
			if ( '' !== $identity ) {
				return self::error( 'order_identity', $identity );
			}
			if ( ! self::resume_allowed( $order ) ) {
				return self::error( 'resume_denied', 'owner' );
			}
			if ( 'complete' === $order->status ) {
				return self::receipt_url( $order );
			}
			if ( ! in_array( $order->status, array( 'pending', 'failed', 'abandoned' ), true ) ) {
				return self::error( 'order_status', Checkout_Diag::detail( (string) $order->status ) );
			}
			$owned = Lock::acquire( 'order:' . $order->id );
			if ( ! $owned ) {
				return self::error( 'order_lock', 'busy' );
			}
			if ( function_exists( 'EDD' ) ) {
				$edd = EDD();
				if ( is_object( $edd ) && isset( $edd->session ) && is_object( $edd->session ) ) {
					$edd->session->set( 'edd_resume_payment', $order->id );
				}
			}
			$return     = self::receipt_url( $order );
			$cancel     = edd_get_checkout_uri();
			$callback   = Settings::webhook_url();
			$store_urls = array(
				'return'   => $return,
				'cancel'   => $cancel,
				'callback' => $callback,
			);
			foreach ( $store_urls as $slot => $url ) {
				$problem = Settings::url_problem( $url, 'callback' !== $slot );
				if ( '' !== $problem ) {
					return self::error( 'store_url', $slot . '_' . $problem );
				}
			}
			$body     = array(
				'storeOrderId' => self::store_id( $order->id ),
				'orderLabel'   => 'EDD Order #' . absint( $order->id ),
				'amount'       => $amount,
				'currency'     => 'USD',
				'returnUrl'    => $return,
				'cancelUrl'    => $cancel,
				'callbackUrl'  => $callback,
			);
			$api      = Settings::api();
			$response = $api->create_session( $body );
			if ( ! $response['ok'] && 409 === $response['status'] && 'COMMERCE_SESSION_AMOUNT_CHANGED' === $response['code'] ) {
				$old = (string) edd_get_order_meta( $order->id, self::SESSION_META, true );
				if ( preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $old ) ) {
					$cancelled = $api->cancel_session( $old );
					if ( $cancelled['ok'] ) {
						edd_update_order_meta( $order->id, self::SESSION_META, '' );
						$response = $api->create_session( $body );
					}
				}
			}
			if ( ! $response['ok'] ) {
				if ( in_array( $response['code'], array( 'COMMERCE_API_KEY_INVALID', 'COMMERCE_API_KEY_ENVIRONMENT_MISMATCH', 'COMMERCE_CONNECTION_NOT_ACTIVE', 'COMMERCE_WEBHOOK_NOT_CONFIGURED', 'COMMERCE_CONNECTION_NOT_FOUND' ), true ) ) {
					update_option(
						Settings::PAUSE_OPTION,
						array(
							'until' => time() + 300,
							'code'  => sanitize_key( $response['code'] ),
						),
						false
					);
				}
				Logger::log( 'Create session failed: ' . (int) $response['status'] . ' ' . sanitize_key( (string) $response['code'] ), 'error' );
				return self::error( 'api', self::api_detail( $response ), (int) $response['status'] );
			}
			$session  = $response['data'];
			$mismatch = self::session_problem( $session, $order );
			if ( '' !== $mismatch ) {
				return self::error( 'session', $mismatch, (int) $response['status'] );
			}
			$hosted = esc_url_raw( $session['hostedUrl'] );
			if ( $hosted !== $session['hostedUrl'] ) {
				return self::error( 'hosted_url', 'normalized', (int) $response['status'] );
			}
			$rejection = Hosted_Url::rejection( $hosted, Settings::test_mode() );
			if ( null !== $rejection ) {
				return self::error( 'hosted_url', $rejection['reason'], (int) $response['status'] );
			}
			$hosted_host = wp_parse_url( $hosted, PHP_URL_HOST );
			if ( ! is_string( $hosted_host ) || Host_Policy::buyer_redirect_blocked( $hosted_host ) ) {
				return self::error( 'hosted_dns', Host_Policy::resolution_state( is_string( $hosted_host ) ? $hosted_host : '' ), (int) $response['status'] );
			}
			$previous = (string) edd_get_order_meta( $order->id, self::SESSION_META, true );
			$saved    = edd_update_order_meta( $order->id, self::SESSION_META, sanitize_text_field( $session['id'] ) );
			if ( false === $saved && edd_get_order_meta( $order->id, self::SESSION_META, true ) !== $session['id'] ) {
				return self::error( 'session_save', 'failed' );
			}
			if ( $previous !== $session['id'] ) {
				edd_delete_order_meta( $order->id, self::LAST_META );
			}
			edd_update_order_meta( $order->id, self::STORE_META, self::store_id( $order->id ) );
			if ( 'pending' !== $order->status ) {
				edd_update_order_status( $order->id, 'pending' );
			}
			return $hosted;
		} catch ( \Throwable $error ) {
			Logger::log( 'Checkout deferred due to an internal error.', 'error' );
			return self::error( 'exception', Checkout_Diag::detail( get_class( $error ) ) );
		} finally {
			if ( $owned ) {
				Lock::release( $owned );
			}
			Lock::release( $intent );
		}
	}

	/**
	 * A customer-owned order can be resumed only by that customer.
	 *
	 * The lookup key is the EDD payment key, not an order id from the request.
	 * A guest order (no customer id) may continue when that payment key matches.
	 *
	 * @param object $order Order.
	 * @return bool
	 */
	public static function resume_allowed( $order ) {
		$owner = isset( $order->user_id ) ? (int) $order->user_id : 0;
		if ( 1 > $owner ) {
			return true;
		}
		return get_current_user_id() === $owner;
	}

	/**
	 * Whether the API session belongs to this order.
	 *
	 * @param array  $session Session.
	 * @param object $order   Order.
	 * @return bool
	 */
	public static function session_matches( array $session, $order ) {
		return isset( $session['id'], $session['storeOrderId'], $session['livemode'], $session['amount'], $session['currency'] )
			&& is_string( $session['id'] ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $session['id'] )
			&& self::store_id( $order->id ) === (string) $session['storeOrderId']
			&& is_bool( $session['livemode'] ) && ( 'live' === $order->mode ) === $session['livemode']
			&& Amount::equals( $session['amount'], $order->total ) && strtoupper( (string) $order->currency ) === strtoupper( (string) $session['currency'] );
	}

	/**
	 * Why this order cannot continue, or '' when it can.
	 *
	 * @param mixed  $order  Order.
	 * @param string $key    Payment key.
	 * @param string $amount USD amount.
	 * @return string
	 */
	private static function identity_problem( $order, $key, $amount ) {
		if ( ! is_object( $order ) ) {
			return 'missing_order';
		}
		if ( self::ID !== $order->gateway ) {
			return 'gateway';
		}
		if ( ! hash_equals( (string) $order->payment_key, $key ) ) {
			return 'payment_key';
		}
		if ( ! Amount::equals( $order->total, $amount ) ) {
			return 'amount';
		}
		if ( strtoupper( (string) edd_get_currency() ) !== strtoupper( (string) $order->currency ) ) {
			return 'currency';
		}
		if ( ( Settings::test_mode() ? 'test' : 'live' ) !== $order->mode ) {
			return 'mode';
		}
		return '';
	}

	/**
	 * Why the create-session payload does not belong to this order, or ''.
	 *
	 * @param mixed  $session Session data.
	 * @param object $order   Order.
	 * @return string
	 */
	private static function session_problem( $session, $order ) {
		if ( ! is_array( $session ) ) {
			return 'not_object';
		}
		if ( empty( $session['hostedUrl'] ) || ! is_string( $session['hostedUrl'] ) ) {
			return 'hosted_missing';
		}
		if ( ! self::session_matches( $session, $order ) ) {
			if ( ! isset( $session['id'] ) || ! is_string( $session['id'] ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $session['id'] ) ) {
				return 'id';
			}
			if ( ! isset( $session['storeOrderId'] ) || self::store_id( $order->id ) !== (string) $session['storeOrderId'] ) {
				return 'store_order';
			}
			if ( ! isset( $session['livemode'] ) || ! is_bool( $session['livemode'] ) ) {
				return 'livemode_type';
			}
			if ( ( 'live' === $order->mode ) !== $session['livemode'] ) {
				return 'livemode';
			}
			if ( ! isset( $session['amount'] ) || ! Amount::equals( $session['amount'], $order->total ) ) {
				return 'amount';
			}
			return 'currency';
		}
		return '';
	}

	/**
	 * Safe API failure token. Transport text is not stored.
	 *
	 * @param array $response API client result.
	 * @return string
	 */
	private static function api_detail( array $response ) {
		$code = isset( $response['code'] ) ? (string) $response['code'] : '';
		if ( 'NETWORK_ERROR' === $code ) {
			$message = isset( $response['message'] ) ? (string) $response['message'] : '';
			if ( false !== stripos( $message, 'timed out' ) || false !== strpos( $message, 'error 28' ) ) {
				return 'timeout';
			}
			return 'network';
		}
		return Checkout_Diag::detail( $code );
	}

	/**
	 * Why checkout_ready() is false, without credential bytes.
	 *
	 * @return string
	 */
	private static function ready_detail() {
		if ( 'yes' !== Settings::get( 'enabled' ) ) {
			return 'disabled';
		}
		if ( '1' !== (string) get_option( Bootstrap::DB_OPTION ) ) {
			return 'database';
		}
		$key = Settings::get( 'api_key' );
		if ( ! is_string( $key ) || '' === $key ) {
			return 'key_missing';
		}
		if ( Settings::test_mode() ) {
			if ( 0 !== strpos( $key, 'flk_test_' ) ) {
				return 'key_not_test';
			}
		} elseif ( ! Credentials::is_live_key( $key ) ) {
			return 0 === strpos( $key, 'flk_test_' ) ? 'key_is_test' : 'key_shape';
		}
		$secret = Settings::get( 'webhook_secret' );
		if ( ! is_string( $secret ) || '' === $secret ) {
			return 'secret_missing';
		}
		if ( ! Credentials::is_secret( $secret ) ) {
			return 'secret_shape';
		}
		return 'paused';
	}

	/**
	 * Buyer-facing checkout failure. The stage is stored for shop managers only.
	 *
	 * @param string $stage  Stage token.
	 * @param string $detail Safe detail token.
	 * @param int    $http   HTTP status, or 0.
	 * @return \WP_Error
	 */
	private static function error( $stage, $detail = '', $http = 0 ) {
		Checkout_Diag::record( $stage, $detail, $http );
		return new \WP_Error( 'finlstpa_unavailable', __( 'USDC checkout could not be started. Please try again or choose another payment method. No payment confirmation has been recorded.', 'finlinks-stablecoin-payments-for-edd' ) );
	}
}
