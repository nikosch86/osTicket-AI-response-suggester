<?php

/**
 * Test bootstrap - provides stubs for osTicket classes that aren't available
 * outside the osTicket runtime.
 */

// Stub constants
if (!defined('INCLUDE_DIR')) define('INCLUDE_DIR', '/tmp/osticket/include/');
if (!defined('TABLE_PREFIX')) define('TABLE_PREFIX', 'ost_');
if (!defined('ROOT_PATH')) define('ROOT_PATH', '/');
if (!defined('MAJOR_VERSION')) define('MAJOR_VERSION', '1.18');
if (!defined('GIT_VERSION')) define('GIT_VERSION', 'test');

// Stub osTicket base classes for unit tests
if (!class_exists('PluginConfig')) {
    class PluginConfig {
        protected $data = array();
        public function get($key, $default = null) {
            return $this->data[$key] ?? $default;
        }
        public function getInstance() { return null; }
    }
}

if (!class_exists('Plugin')) {
    class Plugin {
        var $config_class = '';
        public function getConfig() { return null; }
    }
}

if (!class_exists('AjaxController')) {
    class AjaxController {
        public function staffOnly() {}
        public function encode($data) {
            return json_encode($data);
        }
    }
}

if (!class_exists('Signal')) {
    class Signal {
        public static function connect($signal, $callback, $class = null) {}
    }
}

if (!function_exists('db_query')) {
    function db_query($sql) { return true; }
}

if (!function_exists('db_input')) {
    function db_input($val) { return "'" . addslashes($val) . "'"; }
}

if (!function_exists('db_fetch_array')) {
    function db_fetch_array($result) { return false; }
}

if (!function_exists('__')) {
    function __($str) { return $str; }
}
