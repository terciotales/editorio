<?php
/**
 * Plugin Name: Editorio
 * Plugin URI: https://example.com/editorio
 * Description: Automates news workflows from RSS sources into reviewable WordPress drafts.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Editorio
 * License: GPL-2.0-or-later
 * Text Domain: editorio
 */

if (! defined('ABSPATH')) {
    exit;
}

define('EDITORIO_VERSION', '0.1.0');
define('EDITORIO_PLUGIN_FILE', __FILE__);
define('EDITORIO_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('EDITORIO_PLUGIN_URL', plugin_dir_url(__FILE__));

$editorio_autoload = EDITORIO_PLUGIN_PATH . 'vendor/autoload.php';
if (file_exists($editorio_autoload)) {
    require_once $editorio_autoload;
}

/**
 * Boots plugin modules during WordPress load.
 */
function editorio_boot_plugin(): void
{
    if (! class_exists(\Editorio\Plugin::class)) {
        return;
    }

    \Editorio\Plugin::boot();
}
add_action('plugins_loaded', 'editorio_boot_plugin');

/**
 * Handles plugin activation.
 */
function editorio_activate(): void
{
    if (! class_exists(\Editorio\Plugin::class)) {
        return;
    }

    \Editorio\Plugin::activate();
}
register_activation_hook(__FILE__, 'editorio_activate');

/**
 * Handles plugin deactivation.
 */
function editorio_deactivate(): void
{
    if (! class_exists(\Editorio\Plugin::class)) {
        return;
    }

    \Editorio\Plugin::deactivate();
}
register_deactivation_hook(__FILE__, 'editorio_deactivate');

