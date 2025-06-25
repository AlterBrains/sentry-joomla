<?php
/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

namespace AlterBrains\Plugin\System\Altersentry\Sentry;

use Joomla\CMS\Cache\CacheController;
use Joomla\CMS\Cache\CacheControllerFactory;

\defined('_JEXEC') or die;

class SentryCacheControllerFactory extends CacheControllerFactory
{
    protected $config;

    /**
     * @since 1.0
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @inheridoc
     * @since 1.0
     */
    public function createCacheController($type = 'output', $options = []): CacheController
    {
        $controller = parent::createCacheController($type, $options);

        $controller->cache = new SentryCache(
            $controller->cache,
            $type ?: 'output',
            $this->config['breadcrumbs_cache'],
            $this->config['tracing_cache']
        );

        return $controller;
    }
}
