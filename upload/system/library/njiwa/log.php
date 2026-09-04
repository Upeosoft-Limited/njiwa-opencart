<?php
/**
 * Everything this extension does that is worth finding later, in one file.
 *
 * It writes to storage/logs/njiwa.log rather than to OpenCart's error log,
 * because a shop looking into "did that customer get their message" should
 * not have to read past every PHP notice the theme produced that day.
 */

class NjiwaLog {

	public static function write($message) {
		// DIR_LOGS arrived in OpenCart 3.0. If a stray include leaves us
		// without it, saying nothing is better than a fatal error inside
		// somebody's checkout.
		if (!defined('DIR_LOGS') || !class_exists('Log')) {
			return;
		}

		$log = new Log('njiwa.log');
		$log->write($message);
	}
}
