<?php
	/**
	 * @package Bitid Authentication
	 * @author Puggan
	 * @version 1.0.1-20180505
	 */

	/*
	Plugin Name: Bitid Authentication
	Description: Bitid Authentication, extends wordpress default authentication with the bitid-protocol
	Version: 1.0.1-20180505
	Author: Puggan
	Author URI: http://blog.puggan.se
	Text Domain: bitid-authentication
	*/

	namespace Puggan;

	define("BITID_AUTHENTICATION_PLUGIN_VERSION", '1.0.1');

	require_once(__DIR__ . "/bitid/BitID.php");
	require_once(__DIR__ . "/BitidAuthentication.php");
	new BitidAuthentication();



