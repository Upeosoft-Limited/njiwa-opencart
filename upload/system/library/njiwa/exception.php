<?php
/**
 * Anything Njiwa refused, or could not be asked.
 *
 * getErrorCode() is the stable, machine readable reason and is the thing to
 * branch on. The wording of the message can change; the code does not.
 */

class NjiwaException extends Exception {

	private $error_code;
	private $status;
	private $docs;

	public function __construct($message, $error_code = 'unknown', $status = 0, $docs = null) {
		parent::__construct($message);

		$this->error_code = $error_code;
		$this->status = (int)$status;
		$this->docs = $docs;
	}

	public function getErrorCode() {
		return $this->error_code;
	}

	public function getStatus() {
		return $this->status;
	}

	public function getDocs() {
		return $this->docs;
	}

	/**
	 * Whether the message is still worth sending later.
	 *
	 * A network failure is not a send failure: the message was never accepted,
	 * so trying again is safe rather than dangerous. The same goes for a 429
	 * or a 5xx, where Njiwa is telling us to come back. Everything else is a
	 * decision Njiwa has made about this message, and repeating it would only
	 * produce the same refusal.
	 */
	public function isWorthRetrying() {
		return $this->error_code === 'connection_failed'
			|| $this->status === 429
			|| $this->status >= 500;
	}
}
