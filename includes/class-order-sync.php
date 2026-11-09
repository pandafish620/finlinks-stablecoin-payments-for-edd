<?php
/**
 * Confirmed FinLinks session to EDD 3.x order-state adapter.
 *
 * @package Finlstpa\EDD
 */

namespace Finlstpa\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Apply a confirmed session to one order.
 */
final class Order_Sync {

	/**
	 * Handle one payment_session webhook payload.
	 *
	 * @param array $payload Decoded payload.
	 * @return string
	 */
	public static function event( array $payload ) {
		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		$key  = isset( $data['storeOrderId'] ) && is_string( $data['storeOrderId'] ) ? $data['storeOrderId'] : '';
		$sid  = isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '';
		if ( ! preg_match( '/^edd-([1-9][0-9]*)$/', $key, $match ) || ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $sid ) ) {
			return Webhook_Processor::IGNORED;
		}
		$order_id = (int) $match[1];
		if ( $order_id < 1 || Gateway::store_id( $order_id ) !== $key ) {
			return Webhook_Processor::IGNORED;
		}
		return self::sync( $order_id, $sid );
	}

	/**
	 * Confirm a session with the API and update the order.
	 *
	 * @param int    $order_id Order id.
	 * @param string $sid      Session id.
	 * @return string
	 */
	public static function sync( $order_id, $sid ) {
		$order_id = absint( $order_id );
		if ( $order_id < 1 || ! is_string( $sid ) || ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $sid ) ) {
			return Webhook_Processor::IGNORED;
		}
		$order = edd_get_order( $order_id );
		if ( ! is_object( $order ) || Gateway::ID !== $order->gateway ) {
			return Webhook_Processor::IGNORED;
		}
		if ( ! Settings::configured() ) {
			return Webhook_Processor::RETRY;
		}
		$owned = Lock::acquire( 'order:' . $order_id );
		if ( ! $owned ) {
			return Webhook_Processor::RETRY;
		}
		try {
			$order   = edd_get_order( $order_id );
			$current = (string) edd_get_order_meta( $order_id, Gateway::SESSION_META, true );
			if ( ! is_object( $order ) || Gateway::ID !== $order->gateway || '' === $current ) {
				return Webhook_Processor::RETRY;
			}
			if ( ! hash_equals( $current, $sid ) ) {
				return Webhook_Processor::IGNORED;
			}
			$response = Settings::api()->get_session( $sid, 7 );
			if ( ! $response['ok'] ) {
				return Webhook_Processor::RETRY;
			}
			$session = $response['data'];
			if ( ! is_array( $session ) || ! isset( $session['id'], $session['storeOrderId'], $session['livemode'] ) || ! is_string( $session['id'] )
				|| ! hash_equals( $sid, $session['id'] ) || Gateway::store_id( $order_id ) !== (string) $session['storeOrderId']
				|| ! is_bool( $session['livemode'] ) || ( 'live' === $order->mode ) !== $session['livemode'] ) {
				return Webhook_Processor::IGNORED;
			}
			$decision = Session_Decider::decide(
				$session,
				array(
					'order_id'           => Gateway::store_id( $order_id ),
					'order_total'        => Amount::format_usd( $order->total ),
					'order_currency'     => $order->currency,
					'order_is_paid'      => in_array( $order->status, array( 'complete', 'refunded', 'revoked', 'partially_refunded' ), true ),
					'order_status'       => $order->status,
					'current_session_id' => $current,
					'livemode'           => 'live' === $order->mode,
					'last_status'        => (string) edd_get_order_meta( $order_id, Gateway::LAST_META, true ),
					'unpaid_action'      => Session_Decider::UNPAID_FAIL,
					'payable_statuses'   => array( 'pending', 'failed', 'abandoned' ),
				)
			);
			if ( Session_Decider::ACTION_IGNORE === $decision['action'] ) {
				return Webhook_Processor::IGNORED;
			}
			$reason = isset( $decision['reason'] ) ? (string) $decision['reason'] : '';
			if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $reason ) ) {
				return Webhook_Processor::RETRY;
			}
			$note = isset( $decision['note'] ) ? sanitize_textarea_field( $decision['note'] ) : '';
			if ( strlen( $note ) > 1000 ) {
				$note = substr( $note, 0, 1000 );
			}
			$note_key = Gateway::NOTE_PREFIX . hash( 'sha256', $sid . ':' . $reason );
			if ( '' !== $note && ! edd_get_order_meta( $order_id, $note_key, true ) ) {
				$note_id = edd_add_note(
					array(
						'object_id'   => $order_id,
						'object_type' => 'order',
						'content'     => $note,
						'user_id'     => 0,
					)
				);
				if ( ! $note_id ) {
					return Webhook_Processor::RETRY;
				}
				edd_update_order_meta( $order_id, $note_key, (string) absint( $note_id ) );
			}
			if ( Session_Decider::ACTION_COMPLETE === $decision['action'] ) {
				$hash = isset( $decision['tx_hash'] ) ? (string) $decision['tx_hash'] : '';
				if ( '' !== $hash && ! preg_match( '/^(?:0x)?[A-Za-z0-9]{16,128}$/', $hash ) ) {
					$hash = '';
				}
				if ( '' !== $hash ) {
					if ( edd_get_payment_transaction_id( $order_id ) !== $hash && false === edd_set_payment_transaction_id( $order_id, $hash ) ) {
						return Webhook_Processor::RETRY;
					}
					edd_update_order_meta( $order_id, Gateway::TX_META, $hash );
				}
				$updated = edd_update_order_status( $order_id, 'complete' );
				$fresh   = edd_get_order( $order_id );
				if ( ! $updated && ( ! is_object( $fresh ) || 'complete' !== $fresh->status ) ) {
					return Webhook_Processor::RETRY;
				}
			} elseif ( in_array( $decision['action'], array( Session_Decider::ACTION_CANCEL, Session_Decider::ACTION_FAIL ), true ) ) {
				edd_update_order_status( $order_id, 'failed' );
			} elseif ( Session_Decider::ACTION_HOLD === $decision['action'] && 'pending' !== $order->status ) {
				edd_update_order_status( $order_id, 'pending' );
			}
			edd_update_order_meta( $order_id, Gateway::LAST_META, $reason );
			return Webhook_Processor::HANDLED;
		} finally {
			Lock::release( $owned );
		}
	}

	/**
	 * Backup confirmation for recent unfinished orders.
	 *
	 * @return void
	 */
	public static function reconcile() {
		Event_Store::purge();
		if ( ! Settings::configured() || ! function_exists( 'edd_get_orders' ) ) {
			return;
		}
		$orders = edd_get_orders(
			array(
				'gateway' => Gateway::ID,
				'status'  => array( 'pending', 'failed', 'abandoned' ),
				'number'  => 50,
				'orderby' => 'date_created',
				'order'   => 'DESC',
			)
		);
		if ( ! is_array( $orders ) ) {
			return;
		}
		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! isset( $order->id ) ) {
				continue;
			}
			$created = isset( $order->date_created ) ? strtotime( $order->date_created . ' UTC' ) : false;
			if ( false !== $created && $created < time() - 2 * DAY_IN_SECONDS ) {
				continue;
			}
			$sid = (string) edd_get_order_meta( $order->id, Gateway::SESSION_META, true );
			if ( '' === $sid ) {
				continue;
			}
			try {
				self::sync( $order->id, $sid );
			} catch ( \Throwable $error ) {
				Logger::log( 'Scheduled confirmation deferred.', 'error' );
			}
		}
	}
}
