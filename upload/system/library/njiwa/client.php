<?php
/**
 * Talking to Njiwa. Transport only.
 *
 * OpenCart has no HTTP layer of its own, so this uses curl directly, the same
 * way every payment extension in the platform does. Nothing else in this
 * extension opens a socket.
 */

class NjiwaClient {

	const DEFAULT_BASE_URL = 'https://njiwa.upeo.ai';

	/** Long enough for a slow line, short enough that nothing holds a request open for ever. */
	const TIMEOUT = 20;

	const CONNECT_TIMEOUT = 10;

	private $api_key;
	private $base_url;
	private $from;

	public function __construct($api_key, $base_url = '', $from = '') {
		$this->api_key = trim((string)$api_key);
		$this->base_url = rtrim(trim((string)$base_url), '/');
		$this->from = preg_replace('/\D/', '', (string)$from);

		if ($this->base_url === '') {
			$this->base_url = self::DEFAULT_BASE_URL;
		}
	}

	/**
	 * Built from the settings as they are saved, which is what both the
	 * admin's test buttons and the sending code read. A setting typed on the
	 * screen but not saved has no effect on anything, and the settings page
	 * says so.
	 *
	 * @param Config $config
	 * @return NjiwaClient
	 */
	public static function fromConfig($config) {
		return new self(
			$config->get('module_njiwa_api_key'),
			$config->get('module_njiwa_url'),
			$config->get('module_njiwa_from')
		);
	}

	public function isConfigured() {
		return $this->api_key !== '';
	}

	public function isTestKey() {
		return strpos($this->api_key, 'sk_test_') === 0;
	}

	public function getBaseUrl() {
		return $this->base_url;
	}

	public function getFrom() {
		return $this->from;
	}

	/**
	 * Send one text message.
	 *
	 * @param string $to              Recipient, digits only.
	 * @param string $text            The message.
	 * @param string $idempotency_key Optional. Njiwa honours it for 24 hours,
	 *                                so a send that runs twice replays the
	 *                                first answer instead of messaging the
	 *                                customer again.
	 *
	 * @return array Njiwa's answer, including the message id.
	 *
	 * @throws NjiwaException
	 */
	public function sendText($to, $text, $idempotency_key = '') {
		$headers = array();

		if ($idempotency_key !== '') {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$body = array(
			'to' => $to,
			'text' => $text
		);

		// Only when the shop named a number. Left out, Njiwa uses the account's
		// default, which is the right answer for the shops that have one number
		// and never think about this again.
		if ($this->from !== '') {
			$body['from'] = $this->from;
		}

		return $this->request('POST', '/v1/messages', $body, $headers);
	}

	/**
	 * The WhatsApp numbers on this account, linked or not.
	 *
	 * @return array
	 *
	 * @throws NjiwaException
	 */
	public function numbers() {
		$answer = $this->request('GET', '/v1/instances');

		return isset($answer['data']) && is_array($answer['data']) ? $answer['data'] : array();
	}

	/**
	 * @throws NjiwaException
	 */
	private function request($method, $path, $body = null, $headers = array()) {
		if (!$this->isConfigured()) {
			throw new NjiwaException('There is no Njiwa API key saved, so nothing can be sent.', 'not_configured');
		}

		$lines = array(
			'Authorization: Bearer ' . $this->api_key,
			'Accept: application/json'
		);

		foreach ($headers as $name => $value) {
			$lines[] = $name . ': ' . $value;
		}

		$curl = curl_init();

		curl_setopt($curl, CURLOPT_URL, $this->base_url . $path);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
		curl_setopt($curl, CURLOPT_TIMEOUT, self::TIMEOUT);
		curl_setopt($curl, CURLOPT_USERAGENT, 'njiwa-opencart/' . (defined('NJIWA_VERSION') ? NJIWA_VERSION : '0'));

		if ($body !== null) {
			$lines[] = 'Content-Type: application/json';
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
		}

		curl_setopt($curl, CURLOPT_HTTPHEADER, $lines);

		$response = curl_exec($curl);
		$error = curl_error($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

		curl_close($curl);

		if ($response === false || $error !== '') {
			// The message was never accepted, so this is not a failed send and
			// the queue is allowed to try it again.
			throw new NjiwaException(
				'Could not reach Njiwa at ' . $this->base_url . '. ' . $error,
				'connection_failed'
			);
		}

		$decoded = json_decode($response, true);

		if (!is_array($decoded)) {
			$decoded = array();
		}

		if ($status >= 400) {
			$refusal = isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : array();

			throw new NjiwaException(
				isset($refusal['message']) ? $refusal['message'] : 'Njiwa answered with HTTP ' . $status . '.',
				isset($refusal['code']) ? $refusal['code'] : 'unknown',
				$status,
				isset($refusal['docs']) ? $refusal['docs'] : null
			);
		}

		return $decoded;
	}
}
