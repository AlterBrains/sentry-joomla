<?php
/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

namespace AlterBrains\Plugin\System\Altersentry\Sentry;

use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationErrorEvent;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Event\Application;
use Joomla\CMS\Event\ErrorEvent;
use Joomla\CMS\Event\View;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseEvents;
use Joomla\DI\Container;
use Joomla\Event\DispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;

\defined('_JEXEC') or die;

/**
 * @noinspection PhpUnused
 * @since        1.0
 */
class IntegrationSite extends Integration
{
    protected const APP_TYPE = 'site';
    protected const APP_CONTAINER_RESOURCE = 'JApplicationSite';

    /**
     * @since 1.0
     */
    protected array $varsBeforeRoute = [];

    /**
     * @var ?int
     * @since 1.0
     */
    protected $responseStatusCode;

    /**
     * @since 1.0
     */
    protected ?Span $bootstrapSpan = null, $executeSpan = null, $initialiseSpan = null, $routeSpan = null, $dispatchSpan = null, $displaySpan = null, $renderSpan = null, $respondSpan = null;

    /**
     * @var ?callable
     * @since 1.0.1
     */
    protected mixed $oldExceptionHandler = null;

    /**
     * @inheritdoc
     * @since 1.0
     */
    public static function isAllowed(array $config): bool
    {
        // Check URL
        return empty($config['enabled_' . static::APP_TYPE . '_url'])
            || \preg_match('#' . $config['enabled_' . static::APP_TYPE . '_url'] . '#', static::getServerUri());
    }

    public function boot(Container $container): void
    {
        // Setup own app factory to emulate onBeforeExecute
        if ($this->config['tracing']) {
            (function ($integration) {
                /** @noinspection PhpUndefinedFieldInspection */
                $oldFactory = $this->factory;

                /** @noinspection PhpDynamicFieldDeclarationInspection */
                $this->factory = static function (...$args) use (&$oldFactory, $integration) {
                    /** @see /includes/app.json */
                    // $app->execute() is launched after app retrieval from container.
                    try {
                        return $oldFactory(...$args);
                    } catch (\Throwable $e) {
                        $error = true;

                        throw $e;
                    }
                    finally {
                        // app.bootstrap can fail!
                        if (!isset($error)) {
                            /** @var IntegrationSite $integration */
                            $integration->onBeforeExecute(\microtime(true));
                        }
                    }
                };
            })(...)->call($container->getResource(static::APP_CONTAINER_RESOURCE), $this);
        }

        // Setup dispatcher
        if ($this->config['breadcrumbs_events'] || $this->config['tracing']) {
            (function () {
                /** @noinspection PhpDynamicFieldDeclarationInspection */
                $this->factory = static function (/*Container $container*/) {
                    require_once __DIR__ . '/SentryDispatcher.php';

                    return Integration::$instance->subscribe(new SentryDispatcher(Integration::$instance->config));
                };
            })(...)->call($container->getResource(DispatcherInterface::class));
        }

        // Setup query monitor
        if ($this->config['breadcrumbs_sql'] || $this->config['tracing_sql']) {
            require_once __DIR__ . '/SentryQueryMonitor.php';
            SentryQueryMonitor::boot($container, $this->config);
        }

        // Setup cache
        if ($this->config['breadcrumbs_cache'] || $this->config['tracing_cache']) {
            (function () {
                /** @noinspection PhpDynamicFieldDeclarationInspection */
                $this->factory = static function (/*Container $container*/) {
                    require_once __DIR__ . '/SentryCacheControllerFactory.php';

                    return new SentryCacheControllerFactory(Integration::$instance->config);
                };
            })(...)->call($container->getResource(CacheControllerFactoryInterface::class));
        }
    }

