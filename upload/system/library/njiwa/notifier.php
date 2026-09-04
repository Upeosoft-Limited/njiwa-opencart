<?php
/**
 * Who hears about what, and when.
 *
 * One rule runs the whole extension: an order reaching the status a merchant
 * has chosen for one of the moments below sends that message, once. Nothing
 * is sent while the customer is waiting at the checkout, and nothing that
 * fails here is ever allowed to break an order.
 *
 * OpenCart order statuses are made up by each shop and stored as numbers, so
 * there is nothing to hardcode: the merchant says which of their statuses
 * means "paid" and which means "on its way", and this reads that back.
 */

class NjiwaNotifier {

	/** The moments a customer hears about, in the order the settings page lists them. */
	public static function customerEvents() {
		return array('placed', 'paid', 'shipped', 'cancelled', 'refunded');
	}

	/** The one message that goes to the shop rather than the customer. */
	const EVENT_ALERT = 'alert';

	private $registry;
	private $config;
	private $db;
	private $queue;
	private $client;
	private $templates;

	public function __construct($registry) {
		$this->registry = $registry;
		$this->config = $registry->get('config');
		$this->db = $registry->get('db');
		$this->queue = new NjiwaQueue($this->db);
		$this->client = NjiwaClient::fromConfig($this->config);
		$this->templates = new NjiwaTemplates($registry);
	}

	/**
	 * The master switch, and whether there is a key to send with. Off keeps
	 * every setting and sends nothing.
	 */
	public function isOn() {
		return (bool)$this->config->get('module_njiwa_status') && $this->client->isConfigured();
	}

	public function getQueue() {
		return $this->queue;
	}

	public function getClient() {
		return $this->client;
	}

	/**
	 * An order has just arrived at a status. Work out what, if anything, that
	 * means for WhatsApp, and write the messages down.
	 *
	 * @param array $order_info As catalog/model/checkout/order getOrder returns it.
	 * @param array $products   As getOrderProducts returns them.
	 *
	 * @return array The ids of the messages this queued.
	 */
	public function orderEvent(array $order_info, array $products) {
		$status_id = isset($order_info['order_status_id']) ? (int)$order_info['order_status_id'] : 0;

		// Status 0 is an order OpenCart wrote down while the customer was
		// still on the checkout. It is not an order yet; it is somebody who
		// reached the payment page and may never come back.
		if ($status_id < 1) {
			return array();
		}

		$queued = $this->tellTheCustomer($order_info, $products, $status_id);

		return array_merge($queued, $this->tellTheShop($order_info, $products));
	}

	/**
	 * @return array
	 */
	private function tellTheCustomer(array $order_info, array $products, $status_id) {
		$queued = array();

		foreach (self::customerEvents() as $event) {
			if (!$this->config->get('module_njiwa_event_' . $event)) {
				continue;
			}

			if ((int)$this->config->get('module_njiwa_status_' . $event) !== $status_id) {
				continue;
			}

			$number = NjiwaNumbers::toMsisdn(
				isset($order_info['telephone']) ? $order_info['telephone'] : '',
				$this->config->get('module_njiwa_country_code')
			);

			if ($number === '') {
				// A customer without a usable phone number is ordinary, not an
				// error. Nothing is sent and nobody is woken up about it.
				NjiwaLog::write('Order ' . (int)$order_info['order_id'] . ' has no usable phone number, so the "' . $event . '" message was not sent.');
				continue;
			}

			$id = $this->write($order_info, $products, $event, $number);

			if ($id) {
				$queued[] = $id;
			}
		}

		return $queued;
	}

