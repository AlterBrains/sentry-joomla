# Sentry plugin for Joomla!

Open-source error tracking and performance monitoring for Joomla! An implementation of the [Sentry Client](https://github.com/getsentry/sentry-php) library for Joomla.

[Sentry](https://sentry.io) is a Application Monitoring and Error Tracking Software.

## Installation and configuration of Sentry

Create a new project in [Sentry's Dashboard](https://sentry.io/settings/projects/) and find the DSN in "Project Settings > Client Keys".

Even free plan is enough to have a nice error tracking, but paid plans are recommended for advanced tracing details. Free plan includes 10M spans but this limit can be easily exhausted depending on the number of page views.

## Installation and configuration in Joomla!

Install the plugin in Joomla! Extension Manager. You can find the latest release [here](https://github.com/AlterBrains/sentry-joomla/releases).

Create a file `defines.php` in the root folder of your Joomla! installation:

```php
<?php
require __DIR__ . '/plugins/system/altersentry/src/bootstrap.php';
```

If you want to also use Sentry for Joomla! administrator area, additionally create a file `defines.php` in `/administrator` folder:

```php
<?php
require __DIR__ . '/../plugins/system/altersentry/src/bootstrap.php';
```
If you already have `defines.php` file, you need to prepend the code.

Currently, we only support integration for site and administrator applications. Support for CLI and API requests can be added later.

Edit "System - Alter Sentry" plugin in Joomla! Extension Manager, set Status to Enabled and adjust all settings as required.

Click "Toggle Inline Help" toolbar button for setting descriptions.

Note: the plugin is actually only used for configuration file changes. Disabling the plugin won't stop the Sentry integration. You need to disable integration in plugin settings.

The plugin updates the configuration file `plugins/system/altersentry/config.php`. This file is git-ignored.

You can create additional custom config `plugins/system/altersentry/config.custom.php`, see `bootstrap.php` plugin file for details.

## Implementation notes

Unfortunately, due to Joomla! code limitations, it's impossible to provide the 100% correct application request lifecycle.

There are specific spans which are kind a "virtual" because the application code lacks specific events (i.e. `onBeforeExecute` event is missed in `CMSApplication`):

- `app.execute` start is assumed once an application is resolved from container.
- `app.initialise` start is assumed once a database connection is established. It's the closest possible traceable event.
- `app.route` start is assumed right after the `onAfterInitialise` event. 
- `app.dispatch` start is assumed right after the `onAfterRoute` event.
- `app.render` start is assumed right after the `onAfterDispatch` event.

Anyway, we managed to make the tracing as close as possible. And, without core files modifications. And, with minimum possible performance impact.

Note: we don't recommend permanent tracing for high-loaded production websites. Tracing is enabled for a certain period of time to debug the application.

## Copyright & License

- (C) 2025 AlterBrains. <https://alterbrains.com>
- Distributed under the GNU General Public License version 2 or later, see LICENSE.txt
