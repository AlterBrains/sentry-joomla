<?php
/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

namespace AlterBrains\Plugin\System\Altersentry\Sentry;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseEvents;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Event\ConnectionEvent;
use Joomla\Database\QueryMonitorInterface;
use Joomla\DI\Container;
use Joomla\Event\DispatcherInterface;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

\defined('_JEXEC') or die;

class SentryQueryMonitor implements QueryMonitorInterface
{
    /**
     * @var ?QueryMonitorInterface
     * @since 1.0
     */
    public $oldMonitor;

    /**
     * @var string
     * @since 1.0
     */
    protected $sql;

    /**
     * @var float
     * @since 1.0
     */
    protected $startTime;

    /**
     * @var ?array
     * @since 1.0
     */
    protected $boundParams;

    /**
     * @var bool
     * @since 1.0
     */
    protected $breadcrumbsSql, $breadcrumbsSqlBindings;

    /**
     * @var bool
     * @since 1.0
     */
    protected $tracingSql, $tracingSqlBindings, $traceSqlQueryOrigin;

    /**
     * @var float
     * @since 1.0
     */
    protected $traceSqlQueryOriginThreshold;

    /**
     * @var ?string
     * @since 1.0
     */
    protected $traceSqlQueryOriginPattern;

    /**
     * @var self
     * @since 1.0
     */
    protected static $instance;

    /**
     * @var array
     * @since 1.0
     */
    public static $queryStats = [
        'queryCount' => 0,
        'executionTimeMs' => 0,
    ];

    public static function boot(Container $container, array $config): void
    {
        $dispatcher = $container->get(DispatcherInterface::class);

        $plugin = null;

        $dispatcher->addListener(/*'onAfterConnect'*/ DatabaseEvents::POST_CONNECT,
            function (ConnectionEvent $e) use ($dispatcher, $config, &$plugin) {
                // Set new monitor
                $debugMonitor = $e->getDriver()->getMonitor();

                // Deal with debug plugin, only one time
                // todo - remove once https://github.com/joomla/joomla-cms/pull/45579 is merged
                if (\JDEBUG && !self::$instance) {
                    $dispatcher->addListener('onAfterInitialise', static function () use (&$plugin) {
                        // Get debug plugin instance here, not in onAfterDisconnect handler
                        $plugin = Factory::getApplication()->bootPlugin('debug', 'system');
                    }, \PHP_INT_MAX);

                    if (!$debugMonitor) {
                        // Debug and no monitor? We are in debug plugin, skip!
                        return;
                    }

                    // Special handling for Debug plugin
                    $dispatcher->addListener(/*'onAfterDisconnect'*/ DatabaseEvents::POST_DISCONNECT,
                        static function (ConnectionEvent $e) use (&$plugin) {
                            $monitor = $e->getDriver()->getMonitor();

                            // Restore original debug monitor: debug plugin requires instance of DebugMonitor, but it's a final class :(
                            if ($monitor instanceof SentryQueryMonitor && $monitor->oldMonitor && $plugin) {
                                /** @see SentryQueryMonitor::$oldMonitor */
                                (function ($monitor) {
                                    /** @noinspection PhpDynamicFieldDeclarationInspection */
                                    $this->queryMonitor = $monitor;
                                })(...)->call($plugin, $monitor->oldMonitor);
                            }
                        },
                        \PHP_INT_MAX
                    );
                }

                if (!$debugMonitor instanceof SentryQueryMonitor) {
                    $e->getDriver()->setMonitor(self::$instance ??= new SentryQueryMonitor($config, $debugMonitor));
                }
            },
            \PHP_INT_MAX
        );
    }