	/**
	 * The shop hears once per order, on the first status that means the order
	 * is real. Which status that is depends on how the shop is paid, so there
	 * is nothing to choose: the first status an order is ever given is the
	 * moment it stopped being an abandoned checkout.
	 *
	 * @return array
	 */
	private function tellTheShop(array $order_info, array $products) {
		if (!$this->config->get('module_njiwa_event_alert')) {
			return array();
		}

		if (!$this->isFirstStatus((int)$order_info['order_id'])) {
			return array();
		}

		$numbers = NjiwaNumbers::parseList($this->config->get('module_njiwa_alert_numbers'));

		if (!$numbers) {
			return array();
		}

		$queued = array();

		foreach ($numbers as $number) {
			$id = $this->write($order_info, $products, self::EVENT_ALERT, $number);

			if ($id) {
				$queued[] = $id;
			}
		}

		return $queued;
	}

	/**
	 * Whether this is the first status this order has ever had.
	 *
	 * OpenCart writes a history row every time an order's status changes, and
	 * by the time this runs the row for the change that brought us here is
	 * already there. One row means the order has only just become real, which
	 * is the moment worth hearing about.
	 *
	 * Without this, a shop switching the alert on today and then marking a
	 * week-old order as sent would be told it was a new order.
	 */
	private function isFirstStatus($order_id) {
		$query = $this->db->query(
			"SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "order_history`
			WHERE `order_id` = '" . (int)$order_id . "' AND `order_status_id` > '0'"
		);

		return isset($query->row['total']) && (int)$query->row['total'] <= 1;
	}

	/**
	 * @return int The queued message, or 0 if there was nothing to send or it
	 *             had been sent already.
	 */
	private function write(array $order_info, array $products, $event, $number) {
		$template = $this->config->get('module_njiwa_template_' . $event);

		// A template that has never been saved falls back to the wording that
		// ships with the extension, so a shop that ticked an event and never
		// opened the box still has something to send. A template the merchant
		// has deliberately emptied is how one message is switched off without
		// switching off the event, and that stays empty.
		if ($template === null) {
			$template = NjiwaTemplates::defaultFor($event);
		}

		$text = $this->templates->render($template, $order_info, $products);

		if ($text === '') {
			return 0;
		}

		return $this->queue->add((int)$order_info['order_id'], $event, $number, $text);
	}

	/**
	 * Send these once the shop has finished answering whoever is waiting, and
	 * anything else the queue has waiting with them.
	 *
	 * On PHP-FPM, which is what most hosting runs, fastcgi_finish_request
	 * hands the response back and lets this carry on afterwards, so the
	 * customer waits for nothing. Everywhere else the send happens at the very
	 * end of the same request, after the page is built and the order is
	 * already saved: it can delay a page, but it cannot lose an order, and a
	 * shop that does not want even that can set sending to cron only.
	 */
	public function deliverAfterResponse(array $ids) {
		if ($this->config->get('module_njiwa_send_mode') === 'cron') {
			return;
		}

		$notifier = $this;

		register_shutdown_function(function () use ($notifier, $ids) {
			// Whoever asked for the page has it by now, and if they have
			// closed the tab this still finishes.
			@ignore_user_abort(true);

			if (function_exists('fastcgi_finish_request')) {
				@fastcgi_finish_request();
			}

			try {
				$notifier->deliver(array_unique(array_merge($ids, $notifier->due())));
			} catch (Throwable $e) {
				NjiwaLog::write('Sending after the response failed: ' . $e->getMessage());
			}
		});
	}

	/**
	 * Messages already in the queue that are due another attempt.
	 *
	 * A shop that has not set up a cron job has nothing else that ever reads
	 * the queue: a message Njiwa could not be reached for goes back to
	 * 'queued', and without this it would sit there for ever and the customer
	 * would simply never be messaged. An order changing status is the one
	 * thing that happens in every shop, so it is what moves the queue along
	 * when nothing else does.
	 *
	 * The number taken at a time is small on purpose. On hosting without
	 * PHP-FPM this runs at the end of somebody's request, and a shop that has
	 * been unable to reach Njiwa for an hour must not work through the backlog
	 * on one customer's page. The rest waits for the next status change, or
	 * for the cron address if the shop runs one.
	 *
	 * @return array
	 */
	public function due($limit = 5) {
		$ids = array();

		foreach ($this->queue->pending($limit) as $row) {
			$ids[] = (int)$row['njiwa_message_id'];
		}

		return $ids;
	}

	/**
	 * Send the messages with these ids, skipping any that somebody else has
	 * already taken.
	 *
	 * @return array Counts, as sent and failed.
	 */
	public function deliver(array $ids) {
		$done = array('sent' => 0, 'failed' => 0);

		if (!$this->isOn()) {
			// Not a silent no-op: a shop with messages waiting and the switch
			// off should be able to find out why nothing arrived.
			NjiwaLog::write(
				'Njiwa is switched off or has no API key, so ' . count($ids) . ' message(s) were not sent.'
			);

			return $done;
		}

		foreach ($ids as $id) {
			if (!$this->queue->claim($id)) {
				continue;
			}

			$row = $this->queue->get($id);

			if (!$row) {
				continue;
			}

			if ($this->sendOne($row)) {
				$done['sent']++;
			} else {
				$done['failed']++;
			}
		}

		return $done;
	}

	/**
	 * Everything still waiting, whenever something gets round to it. This is
	 * what the cron address runs, and it works through the queue in one go
	 * rather than a few at a time, because nobody is waiting on it.
	 *
	 * @return array
	 */
	public function drain($limit = 50) {
		$ids = array();

		foreach ($this->queue->pending($limit) as $row) {
			$ids[] = (int)$row['njiwa_message_id'];
		}

		return $this->deliver($ids);
	}

	/**
	 * @return bool Whether it went.
	 */
	private function sendOne(array $row) {
		$id = (int)$row['njiwa_message_id'];
		$attempts = (int)$row['attempts'] + 1;

		try {
			$answer = $this->client->sendText($row['recipient'], $row['text'], $this->idempotencyKey($row));

			$njiwa_id = isset($answer['id']) ? $answer['id'] : '';
			$note = $this->client->isTestKey() ? 'Test key, so nothing reached WhatsApp.' : '';

			$this->queue->markSent($id, $njiwa_id, $note);

			NjiwaLog::write(
				'Order ' . (int)$row['order_id'] . ', ' . $row['event'] . ': sent to +' . $row['recipient']
				. ' (' . ($njiwa_id !== '' ? $njiwa_id : '?') . ').' . ($note !== '' ? ' ' . $note : '')
			);

			return true;
		} catch (NjiwaException $e) {
			$note = $e->getMessage() . ' (' . $e->getErrorCode() . ')';

			if ($e->isWorthRetrying() && $attempts < NjiwaQueue::MAX_ATTEMPTS) {
				$this->queue->requeue($id, $note);
			} else {
				$this->queue->markFailed($id, $note);
			}

			NjiwaLog::write(
				'Order ' . (int)$row['order_id'] . ', ' . $row['event'] . ': could not message +' . $row['recipient']
				. '. ' . $note . ' Attempt ' . $attempts . ' of ' . NjiwaQueue::MAX_ATTEMPTS . '.'
			);

			return false;
		}
	}

	/**
	 * One key per order, event and recipient.
	 *
	 * Njiwa honours it for 24 hours, so a send that runs twice, or a retry
	 * after a timeout that actually arrived, replays the first answer instead
	 * of messaging the customer again. The recipient is part of the key
	 * because one alert can go to several of your own numbers, and they must
	 * not collapse into one another.
	 *
	 * The shop is in the key so that two OpenCart installations sharing one
	 * Njiwa account cannot claim each other's order numbers.
	 */
	private function idempotencyKey(array $row) {
		$shop = substr(md5(DB_DATABASE . DB_PREFIX), 0, 8);

		return 'oc-' . $shop . '-' . (int)$row['order_id'] . '-' . $row['event'] . '-' . substr(md5($row['recipient']), 0, 6);
	}
}
