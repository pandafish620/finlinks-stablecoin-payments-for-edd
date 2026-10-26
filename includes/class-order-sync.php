<?php
/**
 * Confirmed FinLinks session to EDD 3.x order-state adapter.
 *
 * @package FinLinks\EDD
 */
namespace FinLinks\EDD;
defined( 'ABSPATH' ) || exit;

final class Order_Sync {
	public static function event( array $payload ) {
		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		$key  = isset( $data['storeOrderId'] ) && is_string( $data['storeOrderId'] ) ? $data['storeOrderId'] : '';
		$sid  = isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '';
		if ( ! preg_match( '/^edd-([1-9][0-9]*)$/', $key, $match ) || '' === $sid ) {
			return Finlinks_Webhook_Processor::IGNORED;
		}
		return self::sync( (int) $match[1], $sid );
	}

	public static function sync( $order_id, $sid ) {
		$order = edd_get_order( $order_id );
		if ( ! $order || Gateway::ID !== $order->gateway ) {
			return Finlinks_Webhook_Processor::IGNORED;
		}
		if ( ! Settings::configured() ) {
			return Finlinks_Webhook_Processor::RETRY;
		}
		$owned = Lock::acquire( 'order:' . $order_id );
		if ( ! $owned ) {
			return Finlinks_Webhook_Processor::RETRY;
		}
		try {
			$order = edd_get_order( $order_id );
			$current = (string) edd_get_order_meta( $order_id, Gateway::SESSION_META, true );
			if ( ! $order || Gateway::ID !== $order->gateway || '' === $current ) {
				return Finlinks_Webhook_Processor::RETRY;
			}
			if ( ! hash_equals( $current, (string) $sid ) ) {
				return Finlinks_Webhook_Processor::IGNORED;
			}
			// Do not trust the webhook payment status. Confirm with the authenticated API.
			$response = Settings::api()->get_session( $sid, 7 );
			if ( ! $response['ok'] ) {
				return Finlinks_Webhook_Processor::RETRY;
			}
			$session = $response['data'];
			if ( ! isset( $session['id'], $session['storeOrderId'], $session['livemode'] ) || ! is_string( $session['id'] )
				|| $session['id'] !== $sid || (string) $session['storeOrderId'] !== Gateway::store_id( $order_id )
				|| ! is_bool( $session['livemode'] ) || $session['livemode'] !== ( 'live' === $order->mode ) ) {
				return Finlinks_Webhook_Processor::IGNORED;
			}
			$decision = Finlinks_Session_Decider::decide( $session, array(
				'order_id' => Gateway::store_id( $order_id ),
				'order_total' => Finlinks_Amount::format_usd( $order->total ),
				'order_currency' => $order->currency,
				'order_is_paid' => in_array( $order->status, array( 'complete', 'refunded', 'revoked', 'partially_refunded' ), true ),
				'order_status' => $order->status,
				'current_session_id' => $current,
				'livemode' => 'live' === $order->mode,
				'last_status' => (string) edd_get_order_meta( $order_id, Gateway::LAST_META, true ),
				'unpaid_action' => Finlinks_Session_Decider::UNPAID_FAIL,
				'payable_statuses' => array( 'pending', 'failed', 'abandoned' ),
			) );
			if ( Finlinks_Session_Decider::ACTION_IGNORE === $decision['action'] ) {
				return Finlinks_Webhook_Processor::IGNORED;
			}
			$note = isset( $decision['note'] ) ? sanitize_textarea_field( $decision['note'] ) : '';
			$note_key = '_finlinks_edd_note_' . hash( 'sha256', $sid . ':' . $decision['reason'] );
			if ( '' !== $note && ! edd_get_order_meta( $order_id, $note_key, true ) ) {
				$note_id = edd_add_note( array( 'object_id' => $order_id, 'object_type' => 'order', 'content' => $note, 'user_id' => 0 ) );
				if ( ! $note_id ) {
					return Finlinks_Webhook_Processor::RETRY;
				}
				edd_update_order_meta( $order_id, $note_key, (string) $note_id );
			}
			if ( Finlinks_Session_Decider::ACTION_COMPLETE === $decision['action'] ) {
				$hash = isset( $decision['tx_hash'] ) ? sanitize_text_field( $decision['tx_hash'] ) : '';
				if ( '' !== $hash ) {
					if ( edd_get_payment_transaction_id( $order_id ) !== $hash && false === edd_set_payment_transaction_id( $order_id, $hash ) ) {
						return Finlinks_Webhook_Processor::RETRY;
					}
					edd_update_order_meta( $order_id, Gateway::TX_META, $hash );
				}
				$updated = edd_update_order_status( $order_id, 'complete' );
				$fresh = edd_get_order( $order_id );
				if ( ! $updated && ( ! $fresh || 'complete' !== $fresh->status ) ) {
					return Finlinks_Webhook_Processor::RETRY;
				}
			} elseif ( in_array( $decision['action'], array( Finlinks_Session_Decider::ACTION_CANCEL, Finlinks_Session_Decider::ACTION_FAIL ), true ) ) {
				edd_update_order_status( $order_id, 'failed' );
			} elseif ( Finlinks_Session_Decider::ACTION_HOLD === $decision['action'] && 'pending' !== $order->status ) {
				edd_update_order_status( $order_id, 'pending' );
			}
			edd_update_order_meta( $order_id, Gateway::LAST_META, $decision['reason'] );
			return Finlinks_Webhook_Processor::HANDLED;
		} finally {
			Lock::release( $owned );
		}
	}

	public static function reconcile() {
		Event_Store::purge();
		if ( ! Settings::configured() ) {
			return;
		}
		foreach ( edd_get_orders( array( 'gateway' => Gateway::ID, 'status' => array( 'pending', 'failed', 'abandoned' ), 'number' => 50, 'orderby' => 'date_created', 'order' => 'DESC' ) ) as $order ) {
			if ( isset( $order->date_created ) && strtotime( $order->date_created . ' UTC' ) < time() - 2 * DAY_IN_SECONDS ) {
				continue;
			}
			$sid = (string) edd_get_order_meta( $order->id, Gateway::SESSION_META, true );
			if ( '' !== $sid ) {
				try {
					self::sync( $order->id, $sid );
				} catch ( \Throwable $error ) {
					Finlinks_Logger::log( 'Scheduled confirmation deferred.', 'error' );
				}
			}
		}
	}
}

