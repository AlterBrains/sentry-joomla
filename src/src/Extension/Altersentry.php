<?php

/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

/** @noinspection ClassReImplementsParentInterfaceInspection */

namespace AlterBrains\Plugin\System\Altersentry\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\DispatcherAwareInterface;
use Joomla\Event\DispatcherAwareTrait;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Actually unused. We boot via dispatcher in service provider.
 * @since  1.0
 */
class Altersentry extends CMSPlugin implements SubscriberInterface, DispatcherAwareInterface
{
    use DispatcherAwareTrait;

    /**
     * @inheritDoc
     * @since 1.0
     */
    public function __construct(DispatcherInterface $dispatcher, array $config = [])
    {
        parent::__construct($config);

        $this->dispatcher  = $dispatcher;
    }

    /**
     * @inheritDoc
     * @since 1.0
     */
    public static function getSubscribedEvents(): array
    {
        return [];
    }
}
