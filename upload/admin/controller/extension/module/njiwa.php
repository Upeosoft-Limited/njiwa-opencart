<?php
/**
 * Extensions, Modules, Njiwa WhatsApp.
 *
 * Everything a shop needs to set up is on this one page, and every field
 * explains itself. A setting whose meaning has to be looked up somewhere else
 * is a setting people get wrong.
 */

require_once DIR_SYSTEM . 'library/njiwa/njiwa.php';

class ControllerExtensionModuleNjiwa extends Controller {

	/** The row this extension owns in the oc_event table. */
	const EVENT_CODE = 'njiwa_order';

	/**
	 * addOrderHistory is the one place in OpenCart where an order's status
	 * genuinely changes, whether the change came from a payment extension, the
	 * order screen or the API. The "catalog/" in front says which half of
	 * OpenCart the event belongs to; the storefront strips it when it reads
	 * the row back.
	 */
	const EVENT_TRIGGER = 'catalog/model/checkout/order/addOrderHistory/after';
	const EVENT_ACTION = 'extension/module/njiwa/order';

	/** What the Events page in OpenCart 3.0.2 and newer shows against the row. */
	const EVENT_DESCRIPTION = 'Njiwa WhatsApp: messages the customer when an order changes status.';

	/** How long somebody has to wait between test messages. */
	const TEST_INTERVAL = 30;

	private $error = array();

	public function index() {
		$this->load->language('extension/module/njiwa');
		$this->load->model('setting/setting');
		$this->load->model('localisation/order_status');

		$this->document->setTitle($this->language->get('heading_title'));

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('module_njiwa', $this->settings());

			// A shop that copied new files over an old version, or that lost
			// the event row somewhere, gets both put right by saving.
			$this->repair();

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
		}

		$data = array();

		foreach (array('warning', 'url', 'from', 'country_code', 'alert_numbers', 'status_clash') as $key) {
			$data['error_' . $key] = isset($this->error[$key]) ? $this->error[$key] : '';
		}

		$data['breadcrumbs'] = array(
			array(
				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
			),
			array(
				'text' => $this->language->get('text_extension'),
				'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
			),
			array(
				'text' => $this->language->get('heading_title'),
				'href' => $this->url->link('extension/module/njiwa', 'user_token=' . $this->session->data['user_token'], true)
			)
		);

		$data['action'] = $this->url->link('extension/module/njiwa', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

		// The two buttons build their own addresses in JavaScript, because a
		// link built here arrives with its ampersands escaped for HTML and
		// would lose everything after the first one.
		$data['user_token'] = $this->session->data['user_token'];

		$data['module_njiwa_status'] = (int)$this->value('module_njiwa_status', 0);
		$data['module_njiwa_api_key'] = $this->value('module_njiwa_api_key', '');
		$data['module_njiwa_url'] = $this->value('module_njiwa_url', NjiwaClient::DEFAULT_BASE_URL);
		$data['module_njiwa_from'] = $this->value('module_njiwa_from', '');
		$data['module_njiwa_country_code'] = $this->value('module_njiwa_country_code', '');
		$data['module_njiwa_send_mode'] = $this->value('module_njiwa_send_mode', 'response');
		$data['module_njiwa_event_alert'] = (int)$this->value('module_njiwa_event_alert', 0);
		$data['module_njiwa_alert_numbers'] = $this->value('module_njiwa_alert_numbers', '');
		$data['module_njiwa_template_alert'] = $this->value('module_njiwa_template_alert', NjiwaTemplates::defaultFor('alert'));

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		// The moments, each with its own switch, its own status and its own
		// wording, built from the list the sending code works from so the page
		// cannot offer something that is never read.
		$data['events'] = array();

		foreach (NjiwaNotifier::customerEvents() as $event) {
			$data['events'][] = array(
				'key' => $event,
				'name' => $this->language->get('event_' . $event),
				'about' => $this->language->get('about_' . $event),
				'enabled' => (int)$this->value('module_njiwa_event_' . $event, 0),
				'order_status_id' => (int)$this->value('module_njiwa_status_' . $event, 0),
				'template' => $this->value('module_njiwa_template_' . $event, NjiwaTemplates::defaultFor($event)),
				'error' => isset($this->error['status_' . $event]) ? $this->error['status_' . $event] : ''
			);
		}

		// The placeholder list, built from the code that does the replacing,
		// so the two cannot drift apart.
		$meanings = $this->language->get('njiwa_placeholder');
		$data['placeholders'] = array();

		foreach (NjiwaTemplates::tokens() as $token) {
			$data['placeholders'][] = array(
				'token' => $token,
				'meaning' => isset($meanings[$token]) ? $meanings[$token] : ''
			);
		}

		$data += $this->state();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/njiwa', $data));
	}

