<?php
/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

namespace AlterBrains\Plugin\System\Altersentry\Sentry;

\defined('_JEXEC') or die;

require __DIR__ . '/IntegrationSite.php';

/**
 * @noinspection PhpUnused
 * @since        1.0
 */
class IntegrationAdministrator extends IntegrationSite
{
    protected const APP_TYPE = 'administrator';
    protected const APP_CONTAINER_RESOURCE = 'JApplicationAdministrator';
}
