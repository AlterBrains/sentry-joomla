<?php
/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

namespace AlterBrains\Plugin\System\Altersentry\Sentry;

use Joomla\Console\Application as BaseConsoleApplication;

\defined('_JEXEC') or die;

/**
 * @noinspection PhpUnused
 * @since        1.0
 */
class IntegrationCli extends Integration
{
    protected const APP_TYPE = 'cli';
    protected const APP_CONTAINER_RESOURCE = BaseConsoleApplication::class;

    // todo
}