	/**
	 * What is true about this installation right now, in plain words. It is
	 * the first thing to look at when a message did not arrive.
	 *
	 * @return array
	 */
	private function state() {
		$queue = new NjiwaQueue($this->db);

		$state = array(
			'event_registered' => $this->eventRegistered(),
			'table_exists' => $queue->tableExists(),
			'cron_url' => ''
		);

		$waiting = $state['table_exists'] ? $queue->countPending() : 0;

		$state['text_waiting'] = sprintf($this->language->get('text_waiting'), $waiting);
		$state['text_cron_url'] = sprintf($this->language->get('text_cron_url'), $this->language->get('text_send_cron'));

		$token = (string)$this->config->get('module_njiwa_cron_token');

		if ($token !== '' && defined('HTTP_CATALOG')) {
			$state['cron_url'] = HTTP_CATALOG . 'index.php?route=extension/module/njiwa/run&token=' . $token;
		}

		return $state;
	}

	/**
	 * Add the event row and the message table if they are not there.
	 *
	 * Both are safe to run again: the table is created only if missing, and
	 * the event row is looked up by the code this extension owns.
	 */
	private function repair() {
		$queue = new NjiwaQueue($this->db);
		$queue->createTable();

		if (!$this->eventRegistered()) {
			$this->installEvent();
		}
	}

	/**
	 * Whether the row this extension listens through is in the event table.
	 *
	 * The obvious call is $this->model_setting_event->getEventByCode(), and it
	 * cannot be used: that method arrived in OpenCart 3.0.2.0, and a method a
	 * model does not have is not something PHP will let us catch. OpenCart
	 * hands every model out wrapped in Proxy, and Proxy::__call() ends in
	 * exit(), so on 3.0.0.x and 3.0.1.x the call would kill the request in the
	 * middle of installing: the message table would exist, the event row would
	 * never be written, and the extension would sit there looking installed
	 * and sending nothing. The event table itself is the same in every 3.0.x,
	 * so reading it directly is the one thing that works throughout.
	 */
	private function eventRegistered() {
		$query = $this->db->query(
			"SELECT `event_id` FROM `" . DB_PREFIX . "event`
			WHERE `code` = '" . $this->db->escape(self::EVENT_CODE) . "' LIMIT 1"
		);

		return (bool)$query->num_rows;
	}

	/**
	 * Write the event row, so the storefront hears about status changes.
	 *
	 * addEvent() is left alone for a second reason on top of the first: its
	 * argument list is not the same throughout 3.0.x, because a description
	 * was added between the code and the trigger, and calling it with the
	 * arguments in the other version's order would register an event that
	 * listens for nothing at all. Writing the row here means the columns are
	 * named rather than counted.
	 *
	 * The columns are read from the table rather than assumed, because the two
	 * that came later, `description` and `sort_order`, are declared NOT NULL
	 * with no default: naming one the table does not have, or leaving out one
	 * it does, is an error either way on a MySQL in strict mode.
	 */
	private function installEvent() {
		$present = array();
		$query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "event`");

		foreach ($query->rows as $column) {
			$present[] = $column['Field'];
		}

		$set = array(
			"`code` = '" . $this->db->escape(self::EVENT_CODE) . "'",
			"`trigger` = '" . $this->db->escape(self::EVENT_TRIGGER) . "'",
			"`action` = '" . $this->db->escape(self::EVENT_ACTION) . "'",

			// The storefront only registers events that are switched on.
			"`status` = '1'"
		);

		if (in_array('description', $present, true)) {
			$set[] = "`description` = '" . $this->db->escape(self::EVENT_DESCRIPTION) . "'";
		}

