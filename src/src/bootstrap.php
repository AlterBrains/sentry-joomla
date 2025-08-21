<?php
/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

defined('_JEXEC') or die;

// Detect app type (can be defined before including this file.)
if (!isset($appType)) {
    if (defined('STDIN')) {
        $appType = 'cli';
    } elseif (str_ends_with($_SERVER['PHP_SELF'] ?? '', '/administrator/index.php')) {
        $appType = 'administrator';
    } elseif (str_ends_with($_SERVER['PHP_SELF'] ?? '', '/index.php')) {
        $appType = 'site';
    } else {
        // Unknown app type.
        return;
    }
}

// Load plugin-generated config
if (!is_file(__DIR__ . '/../config.php') || !($sentryConfig = require __DIR__ . '/../config.php')) {
    return;
}

// Load custom config
if (is_file(__DIR__ . '/../config.custom.php')) {
    /** @noinspection PhpIncludeInspection */
    require __DIR__ . '/../config.custom.php';
}

// Check enabled flag
if (empty($sentryConfig['enabled']) || empty($sentryConfig['enabled_' . $appType])) {
    return;
}

require __DIR__ . '/Sentry/Integration.php';

AlterBrains\Plugin\System\Altersentry\Sentry\Integration::init($appType, $sentryConfig);
