<?php
/**
 * Buyer and admin copy for API failures. No WordPress except __().
 *
 * Buyer text never names a host, a limit, or a verification state.
 * Admin text is chosen from the error code. Callers must not pass cURL
 * strings or response bodies into these methods.
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Mapped messages.
 */
final class Finlinks_Messages {

	/**
	 * Buyer-facing message for an API error code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function buyer( $code ) {
		switch ( (string) $code ) {
			case 'COMMERCE_SESSION_HAS_PAYMENT':
				return __( 'A payment for this order is already in progress. Please contact the store.', 'finlinks-stablecoin-payments-for-edd' );
			case 'RATE_LIMITED':
			case 'COMMERCE_RATE_LIMITED':
				return __( 'Too many attempts. Please wait a minute and try again.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_AMOUNT_INVALID':
			case 'COMMERCE_CURRENCY_MISMATCH':
				return __( 'USDC payment is not available for this order amount or currency.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_PAYMENT_DECLINED':
			case 'COMMERCE_SESSION_LIMIT_REACHED':
			case 'COMMERCE_PAYEE_UNAVAILABLE':
				return __( 'This order can\'t be paid with USDC right now. Please choose another payment method.', 'finlinks-stablecoin-payments-for-edd' );
			case 'NETWORK_ERROR':
			case 'INTERNAL_ERROR':
			case 'INVOICE_OPEN_AMOUNT_TAKEN':
			case 'COMMERCE_SESSION_UNAVAILABLE':
				return __( 'We could not start the USDC payment. Please try again.', 'finlinks-stablecoin-payments-for-edd' );
			default:
				return __( 'USDC payments are temporarily unavailable. Please choose another payment method.', 'finlinks-stablecoin-payments-for-edd' );
		}
	}

	/**
	 * Admin-facing message for a service or transport failure.
	 *
	 * Verification failures read the structured `data` object the API client
	 * already keeps (`delivered`, `httpStatus`, `latencyMs`, `error`).
	 * `error` is used only when it is exactly `timeout` or `network`.
	 *
	 * @param string $code Error code from the API client. Not a response body.
	 * @param array  $data Structured `data` from the client result. Optional.
	 * @return string
	 */
	public static function admin_failure( $code, $data = array() ) {
		$code = self::safe_code( $code );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		switch ( $code ) {
			case 'COMMERCE_VERIFICATION_FAILED':
				return self::verification_failed( $data );
			case 'COMMERCE_MERCHANT_NOT_ELIGIBLE':
				return __( 'Your account is currently not eligible to connect a store. Please contact support.', 'finlinks-stablecoin-payments-for-edd' );
			case 'NETWORK_ERROR':
				return __( 'Could not reach the FinLinks API. Try again in a moment.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_API_KEY_MISSING':
				return __( 'Save the FinLinks API key, then try again.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_API_KEY_INVALID':
				return __( 'The FinLinks API key was rejected. Check the key in Downloads > FinLinks USDC and in the FinLinks portal.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_API_KEY_ENVIRONMENT_MISMATCH':
				return __( 'The API key does not match Test mode. Use an flk_test_ key with Test mode on, or an flk_live_ key with Test mode off.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_CONNECTION_NOT_ACTIVE':
				return __( 'The FinLinks connection is not active. Register the webhook and verify the store, then try again.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_SESSIONS_DISABLED':
				return __( 'USDC payment sessions are turned off for this connection.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_WEBHOOK_NOT_CONFIGURED':
				return __( 'The webhook is not registered. Use "Register webhook and verify store".', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_REDIRECT_URL_INVALID':
				return __( 'The store return or cancel URL was rejected. Use https on this store, with pretty permalinks and no explicit port.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_WEBHOOK_URL_INVALID':
				return __( 'The webhook URL was rejected. Use https and pretty permalinks, with no query string.', 'finlinks-stablecoin-payments-for-edd' );
			case 'RATE_LIMITED':
			case 'COMMERCE_RATE_LIMITED':
				return __( 'Too many requests to the FinLinks API. Wait a minute and try again.', 'finlinks-stablecoin-payments-for-edd' );
			case 'IDENTITY_REQUIRED':
			case 'KYB_REJECTED_FRAUD':
				return __( 'This FinLinks connection cannot take payments. Check the connection in the FinLinks portal.', 'finlinks-stablecoin-payments-for-edd' );
			default:
				return sprintf(
					/* translators: %s: error code */
					__( 'The FinLinks API could not complete this request (%s).', 'finlinks-stablecoin-payments-for-edd' ),
					$code
				);
		}
	}

	/**
	 * Store-verification failure from the real 422 payload.
	 *
	 * `data.error` is exactly `timeout` or `network` when the service could not
	 * complete the request (`httpStatus` is null). Otherwise `error` is
	 * `HTTP <status>` and is never copied into the notice. A 2xx means the
	 * store answered with the wrong challenge. Missing or unusable fields get
	 * a generic sentence. No host, URL, or response body is included.
	 *
	 * @param array $data Client `data` array.
	 * @return string
	 */
	private static function verification_failed( array $data ) {
		$token = self::transport_token( $data );
		if ( 'timeout' === $token ) {
			return __( 'Store verification failed: the request to your site timed out. Check firewall or Cloudflare rules for /wp-json/.', 'finlinks-stablecoin-payments-for-edd' );
		}
		if ( 'network' === $token ) {
			return __( 'Store verification failed: the payment service could not reach your site. Check firewall or Cloudflare rules for /wp-json/.', 'finlinks-stablecoin-payments-for-edd' );
		}
		$status = self::http_status( $data );
		if ( null === $status ) {
			return __( 'Store verification failed. Check firewall or Cloudflare rules for /wp-json/.', 'finlinks-stablecoin-payments-for-edd' );
		}
		if ( $status >= 200 && $status <= 299 ) {
			return __( 'Store verification failed: your site responded, but the response did not match. Check page caching for /wp-json/.', 'finlinks-stablecoin-payments-for-edd' );
		}
		if ( 401 === $status ) {
			return sprintf(
				/* translators: %d: HTTP status returned by this store */
				__( 'Store verification failed: your site returned HTTP %d. Check the webhook signing secret.', 'finlinks-stablecoin-payments-for-edd' ),
				$status
			);
		}
		if ( 403 === $status ) {
			return sprintf(
				/* translators: %d: HTTP status returned by this store */
				__( 'Store verification failed: your site returned HTTP %d. Check firewall or Cloudflare rules for /wp-json/.', 'finlinks-stablecoin-payments-for-edd' ),
				$status
			);
		}
		if ( 404 === $status ) {
			return sprintf(
				/* translators: %d: HTTP status returned by this store */
				__( 'Store verification failed: your site returned HTTP %d. Check that pretty permalinks are enabled and /wp-json/ is reachable.', 'finlinks-stablecoin-payments-for-edd' ),
				$status
			);
		}
		if ( 503 === $status ) {
			return sprintf(
				/* translators: %d: HTTP status returned by this store */
				__( 'Store verification failed: your site returned HTTP %d. Check the webhook signing secret and the server clock.', 'finlinks-stablecoin-payments-for-edd' ),
				$status
			);
		}
		if ( $status >= 300 && $status <= 399 ) {
			return sprintf(
				/* translators: %d: HTTP status returned by this store */
				__( 'Store verification failed: your site returned HTTP %d. Redirects are not followed. Check that /wp-json/ answers directly.', 'finlinks-stablecoin-payments-for-edd' ),
				$status
			);
		}
		return sprintf(
			/* translators: %d: HTTP status returned by this store */
			__( 'Store verification failed: your site returned HTTP %d. Check that /wp-json/ is reachable.', 'finlinks-stablecoin-payments-for-edd' ),
			$status
		);
	}

	/**
	 * `timeout` or `network` when data.error is exactly that token.
	 *
	 * @param array $data Data.
	 * @return string
	 */
	private static function transport_token( array $data ) {
		if ( ! isset( $data['error'] ) || ! is_string( $data['error'] ) ) {
			return '';
		}
		if ( 'timeout' === $data['error'] || 'network' === $data['error'] ) {
			return $data['error'];
		}
		return '';
	}

	/**
	 * HTTP status from data.httpStatus, or null when it is missing or not a status code.
	 *
	 * @param array $data Data.
	 * @return int|null
	 */
	private static function http_status( array $data ) {
		if ( ! array_key_exists( 'httpStatus', $data ) || null === $data['httpStatus'] || '' === $data['httpStatus'] ) {
			return null;
		}
		$v = $data['httpStatus'];
		if ( is_int( $v ) ) {
			$n = $v;
		} elseif ( is_float( $v ) && $v === floor( $v ) ) {
			$n = (int) $v;
		} elseif ( is_string( $v ) && 1 === preg_match( '/^[1-5][0-9]{2}$/', $v ) ) {
			$n = (int) $v;
		} else {
			return null;
		}
		if ( $n < 100 || $n > 599 ) {
			return null;
		}
		return $n;
	}

	/**
	 * Admin notice for a Connect error code. Server prose is not accepted and is never shown.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function connect( $code ) {
		$code = self::safe_code( $code );
		switch ( $code ) {
			case 'COMMERCE_CONNECT_REQUEST_INVALID':
				return __( 'FinLinks could not start the connection. Try again.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_CONNECT_RETURN_URL_INVALID':
				return __( 'FinLinks could not use this store\'s settings page as the return address. Paste the API key and webhook signing secret under Advanced.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_CONNECT_HTTPS_REQUIRED':
				return __( 'Connect needs an https store address.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_CONNECT_STATE_INVALID':
			case 'COMMERCE_CONNECT_STATE_MISMATCH':
			case 'COMMERCE_CONNECT_CODE_INVALID':
			case 'COMMERCE_CONNECT_CODE_USED':
			case 'COMMERCE_CONNECT_PKCE_INVALID':
				return __( 'This connection attempt is no longer valid. Start Connect again.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_CONNECT_STATE_EXPIRED':
				return __( 'This connection attempt expired. Start Connect again.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_ACCOUNT_NOT_BUSINESS':
				return __( 'Connect is available for a FinLinks business account.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_KYB_REJECTED':
				return __( 'This FinLinks account cannot connect this EDD store.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_SHOP_ALREADY_LINKED':
				return __( 'This store is already connected to another FinLinks account.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_CONNECTION_NEEDS_REAUTH':
				return __( 'Reconnect this store.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_CONNECT_MODE_MISMATCH':
				return __( 'The FinLinks account mode does not match test mode in EDD.', 'finlinks-stablecoin-payments-for-edd' );
			case 'COMMERCE_OAUTH_UNAVAILABLE':
				return __( 'FinLinks is temporarily unavailable. Try again later.', 'finlinks-stablecoin-payments-for-edd' );
			case 'CONNECT_DISABLED':
				return __( 'Use the dedicated EDD connection credentials in Downloads > FinLinks USDC.', 'finlinks-stablecoin-payments-for-edd' );
			case 'FINLINKS_API_BASE_MISMATCH':
				return __( 'FinLinks returned an unexpected API address. Nothing was saved.', 'finlinks-stablecoin-payments-for-edd' );
			case 'FINLINKS_CREDENTIALS_REJECTED':
				return __( 'FinLinks returned credentials this store cannot save. Nothing was saved.', 'finlinks-stablecoin-payments-for-edd' );
			case 'FINLINKS_CONNECT_CANCELLED':
				return __( 'Connection cancelled.', 'finlinks-stablecoin-payments-for-edd' );
			case 'FINLINKS_ROTATION_UNCERTAIN':
				return __( 'Click Test connection. If it fails, click Reconnect again.', 'finlinks-stablecoin-payments-for-edd' );
			default:
				return self::admin_failure( $code );
		}
	}

	/**
	 * Error code safe to show to an admin. Anything else becomes UNKNOWN.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function safe_code( $code ) {
		$code = strtoupper( (string) $code );
		return 1 === preg_match( '/^[A-Z0-9_]{1,64}$/', $code ) ? $code : 'UNKNOWN';
	}
}
