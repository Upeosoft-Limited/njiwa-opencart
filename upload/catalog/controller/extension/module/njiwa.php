<?php
/**
 * The storefront half: the one place an order's status genuinely changes, and
 * the address a cron job can call.
 *
 * The event this answers is registered in the oc_event table when the
 * extension is installed:
 *
 *   trigger  catalog/model/checkout/order/addOrderHistory/after
 *   action   extension/module/njiwa/order
 *
 * addOrderHistory is where every payment extension, every admin status change
 * and every API call ends up, and it is the only place in OpenCart where an
 * order really moves from one status to another. Watching anything else means
 * either missing orders or messaging people who only had their address edited.
 */
class ControllerExtensionModuleNjiwa extends Controller {

	/**
	 * Njiwa has nothing to draw on a page. It appears in the module list all
	 * the same, so if somebody adds it to a layout it draws nothing rather
	 * than breaking the page it was added to.
	 */
	public function index() {
		return '';
	}

	/**
	 * An order has just been given a status.
	 *
	 * The arguments are the ones addOrderHistory was called with, but the
	 * status actually written can differ from the status asked for: an
	 * anti-fraud extension can change it on the way through. So this reads the
	 * order back and believes the order rather than the request.
	 *
	 * Nothing here is allowed to throw. This runs inside somebody's checkout,
	 * and an order must never fail because a WhatsApp message could not be
	 * arranged.
	 *
	 * It also has to return nothing at all: OpenCart hands the first non-null
	 * answer from an event back to the caller as the model's own return value,
	 * and stops calling the extensions after it.
	 */
	public function order(&$route, &$args, &$output) {
		$library = DIR_SYSTEM . 'library/njiwa/njiwa.php';

		if (!is_file($library)) {
			return;
		}

		require_once $library;

		try {
			$order_id = isset($args[0]) ? (int)$args[0] : 0;

			if ($order_id < 1) {
				return;
			}

			$notifier = new NjiwaNotifier($this->registry);

			if (!$notifier->isOn()) {
				return;
			}

			$this->load->model('checkout/order');

			$order_info = $this->model_checkout_order->getOrder($order_id);

			if (!$order_info) {
				return;
			}

			$queued = $notifier->orderEvent($order_info, $this->model_checkout_order->getOrderProducts($order_id));

			$notifier->deliverAfterResponse($queued);
		} catch (Throwable $e) {
			NjiwaLog::write('Order ' . (isset($order_id) ? $order_id : '?') . ': could not work out what to send. ' . $e->getMessage());
		}
	}

	/**
	 * Send whatever is still waiting.
	 *
	 * A shop with a real cron job can call this every minute and take sending
	 * off the request entirely. Everyone else can leave it alone: it is also
	 * the safety net that picks up a message Njiwa could not be reached for
	 * the first time.
	 *
	 * The token is a secret, so this address is not something a passer-by can
	 * make work by guessing.
	 */
	public function run() {
		$library = DIR_SYSTEM . 'library/njiwa/njiwa.php';

		if (!is_file($library)) {
			return;
		}

		require_once $library;

		$this->response->addHeader('Content-Type: text/plain; charset=utf-8');

		$expected = (string)$this->config->get('module_njiwa_cron_token');
		$given = isset($this->request->get['token']) ? (string)$this->request->get['token'] : '';

		if ($expected === '' || !hash_equals($expected, $given)) {
			$protocol = isset($this->request->server['SERVER_PROTOCOL']) ? $this->request->server['SERVER_PROTOCOL'] : 'HTTP/1.1';

			$this->response->addHeader($protocol . ' 403 Forbidden');
			$this->response->setOutput("Njiwa: wrong or missing token.\n");

			return;
		}

		try {
			$notifier = new NjiwaNotifier($this->registry);

			$done = $notifier->drain(100);

			$this->response->setOutput('Njiwa: ' . $done['sent'] . " sent, " . $done['failed'] . " not sent.\n");
		} catch (Throwable $e) {
			NjiwaLog::write('The cron address could not run: ' . $e->getMessage());

			$this->response->setOutput("Njiwa: could not run. There is more in storage/logs/njiwa.log.\n");
		}
	}
}