    public function subscribe(SentryDispatcher $dispatcher): SentryDispatcher
    {
        // Ensure that our onAfterRoute handler is the last one.
        $dispatcher->addListener('onAfterInitialise', function () use ($dispatcher) {
            $dispatcher->addListener('onAfterRoute', [$this, 'onRouteDone'], \PHP_INT_MIN);

            // Special case for system cache plugin: it will just echo app without onAfterRespond
            if (!\JDEBUG) {
                $dispatcher->addListener('onPageCacheSetCaching', function () {
                    \register_shutdown_function([$this, 'onTerminate']);
                });
            }
        }, \PHP_INT_MIN);

        // App redirect: subscribed separately, only for redirect breadcrumb, it's only for ApplicationEvents::BEFORE_RESPOND event.
        if ($this->config['breadcrumbs_redirect'] ?? false) {
            $dispatcher->addListener(ApplicationEvents::BEFORE_RESPOND, [$this, 'onRedirect'], \PHP_INT_MAX);
        }

        // Setup exception handler
        if ($this->config['exceptions'] ?? false) {
            $dispatcher->addListener('onError', [$this, 'onError'], \PHP_INT_MAX);
            $dispatcher->addListener(ApplicationEvents::ERROR, [$this, 'onError'], \PHP_INT_MAX);

            // setup global handler right now, we need to catch error during app.bootstrap as well,
            // before Joomla adds own handler.
            // And store currently available Symfony\Component\ErrorHandler\ErrorHandler handler
            // Note: CMSApplication::execute() catches Throwable and triggers error events + \Joomla\CMS\Exception\ExceptionHandler::handleException() next
            // Hence, original Symfony handler is not used in $app->execute();
            $this->oldExceptionHandler = \set_exception_handler([$this, 'onUnhandledException']);
        }

        $dispatcher::$beforeEventCalls = [
            DatabaseEvents::POST_CONNECT => [Integration::$instance, 'onAfterConnect'],
            //SessionEvents::START              => [Integration::$instance, 'onSessionStart'],
            'onBeforeDisplay' => [Integration::$instance, 'onBeforeDisplay'],
            'onBeforeRespond' => [Integration::$instance, 'onBeforeRespond'],
            ApplicationEvents::BEFORE_RESPOND => [Integration::$instance, 'onBeforeRespond'],
        ];

        $dispatcher::$afterEventCalls = [
            'onAfterInitialise' => [Integration::$instance, 'onAfterInitialise'],
            'onAfterRoute' => [Integration::$instance, 'onAfterRoute'],
            'onAfterDisplay' => [Integration::$instance, 'onAfterDisplay'],
            'onAfterDispatch' => [Integration::$instance, 'onAfterDispatch'],
            'onAfterRender' => [Integration::$instance, 'onAfterRender'],
            'onAfterRespond' => [Integration::$instance, 'onAfterRespond'],
            ApplicationEvents::AFTER_RESPOND => [Integration::$instance, 'onAfterRespond'],
        ];

        // Just of smth goes wrong and no respond occurs
        // todo - do we actually need this, or better trace only really responded requests?
        //\register_shutdown_function([$this, 'onTerminate']);

        return $dispatcher;
    }

    /**
     * Note: respects custom config transaction_name and transaction_data
     * @since 1.0
     */
    public function startTransaction(): void
    {
        $sentry = SentrySdk::getCurrentHub();

        // Prevent starting a new transaction if we are already in a transaction
        if ($sentry->getTransaction() !== null) {
            return;
        }

        $requestPath = $_SERVER['REQUEST_URI'] ?? '/';

        // todo - add header support
        /*$context = continueTrace(
            $_SERVER['HTTP_BAGGAGE_SENTRY_TRACE'] ?? $_SERVER['HTTP_BAGGAGE_TRACEPARENT'] ?? '',
            $_SERVER['HTTP_BAGGAGE'] ?? ''
        );*/

        $context = TransactionContext::make()
            ->setName($this->config['transaction_name'] ?? $requestPath)
            ->setOp('app.' . static::APP_TYPE)
            ->setOrigin('auto.app')
            ->setSource(TransactionSource::url())
            ->setStartTimestamp($this->startTime)
            ->setData(
                ($this->config['transaction_data'] ?? [])
                + [
                    'path' => $requestPath,
                    'http.request.method' => $_SERVER['REQUEST_METHOD'],
                ]
            );

        $this->transaction = $sentry->startTransaction($context);

        SentrySdk::getCurrentHub()->setSpan($this->transaction);

        // If this transaction is not sampled, we can stop here to prevent doing work for nothing
        if (!$this->transaction->getSampled()) {
            return;
        }

        $this->bootstrapSpan = $this->transaction->startChild(
            SpanContext::make()
                ->setOp('app.bootstrap')
                ->setOrigin('auto.app')
                ->setStartTimestamp($this->startTime)
        );
    }