		if (in_array('sort_order', $present, true)) {
			$set[] = "`sort_order` = '0'";
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "event` SET " . implode(', ', $set));
	}

	/**
	 * Pressing the green install button on the Extensions page ends up here.
	 */
	public function install() {
		$this->load->model('setting/setting');

		$this->repair();

		// Defaults for the things that must have a value before anybody opens
		// the page. Anything already saved wins, so putting the extension back
		// after removing it does not throw away a key or a form of words.
		$existing = $this->model_setting_setting->getSetting('module_njiwa');

		$defaults = array(
			'module_njiwa_status' => 0,
			'module_njiwa_url' => NjiwaClient::DEFAULT_BASE_URL,
			'module_njiwa_send_mode' => 'response',
			'module_njiwa_cron_token' => $this->newToken()
		);

		$this->model_setting_setting->editSetting('module_njiwa', array_merge($defaults, $existing));
	}

	/**
	 * Removing the extension removes the key, because a live API key nobody is
	 * looking after any more is a key worth having taken away.
	 *
	 * The message table stays. It is the record of what was sent, and it is
	 * also what stops an extension that is put back tomorrow from messaging
	 * everybody about the orders it already handled.
	 */
	public function uninstall() {
		$this->load->model('setting/setting');

		// Taken out the way it was put in, by the code this extension owns.
		$this->db->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `code` = '" . $this->db->escape(self::EVENT_CODE) . "'");

		$this->model_setting_setting->deleteSetting('module_njiwa');
	}

	/**
	 * Who this key belongs to, and what it can send from. Sends nothing.
	 */
	public function test() {
		$this->load->language('extension/module/njiwa');

		$json = array();

		if (!$this->user->hasPermission('modify', 'extension/module/njiwa')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$client = NjiwaClient::fromConfig($this->config);

			try {
				$numbers = $client->numbers();
				$lines = array();

				if ($client->isTestKey()) {
					$lines[] = $this->language->get('text_test_key');
				}

				if (!$numbers) {
					$lines[] = $this->language->get('text_no_instances');
				} else {
					$listed = array();
					$known = array();

					foreach ($numbers as $number) {
						$msisdn = !empty($number['msisdn']) ? preg_replace('/\D/', '', $number['msisdn']) : '';

						if ($msisdn !== '') {
							$known[] = $msisdn;
						}

						$listed[] = htmlspecialchars(isset($number['label']) ? $number['label'] : '')
							. ' &mdash; ' . ($msisdn !== '' ? '+' . htmlspecialchars($msisdn) : $this->language->get('text_not_linked'))
							. ' (' . htmlspecialchars(isset($number['status']) ? $number['status'] : '') . ')';
					}

					$lines[] = $this->language->get('text_connected') . '<br>' . implode('<br>', $listed);

					// A sending number that is not on the account refuses every
					// message, one at a time, for ever. Better to know now.
					if ($client->getFrom() !== '' && !in_array($client->getFrom(), $known, true)) {
						$lines[] = $this->language->get('text_from_unknown');
					}
				}

				$json['success'] = implode('<br><br>', $lines);
			} catch (NjiwaException $e) {
				$json['error'] = htmlspecialchars($e->getMessage());
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * One real message, to the shop's own number, with wording that is fixed
	 * here in the code: whoever presses this chooses the recipient from their
	 * own saved numbers and nothing else.
	 */
	public function send() {
		$this->load->language('extension/module/njiwa');

		$json = array();

		if ($this->request->server['REQUEST_METHOD'] != 'POST') {
			$json['error'] = $this->language->get('error_permission');
		} elseif (!$this->user->hasPermission('modify', 'extension/module/njiwa')) {
			$json['error'] = $this->language->get('error_permission');
		} elseif (isset($this->session->data['njiwa_last_test']) && (time() - $this->session->data['njiwa_last_test']) < self::TEST_INTERVAL) {
			// A button that sends a real WhatsApp message is a button somebody
			// will lean on.
			$json['error'] = $this->language->get('error_test_too_soon');
		} else {
			$numbers = NjiwaNumbers::parseList($this->config->get('module_njiwa_alert_numbers'));

			if (!$numbers) {
				$json['error'] = $this->language->get('error_test_numbers');
			} else {
				$client = NjiwaClient::fromConfig($this->config);

				try {
					$this->session->data['njiwa_last_test'] = time();

					$answer = $client->sendText(
						$numbers[0],
						sprintf($this->language->get('text_test_message'), $this->config->get('config_name'))
					);

					$message = sprintf(
						$this->language->get('text_test_sent'),
						htmlspecialchars($numbers[0]),
						htmlspecialchars(isset($answer['id']) ? $answer['id'] : '?')
					);

					if ($client->isTestKey()) {
						$message .= ' ' . $this->language->get('text_test_was_test_key');
					}

					$json['success'] = $message;
				} catch (NjiwaException $e) {
					$json['error'] = htmlspecialchars($e->getMessage());
				}
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * What goes into the settings table, cleaned up on the way in.
	 *
	 * @return array
	 */
	private function settings() {
		$post = $this->request->post;

		$mode = isset($post['module_njiwa_send_mode']) ? $post['module_njiwa_send_mode'] : 'response';

		$settings = array(
			'module_njiwa_status' => isset($post['module_njiwa_status']) ? (int)$post['module_njiwa_status'] : 0,
			'module_njiwa_api_key' => isset($post['module_njiwa_api_key']) ? trim($post['module_njiwa_api_key']) : '',
			'module_njiwa_url' => isset($post['module_njiwa_url']) ? rtrim(trim($post['module_njiwa_url']), '/') : NjiwaClient::DEFAULT_BASE_URL,
			'module_njiwa_from' => isset($post['module_njiwa_from']) ? preg_replace('/\D/', '', $post['module_njiwa_from']) : '',
			'module_njiwa_country_code' => isset($post['module_njiwa_country_code']) ? preg_replace('/\D/', '', $post['module_njiwa_country_code']) : '',
			'module_njiwa_send_mode' => $mode === 'cron' ? 'cron' : 'response',
			'module_njiwa_event_alert' => isset($post['module_njiwa_event_alert']) ? (int)$post['module_njiwa_event_alert'] : 0,
			'module_njiwa_alert_numbers' => isset($post['module_njiwa_alert_numbers']) ? trim($post['module_njiwa_alert_numbers']) : '',
			'module_njiwa_template_alert' => isset($post['module_njiwa_template_alert']) ? trim($post['module_njiwa_template_alert']) : '',

			// The storefront cannot work out where this dashboard lives,
			// because an OpenCart admin folder is usually renamed and nothing
			// out there knows the new name. So it is written down here, every
			// time, and {admin_url} reads it back.
			'module_njiwa_admin_url' => $this->adminUrl(),

			// Generated once and kept, so the cron address a shop has already
			// put in its crontab keeps working.
			'module_njiwa_cron_token' => $this->cronToken()
		);

		foreach (NjiwaNotifier::customerEvents() as $event) {
			$settings['module_njiwa_event_' . $event] = isset($post['module_njiwa_event_' . $event]) ? (int)$post['module_njiwa_event_' . $event] : 0;
			$settings['module_njiwa_status_' . $event] = isset($post['module_njiwa_status_' . $event]) ? (int)$post['module_njiwa_status_' . $event] : 0;
			$settings['module_njiwa_template_' . $event] = isset($post['module_njiwa_template_' . $event]) ? trim($post['module_njiwa_template_' . $event]) : '';
		}

		return $settings;
	}

	private function validate() {
		if (!$this->user->hasPermission('modify', 'extension/module/njiwa')) {
			$this->error['warning'] = $this->language->get('error_permission');

			return false;
		}

		$post = $this->request->post;

		if (!isset($post['module_njiwa_url']) || trim($post['module_njiwa_url']) === '') {
			$this->error['url'] = $this->language->get('error_url');
		}

		// The sending number is the one place a leading zero cannot be
		// forgiven: the country it belongs to really is ambiguous, and Njiwa
		// has nothing to read it against.
		$from = isset($post['module_njiwa_from']) ? preg_replace('/\D/', '', $post['module_njiwa_from']) : '';

		if ($from !== '' && !preg_match('/^[1-9][0-9]{6,14}$/', $from)) {
			$this->error['from'] = $this->language->get('error_from');
		}

		$code = isset($post['module_njiwa_country_code']) ? trim($post['module_njiwa_country_code']) : '';

		if ($code !== '' && !preg_match('/^[0-9]{1,4}$/', $code)) {
			$this->error['country_code'] = $this->language->get('error_country_code');
		}

		$chosen = array();

		foreach (NjiwaNotifier::customerEvents() as $event) {
			if (empty($post['module_njiwa_event_' . $event])) {
				continue;
			}

			$status_id = isset($post['module_njiwa_status_' . $event]) ? (int)$post['module_njiwa_status_' . $event] : 0;

			if ($status_id < 1) {
				$this->error['status_' . $event] = $this->language->get('error_no_status');

				continue;
			}

			// Two moments on one status would send the same customer two
			// messages at the same instant, which reads as a fault in the shop.
			if (in_array($status_id, $chosen, true)) {
				$this->error['status_clash'] = $this->language->get('error_status_clash');
			}

			$chosen[] = $status_id;
		}

		if (!empty($post['module_njiwa_event_alert'])) {
			$numbers = NjiwaNumbers::parseList(isset($post['module_njiwa_alert_numbers']) ? $post['module_njiwa_alert_numbers'] : '');

			if (!$numbers) {
				$this->error['alert_numbers'] = $this->language->get('error_alert_numbers');
			}
		}

		return !$this->error;
	}

	/**
	 * A posted value first, then what is saved, then what this extension
	 * ships with. Posting comes first so a page that failed to save gives
	 * somebody back what they typed.
	 */
	private function value($key, $default) {
		if (isset($this->request->post[$key])) {
			return $this->request->post[$key];
		}

		$saved = $this->config->get($key);

		return $saved === null ? $default : $saved;
	}

	private function adminUrl() {
		if (!empty($this->request->server['HTTPS']) && defined('HTTPS_SERVER')) {
			return HTTPS_SERVER;
		}

		return defined('HTTP_SERVER') ? HTTP_SERVER : '';
	}

	private function cronToken() {
		$token = (string)$this->config->get('module_njiwa_cron_token');

		return $token !== '' ? $token : $this->newToken();
	}

	private function newToken() {
		if (function_exists('random_bytes')) {
			return bin2hex(random_bytes(16));
		}

		return md5(uniqid(mt_rand(), true));
	}
}
