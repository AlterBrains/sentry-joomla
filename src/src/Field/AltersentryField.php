<?php

/**
 * @package    Alter Sentry
 * @copyright  Copyright (C) 2025-2025 AlterBrains.com. All rights reserved.
 * @license    https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL
 */

namespace AlterBrains\Plugin\System\Altersentry\Field;

use AlterBrains\Plugin\System\Altersentry\Sentry\Integration;
use Joomla\CMS\Form\FormField;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * @noinspection PhpUnused
 * @since        1.0
 */
class AltersentryField extends FormField
{
    /**
     * @var string
     * @since 1.0
     */
    protected $type = 'Aoinfo';

    /**
     * @inheritDoc
     * @since 1.0
     */
    public function getInput()
    {
        return '';
    }

    /**
     * @inheritDoc
     * @since 1.0
     */
    public function validate($value, $group = null, ?Registry $input = null)
    {
        $config = (array) $input->get('params');

        // Sentry requires non-empty value
        if (empty($config['sentry_environment'])) {
            $config['sentry_environment'] = 'production';
        }
        if (empty($config['sentry_release'])) {
            $config['sentry_release'] = 'JVERSION';
        }

        // Basic
        $config['enabled']               = (bool) $config['enabled'];
        $config['enabled_site']          = (bool) $config['enabled_site'];
        $config['enabled_administrator'] = (bool) $config['enabled_administrator'];
        $config['enabled_cli']           = (bool) $config['enabled_cli'];
        $config['enabled_api']           = (bool) $config['enabled_api'];

        // Core
        $config['sentry_max_breadcrumbs']  = (int) $config['sentry_max_breadcrumbs'];
        $config['sentry_max_value_length'] = (int) $config['sentry_max_value_length'];
        $config['sentry_send_default_pii'] = (bool) $config['sentry_send_default_pii'];

        // Exceptions
        $config['sentry_sample_rate']       = (float) $config['sentry_sample_rate'];
        $config['sentry_context_lines']     = (int) $config['sentry_context_lines'];
        $config['sentry_ignore_exceptions'] = $this->normalizeMultiInput($config['sentry_ignore_exceptions']);
        $config['sentry_error_types']       = (int) $config['sentry_error_types'];

        // Breadcrumbs
        $config['breadcrumbs_route']         = (bool) $config['breadcrumbs_route'];
        $config['breadcrumbs_cache']         = (bool) $config['breadcrumbs_cache'];
        $config['breadcrumbs_sql']           = (bool) $config['breadcrumbs_sql'];
        $config['breadcrumbs_sql_bindings']  = (bool) $config['breadcrumbs_sql_bindings'];
        $config['breadcrumbs_events']        = (bool) $config['breadcrumbs_events'];
        $config['breadcrumbs_events_only']   = $this->normalizeMultiInput($config['breadcrumbs_events_only']);
        $config['breadcrumbs_events_ignore'] = $this->normalizeMultiInput($config['breadcrumbs_events_ignore']);

        // Tracing
        $config['sentry_traces_sample_rate'] = (float) $config['sentry_traces_sample_rate'];
        $config['tracing_cache']             = (bool) $config['tracing_cache'];
        $config['tracing_sql']               = (bool) $config['tracing_sql'];
        $config['tracing_sql_bindings']      = (bool) $config['tracing_sql_bindings'];
        $config['tracing_events']            = (bool) $config['tracing_events'];
        $config['tracing_events_only']       = $this->normalizeMultiInput($config['tracing_events_only']);
        $config['tracing_events_ignore']     = $this->normalizeMultiInput($config['tracing_events_ignore']);

        if (empty($config['tracing'])) {
            unset($config['sentry_traces_sample_rate']);
        }

        // Custom Sentry options
        if ($config['sentry_custom']) {
            try {
                $sentryCustom = \json_decode($config['sentry_custom'], true, 512, \JSON_THROW_ON_ERROR);
            } catch (\Exception $e) {
                return new \UnexpectedValueException(\sprintf('Invalid Custom Options JSON: %s', $e->getMessage()));
            }
        }
        unset($config['sentry_custom']);

        // Profiling
        $config['sentry_profiles_sample_rate'] = (float) $config['sentry_profiles_sample_rate'];

        // Group sentry settings
        foreach ($config as $k => $v) {
            if (\str_starts_with($k, 'sentry_')) {
                $config['sentry'][\substr($k, 7)] = $v;
                unset($config[$k]);
            }
        }
        $config['sentry'] = ($sentryCustom ?? []) + $config['sentry'];

        Integration::writeConfig($config);

        return true;
    }

    protected function normalizeMultiInput(string $input): array
    {
        return \array_filter(
            \array_map('\trim', \explode(',', \strtr(\trim($input), [
                "\r\n" => ',',
                "\n"   => ',',
                "\r"   => ',',
            ])))
        );
    }
}
