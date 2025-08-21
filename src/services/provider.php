<?php
/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

defined('_JEXEC') or die;

//use AlterBrains\Plugin\System\Altersentry\Sentry\Integration;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
//use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
    /**
     * @inheritDoc
     * @since 1.0
     */
    public function register(Container $container)
    {
//        // We are in app->initialiseApp();
//        if (class_exists(Integration::class, false) && isset(Integration::$instance)) {
//            Integration::$instance->subscribe($container->get(DispatcherInterface::class));
//        }

        // No sense to load plugin.
//        $container->set(
//            PluginInterface::class,
//            function (Container $container) {
//                return new Altersentry(
//                    $container->get(DispatcherInterface::class),
//                    (array) PluginHelper::getPlugin('system', 'altersentry')
//                );
//            }
//        );
    }
};
