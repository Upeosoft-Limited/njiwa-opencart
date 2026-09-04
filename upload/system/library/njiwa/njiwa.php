<?php
/**
 * Njiwa for OpenCart, loaded in one line.
 *
 * OpenCart's own library loader expects one class per file in a namespace of
 * its own and registers whatever it loads in the registry under the file's
 * name. These classes are plain helpers rather than registry services, so the
 * admin controller and the catalog event handler both include this file and
 * get all of them, which is easier to follow than six load calls.
 */

if (!defined('NJIWA_VERSION')) {
	define('NJIWA_VERSION', '0.1.0');
}

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/exception.php';
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/numbers.php';
require_once __DIR__ . '/templates.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/notifier.php';
