<?php
/**
 * EDD gateway registration and hosted checkout adapter.
 *
 * @package FinLinks\EDD
 */
namespace FinLinks\EDD;
defined( 'ABSPATH' ) || exit;

final class Gateway {
	const ID = 'finlinks_usdc';
	const SESSION_META = '_finlinks_edd_session';
	const LAST_META = '_finlinks_edd_last_status';
	const TX_META = '_finlinks_edd_transaction_hash';

	public static function init() {
		add_filter( 'edd_payment_gateways', array( __CLASS__, 'register' ) );
		add_filter( 'edd_enabled_payment_gateways', array( __CLASS__, 'available' ) );
		add_action( 'edd_finlinks_usdc_cc_form', array( __CLASS__, 'description' ) );
		add_action( 'edd_gateway_finlinks_usdc', array( __CLASS__, 'process' ) );
	}

	public static function register( $gateways ) {
		$gateways[ self::ID ] = array(
			'admin_label' => __( 'FinLinks USDC', 'finlinks-stablecoin-payments-for-edd' ),
			'checkout_label' => __( 'Pay with USDC', 'finlinks-stablecoin-payments-for-edd' ),
			'supports' => array(),
		);
		return $gateways;
	}

	public static function available( $gateways ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}
		if ( ! Settings::checkout_ready() || ! Finlinks_Amount::is_eligible( edd_get_currency(), Finlinks_Amount::format_usd( edd_get_cart_total() ) ) ) {
			unset( $gateways[ self::ID ] );
		}
		return $gateways;
	}

	public static function description() {
		echo '<p>' . esc_html__( 'You will be redirected to the official FinLinks hosted page to pay with USDC. Your download becomes available only after payment confirmation.', 'finlinks-stablecoin-payments-for-edd' ) . '</p>';
	}

	public static function store_id( $order_id ) {
		return 'edd-' . absint( $order_id );
	}

	public static function receipt_url( $order ) {
		$base = function_exists( 'edd_get_confirmation_page_uri' ) ? edd_get_confirmation_page_uri() : edd_get_success_page_uri();
		$args = array( 'payment_key' => $order->payment_key );
		if ( method_exists( $order, 'get_receipt_hash' ) ) {
			$args['order'] = $order->get_receipt_hash();
			$args['id']    = $order->id;
		}
		return add_query_arg( $args, $base );
	}

	public static function process( $purchase_data ) {
		$nonce = is_array( $purchase_data ) && isset( $purchase_data['gateway_nonce'] ) && is_string( $purchase_data['gateway_nonce'] ) ? sanitize_text_field( $purchase_data['gateway_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'edd-gateway' ) ) {
			wp_die( esc_html__( 'Checkout verification failed. Reload the checkout page.', 'finlinks-stablecoin-payments-for-edd' ), '', array( 'response' => 403 ) );
		}
		$result = self::prepare( $purchase_data );
		if ( is_wp_error( $result ) ) {
			edd_set_error( 'finlinks_edd_checkout', esc_html( $result->get_error_message() ) );
			edd_send_back_to_checkout( '?payment-mode=' . self::ID );
			return;
		}
		$host = (string) wp_parse_url( $result, PHP_URL_HOST );
		add_filter( 'allowed_redirect_hosts', static function ( $hosts ) use ( $host ) {
			$hosts[] = $host;
			return $hosts;
		} );
		wp_safe_redirect( $result, 303, 'FinLinks EDD' );
		exit;
	}

	/**
	 * Only EDD-validated purchase data is passed here by process().
	 *
	 * @param array $purchase_data EDD checkout data, not a raw POST body.
	 * @return string|\WP_Error Hosted or receipt URL.
	 */
	public static function prepare( array $purchase_data ) {
		$amount = isset( $purchase_data['price'] ) ? Finlinks_Amount::format_usd( $purchase_data['price'] ) : null;
		$key    = isset( $purchase_data['purchase_key'] ) && is_string( $purchase_data['purchase_key'] ) ? $purchase_data['purchase_key'] : '';
		if ( ! Settings::checkout_ready() || ! Finlinks_Amount::is_eligible( edd_get_currency(), $amount ) ) {
			return self::error();
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $key ) ) {
			return self::error();
		}
		$intent = Lock::acquire( 'checkout:' . $key );
		if ( ! $intent ) {
			return new \WP_Error( 'finlinks_edd_busy', __( 'This checkout is already being processed. Please wait before trying again.', 'finlinks-stablecoin-payments-for-edd' ) );
		}
		$owned = false;
		try {
			$order = edd_get_order_by( 'payment_key', $key );
			if ( ! $order ) {
				$data = array_intersect_key( $purchase_data, array_flip( array( 'price', 'date', 'user_email', 'purchase_key', 'downloads', 'user_info', 'cart_details', 'fees', 'subtotal', 'discount', 'tax' ) ) );
				$data['status']   = 'pending';
				$data['gateway']  = self::ID;
				$data['currency'] = edd_get_currency();
				$id = edd_insert_payment( $data );
				if ( ! $id ) {
					return self::error();
				}
				$order = edd_get_order( $id );
				if ( class_exists( '\\EDD\\Discounts\\OrderValidation' ) && is_callable( array( '\\EDD\\Discounts\\OrderValidation', 'refuse_unclaimed' ) ) && \EDD\Discounts\OrderValidation::refuse_unclaimed( $id ) ) {
					return self::error();
				}
			}
			if ( ! $order || self::ID !== $order->gateway || ! hash_equals( (string) $order->payment_key, $key ) || ! Finlinks_Amount::equals( $order->total, $amount ) || strtoupper( $order->currency ) !== strtoupper( edd_get_currency() ) || $order->mode !== ( Settings::test_mode() ? 'test' : 'live' ) ) {
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
			if ( function_exists( 'EDD' ) && EDD()->session ) {
				EDD()->session->set( 'edd_resume_payment', $order->id );
			}
			$return   = self::receipt_url( $order );
			$cancel   = edd_get_checkout_uri();
			$callback = Settings::webhook_url();
			if ( ! Settings::url_ok( $return ) || ! Settings::url_ok( $cancel ) || ! Settings::url_ok( $callback, false ) ) {
				return self::error();
			}
			$body = array(
				'storeOrderId' => self::store_id( $order->id ),
				'orderLabel' => 'EDD Order #' . $order->id,
				'amount' => $amount,
				'currency' => 'USD',
				'returnUrl' => $return,
				'cancelUrl' => $cancel,
				'callbackUrl' => $callback,
			);
			$api = Settings::api();
			$response = $api->create_session( $body );
			if ( ! $response['ok'] && 409 === $response['status'] && 'COMMERCE_SESSION_AMOUNT_CHANGED' === $response['code'] ) {
				$old = (string) edd_get_order_meta( $order->id, self::SESSION_META, true );
				if ( '' !== $old ) {
					$cancelled = $api->cancel_session( $old );
					if ( $cancelled['ok'] ) {
						edd_update_order_meta( $order->id, self::SESSION_META, '' );
						$response = $api->create_session( $body );
					}
				}
			}
			if ( ! $response['ok'] ) {
				if ( in_array( $response['code'], array( 'COMMERCE_API_KEY_INVALID', 'COMMERCE_API_KEY_ENVIRONMENT_MISMATCH', 'COMMERCE_CONNECTION_NOT_ACTIVE', 'COMMERCE_WEBHOOK_NOT_CONFIGURED', 'COMMERCE_CONNECTION_NOT_FOUND' ), true ) ) {
					update_option( Settings::PAUSE_OPTION, array( 'until' => time() + 300, 'code' => sanitize_key( $response['code'] ) ), false );
				}
				Finlinks_Logger::log( 'Create session failed: ' . $response['status'] . ' ' . $response['code'], 'error' );
				return self::error();
			}
			$session = $response['data'];
			if ( ! self::session_matches( $session, $order ) || empty( $session['hostedUrl'] ) || ! is_string( $session['hostedUrl'] ) || ! Finlinks_Hosted_Url::is_allowed( $session['hostedUrl'], Settings::test_mode() ) ) {
				return self::error();
			}
			$previous = (string) edd_get_order_meta( $order->id, self::SESSION_META, true );
			$saved = edd_update_order_meta( $order->id, self::SESSION_META, sanitize_text_field( $session['id'] ) );
			if ( false === $saved && edd_get_order_meta( $order->id, self::SESSION_META, true ) !== $session['id'] ) {
				return self::error();
			}
			if ( $previous !== $session['id'] ) {
				edd_delete_order_meta( $order->id, self::LAST_META );
			}
			edd_update_order_meta( $order->id, '_finlinks_edd_store_order_id', self::store_id( $order->id ) );
			if ( 'pending' !== $order->status ) {
				edd_update_order_status( $order->id, 'pending' );
			}
			return esc_url_raw( $session['hostedUrl'] );
		} catch ( \Throwable $error ) {
			Finlinks_Logger::log( 'Checkout deferred due to an internal error.', 'error' );
			return self::error();
		} finally {
			if ( $owned ) {
				Lock::release( $owned );
			}
			Lock::release( $intent );
		}
	}

	public static function session_matches( array $session, $order ) {
		return isset( $session['id'], $session['storeOrderId'], $session['livemode'], $session['amount'], $session['currency'] )
			&& is_string( $session['id'] ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $session['id'] )
			&& (string) $session['storeOrderId'] === self::store_id( $order->id )
			&& is_bool( $session['livemode'] ) && $session['livemode'] === ( 'live' === $order->mode )
			&& Finlinks_Amount::equals( $session['amount'], $order->total ) && strtoupper( (string) $session['currency'] ) === strtoupper( $order->currency );
	}

	private static function error() {
		return new \WP_Error( 'finlinks_edd_unavailable', __( 'USDC checkout could not be started. Please try again or choose another payment method. No payment confirmation has been recorded.', 'finlinks-stablecoin-payments-for-edd' ) );
	}
}

