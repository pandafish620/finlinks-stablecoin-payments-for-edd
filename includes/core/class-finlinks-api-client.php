<?php
/**
 * Client for the plugin-side API (contract §4.4, §5). Auth: Bearer API key.
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wp_remote_request wrapper. Responses are normalised to:
 * array{ ok:bool, status:int, data:array, code:string, message:string, network_error:bool }
 */
final class Finlinks_Api_Client {

	/** @var string */
	private $base;

	/** @var string */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $base    API base URL without trailing slash.
	 * @param string $api_key API key.
	 */
	public function __construct( $base, $api_key ) {
		$this->base    = untrailingslashit( (string) $base );
		$this->api_key = (string) $api_key;
	}

	public function get_connection() {
		return $this->request( 'GET', '/api/v1/commerce/connection' );
	}

	public function put_webhook( $webhook_url ) {
		return $this->request(
			'PUT',
			'/api/v1/commerce/connection/webhook',
			array(
				'webhookUrl'      => $webhook_url,
				'platformVersion' => defined( 'EDD_VERSION' ) ? EDD_VERSION : null,
				'pluginVersion'   => FINLINKS_EDD_VERSION,
			)
		);
	}

	public function verify_connection() {
		// The service waits up to 10 s for our own webhook to answer the challenge.
		return $this->request( 'POST', '/api/v1/commerce/connection/verify', array(), 30 );
	}

	public function test_webhook() {
		return $this->request( 'POST', '/api/v1/commerce/connection/test-webhook', array(), 30 );
	}

	public function create_session( array $body ) {
		return $this->request( 'POST', '/api/v1/commerce/sessions', $body );
	}

	public function get_session( $id, $timeout = null ) {
		return $this->request( 'GET', '/api/v1/commerce/sessions/' . rawurlencode( (string) $id ), null, null === $timeout ? 15 : (int) $timeout );
	}

	public function list_sessions( $store_order_id ) {
		return $this->request( 'GET', '/api/v1/commerce/sessions?storeOrderId=' . rawurlencode( (string) $store_order_id ) );
	}

	public function cancel_session( $id ) {
		return $this->request( 'POST', '/api/v1/commerce/sessions/' . rawurlencode( (string) $id ) . '/cancel' );
	}

	/**
	 * Perform a request.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path (may include a query string).
	 * @param array|null $body    JSON body.
	 * @param int        $timeout Seconds.
	 * @return array
	 */
	private function request( $method, $path, $body = null, $timeout = 15 ) {
		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
				'User-Agent'    => 'FinLinks-Stablecoin-Payments-EDD/' . FINLINKS_EDD_VERSION,
			),
		);
		if ( null !== $body && 'GET' !== $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( (object) array_filter( $body, static function ( $v ) {
				return null !== $v;
			} ) );
		}

		$response = wp_remote_request( $this->base . $path, $args );

		if ( is_wp_error( $response ) ) {
			Finlinks_Logger::log( sprintf( '%s %s failed: %s', $method, $path, $response->get_error_message() ), 'error' );
			return array(
				'ok'            => false,
				'status'        => 0,
				'data'          => array(),
				'code'          => 'NETWORK_ERROR',
				'message'       => $response->get_error_message(),
				'network_error' => true,
			);
		}

		self::record_clock( wp_remote_retrieve_header( $response, 'date' ) );

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		$ok      = $status >= 200 && $status < 300 && ! empty( $decoded['success'] );

		$out = array(
			'ok'            => $ok,
			'status'        => $status,
			'data'          => isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array(),
			'code'          => isset( $decoded['code'] ) ? (string) $decoded['code'] : ( $ok ? '' : 'HTTP_' . $status ),
			'message'       => isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : '',
			'network_error' => false,
		);
		Finlinks_Logger::log( sprintf( '%s %s -> %d %s', $method, $path, $status, $out['code'] ) );
		return $out;
	}

	/**
	 * Remember how far this server's clock is from the API's Date header (§16.5).
	 * Written at most every 10 minutes unless the value moves by more than 5 seconds.
	 *
	 * @param string|array $date Date header.
	 */
	public static function record_clock( $date ) {
		if ( is_array( $date ) ) {
			$date = reset( $date );
		}
		$skew = Finlinks_Timing::skew_from_date_header( $date, time() );
		if ( null === $skew ) {
			return;
		}
		$prev = get_option( Settings::CLOCK_OPTION );
		if ( is_array( $prev ) && isset( $prev['skew'], $prev['at'] ) && abs( $prev['skew'] - $skew ) <= 5 && $prev['at'] > time() - 600 ) {
			return;
		}
		update_option( Settings::CLOCK_OPTION, array( 'skew' => $skew, 'at' => time() ), false );
	}
}
