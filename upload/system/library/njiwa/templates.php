<?php
/**
 * The message itself.
 *
 * A template is plain text with placeholders in braces. Every placeholder a
 * shop can use is listed in tokens() below, and that same list is what the
 * settings page prints, so the documentation on the page cannot drift from
 * the code that does the replacing.
 */

class NjiwaTemplates {

	/** WhatsApp takes 4096 characters. Stopping short leaves room for a footer. */
	const MAX_LENGTH = 4000;

	/** How many order lines {items} prints before it starts counting instead. */
	const MAX_ITEMS = 10;

	/** What {first_name} says when an order somehow has no name on it. */
	const NO_NAME = 'there';

	private $registry;

	public function __construct($registry) {
		$this->registry = $registry;
	}

	/**
	 * Every placeholder, in the order the settings page should list them. The
	 * wording that explains each one lives in the admin language file, keyed
	 * by the token, so it can be translated.
	 *
	 * @return array
	 */
	public static function tokens() {
		return array(
			'{first_name}',
			'{last_name}',
			'{customer_name}',
			'{order_number}',
			'{order_total}',
			'{order_date}',
			'{order_status}',
			'{payment_method}',
			'{items}',
			'{item_count}',
			'{shop_name}',
			'{order_url}',
			'{admin_url}'
		);
	}

	/**
	 * What each message says before anybody edits it.
	 *
	 * These live here rather than on the settings page because the code that
	 * sends a message never loads the admin, and a shop that has saved the
	 * settings page exactly zero times must still send something sensible.
	 *
	 * They are deliberately short. A WhatsApp message that reads like an email
	 * gets read like an email, which is to say not at all.
	 *
	 * @return string
	 */
	public static function defaultFor($event) {
		$defaults = array(
			'placed' => "Hi {first_name}, we have your order {order_number} for {order_total}. We will let you know the moment your payment comes through.\n\n{shop_name}",
			'paid' => "Hi {first_name}, thank you. Your payment for order {order_number} came through and we are getting it ready.\n\n{items}\n\nTotal {order_total}\n{shop_name}",
			'shipped' => "Hi {first_name}, order {order_number} is on its way to you. Thank you for shopping with {shop_name}.",
			'cancelled' => "Hi {first_name}, order {order_number} has been cancelled and you have not been charged. If that was not you, reply to this message and we will look into it.\n\n{shop_name}",
			'refunded' => "Hi {first_name}, we have refunded {order_total} for order {order_number}. Banks take a few days to show it.\n\n{shop_name}",
			'alert' => "New order {order_number} on {shop_name}.\n\n{customer_name}\n{item_count} item(s), {order_total}\nPaid by {payment_method}\n\n{admin_url}"
		);

		return isset($defaults[$event]) ? $defaults[$event] : '';
	}

	/**
	 * @param string $template   Raw template text.
	 * @param array  $order_info As catalog/model/checkout/order getOrder returns it.
	 * @param array  $products   As getOrderProducts returns them.
	 *
	 * @return string The message, or '' if the template is empty.
	 */
	public function render($template, array $order_info, array $products = array()) {
		$template = trim((string)$template);

		if ($template === '') {
			return '';
		}

		$message = strtr($template, $this->values($order_info, $products));

		// Anything still in braces is a placeholder that does not exist,
		// usually a typo. Sending "{order_no}" to a customer looks broken, so
		// it comes out and the shop is told where to look.
		if (preg_match_all('/\{[a-z_]+\}/', $message, $found)) {
			NjiwaLog::write(
				'Unknown placeholder ' . implode(', ', array_unique($found[0]))
				. ' in a message template. It was removed before sending.'
			);

			$message = preg_replace('/\{[a-z_]+\}/', '', $message);
		}

		$message = trim(preg_replace('/\n{3,}/', "\n\n", $message));

		if (function_exists('mb_strlen') && mb_strlen($message, 'UTF-8') > self::MAX_LENGTH) {
			$message = mb_substr($message, 0, self::MAX_LENGTH - 1, 'UTF-8') . "\xE2\x80\xA6";
		}

		return $message;
	}

