<?php

defined('ABSPATH') || exit;

/**
 * Fetch settings saved in the options table with safe defaults.
 */
function mp_get_settings()
{
    $defaults = [
        'identity_url'  => '',
        'rest_url'      => '',
        'client_id'     => '',
        'client_secret' => '',
        'segment_field' => 'web_segment',
        // Customizable segment list and mapping.
        'segments'      => [],
        'segment_alias' => [],
        // CTA personalization settings.
        'cta'           => [],
        'cta_keys'      => [],
        'cta_selectors' => [],
        'cta_update_href' => [],
    ];

    $options = get_option('mp_settings', []);

    // Legacy fallback for existing installs.
    if ((!is_array($options) || empty($options)) && get_option('tcp_mp_settings')) {
        $options = get_option('tcp_mp_settings', []);
    }

    if (!is_array($options)) {
        $options = [];
    }

    return wp_parse_args($options, $defaults);
}

/**
 * Canonical segment keys used by the plugin.
 */
function mp_get_segment_keys()
{
    $settings = mp_get_settings();
    $custom   = isset($settings['segments']) && is_array($settings['segments']) ? $settings['segments'] : [];

    // Start with only the required default; admins can add any others.
    $defaults = ['default' => 'Default'];

    $segments = !empty($custom) ? $custom : $defaults;

    // Always ensure a default segment exists.
    if (!isset($segments['default'])) {
        $segments['default'] = 'Default';
    }

    return $segments;
}

/**
 * CTA keys (built-in + custom).
 */
function mp_get_cta_keys()
{
    $settings = mp_get_settings();
    $custom   = isset($settings['cta_keys']) && is_array($settings['cta_keys']) ? $settings['cta_keys'] : [];
    $savedCta = isset($settings['cta']) && is_array($settings['cta']) ? array_keys($settings['cta']) : [];

    $keys = array_values(array_unique(array_merge($savedCta, $custom)));

    return $keys;
}

/**
 * Convert a segment label into a stable key.
 */
function mp_segment_to_key($segment)
{
    $segment = (string) $segment;
    $segment = strtolower(trim($segment));
    if ($segment === '') {
        return 'default';
    }

    $segment = preg_replace('/\s+/', '_', $segment);
    $segment = str_replace('-', '_', $segment);

    // Normalize common variants.
    if ($segment === 'publicsafety') {
        $segment = 'public_safety';
    }

    // Allow admin-specified aliases to map raw Marketo values to the canonical slug.
    $settings = mp_get_settings();
    if (!empty($settings['segment_alias']) && is_array($settings['segment_alias'])) {
        foreach ($settings['segment_alias'] as $raw_value => $slug) {
            $raw_value = strtolower(trim((string) $raw_value));
            $slug      = strtolower(trim((string) $slug));
            if ($raw_value !== '' && $raw_value === $segment && $slug !== '') {
                $segment = $slug;
                break;
            }
        }
    }

    $known = array_keys(mp_get_segment_keys());
    if (in_array($segment, $known, true)) {
        return $segment;
    }

    return 'default';
}

/**
 * Get CTA config for a segment key with fallback to Default.
 */
function mp_get_cta_config($cta_key, $segment)
{
    $settings = mp_get_settings();
    $cta      = isset($settings['cta']) && is_array($settings['cta']) ? $settings['cta'] : [];
    $seg_key  = mp_segment_to_key($segment);

    $entry = $cta[$cta_key][$seg_key] ?? null;
    if (!is_array($entry) || (empty($entry['text']) && empty($entry['url']))) {
        $entry = $cta[$cta_key]['default'] ?? ['text' => '', 'url' => ''];
    }

    $selectors = [];
    if (!empty($settings['cta_selectors'][$cta_key]) && is_array($settings['cta_selectors'][$cta_key])) {
        $selectors = array_filter(array_map('trim', $settings['cta_selectors'][$cta_key]));
    }

    $update_href = true;
    if (isset($settings['cta_update_href'][$cta_key])) {
        $update_href = (bool) $settings['cta_update_href'][$cta_key];
    } elseif ($cta_key === 'help_choose') {
        // Preserve existing behavior for the modal/open-in-place CTA.
        $update_href = false;
    }

    return [
        'text' => isset($entry['text']) ? sanitize_text_field($entry['text']) : '',
        'url'  => isset($entry['url']) ? esc_url_raw($entry['url']) : '',
        'selectors'   => $selectors,
        'update_href' => $update_href,
    ];
}

/**
 * Build a full CTA payload for all keys for a given segment.
 */
function mp_get_all_cta_configs($segment)
{
    $keys   = mp_get_cta_keys();
    $output = [];

    foreach ($keys as $key) {
        $output[$key] = mp_get_cta_config($key, $segment);
    }

    return $output;
}

/**
 * Normalize a Marketo base URL by trimming trailing slashes.
 */
function mp_normalize_url($url)
{
    $url = trim((string) $url);

    if ($url === '') {
        return '';
    }

    return rtrim($url, '/');
}

/**
 * Safely set the segment cookie.
 */
function mp_set_segment_cookie($segment)
{
    $segment = sanitize_text_field($segment);

    $args = [
        'expires'  => time() + DAY_IN_SECONDS,
        'path'     => defined('COOKIEPATH') ? COOKIEPATH : '/',
        'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
        'secure'   => is_ssl(),
        'httponly' => true,
    ];

    // Use SameSite where supported (PHP 7.3+).
    if (PHP_VERSION_ID >= 70300) {
        $args['samesite'] = 'Lax';
        setcookie('tcp_user_segment', $segment, $args);
    } else {
        setcookie(
            'tcp_user_segment',
            $segment,
            $args['expires'],
            $args['path'],
            $args['domain'],
            $args['secure'],
            $args['httponly']
        );
    }
}

/**
 * Retrieve a clean segment value.
 */
function mp_sanitize_segment($segment)
{
    if (!is_scalar($segment)) {
        return false;
    }

    $segment = sanitize_text_field((string) $segment);

    if ($segment === '') {
        return false;
    }

    return $segment;
}