    /**
     * @since 1.0
     */
    public function __construct(array $config, ?QueryMonitorInterface $oldMonitor = null)
    {
        $this->oldMonitor = $oldMonitor;

        $this->breadcrumbsSql = $config['breadcrumbs_sql'] ?? false;
        $this->breadcrumbsSqlBindings = $config['breadcrumbs_sql_bindings'] ?? false;

        $this->tracingSql = $config['tracing_sql'] ?? false;
        $this->tracingSqlBindings = $config['tracing_sql_bindings'] ?? false;
        $this->traceSqlQueryOrigin = $config['tracing_sql_origin'] ?? false;
        $this->traceSqlQueryOriginThreshold = ($config['tracing_sql_origin_threshold_ms'] ?? 100) / 1000;
        if ($config['tracing_sql_origin_pattern'] ?? null) {
            $this->traceSqlQueryOriginPattern = '~' . $config['tracing_sql_origin_pattern'] . '~';
        }
    }

    /**
     * @inheritDoc
     * @since 1.0
     */
    public function startQuery(string $sql, ?array $boundParams = null): void
    {
        $this->sql = $sql;
        $this->boundParams = $boundParams;
        $this->startTime = \microtime(true);

        $this->oldMonitor?->startQuery($sql, $boundParams);
    }

    /**
     * @inheritDoc
     * @since 1.0
     */
    public function stopQuery(): void
    {
        $endTime = \microtime(true);
        $executionTimeMs = \round((\microtime(true) - $this->startTime) * 1000, 2);
        $bindings = [];

        // Always collect stats
        static::$queryStats['queryCount']++;
        static::$queryStats['executionTimeMs'] += $executionTimeMs;

        if (($this->breadcrumbsSqlBindings || $this->tracingSqlBindings) && $this->boundParams) {
            foreach ($this->boundParams as $key => $binding) {
                /** @noinspection OnlyWritesOnParameterInspection
                 * @noinspection RedundantSuppression
                 */
                $bindings[$key] = $binding instanceof \stdClass ? $binding->value : $binding;
            }
        }

        // Breadcrumbs
        if ($this->breadcrumbsSql) {
            Integration::addBreadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'db.sql.query',
                $this->sql,
                [
                    'executionTimeMs' => $executionTimeMs,
                ] + ($bindings ? ['bindings' => $bindings] : []),
            );
        }

        // Tracing
        if ($this->tracingSql && ($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
            $context = SpanContext::make()
                ->setOp('db.sql.query')
                ->setOrigin('auto.db')
                ->setDescription($this->sql)
                ->setStartTimestamp($this->startTime)
                ->setEndTimestamp($endTime);

            $data = [];

            if ($this->tracingSqlBindings && $bindings) {
                $data = [
                    'db.sql.bindings' => $bindings,
                ];
            }

            if ($this->traceSqlQueryOrigin
                && $endTime - $this->startTime >= $this->traceSqlQueryOriginThreshold
                && (!$this->traceSqlQueryOriginPattern || \preg_match($this->traceSqlQueryOriginPattern, $this->sql))
                && ($queryOrigin = Integration::resolveEventOrigin(5/*is enough*/, [$this, 'filterQueryOriginFrame']))
            ) {
                $data += $queryOrigin;
            }

            $context->setData($data);
            $parentSpan->startChild($context);
        }

        $this->oldMonitor?->stopQuery();
    }

    public function filterQueryOriginFrame(array &$frames): ?array
    {
        //var_dump($frames);die;

        // find first call to DatabaseDriverInterface::execute, the next frame is bingo
        while (isset($frames[0])) {
            $frame = \array_shift($frames);

            // Skip DatabaseDriver::execute() called in DatabaseDriver instance:
            // find call to DatabaseInterface::execute and check next frame,
            // If next frame is also instance of DatabaseInterface - it's an origin call like $db->loadResult()
            // otherwise, it's a direct call to $db->execute() from non-$db instance
            if (($frame['function'] ?? '') === 'execute'
                && \is_a($frame['class'] ?? '', DatabaseInterface::class, true)
            ) {
                return \is_a($frames[0]['class'] ?? '', DatabaseInterface::class, true) ? $frames[0] : $frame;
            }
        }

        return null;
    }

    /**
     * @since 1.0
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->oldMonitor->$name(...$arguments);
    }
}
