<?php
/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die();

/**
 * @since        1.0
 * @noinspection PhpUnused
 */
class PlgSystemAltersentryInstallerScript
{
    /**
     * @var string
     * @since 1.0
     */
    protected $extension_name = 'System - Alter Sentry';

    /**
     * @var string
     * @since 2.0
     */
    protected $minimumPhp = '8.3';

    /**
     * @var string
     * @since 2.0
     */
    protected $minimumJoomla = '5.4';

    /**
     * @return bool
     * @since 2.0
     */
    public function preflight()
    {
        if (!empty($this->minimumPhp) && version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', $this->minimumPhp), 'error');

            return false;
        }
        if (!empty($this->minimumJoomla) && version_compare(JVERSION, $this->minimumJoomla, '<')) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', $this->minimumJoomla), 'error');

            return false;
        }

        return true;
    }

    public function install()
    {
        Factory::getApplication()->enqueueMessage(\sprintf('Successfully installed "%s" plugin.', $this->extension_name));
    }

    public function update()
    {
        Factory::getApplication()->enqueueMessage(\sprintf('Successfully updated "%s" plugin.', $this->extension_name));
    }

    public function uninstall()
    {
        Factory::getApplication()->enqueueMessage(\sprintf('Successfully uninstalled "%s" plugin.', $this->extension_name));
    }
}
