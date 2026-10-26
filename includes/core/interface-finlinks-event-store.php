<?php
/**
 * Processed webhook event ids (contract §7.3 step 3).
 *
 * @package FinLinks\EDD
 */

namespace FinLinks\EDD;

defined( 'ABSPATH' ) || exit;

interface Finlinks_Event_Store {

	/**
	 * Atomically claim an event id. Returns false if it was already claimed.
	 *
	 * @param string $event_id Finlinks-Event-Id.
	 * @return bool
	 */
	public function claim( $event_id );

	/**
	 * Release a claim so a retry can process the event again.
	 *
	 * @param string $event_id Finlinks-Event-Id.
	 * @return void
	 */
	public function release( $event_id );
}

/**
 * In-memory store, used by unit tests.
 */
final class Finlinks_Memory_Event_Store implements Finlinks_Event_Store {

	/** @var array<string,bool> */
	private $ids = array();

	public function claim( $event_id ) {
		if ( isset( $this->ids[ $event_id ] ) ) {
			return false;
		}
		$this->ids[ $event_id ] = true;
		return true;
	}

	public function release( $event_id ) {
		unset( $this->ids[ $event_id ] );
	}
}
