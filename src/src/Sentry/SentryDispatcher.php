<?php
/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

namespace AlterBrains\Plugin\System\Altersentry\Sentry;

use Joomla\Event\Dispatcher;
use Joomla\Event\EventInterface;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

\defined('_JEXEC') or die;

/**
 * @since 1.0
 */
class SentryDispatcher extends Dispatcher
{
    /**
     * @var bool
     * @since 1.0
     */
    protected $breadcrumbsEvents, $breadcrumbsEventsReal;

    /**
     * @var ?array
     * @since 1.0
     */
    protected $breadcrumbsEventsOnly, $breadcrumbsEventsIgnore;

    /**
     * @var bool
     * @since 1.0
     */
    protected $tracing, $tracingEvents, $tracingEventsReal, $tracingEventsOrigin;

    /**
     * @var float
     * @since 1.0
     */
    protected $tracingEventsOriginThreshold;

    /**
     * @var ?string
     * @since 1.0
     */
    protected $tracingEventsOriginPattern;

    /**
     * @var ?array
     * @since 1.0
     */
    protected $tracingEventsOnly, $tracingEventsIgnore;

    /**
     * @var array
     * @since 1.0
     */
    public static $eventStats = [];

    /**
     * @var callable[]
     * @since 1.0
     */
    public static $beforeEventCalls, $afterEventCalls;

    /**
     * @since 1,0
     */
    public function __construct(array $config)
    {
        $this->breadcrumbsEvents       = $config['breadcrumbs_events'] ?? false;
        $this->breadcrumbsEventsReal   = $config['breadcrumbs_events_real'] ?? false;
        $this->breadcrumbsEventsOnly   = \array_flip($config['breadcrumbs_events_only'] ?: []);
        $this->breadcrumbsEventsIgnore = \array_flip($config['breadcrumbs_events_ignore'] ?: []);

        $this->tracing                      = $config['tracing'] ?? false;
        $this->tracingEvents                = $config['tracing_events'] ?? false;
        $this->tracingEventsReal            = $config['tracing_events_real'] ?? false;
        $this->tracingEventsOrigin          = $config['tracing_events_origin'] ?? false;
        $this->tracingEventsOriginThreshold = ($config['tracing_events_origin_threshold_ms'] ?? 100) / 1000;
        if ($config['tracing_events_origin_pattern'] ?? null) {
            $this->tracingEventsOriginPattern = '~' . $config['tracing_sql_origin_pattern'] . '~';
        }
        $this->tracingEventsOnly   = \array_flip($config['tracing_events_only'] ?: []);
        $this->tracingEventsIgnore = \array_flip($config['tracing_events_ignore'] ?: []);

        // Joomla auto-loader is not enabled yet
        if ($this->tracingEvents) {
            require_once __DIR__ . '/EventSpanHelper.php';
        }
    }

    /**
     * @inheridoc
     * @since        1.0
     */
    public function dispatch(string $name, ?EventInterface $event = null): EventInterface
    {
        $startTime = \microtime(true);

        // Always collect stats
        static::$eventStats[$name] = (static::$eventStats[$name] ?? 0) + 1;

        $listenerCount = isset($this->listeners[$name]) ? $this->listeners[$name]->count() : 0;

        // Breadcrumbs
        if ($this->breadcrumbsEvents
            && !isset($this->breadcrumbsEventsIgnore[$name])
            && (!$this->breadcrumbsEventsReal || $listenerCount)
            && (!$this->breadcrumbsEventsOnly || isset($this->breadcrumbsEventsOnly[$name]))
        ) {
            $breadcrumb = new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'app.event',
                $name,
                [
                    'listenerCount' => $listenerCount,
                ]
            );

            Integration::addBreadcrumb($breadcrumb);
        }

        // Tracing
        if ($this->tracing) {
            // App spans
            if (isset(static::$beforeEventCalls[$name])) {
                static::$beforeEventCalls[$name]($startTime, $event);
            }

            // Event spans
            if ($this->tracingEvents
                && !isset($this->tracingEventsIgnore[$name])
                && (!$this->tracingEventsReal || $listenerCount)
                && (!$this->tracingEventsOnly || isset($this->tracingEventsOnly[$name]))
            ) {
                /** @noinspection NestedPositiveIfStatementsInspection */
                if (($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
                    $context = SpanContext::make()
                        ->setOp('app.event')
                        ->setOrigin('auto.app')
                        ->setDescription(EventSpanHelper::getSpanDescription($event, $name))
                        ->setData(
                            [
                                'listenerCount' => $listenerCount,
                            ] + EventSpanHelper::getSpanData($event, $name)
                        );

                    $span = $parentSpan->startChild($context);

                    Integration::pushSpan($span);
                }
            }
        }

        try {
            $startTime = \microtime(true);

            return parent::dispatch($name, $event);
        } catch (\Throwable $e) {
            $endTime ??= \microtime(true);

            if ($e->getCode() !== 404) {
                $spanStatus = SpanStatus::internalError();
            }

            throw $e;
        } finally {
            $endTime ??= \microtime(true);

            // Add execution time to breadcrumb
            if (isset($breadcrumb)) {
                (function () use ($startTime, $endTime) {
                    /** @noinspection PhpUndefinedFieldInspection */
                    $this->metadata += [
                        'executionTimeMs' => \round(($endTime - $startTime) * 1000, 2),
                    ];
                })(...)->call($breadcrumb);
            }

            // Finish event span
            if (isset($span)) {
                if ($this->tracingEventsOrigin
                    && $endTime - $startTime >= $this->tracingEventsOriginThreshold
                    && (!$this->tracingEventsOriginPattern || \preg_match($this->tracingEventsOriginPattern, $name))
                    && ($queryOrigin = Integration::resolveEventOrigin(2/*is enough*/))
                ) {
                    $span->setData($queryOrigin);
                }

                $span->setStartTimestamp($startTime)
                    ->setStatus($spanStatus ?? SpanStatus::ok())
                    ->finish($endTime);

                Integration::popSpan();
            }

            // App spans
            if (isset(static::$afterEventCalls[$name])) {
                static::$afterEventCalls[$name]($endTime, $event);
            }
        }
    }

    /**
     * Triggers original Joomla dispatcher.
     * @since        1.0
     * @noinspection PhpUnused
     */
    public function dispatchOriginal(string $name, ?EventInterface $event = null): EventInterface
    {
        return parent::dispatch($name, $event);
    }
}
