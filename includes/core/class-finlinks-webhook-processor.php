<?php
/**
 * Webhook receiver logic (contract §4.2, §7). Framework-free so it can be unit tested.
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies, de-duplicates and dispatches one webhook delivery.
 */
final class Finlinks_Webhook_Processor {

	/** Session handler results. */
	const HANDLED = 'handled';
	const IGNORED = 'ignored';
	const RETRY   = 'retry';

	/** @var string */
	private $secret;

	/** @var string Second secret accepted while a rotation is pending. */
	private $alt_secret;

	/** @var Finlinks_Event_Store */
	private $store;

	/** @var callable(array):string */
	private $session_handler;

	/** @var callable():int */
	private $clock;

	/** @var callable|null Called with the event timestamp when a signed event is outside the window. */
	private $on_stale;

	/**
	 * Constructor.
	 *
	 * @param string               $secret          Webhook signing secret ('' when not configured).
	 * @param Finlinks_Event_Store $store           Processed-event store.
	 * @param callable             $session_handler Receives the decoded payload of a payment_session.* event,
	 *                                              must confirm by GET and return HANDLED, IGNORED or RETRY.
	 * @param callable|null        $clock           Returns unix time.
	 * @param callable|null        $on_stale        Receives the timestamp of a signed but stale event.
	 * @param string               $alt_secret      Pending webhook secret. Empty when no rotation is pending.
	 */
	public function __construct( $secret, Finlinks_Event_Store $store, callable $session_handler, $clock = null, $on_stale = null, $alt_secret = '' ) {
		$this->secret          = (string) $secret;
		$this->alt_secret      = (string) $alt_secret;
		$this->store           = $store;
		$this->session_handler = $session_handler;
		$this->clock           = $clock ? $clock : 'time';
		$this->on_stale        = $on_stale;
	}

	/**
	 * Handle a delivery.
	 *
	 * @param array  $headers  Header map; keys are matched case-insensitively ("Finlinks-Signature" or "finlinks_signature").
	 * @param string $raw_body Raw body exactly as received.
	 * @return array{0:int,1:array} HTTP status and JSON body.
	 */
	public function handle( array $headers, $raw_body ) {
		$h = array();
		foreach ( $headers as $k => $v ) {
			if ( is_array( $v ) ) {
				$v = reset( $v );
			}
			$h[ str_replace( '_', '-', strtolower( (string) $k ) ) ] = (string) $v;
		}

		$result = $this->signature_result( $h, (string) $raw_body );
		if ( 'not_configured' === $result ) {
			// Not configured yet: retryable, so events are not lost while the merchant finishes setup.
			return array( 503, array( 'error' => 'not_configured' ) );
		}
		if ( Finlinks_Signature::STALE === $result ) {
			// Correctly signed but outside the 5-minute window: most likely clock skew on one side.
			// Not processed, and not a 4xx (which is permanent, §16.5). 503 lets the service retry
			// with a fresh timestamp; the backup poll (GET session) recovers it in any case.
			if ( $this->on_stale ) {
				call_user_func( $this->on_stale, (int) $h['finlinks-timestamp'] );
			}
			return array( 503, array( 'error' => $result ) );
		}
		if ( Finlinks_Signature::OK !== $result ) {
			return array( 401, array( 'error' => $result ) );
		}

		$payload = json_decode( (string) $raw_body, true );
		if ( ! is_array( $payload ) || empty( $payload['id'] ) || empty( $payload['type'] ) || ! is_string( $payload['id'] ) || ! is_string( $payload['type'] ) ) {
			return array( 400, array( 'error' => 'invalid_payload' ) );
		}
		$event_id = $payload['id'];
		if ( isset( $h['finlinks-event-id'] ) && $h['finlinks-event-id'] !== $event_id ) {
			return array( 400, array( 'error' => 'event_id_mismatch' ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $event_id ) ) {
			return array( 400, array( 'error' => 'invalid_event_id' ) );
		}

		$type = $payload['type'];

		if ( 'connection.verify' === $type ) {
			$challenge = self::find_challenge( $payload );
			if ( null === $challenge ) {
				return array( 400, array( 'error' => 'missing_challenge' ) );
			}
			return array( 200, array( 'challenge' => $challenge ) );
		}

		if ( 'ping' === $type ) {
			return array( 200, array( 'ok' => true ) );
		}

		if ( 0 !== strpos( $type, 'payment_session.' ) ) {
			// Unknown future event types are acknowledged and ignored.
			return array( 200, array( 'ignored' => true ) );
		}

		if ( ! $this->store->claim( $event_id ) ) {
			return array( 200, array( 'duplicate' => true ) );
		}

		try {
			$outcome = call_user_func( $this->session_handler, $payload );
		} catch ( \Throwable $e ) {
			$outcome = self::RETRY;
		}

		if ( self::RETRY === $outcome ) {
			$this->store->release( $event_id );
			return array( 503, array( 'error' => 'retry_later' ) );
		}
		return array( 200, array( 'received' => true ) );
	}

	/**
	 * Verify against the live secret, then the pending secret when the first signature does not match.
	 * A stale but valid signature is not tried against the other secret.
	 *
	 * @param array  $h        Normalized headers.
	 * @param string $raw_body Raw body.
	 * @return string Signature result, or not_configured.
	 */
	private function signature_result( array $h, $raw_body ) {
		$secrets = array();
		if ( '' !== $this->secret ) {
			$secrets[] = $this->secret;
		}
		if ( '' !== $this->alt_secret && $this->alt_secret !== $this->secret ) {
			$secrets[] = $this->alt_secret;
		}
		if ( ! $secrets ) {
			return 'not_configured';
		}
		$timestamp = isset( $h['finlinks-timestamp'] ) ? $h['finlinks-timestamp'] : null;
		$signature = isset( $h['finlinks-signature'] ) ? $h['finlinks-signature'] : null;
		$result    = Finlinks_Signature::MISSING;
		foreach ( $secrets as $secret ) {
			$result = Finlinks_Signature::verify(
				$secret,
				$timestamp,
				$signature,
				$raw_body,
				(int) call_user_func( $this->clock )
			);
			if ( Finlinks_Signature::BAD_SIGNATURE !== $result ) {
				return $result;
			}
		}
		return $result;
	}

	/**
	 * The challenge of a connection.verify event, at data.challenge (§16.1).
	 *
	 * @param array $payload Decoded payload.
	 * @return string|null
	 */
	private static function find_challenge( array $payload ) {
		if ( isset( $payload['data']['challenge'] ) && is_string( $payload['data']['challenge'] ) && '' !== $payload['data']['challenge'] ) {
			return $payload['data']['challenge'];
		}
		return null;
	}
}
