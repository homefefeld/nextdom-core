<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */
define ('NEXTDOM_ROOT', realpath(__DIR__.'/../..'));
date_default_timezone_set('Europe/Brussels');
require_once NEXTDOM_ROOT.'/vendor/autoload.php'; // NEXTDOM
require_once NEXTDOM_ROOT.'/core/config/common.config.php'; // NEXTDOM
require_once NEXTDOM_ROOT.'/core/class/DB.class.php'; // NEXTDOM
require_once NEXTDOM_ROOT.'/core/class/config.class.php'; // NEXTDOM
require_once NEXTDOM_ROOT.'/core/class/nextdom.class.php'; // NEXTDOM
require_once NEXTDOM_ROOT.'/core/class/jeedom.class.php'; // NEXTDOM
require_once NEXTDOM_ROOT.'/core/class/plugin.class.php'; // NEXTDOM
require_once NEXTDOM_ROOT.'/core/class/translate.class.php'; // NEXTDOM
require_once NEXTDOM_ROOT.'/core/php/utils.inc.php'; // NEXTDOM
include_file('core', 'nextdom', 'config');
include_file('core', 'compatibility', 'config');
include_file('core', 'utils', 'class');
include_file('core', 'log', 'class');

try {
	$configs = config::byKeys(array('timezone', 'log::level'));
	if (isset($configs['timezone'])) {
		date_default_timezone_set($configs['timezone']);
	}
} catch (Exception $e) {

} catch (Error $e) {

}

try {
	if (isset($configs['log::level'])) {
		log::define_error_reporting($configs['log::level']);
	}
} catch (Exception $e) {

} catch (Error $e) {

}

function nextdomCoreAutoload($classname) {
	try {
		include_file('core', $classname, 'class');
	} catch (Exception $e) {

	} catch (Error $e) {

	}
}

function nextdomPluginAutoload($_classname) {
	$classname = str_replace(array('Real', 'Cmd'), '', $_classname);
	$plugin_active = config::byKey('active', $classname, null);
	if ($plugin_active === null || $plugin_active == '') {
		$classname = explode('_', $classname)[0];
		$plugin_active = config::byKey('active', $classname, null);
	}
	try {
		if ($plugin_active == 1) {
			include_file('core', $classname, 'class', $classname);
		}
	} catch (Exception $e) {

	} catch (Error $e) {

	}
}

function nextdomOtherAutoload($classname) {
	try {
		include_file('core', substr($classname, 4), 'com');
		return;
	} catch (Exception $e) {

	} catch (Error $e) {

	}
	try {
		include_file('core', substr($classname, 5), 'repo');
		return;
	} catch (Exception $e) {

	} catch (Error $e) {

	}
}
spl_autoload_register('nextdomOtherAutoload', true, true);
spl_autoload_register('nextdomPluginAutoload', true, true);
spl_autoload_register('nextdomCoreAutoload', true, true);