	/**
	 * @return array
	 */
	private function values(array $order_info, array $products) {
		$first = isset($order_info['firstname']) ? trim($order_info['firstname']) : '';
		$last = isset($order_info['lastname']) ? trim($order_info['lastname']) : '';
		$order_id = isset($order_info['order_id']) ? (int)$order_info['order_id'] : 0;

		$count = 0;

		foreach ($products as $product) {
			$count += (int)$product['quantity'];
		}

		return array(
			'{first_name}' => $first !== '' ? $first : self::NO_NAME,
			'{last_name}' => $last,
			'{customer_name}' => trim($first . ' ' . $last),
			'{order_number}' => (string)$order_id,
			'{order_total}' => $this->total($order_info),
			'{order_date}' => $this->date($order_info),
			'{order_status}' => isset($order_info['order_status']) ? $order_info['order_status'] : '',
			'{payment_method}' => isset($order_info['payment_method']) ? $order_info['payment_method'] : '',
			'{items}' => $this->items($products),
			'{item_count}' => (string)$count,
			'{shop_name}' => $this->shopName($order_info),
			'{order_url}' => $this->orderUrl($order_info, $order_id),
			'{admin_url}' => $this->adminUrl($order_id)
		);
	}

	/**
	 * The total in the currency the customer actually paid in, which is not
	 * always the shop's own: OpenCart stores the rate on the order for exactly
	 * this reason, and quoting the number without it would name a price the
	 * customer never saw.
	 */
	private function total(array $order_info) {
		$total = isset($order_info['total']) ? $order_info['total'] : 0;
		$code = isset($order_info['currency_code']) ? $order_info['currency_code'] : '';
		$value = isset($order_info['currency_value']) ? $order_info['currency_value'] : 1;

		$currency = $this->registry->get('currency');

		if ($currency) {
			$formatted = $currency->format($total, $code, $value);
		} else {
			$formatted = trim($code . ' ' . number_format((float)$total * (float)$value, 2));
		}

		// Some shops keep their currency symbol as an HTML entity, which is
		// right on a web page and wrong in a WhatsApp message.
		return html_entity_decode(strip_tags($formatted), ENT_QUOTES, 'UTF-8');
	}

	private function date(array $order_info) {
		if (empty($order_info['date_added'])) {
			return '';
		}

		$language = $this->registry->get('language');
		$format = $language ? $language->get('date_format_short') : '';

		// A missing language key comes back as the key itself, which would
		// print "date_format_short" to a customer.
		if (!$format || $format === 'date_format_short') {
			$format = 'd/m/Y';
		}

		return date($format, strtotime($order_info['date_added']));
	}

	private function items(array $products) {
		$lines = array();
		$more = 0;

		foreach ($products as $product) {
			if (count($lines) >= self::MAX_ITEMS) {
				$more++;
				continue;
			}

			$lines[] = (int)$product['quantity'] . ' x ' . strip_tags($product['name']);
		}

		if ($more > 0) {
			$lines[] = 'and ' . $more . ($more === 1 ? ' more item' : ' more items');
		}

		return implode("\n", $lines);
	}

	private function shopName(array $order_info) {
		// The name of the store the order was placed in, so a shop running
		// several stores from one OpenCart signs each message correctly.
		if (!empty($order_info['store_name'])) {
			return $order_info['store_name'];
		}

		$config = $this->registry->get('config');

		return $config ? (string)$config->get('config_name') : '';
	}

	private function orderUrl(array $order_info, $order_id) {
		if (empty($order_info['store_url']) || !$order_id) {
			return '';
		}

		return rtrim($order_info['store_url'], '/') . '/index.php?route=account/order/info&order_id=' . $order_id;
	}

	/**
	 * The link that opens the order in the dashboard.
	 *
	 * The catalog side cannot work this out for itself: an OpenCart admin
	 * folder is often renamed, and nothing in the storefront knows the new
	 * name. So the settings page writes its own address down every time it is
	 * saved, and this reads it back. Whoever follows the link is asked to sign
	 * in first, which is the point.
	 */
	private function adminUrl($order_id) {
		$config = $this->registry->get('config');
		$base = $config ? trim((string)$config->get('module_njiwa_admin_url')) : '';

		if ($base === '' || !$order_id) {
			return '';
		}

		return rtrim($base, '/') . '/index.php?route=sale/order/info&order_id=' . $order_id;
	}
}
