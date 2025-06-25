<?php
/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

namespace AlterBrains\Plugin\System\Altersentry\Sentry;

use Joomla\CMS\Event\Application;
use Joomla\CMS\Event\Content;
use Joomla\CMS\Event\View;
use Joomla\CMS\Event\Module;
use Joomla\CMS\Event\Table;
use Joomla\Event\EventInterface;

\defined('_JEXEC') or die;

/**
 * Omit calling event->getName(), use $name. Maybe, not the best idea, but faster!
 * todo - approve or switch to $e->getName()
 * @since 1.0
 */
abstract class EventSpanHelper
{
    public static function getSpanDescription(EventInterface $e, string $name): string
    {
        /** @noinspection PhpSwitchCanBeReplacedWithMatchExpressionInspection */
        switch ($name) {
            case 'onAfterInitialiseDocument':
                /** @var Application\AfterInitialiseDocumentEvent $e */
                return $name . ' - ' . $e->getDocument()->getType();

            case 'onContentPrepare':
                /** @var Content\ContentPrepareEvent $e */
                return $name . ' - ' . $e->getContext();

            case 'onBeforeDisplay':
                /** @var View\DisplayEvent $e */
                return $name . ' - ' . $e->getArgument('extension');

            case 'onAfterRenderModules':
                /** @var Module\AfterRenderModulesEvent $e */
                return $name . ' - ' . ($e->getAttributes()['name'] ?? '');

            case 'onRenderModule':
                /** @var Module\RenderModuleEvent $e */
                return $name . ' - ' . ($e->getModule()?->module ?? '') . '.' . ($e->getModule()?->id ?? '');

            case 'onAfterRenderModule':
                /** @var Module\AfterRenderModuleEvent $e */
                return $name . ' - ' . ($e->getModule()?->module ?? '') . '.' . ($e->getModule()?->id ?? '');

            case 'onTableObjectCreate':
                /** @var Table\ObjectCreateEvent $e */
                return $name . ' - ' . $e->getArgument('subject')->getTableName();

            case 'onTableAfterLoad':
                /** @var Table\AfterLoadEvent $e */
                return $name . ' - ' . $e->getArgument('subject')->getTableName();

            default:
                return $name;
        }
    }

    public static function getSpanData(EventInterface $e, string $name): array
    {
        /** @noinspection PhpSwitchCanBeReplacedWithMatchExpressionInspection */
        switch ($name) {
            case 'onContentPrepare':
                /** @var Content\ContentPrepareEvent $e */
                return [
                    'item.id' => $e->getItem()?->id ?? null,
                ];

            case 'onBeforeDisplay':
                /** @var View\DisplayEvent $e */
                return [
                    'viewClass' => \get_class($e->getArgument('subject')),
                ];

            case 'onAfterCleanModuleList':
                /** @var Module\AfterCleanModuleListEvent $e */
                return [
                    'moduleCount' => \count($e->getModules()),
                ];

            case 'onAfterRenderModules':
                /** @var Module\AfterRenderModulesEvent $e */
                return $e->getAttributes();

            case 'onRenderModule':
                /** @var Module\RenderModuleEvent $e */
                return [
                    //'id' => $e->getModule()?->id ?? null,
                    //'module' => $e->getModule()?->module ?? null,
                    'position' => $e->getModule()?->position ?? null,
                ];

            case 'onAfterRenderModule':
                /** @var Module\AfterRenderModuleEvent $e */
                return [
                    //'id' => $e->getModule()?->id ?? null,
                    //'module' => $e->getModule()?->module ?? null,
                    'position' => $e->getModule()?->position ?? null,
                ];

            case 'onTableAfterLoad':
                /** @var Table\AfterLoadEvent $e */
                return [
                    /** @see \Joomla\CMS\Table\Table::getTableName() */
                    //'table' => $e->getArgument('subject')->getTableName(),
                    /** @see \Joomla\CMS\Table\Table::getId() */
                    'id' => $e->getArgument('subject')->getId(),
                ];

            default:
                return [];
        }
    }
}
