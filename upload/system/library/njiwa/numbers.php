<?php
/**
 * Turning what a customer typed into a number WhatsApp can reach.
 *
 * People write their number the way they say it: 0712 345 678, (071) 234-5678,
 * +254 712 345 678. WhatsApp needs one form, which is digits only.
 *
 * OpenCart does not keep international calling codes anywhere, so unlike the
 * WooCommerce version there is no country list to ask. It does not need one:
 * Njiwa reads a recipient against the country of the number it is sending
 * from, so a local number with its leading zero arrives correctly at a shop
 * that sells in one country. The "country code for local numbers" setting is
 * for the shops that sell in more than one.
 */

class NjiwaNumbers {

	/** E.164 stops at fifteen digits, and nothing shorter than seven is a phone number. */
	const MIN_DIGITS = 7;
	const MAX_DIGITS = 15;

	/**
	 * @param string $phone        As the customer typed it.
	 * @param string $default_code The shop's calling code, digits only, or ''.
	 *
	 * @return string Digits only, or '' if there is nothing usable.
	 */
	public static function toMsisdn($phone, $default_code = '') {
		$raw = trim((string)$phone);

		// A WhatsApp group is addressed as 1203630...@g.us, and Njiwa will
		// post to it. One order confirmation sent to a group would go to
		// everybody in it from the shop's own number, so anything that is not
		// plainly a phone number is refused rather than cleaned up.
		if ($raw === '' || strpos($raw, '@') !== false) {
			return '';
		}

		$digits = preg_replace('/\D/', '', $raw);

		if ($digits === '') {
			return '';
		}

		// A leading + or 00 is the customer saying "this is the whole number".
		// Believe them, and stop before the shop's own country code gets a
		// say: somebody abroad who typed their full number would otherwise
		// have a second country code stuck in front of it.
		$already_international = strpos($raw, '+') === 0 || strpos($digits, '00') === 0;

		// 00 is how much of the world dials out.
		if (strpos($digits, '00') === 0) {
			$digits = substr($digits, 2);
		}

		$code = preg_replace('/\D/', '', (string)$default_code);

		if (!$already_international && $code !== '') {
			// Already international. The length test is what stops a national
			// number that happens to open with its own country's digits being
			// mistaken for one, which is a real hazard in +1 countries.
			if (strpos($digits, $code) === 0 && strlen($digits) >= strlen($code) + 7) {
				return self::sane($digits);
			}

			// The trunk prefix: the 0 you dial at home and never abroad.
			$digits = $code . ltrim($digits, '0');
		}

		return self::sane($digits);
	}

	/**
	 * A list typed by the shop owner: one number per line or comma separated.
	 *
	 * These are the shop's own numbers rather than a customer's, so they are
	 * expected in full international form and are not expanded against
	 * anything. Getting your own number wrong is a mistake you make once.
	 *
	 * @return array
	 */
	public static function parseList($raw) {
		$numbers = array();

		foreach (preg_split('/[\s,;]+/', (string)$raw) as $piece) {
			if (strpos($piece, '@') !== false) {
				continue;
			}

			$digits = self::sane(preg_replace('/\D/', '', $piece));

			if ($digits !== '') {
				$numbers[] = $digits;
			}
		}

		return array_values(array_unique($numbers));
	}

	private static function sane($digits) {
		$length = strlen($digits);

		return ($length >= self::MIN_DIGITS && $length <= self::MAX_DIGITS) ? $digits : '';
	}
}
