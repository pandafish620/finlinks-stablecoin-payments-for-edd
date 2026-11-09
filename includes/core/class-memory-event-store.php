<?php
/**
 * In-memory processed-event store for tests.
 *
 * @package Finlstpa\EDD
 */

namespace Finlstpa\EDD;

defined( 'ABSPATH' ) || exit;

/**
 * Process-local claim store. Not used for live payments.
 */
final class Memory_Event_Store implements Event_Claim_Store {

	/**
	 * Claimed event ids.
	 *
	 * @var array<string,bool>
	 */
	private $ids = array();

	/**
	 * Claim an event id.
	 *
	 * @param string $event_id Event id.
	 * @return bool
	 */
	public function claim( $event_id ) {
		if ( isset( $this->ids[ $event_id ] ) ) {
			return false;
		}
		$this->ids[ $event_id ] = true;
		return true;
	}

	/**
	 * Release an event id.
	 *
	 * @param string $event_id Event id.
	 * @return void
	 */
	public function release( $event_id ) {
		unset( $this->ids[ $event_id ] );
	}
}
