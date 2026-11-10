<?php
/**
 * Processed webhook event ids (contract §7.3 step 3).
 *
 * @package Finlstpa\EDD
 */

namespace Finlstpa\EDD;

defined( 'ABSPATH' ) || exit;

interface Event_Claim_Store {

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
