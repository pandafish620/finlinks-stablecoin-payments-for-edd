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
			if ( ! preg_match( '/^[a-z0-9.-]+$/', $host ) || Host_Policy::is_blocked( $host ) ) {
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
		if ( ! Settings::checkout_ready() || ! Amount::is_eligible( edd_get_currency(), $amount ) ) {
			return self::error();
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $key ) ) {
			return self::error();
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
					return self::error();
				}
				$order = edd_get_order( $id );
				if ( class_exists( '\EDD\Discounts\OrderValidation' ) && is_callable( array( '\EDD\Discounts\OrderValidation', 'refuse_unclaimed' ) ) && \EDD\Discounts\OrderValidation::refuse_unclaimed( $id ) ) {
					return self::error();
				}
			}
			if ( ! is_object( $order ) || self::ID !== $order->gateway || ! hash_equals( (string) $order->payment_key, $key ) || ! Amount::equals( $order->total, $amount ) || strtoupper( (string) edd_get_currency() ) !== strtoupper( (string) $order->currency ) || ( Settings::test_mode() ? 'test' : 'live' ) !== $order->mode ) {
				return self::error();
			}
			$buyer = get_current_user_id();
			if ( $buyer > 0 && isset( $order->user_id ) && (int) $order->user_id > 0 && (int) $order->user_id !== $buyer ) {
				return self::error();
			}
			if ( 'complete' === $order->status ) {
				return self::receipt_url( $order );
			}
			if ( ! in_array( $order->status, array( 'pending', 'failed', 'abandoned' ), true ) ) {
				return self::error();
			}
			$owned = Lock::acquire( 'order:' . $order->id );
			if ( ! $owned ) {
				return self::error();
			}
			if ( function_exists( 'EDD' ) ) {
				$edd = EDD();
				if ( is_object( $edd ) && isset( $edd->session ) && is_object( $edd->session ) ) {
					$edd->session->set( 'edd_resume_payment', $order->id );
				}
			}
			$return   = self::receipt_url( $order );
			$cancel   = edd_get_checkout_uri();
			$callback = Settings::webhook_url();
			if ( ! Settings::url_ok( $return ) || ! Settings::url_ok( $cancel ) || ! Settings::url_ok( $callback, false ) ) {
				return self::error();
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
				return self::error();
			}
			$session = $response['data'];
			if ( ! is_array( $session ) || ! self::session_matches( $session, $order ) || empty( $session['hostedUrl'] ) || ! is_string( $session['hostedUrl'] ) ) {
				return self::error();
			}
			$hosted = esc_url_raw( $session['hostedUrl'] );
			if ( ! Hosted_Url::is_allowed( $hosted, Settings::test_mode() ) ) {
				return self::error();
			}
			$previous = (string) edd_get_order_meta( $order->id, self::SESSION_META, true );
			$saved    = edd_update_order_meta( $order->id, self::SESSION_META, sanitize_text_field( $session['id'] ) );
			if ( false === $saved && edd_get_order_meta( $order->id, self::SESSION_META, true ) !== $session['id'] ) {
				return self::error();
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
			return self::error();
		} finally {
			if ( $owned ) {
				Lock::release( $owned );
			}
			Lock::release( $intent );
		}
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
	 * Buyer-facing checkout failure. No provider detail is included.
	 *
	 * @return \WP_Error
	 */
	private static function error() {
		return new \WP_Error( 'finlstpa_unavailable', __( 'USDC checkout could not be started. Please try again or choose another payment method. No payment confirmation has been recorded.', 'finlinks-stablecoin-payments-for-edd' ) );
	}
}
