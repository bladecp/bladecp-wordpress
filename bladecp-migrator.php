<?php
/**
 * Plugin Name:       BladeCP Migrator
 * Plugin URI:        https://developers.bladecp.com/wordpress-migrator
 * Description:       Move this WordPress site to BladeCP Fast Deploy in a few clicks: check, connect, migrate, review and go live.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            BladeCP
 * Author URI:        https://bladecp.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       bladecp-migrator
 * Domain Path:       /languages
 *
 * @package BladeCP\Migrator
 */

/*
 * Copyright (c) 2026 BladeCP. Released under the MIT License: see the LICENSE
 * file that ships with the plugin. Third-party notices: assets/fonts/OFL.txt
 * (Inter typeface) and includes/LICENSE-lucide.txt (Lucide icons).
 */

defined( 'ABSPATH' ) || exit;

define( 'BLADECP_MIGRATOR_VERSION', '0.1.0' );
define( 'BLADECP_MIGRATOR_FILE', __FILE__ );
define( 'BLADECP_MIGRATOR_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-autoloader.php';

\BladeCP\Migrator\Autoloader::register( __DIR__ . '/includes' );

register_activation_hook( __FILE__, array( '\BladeCP\Migrator\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\BladeCP\Migrator\Plugin', 'deactivate' ) );

\BladeCP\Migrator\Plugin::boot();