    public function finishTransaction(): void
    {
        if ($this->transaction === null) {
            return;
        }

        // Make sure we set the transaction and not have a child span in the Sentry SDK
        // If the transaction is not on the scope during finish, the trace.context is wrong
        SentrySdk::getCurrentHub()->setSpan($this->transaction);

        $this->configureScope();
        $this->configureScopeBeforeSend();

        $this->transaction->finish();
        $this->transaction = null;
    }

    /**
     * ApplicationEvents::AFTER_RESPOND (app redirect) has ApplicationEvent, 'onAfterRespond' has onAfterRespond
     * @since 1.0
     */
    public function onTerminate(?float $endTime = null): void
    {
        $endTime ??= \microtime(true);

        if ($this->transaction === null) {
            return;
        }

        // Terminating without proper response obtained in onAfterRespond?
        if ($this->responseStatusCode === null
            && (false === $this->responseStatusCode = \http_response_code())
        ) {
            // todo - how to get status once headers are sent?
            $this->responseStatusCode = 200;
        }

        // Trace missing routes?
        if (!$this->config['tracing_missing_routes'] && $this->responseStatusCode === 404) {
            $this->transaction = null;

            return;
        }

        // onAfterRespond (which triggers onTerminate()) can be executed before $app->respond():
        //: i.e. by system cache plugin which runs on onAfterRoute
        $this->finishRender($endTime);
        $this->finishDispatch($endTime);
        $this->finishDisplay($endTime);
        $this->finishRoute($endTime);
        $this->finishInitialise($endTime);
        $this->finishExecute($endTime);

        $this->transaction->setHttpStatus($this->responseStatusCode);

        if ($this->config['tracing_continue_after_response']) {
            \register_shutdown_function([$this, 'finishTransaction']);
        } else {
            $this->finishTransaction();
        }
    }

