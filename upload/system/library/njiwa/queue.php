<?php
/**
 * The list of messages, and what happened to each one.
 *
 * OpenCart has no job runner, no queue and nowhere to hang a note on an order
 * that does not also change the order's status, so this table does three jobs
 * at once:
 *
 *  - it is the marker that stops a customer being messaged twice, because the
 *    unique key on order, event and recipient means the second attempt to
 *    write the row simply fails;
 *  - it is the queue, so the sending can happen after the shop has finished
 *    answering the customer;
 *  - it is the record the shop reads when it wonders what was sent.
 *
 * It survives uninstalling on purpose. A shop that removes the extension and
 * puts it back must not message everybody about orders they placed last month.
 */

class NjiwaQueue {

	/** After this many tries a message is left alone. Something is wrong that trying again will not fix. */
	const MAX_ATTEMPTS = 5;

	/** A message claimed but never finished, because PHP was killed mid-send, is free again after this. */
	const STALE_MINUTES = 10;

	/**
	 * How long a message that has already been tried waits before it is tried
	 * again. Njiwa being unreachable usually means a network or a service that
	 * needs a minute; trying five times in five seconds would spend every
	 * attempt on the same bad minute and give up while the shop was still
	 * fine. A message that has never been tried waits for nothing.
	 */
	const RETRY_MINUTES = 5;

	private $db;
	private $table;

	public function __construct($db) {
		$this->db = $db;
		$this->table = DB_PREFIX . 'njiwa_message';
	}

	public function createTable() {
		$this->db->query(
			"CREATE TABLE IF NOT EXISTS `" . $this->table . "` (
				`njiwa_message_id` int(11) NOT NULL AUTO_INCREMENT,
				`order_id` int(11) NOT NULL,
				`event` varchar(32) NOT NULL,
				`recipient` varchar(24) NOT NULL,
				`text` text NOT NULL,
				`status` varchar(16) NOT NULL,
				`attempts` int(3) NOT NULL DEFAULT '0',
				`njiwa_id` varchar(64) NOT NULL DEFAULT '',
				`note` varchar(255) NOT NULL DEFAULT '',
				`date_added` datetime NOT NULL,
				`date_modified` datetime NOT NULL,
				PRIMARY KEY (`njiwa_message_id`),
				UNIQUE KEY `order_event_recipient` (`order_id`, `event`, `recipient`),
				KEY `status` (`status`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci"
		);
	}

	public function tableExists() {
		$query = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($this->table) . "'");

		return (bool)$query->num_rows;
	}

	/**
	 * Claim this order, this event and this recipient, and write the finished
	 * message down.
	 *
	 * The wording is rendered now rather than when the message is sent,
	 * because it should say what was true at the moment it happened rather
	 * than what happens to be true a minute later.
	 *
	 * INSERT IGNORE is doing the work of a lock here: two requests that both
	 * decide to message the same customer about the same event race for the
	 * same unique key, and only one of them can win it.
	 *
	 * @return int The new row, or 0 if this message has already been claimed.
	 */
	public function add($order_id, $event, $recipient, $text) {
		$this->db->query(
			"INSERT IGNORE INTO `" . $this->table . "` SET
				`order_id` = '" . (int)$order_id . "',
				`event` = '" . $this->db->escape($event) . "',
				`recipient` = '" . $this->db->escape($recipient) . "',
				`text` = '" . $this->db->escape($text) . "',
				`status` = 'queued',
				`date_added` = NOW(),
				`date_modified` = NOW()"
		);

		if (!$this->db->countAffected()) {
			return 0;
		}

		return (int)$this->db->getLastId();
	}

	/**
	 * Take this message for sending, if nobody else has it.
	 *
	 * @return bool Whether it is ours to send.
	 */
	public function claim($id) {
		$this->db->query(
			"UPDATE `" . $this->table . "` SET
				`status` = 'sending',
				`attempts` = `attempts` + 1,
				`date_modified` = NOW()
			WHERE `njiwa_message_id` = '" . (int)$id . "'
				AND `attempts` < " . (int)self::MAX_ATTEMPTS . "
				AND " . $this->freeCondition()
		);

		return $this->db->countAffected() > 0;
	}

	public function markSent($id, $njiwa_id, $note = '') {
		$this->db->query(
			"UPDATE `" . $this->table . "` SET
				`status` = 'sent',
				`njiwa_id` = '" . $this->db->escape((string)$njiwa_id) . "',
				`note` = '" . $this->db->escape(substr($note, 0, 255)) . "',
				`date_modified` = NOW()
			WHERE `njiwa_message_id` = '" . (int)$id . "'"
		);
	}

	/**
	 * Njiwa has refused this message, or it has run out of tries. It stays in
	 * the table with the reason on it, and nothing sends it again.
	 */
	public function markFailed($id, $note) {
		$this->db->query(
			"UPDATE `" . $this->table . "` SET
				`status` = 'failed',
				`note` = '" . $this->db->escape(substr($note, 0, 255)) . "',
				`date_modified` = NOW()
			WHERE `njiwa_message_id` = '" . (int)$id . "'"
		);
	}

	/**
	 * Njiwa could not be reached, which is not the same as a refusal: the
	 * message was never accepted, so it goes back in the queue. What picks it
	 * up is the cron address if the shop runs one, and otherwise the next
	 * order status change anywhere in the shop, which sends whatever else is
	 * due along with its own messages.
	 */
	public function requeue($id, $note) {
		$this->db->query(
			"UPDATE `" . $this->table . "` SET
				`status` = 'queued',
				`note` = '" . $this->db->escape(substr($note, 0, 255)) . "',
				`date_modified` = NOW()
			WHERE `njiwa_message_id` = '" . (int)$id . "'"
		);
	}

	/**
	 * @return array
	 */
	public function get($id) {
		$query = $this->db->query("SELECT * FROM `" . $this->table . "` WHERE `njiwa_message_id` = '" . (int)$id . "'");

		return $query->row;
	}

	/**
	 * Everything waiting that is due another attempt, oldest first.
	 *
	 * A message that has been tried once is held back for a few minutes, so a
	 * shop whose status changes come in a rush does not spend all five
	 * attempts on the same outage.
	 *
	 * @return array
	 */
	public function pending($limit = 50) {
		$query = $this->db->query(
			"SELECT * FROM `" . $this->table . "`
			WHERE `attempts` < " . (int)self::MAX_ATTEMPTS . "
				AND " . $this->freeCondition() . "
				AND (`attempts` = '0' OR `date_modified` < DATE_SUB(NOW(), INTERVAL " . (int)self::RETRY_MINUTES . " MINUTE))
			ORDER BY `njiwa_message_id` ASC
			LIMIT " . (int)$limit
		);

		return $query->rows;
	}

	/**
	 * How many messages have not been sent and have not been given up on.
	 *
	 * This counts a message that is waiting out its few minutes before the
	 * next attempt as well, because from the shop's side it is still waiting.
	 */
	public function countPending() {
		$query = $this->db->query(
			"SELECT COUNT(*) AS total FROM `" . $this->table . "`
			WHERE `attempts` < " . (int)self::MAX_ATTEMPTS . " AND " . $this->freeCondition()
		);

		return isset($query->row['total']) ? (int)$query->row['total'] : 0;
	}

	/**
	 * A message nobody is sending: either waiting, or claimed by a request
	 * that died before it finished.
	 */
	private function freeCondition() {
		return "(`status` = 'queued' OR (`status` = 'sending' AND `date_modified` < DATE_SUB(NOW(), INTERVAL " . (int)self::STALE_MINUTES . " MINUTE)))";
	}
}
