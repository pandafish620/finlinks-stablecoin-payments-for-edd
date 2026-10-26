<?php
/**
 * Maps a confirmed session (contract §6.1) to a e-commerce order action. Pure PHP; only needs __() (stubbed in unit tests).
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what to do with an order given the session object returned by GET /api/v1/commerce/sessions/:id.
 */
final class Finlinks_Session_Decider {

	const STATUSES = array( 'open', 'paid', 'expired', 'failed', 'cancelled' );

	/** Order statuses WC_Order::payment_complete() moves to paid (WooCommerce default filter value). */
	const PAYABLE_ORDER_STATUSES = array( 'pending', 'on-hold', 'failed', 'cancelled' );

	const ACTION_IGNORE   = 'ignore';
	const ACTION_COMPLETE = 'complete';
	const ACTION_HOLD     = 'hold';
	const ACTION_CANCEL   = 'cancel';
	const ACTION_FAIL     = 'fail';
	const ACTION_NOTE     = 'note';

	/** Merchant setting for expired/cancelled sessions. */
	const UNPAID_NONE   = 'none';
	const UNPAID_CANCEL = 'cancel';
	const UNPAID_FAIL   = 'fail';

	/**
	 * Decide.
	 *
	 * @param array $session Session object (§5.2 shape).
	 * @param array $ctx {
	 *     @type string $order_id           WooCommerce order id.
	 *     @type string $order_total        Order total as 2-decimal string.
	 *     @type string $order_currency     Order currency.
	 *     @type bool   $order_is_paid      WC_Order::is_paid().
	 *     @type string $order_status       Order status without "wc-".
	 *     @type string $current_session_id Session id stored on the order.
	 *     @type bool   $livemode           Plugin mode (true = live).
	 *     @type string $last_status        Last session status applied to this order.
	 *     @type string $unpaid_action      none|cancel|fail.
	 *     @type array  $payable_statuses   Optional. Statuses payment_complete() accepts (filtered list).
	 * }
	 * @return array{action:string, reason:string, note?:string, tx_hash?:string, overpaid?:bool}
	 */
	public static function decide( array $session, array $ctx ) {
		$status = isset( $session['status'] ) ? (string) $session['status'] : '';
		$id     = isset( $session['id'] ) ? (string) $session['id'] : '';

		if ( '' === $id || ! in_array( $status, self::STATUSES, true ) ) {
			return self::r( self::ACTION_IGNORE, 'invalid_session' );
		}
		if ( ! isset( $session['storeOrderId'] ) || (string) $session['storeOrderId'] !== (string) $ctx['order_id'] ) {
			return self::r( self::ACTION_IGNORE, 'order_mismatch' );
		}
		if ( isset( $session['livemode'] ) && (bool) $session['livemode'] !== (bool) $ctx['livemode'] ) {
			return self::r( self::ACTION_IGNORE, 'livemode_mismatch' );
		}
		// Never act on (or downgrade) an order that is already paid (§7.5).
		if ( ! empty( $ctx['order_is_paid'] ) ) {
			return self::r( self::ACTION_IGNORE, 'already_paid' );
		}

		if ( 'paid' === $status ) {
			$amount_ok   = isset( $session['amount'] ) && Finlinks_Amount::equals( $session['amount'], $ctx['order_total'] );
			$currency_ok = isset( $session['currency'] ) && strtoupper( (string) $session['currency'] ) === strtoupper( (string) $ctx['order_currency'] );
			if ( ! $amount_ok || ! $currency_ok ) {
				if ( 'paid_mismatch' === ( isset( $ctx['last_status'] ) ? $ctx['last_status'] : '' ) ) {
					return self::r( self::ACTION_IGNORE, 'unchanged' );
				}
				return self::r(
					self::ACTION_HOLD,
					'paid_mismatch',
					sprintf(
						/* translators: 1: session id, 2: session amount, 3: session currency, 4: order total, 5: order currency */
						__( 'USDC payment session %1$s is paid for %2$s %3$s, which does not match the order total %4$s %5$s. Please review before fulfilling.', 'finlinks-stablecoin-payments-for-edd' ),
						$id,
						isset( $session['amount'] ) ? (string) $session['amount'] : '?',
						isset( $session['currency'] ) ? (string) $session['currency'] : '?',
						(string) $ctx['order_total'],
						(string) $ctx['order_currency']
					)
				);
			}
			$tx       = isset( $session['txHash'] ) && is_string( $session['txHash'] ) ? $session['txHash'] : '';
			$overpaid = ! empty( $session['overpaid'] );
			$payable = isset( $ctx['payable_statuses'] ) && is_array( $ctx['payable_statuses'] ) ? $ctx['payable_statuses'] : self::PAYABLE_ORDER_STATUSES;
			if ( ! in_array( (string) $ctx['order_status'], $payable, true ) ) {
				// e.g. refunded, draft or a custom status: payment_complete() would not move the order,
				// so record the payment once and let the merchant decide.
				if ( 'paid_unexpected_status' === ( isset( $ctx['last_status'] ) ? $ctx['last_status'] : '' ) ) {
					return self::r( self::ACTION_IGNORE, 'unchanged' );
				}
				return self::r(
					self::ACTION_NOTE,
					'paid_unexpected_status',
					sprintf(
						/* translators: 1: session id, 2: order status, 3: transaction hash or "?" */
						__( 'USDC payment session %1$s is paid (transaction %3$s), but the order status is "%2$s", so it was not changed. Please review the order.', 'finlinks-stablecoin-payments-for-edd' ),
						$id,
						(string) $ctx['order_status'],
						'' !== $tx ? $tx : '?'
					)
				);
			}
			$note = '' !== $tx
				/* translators: 1: session id, 2: transaction hash */
				? sprintf( __( 'USDC payment confirmed (session %1$s, transaction %2$s).', 'finlinks-stablecoin-payments-for-edd' ), $id, $tx )
				/* translators: %s: session id */
				: sprintf( __( 'USDC payment confirmed (session %s).', 'finlinks-stablecoin-payments-for-edd' ), $id );
			if ( 'cancelled' === $ctx['order_status'] ) {
				$note .= ' ' . __( 'The order had already been cancelled before the payment was confirmed; please check stock.', 'finlinks-stablecoin-payments-for-edd' );
			}
			if ( $overpaid ) {
				$note .= ' ' . sprintf(
					/* translators: 1: USDC received, 2: excess USDC */
					__( 'Overpaid: received %1$s USDC, excess %2$s USDC. Refund the excess from the FinLinks portal if needed.', 'finlinks-stablecoin-payments-for-edd' ),
					isset( $session['amountReceived'] ) ? (string) $session['amountReceived'] : '?',
					isset( $session['remainderAmount'] ) ? (string) $session['remainderAmount'] : '?'
				);
			}
			$out             = self::r( self::ACTION_COMPLETE, 'paid', $note );
			$out['tx_hash']  = $tx;
			$out['overpaid'] = $overpaid;
			return $out;
		}

		if ( 'open' === $status ) {
			// §6.2: a partial payment sends no event; the poll sees it as open with amountReceived > 0.
			// Record it once on the current session so the merchant knows money has arrived.
			$received = isset( $session['amountReceived'] ) ? Finlinks_Amount::normalize( $session['amountReceived'] ) : null;
			if ( null !== $received && '0' !== $received
				&& (string) $ctx['current_session_id'] === $id
				&& 'partial' !== ( isset( $ctx['last_status'] ) ? $ctx['last_status'] : '' ) ) {
				return self::r(
					self::ACTION_NOTE,
					'partial',
					sprintf(
						/* translators: 1: session id, 2: USDC received, 3: order amount */
						__( 'USDC payment session %1$s received a partial payment of %2$s USDC of %3$s. The buyer can still pay the rest before the session expires.', 'finlinks-stablecoin-payments-for-edd' ),
						$id,
						(string) $session['amountReceived'],
						isset( $session['amount'] ) ? (string) $session['amount'] : '?'
					)
				);
			}
			return self::r( self::ACTION_IGNORE, 'open' );
		}

		// Terminal non-paid states only matter for the order's current session.
		if ( (string) $ctx['current_session_id'] !== $id ) {
			return self::r( self::ACTION_IGNORE, 'stale_session' );
		}
		if ( isset( $ctx['last_status'] ) && $ctx['last_status'] === $status ) {
			return self::r( self::ACTION_IGNORE, 'unchanged' );
		}

		$order_status = (string) $ctx['order_status'];

		if ( 'failed' === $status ) {
			$note = sprintf(
				/* translators: 1: session id, 2: USDC received, 3: order amount */
				__( 'USDC payment session %1$s ended underpaid: received %2$s USDC of %3$s. Settle it manually from the FinLinks portal (refund or accept).', 'finlinks-stablecoin-payments-for-edd' ),
				$id,
				isset( $session['amountReceived'] ) ? (string) $session['amountReceived'] : '?',
				isset( $session['amount'] ) ? (string) $session['amount'] : '?'
			);
			if ( in_array( $order_status, array( 'pending', 'failed', 'cancelled' ), true ) ) {
				return self::r( self::ACTION_HOLD, 'failed', $note );
			}
			return self::r( self::ACTION_NOTE, 'failed', $note );
		}

		// expired | cancelled.
		$note   = 'expired' === $status
			/* translators: %s: session id */
			? sprintf( __( 'USDC payment session %s expired without payment.', 'finlinks-stablecoin-payments-for-edd' ), $id )
			/* translators: %s: session id */
			: sprintf( __( 'USDC payment session %s was cancelled without payment.', 'finlinks-stablecoin-payments-for-edd' ), $id );
		$action = isset( $ctx['unpaid_action'] ) ? $ctx['unpaid_action'] : self::UNPAID_NONE;
		if ( 'pending' === $order_status && self::UNPAID_CANCEL === $action ) {
			return self::r( self::ACTION_CANCEL, $status, $note );
		}
		if ( 'pending' === $order_status && self::UNPAID_FAIL === $action ) {
			return self::r( self::ACTION_FAIL, $status, $note );
		}
		return self::r( self::ACTION_NOTE, $status, $note );
	}

	/**
	 * Result helper.
	 *
	 * @param string $action Action.
	 * @param string $reason Reason / status recorded as last_status.
	 * @param string $note   Order note.
	 * @return array
	 */
	private static function r( $action, $reason, $note = '' ) {
		$out = array(
			'action' => $action,
			'reason' => $reason,
		);
		if ( '' !== $note ) {
			$out['note'] = $note;
		}
		return $out;
	}
}