    /**
     * @since 1.0
     */
    public function onRedirect(): void
    {
        $response = Factory::getApplication()->getResponse();

        if ($response->hasHeader('Location')) {
            static::addBreadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_NAVIGATION,
                'app.redirect',
                null,
                [
                    'from' => Uri::getInstance()->toString(),
                    'to' => $response->getHeader('Location')[0],
                    'status' => (int)($response->getHeader('Status')[0] ?? $response->getStatusCode()),
                ]
            );
        }
    }

    /**
     * Called as registered handler for breadcrumbs only.
     * @since 1.0
     */
    public function onRouteDone(): void
    {
        if ($this->config['breadcrumbs_route']) {
            //$routeName = sprintf('Itemid %s', $this->application->getMenu()->getActive()?->id);

            // todo, can we use routeName as `com_context.display.article` or `com_context.article.save`
            static::addBreadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_NAVIGATION,
                'route.after',
                null/*$routeName*/,
                Factory::getApplication()->getInput()->getArray()
            );
        }
    }

    /**
     * Native Joomla event.
     * @since 1.0
     */
    public function onError(ErrorEvent|ApplicationErrorEvent $e): void
    {
        // No sense to call previous Symfony handler.
        $this->oldExceptionHandler = null;

        $this->onUnhandledException($e->getError());
    }

    /**
     * @since 1.0.1
     */
    public function onUnhandledException(\Throwable $e): void
    {
        // Set response status code from error, because Joomla error response still has 200
        $this->responseStatusCode = $e->getCode();

        // Skip 404s on demand
        if (!$this->config['exceptions_missing_routes'] && $this->responseStatusCode === 404) {
            return;
        }

        $this->configureScope();
        $this->configureScopeBeforeSend();

        SentrySdk::getCurrentHub()->captureException($e);

        // Call previous Symfony handler.
        if ($this->oldExceptionHandler) {
            ($this->oldExceptionHandler)($e);
            $this->oldExceptionHandler = null;
        }
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    /// Tracing events
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

    /**
     * Emulated event on app retrieval from container.
     * $app->execute() is called next in /includes/app.php
     * @since 1.0
     */
    public function onBeforeExecute(float $startTime): void
    {
        // This event is closest to start of app execute
        $this->bootstrapSpan->finish($startTime);

        $this->executeSpan = $this->transaction->startChild(
            SpanContext::make()
                ->setOp('app.execute')
                ->setOrigin('auto.app')
                ->setStartTimestamp($startTime)
        );
        SentrySdk::getCurrentHub()->setSpan($this->executeSpan);
    }

    protected function finishExecute(float $endTime): void
    {
        if ($this->executeSpan) {
            $this->executeSpan
                ->setData([
                    'memory' => \memory_get_usage(true),
                ])
                ->finish($endTime);
            $this->executeSpan = null;
        }
    }

    /**
     * This event is closest to start of app initialise.
     * @since 1.0
     */
    public function onAfterConnect(float $startTime): void
    {
        // only one time on 1st connect.
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;

        // This event is closest to start of app initialise
        if (($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
            $context = SpanContext::make()
                ->setOp('app.initialise')
                ->setOrigin('auto.app')
                ->setDescription(static::APP_TYPE)
                ->setStartTimestamp($startTime);

            static::pushSpan($this->initialiseSpan = $parentSpan->startChild($context));
        }
    }

    /**
     * Real event.
     * @since 1.0
     */
    public function onAfterInitialise(float $endTime, Application\AfterInitialiseEvent $e): void
    {
        $this->finishInitialise($endTime);

        // We have a user now.
        $this->configureUserScope($e->getApplication());

        // Route starts next, that's the only chance to emulate missing event.
        $this->onBeforeRoute($endTime);
    }

    protected function finishInitialise(float $endTime): void
    {
        if ($this->initialiseSpan) {
            $this->initialiseSpan
                ->setData([
                    'memory' => \memory_get_usage(true),
                ])
                ->finish($endTime);
            $this->initialiseSpan = null;

            static::popSpan();
        }
    }

    /**
     * Emulated event.
     * @since 1.0
     */
    public function onBeforeRoute(float $startTime): void
    {
        if (($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
            $context = SpanContext::make()
                ->setOp('app.route')
                ->setOrigin('auto.app')
                ->setStartTimestamp($startTime);

            static::pushSpan($this->routeSpan = $parentSpan->startChild($context));
        }

        // Remember vars before routing
        $this->varsBeforeRoute = Factory::getApplication()->getInput()->getArray();
    }

    /**
     * Real event.
     * @since 1.0
     */
    public function onAfterRoute(float $endTime): void
    {
        $this->finishRoute($endTime);

        // Dispatch starts next, that's the only chance to emulate missing event.
        $this->onBeforeDispatch($endTime);
    }

    protected function finishRoute(float $endTime): void
    {
        if ($this->routeSpan) {
            // Collect vars added/changed after routing
            $vars = [];
            /** @noinspection PhpLoopCanBeConvertedToArrayFilterInspection */
            foreach (Factory::getApplication()->getInput()->getArray() as $k => $v) {
                if ($v !== ($this->varsBeforeRoute[$k] ?? null)) {
                    $vars[$k] = $v;
                }
            }

            $this->routeSpan
                ->setData([
                    'memory' => \memory_get_usage(true),
                    'routed_vars' => $vars,
                ])
                ->finish($endTime);
            $this->routeSpan = null;

            static::popSpan();
        }
    }

    /**
     * Emulated event.
     * @since 1.0
     */
    public function onBeforeDispatch(float $startTime): void
    {
        if (($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
            $app = Factory::getApplication();

            // Compose desc as `option.task[.view]`
            $input = $app->getInput();
            $option = $input->getCmd('option');
            $task = $input->getCmd('task');
            $view = $input->getCmd('view');
            $Itemid = $input->getCmd('Itemid');

            $desc = $option;
            if ($task) {
                $desc .= '.' . $task;
            }
            // todo - add task/view

            $context = SpanContext::make()
                ->setOp('app.dispatch')
                ->setOrigin('auto.app')
                ->setDescription($desc)
                ->setStartTimestamp($startTime)
                ->setData([
                    'language' => $app->getLanguage()->getTag(),
                    'option' => $option,
                    'view' => $view,
                    'task' => $task,
                    'Itemid' => $Itemid,
                ]);

            static::pushSpan($this->dispatchSpan = $parentSpan->startChild($context));
        }
    }

    /**
     * Real event.
     * @since 1.0
     */
    public function onAfterDispatch(float $endTime): void
    {
        $this->finishDispatch($endTime);

        // Render starts next, that's the only chance to emulate missing event.
        $this->onBeforeRender($endTime);
    }

    protected function finishDispatch(float $endTime): void
    {
        if ($this->dispatchSpan) {
            $this->dispatchSpan
                ->setData([
                    'memory' => \memory_get_usage(true),
                ])
                ->finish($endTime);
            $this->dispatchSpan = null;

            static::popSpan();
        }
    }

    /**
     * Real event.
     * @since 1.0
     */
    public function onBeforeDisplay(float $startTime, View\DisplayEvent $e): void
    {
        if (($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
            $context = SpanContext::make()
                ->setOp('app.display')
                ->setOrigin('auto.app')
                // com_content.article
                ->setDescription($e->getArgument('extension'))
                ->setStartTimestamp($startTime);

            static::pushSpan($this->displaySpan = $parentSpan->startChild($context));
        }
    }

    /**
     * Real event.
     * @since 1.0
     */
    public function onAfterDisplay(float $endTime): void
    {
        $this->finishDisplay($endTime);
    }

    protected function finishDisplay(float $endTime): void
    {
        if ($this->displaySpan) {
            $this->displaySpan
                ->setData([
                    'memory' => \memory_get_usage(true),
                ])
                ->finish($endTime);
            $this->displaySpan = null;

            static::popSpan();
        }
    }

    /**
     * Emulated event.
     * @since 1.0
     */
    public function onBeforeRender(float $startTime): void
    {
        if (($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
            $context = SpanContext::make()
                ->setOp('app.render')
                ->setOrigin('auto.app')
                ->setStartTimestamp($startTime);

            static::pushSpan($this->renderSpan = $parentSpan->startChild($context));
        }
    }

    /**
     * Real event.
     * @since 1.0
     */
    public function onAfterRender(float $endTime): void
    {
        $this->finishRender($endTime);
    }

    protected function finishRender(float $endTime): void
    {
        if ($this->renderSpan) {
            $this->renderSpan
                ->setDescription(Factory::getApplication()->getDocument()->getType())
                ->setData([
                    'memory' => \memory_get_usage(true),
                ])
                ->finish($endTime);
            $this->renderSpan = null;

            static::popSpan();
        }
    }

    /**
     * Real event.
     * ApplicationEvents::BEFORE_RESPOND (app redirect) has ApplicationEvent, 'onBeforeRespond' has BeforeRespondEvent
     * @since 1.0
     */
    public function onBeforeRespond(float $startTime, Application\BeforeRespondEvent|ApplicationEvent $e): void
    {
        // A redirect can occur at any stage, finish all spans in reverse order, we should start 'app.respond' span
        $this->finishRender($startTime);
        $this->finishDispatch($startTime);
        $this->finishDisplay($startTime);
        $this->finishRoute($startTime);
        $this->finishInitialise($startTime);

        // Prevent recursion from bad $app->redirect() called in onBeforeRespond event.
        if ($this->respondSpan) {
            return;
        }

        if (($parentSpan = SentrySdk::getCurrentHub()->getSpan()) && $parentSpan->getSampled()) {
            $context = SpanContext::make()
                ->setOp('app.respond')
                ->setOrigin('auto.app')
                ->setStartTimestamp($startTime);

            $response = Factory::getApplication()->getResponse();

            // $app->redirect() uses ApplicationEvents::BEFORE_RESPOND but not onBeforeRespond
            if ($response->hasHeader('Location') && $e->getName() === ApplicationEvents::BEFORE_RESPOND) {
                $context->setData([
                    'redirect.from' => Uri::getInstance()->toString(),
                    'redirect.to' => $response->getHeader('Location')[0],
                    'redirect.status' => (int)($response->getHeader('Status')[0] ?? $response->getStatusCode()),
                    'redirect.origin' => static::resolveEventOriginAsString(7, 5),
                ]);
            }

            static::pushSpan($this->respondSpan = $parentSpan->startChild($context));
        }
    }

    /**
     * Real event.
     * ApplicationEvents::AFTER_RESPOND (app redirect) has ApplicationEvent, 'onAfterRespond' has onAfterRespond
     * @since 1.0
     */
    public function onAfterRespond(float $endTime, Application\AfterRespondEvent|ApplicationEvent $e): void
    {
        // Note: Joomla error response still has 200 here
        // We could already set the correct code from error before, in onError()
        $this->responseStatusCode ??= $e->getApplication()->getResponse()->getStatusCode();

        $this->finishRespond($endTime, $e->getApplication()->getResponse());

        // That's all folks
        $this->onTerminate($endTime);
    }

    protected function finishRespond(float $endTime, ResponseInterface $response): void
    {
        if ($this->respondSpan) {
            $contentType = $response->getHeader('Content-Type')[0] ?? '';
            // Strip encoding
            if (\str_contains($contentType, ';')) {
                [$contentType,] = \explode(';', $contentType);
            }

            $this->respondSpan
                ->setDescription($contentType)
                ->setData([
                    'memory' => \memory_get_usage(true),
                    'response.content_type' => $contentType,
                    'response.body_length' => $response->getBody()->getSize(),
                    'response.status_code' => (int)($response->getHeader('Status')[0] ?? $response->getStatusCode()),
                ])
                ->finish($endTime);
            $this->respondSpan = null;

            static::popSpan();
        }
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    /// Misc
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

    public static function getServerUri(): string
    {
        /** @noinspection HttpUrlsUsage */
        $protocol = 'http://';
        if ((!empty($_SERVER['HTTPS']) && \strtolower($_SERVER['HTTPS']) !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && \strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) !== 'http'
            )
        ) {
            $protocol = 'https://';
        }

        if (!empty($_SERVER['PHP_SELF']) && !empty($_SERVER['REQUEST_URI'])) {
            $uri = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        } else {
            $uri = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

            if (!empty($_SERVER['QUERY_STRING'])) {
                $uri .= '?' . $_SERVER['QUERY_STRING'];
            }
        }

        // Extra cleanup to remove invalid chars in the URL to prevent injections through the Host header
        return \strtr($uri, [
            "'" => '%27',
            '"' => '%22',
            '<' => '%3C',
            '>' => '%3E',
        ]);
    }

}
