<?php
/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

namespace AlterBrains\Plugin\System\Altersentry\Sentry;

use Joomla\CMS\Cache\Cache;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

\defined('_JEXEC') or die;

class SentryCache extends Cache
{
    /**
     * @var Cache
     * @since 1.0
     */
    protected $oldCache;

    /**
     * @since 1.0
     */
    protected string $controllerType;

    /**
     * @since 1.0
     */
    protected bool $breadcrumbsCache, $tracingCache;

    /** @noinspection MagicMethodsValidityInspection */
    public function __construct(Cache $cache, string $controllerType, bool $breadcrumbsCache, bool $tracingCache)
    {
        $this->oldCache         = $cache;
        $this->controllerType   = $controllerType;
        $this->breadcrumbsCache = $breadcrumbsCache;
        $this->tracingCache     = $tracingCache;

        static::$_handler =& $cache::$_handler;
        $this->_options   =& $cache->_options;
    }

    /**
     * @inheridoc
     * @since 1.0
     */
    public function get($id, $group = null)
    {
        if ($this->breadcrumbsCache) {
            Integration::addBreadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'cache.get',
                $group ?: $this->_options['defaultgroup'],
                [
                    'cache.key'  => $id,
                    'cache.ttl'  => $this->_options['lifetime'],
                    'controller' => $this->controllerType,
                    'storage'    => $this->_options['storage'],
                    'checkTime'  => $this->_options['checkTime'],
                ]
            );
        }

        if ($this->tracingCache && ($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
            $span = $parentSpan->startChild(
                SpanContext::make()
                    ->setOp('cache.get')
                    ->setOrigin('auto.cache')
                    ->setDescription($group ?: $this->_options['defaultgroup'])
                    ->setData([
                        'cache.key'  => $id,
                        'cache.ttl'  => $this->_options['lifetime'],
                        'controller' => $this->controllerType,
                        'storage'    => $this->_options['storage'],
                        'checkTime'  => $this->_options['checkTime'],
                    ])
            );

            try {
                $startTime = \microtime(true);

                return $result = parent::get($id, $group);
            } finally {
                $endTime = \microtime(true);

                $span->setStartTimestamp($startTime)
                    ->setData([
                        'cache.hit' => ($result ?? false) !== false,
                    ])
                    ->finish($endTime);
            }
        }

        return parent::get($id, $group);
    }

    /**
     * @inheridoc
     * @since 1.0
     */
    public function store($data, $id, $group = null)
    {
        if ($this->breadcrumbsCache) {
            Integration::addBreadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'cache.put',
                $group ?: $this->_options['defaultgroup'],
                [
                    'cache.key'  => $id,
                    'cache.ttl'  => $this->_options['lifetime'] * 60,
                    'controller' => $this->controllerType,
                    'storage'    => $this->_options['storage'],
                ]
            );
        }

        if ($this->tracingCache && ($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
            $span = $parentSpan->startChild(
                SpanContext::make()
                    ->setOp('cache.put')
                    ->setOrigin('auto.cache')
                    ->setDescription($group ?: $this->_options['defaultgroup'])
                    ->setData([
                        'cache.key'  => $id,
                        'cache.ttl'  => $this->_options['lifetime'] * 60,
                        'controller' => $this->controllerType,
                        'storage'    => $this->_options['storage'],
                    ])
            );

            try {
                $startTime = \microtime(true);

                return $result = parent::store($data, $id, $group);
            } finally {
                $endTime = \microtime(true);

                $span->setStartTimestamp($startTime)
                    ->setData([
                        'cache.success' => ($result ?? false) !== false,
                    ])
                    ->finish($endTime);
            }
        }

        return parent::store($data, $id, $group);
    }

    /**
     * @inheridoc
     * @since 1.0
     */
    public function remove($id, $group = null)
    {
        if ($this->breadcrumbsCache) {
            Integration::addBreadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'cache.remove',
                $group ?: $this->_options['defaultgroup'],
                [
                    'cache.key'  => $id,
                    'controller' => $this->controllerType,
                    'storage'    => $this->_options['storage'],
                ]
            );
        }

        if ($this->tracingCache && ($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
            $span = $parentSpan->startChild(
                SpanContext::make()
                    ->setOp('cache.remove')
                    ->setOrigin('auto.cache')
                    ->setDescription($group ?: $this->_options['defaultgroup'])
                    ->setData([
                        'cache.key'  => $id,
                        'controller' => $this->controllerType,
                        'storage'    => $this->_options['storage'],
                    ])
            );

            try {
                $startTime = \microtime(true);

                return $result = parent::remove($id, $group);
            } finally {
                $endTime = \microtime(true);

                $span->setStartTimestamp($startTime)
                    ->setData([
                        'cache.success' => ($result ?? false) !== false,
                    ])
                    ->finish($endTime);
            }
        }

        return parent::remove($id, $group);
    }

    /**
     * @inheridoc
     * @since 1.0
     */
    public function clean($group = null, $mode = 'group')
    {
        if ($this->breadcrumbsCache) {
            Integration::addBreadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'cache.flush',
                $group ?: $this->_options['defaultgroup'],
                [
                    'mode'       => $mode,
                    'controller' => $this->controllerType,
                    'storage'    => $this->_options['storage'],
                ]
            );
        }

        if ($this->tracingCache && ($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
            $span = $parentSpan->startChild(
                SpanContext::make()
                    ->setOp('cache.flush')
                    ->setOrigin('auto.cache')
                    ->setDescription($group ?: $this->_options['defaultgroup'])
                    ->setData([
                        'mode'       => $mode,
                        'controller' => $this->controllerType,
                        'storage'    => $this->_options['storage'],
                    ])
            );

            try {
                $startTime = \microtime(true);

                return $result = parent::clean($group, $mode);
            } finally {
                $endTime = \microtime(true);

                $span->setStartTimestamp($startTime)
                    ->setData([
                        'cache.success' => ($result ?? false) !== false,
                    ])
                    ->finish($endTime);
            }
        }

        return parent::clean($group, $mode);
    }

    /**
     * @inheridoc
     * @since 1.0
     */
    public function contains($id, $group = null)
    {
        if ($this->breadcrumbsCache) {
            Integration::addBreadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'cache.contains',
                $group ?: $this->_options['defaultgroup'],
                [
                    'cache.key'  => $id,
                    'controller' => $this->controllerType,
                    'storage'    => $this->_options['storage'],
                ]
            );
        }

        if ($this->tracingCache && ($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
            $span = $parentSpan->startChild(
                SpanContext::make()
                    ->setOp('cache.contains')
                    ->setOrigin('auto.cache')
                    ->setDescription($group ?: $this->_options['defaultgroup'])
                    ->setData([
                        'cache.key'  => $id,
                        'controller' => $this->controllerType,
                        'storage'    => $this->_options['storage'],
                    ])
            );

            try {
                $startTime = \microtime(true);

                return $result = parent::contains($id, $group);
            } finally {
                $endTime = \microtime(true);

                $span->setStartTimestamp($startTime)
                    ->setData([
                        'cache.success' => ($result ?? false) !== false,
                    ])
                    ->finish($endTime);
            }
        }

        return parent::contains($id, $group);
    }
}
