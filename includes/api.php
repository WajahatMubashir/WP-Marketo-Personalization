<?php

defined('ABSPATH') || exit;

/**
 * Marketo connection settings pulled from saved options.
 */
function mp_get_marketo_settings()
{
    $saved = mp_get_settings();

    $settings = [
        'identity_url'  => mp_normalize_url($saved['identity_url']),
        'rest_url'      => mp_normalize_url($saved['rest_url']),
        'client_id'     => $saved['client_id'],
        'client_secret' => $saved['client_secret'],
        'segment_field' => $saved['segment_field'] ?: 'web_segment',
    ];

    /**
     * Filter the Marketo API settings before use.
     */
    $settings = apply_filters('mp_marketo_settings', $settings);

    // Legacy filter name for compatibility.
    return apply_filters('tcp_mp_marketo_settings', $settings);
}

/**
 * Get or request a Marketo access token.
 */
function mp_get_marketo_access_token()
{
    $cached = get_transient('mp_marketo_token');
    if (empty($cached)) {
        $cached = get_transient('tcp_mp_marketo_token');
    }
    if (!empty($cached)) {
        return $cached;
    }

    $settings = mp_get_marketo_settings();

    if (empty($settings['identity_url']) || empty($settings['client_id']) || empty($settings['client_secret'])) {
        return false;
    }

    $token_url = sprintf(
        '%s/oauth/token?grant_type=client_credentials&client_id=%s&client_secret=%s',
        $settings['identity_url'],
        rawurlencode($settings['client_id']),
        rawurlencode($settings['client_secret'])
    );

    $response = wp_remote_get(
        $token_url,
        [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]
    );

    if (is_wp_error($response)) {
        return false;
    }

    if (wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['access_token'])) {
        return false;
    }

    $ttl = !empty($body['expires_in']) ? max(60, absint($body['expires_in']) - 60) : HOUR_IN_SECONDS;
    set_transient('mp_marketo_token', $body['access_token'], $ttl);
    // Legacy transient for compatibility.
    set_transient('tcp_mp_marketo_token', $body['access_token'], $ttl);

    return $body['access_token'];
}

/**
 * Fetch a segment from Marketo for the provided munchkin cookie.
 */
function mp_fetch_from_marketo($mkto_cookie)
{
    $mkto_cookie = sanitize_text_field($mkto_cookie);

    if ($mkto_cookie === '') {
        return false;
    }

    $settings = mp_get_marketo_settings();
    if (empty($settings['rest_url'])) {
        return false;
    }

    $token = mp_get_marketo_access_token();
    if (!$token) {
        return false;
    }

    $segment_field = $settings['segment_field'] ?: 'web_segment';

    // Be forgiving with admin input:
    // - If they paste "https://xxx.mktorest.com/rest" => use it as-is.
    // - If they paste the base "https://xxx.mktorest.com" => append "/rest".
    $rest_base = untrailingslashit($settings['rest_url']);
    if (!preg_match('#/rest$#i', $rest_base)) {
        $rest_base .= '/rest';
    }

    // Preferred Marketo lookup:
    // /rest/v1/leads.json?filterType=cookie&filterValues=<cookie>&fields=<field>
    $endpoint = sprintf(
        '%s/v1/leads.json?filterType=cookie&filterValues=%s&fields=%s',
        $rest_base,
        rawurlencode($mkto_cookie),
        rawurlencode($segment_field)
    );

    $response = wp_remote_get(
        $endpoint,
        [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]
    );

    if (is_wp_error($response)) {
        return false;
    }

    if (wp_remote_retrieve_response_code($response) !== 200) {
        // Back-compat (older builds used a non-standard cookie= param). Try once.
        $fallback = sprintf(
            '%s/v1/leads.json?cookie=%s&fields=%s',
            $rest_base,
            rawurlencode($mkto_cookie),
            rawurlencode($segment_field)
        );

        $response = wp_remote_get(
            $fallback,
            [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ],
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['success']) || empty($body['result'][0][$segment_field])) {
        return false;
    }

    $segment = $body['result'][0][$segment_field];

    $segment = apply_filters('mp_segment_value', $segment, $body, $segment_field);
    $segment = apply_filters('tcp_mp_segment_value', $segment, $body, $segment_field);

    return mp_sanitize_segment($segment);
}
