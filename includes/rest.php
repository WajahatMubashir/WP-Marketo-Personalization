<?php

defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route(
        'tcp/v1',
        '/segment',
        [
            'methods'             => 'GET',
            'callback'            => 'mp_get_segment',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'tcp/v1',
        '/personalization',
        [
            'methods'             => 'GET',
            'callback'            => 'mp_get_personalization',
            'permission_callback' => '__return_true',
        ]
    );
});

function mp_get_segment()
{
    if (!empty($_COOKIE['tcp_user_segment'])) {
        $existing = mp_sanitize_segment($_COOKIE['tcp_user_segment']);
        if ($existing) {
            return ['segment' => mp_segment_to_key($existing)];
        }
    }

    if (empty($_COOKIE['_mkto_trk'])) {
        return ['segment' => 'default'];
    }

    $segment = mp_fetch_from_marketo($_COOKIE['_mkto_trk']);

    if (!$segment) {
        $segment = 'default';
    }

    $segment = mp_segment_to_key($segment);

    mp_set_segment_cookie($segment);

    return ['segment' => $segment];
}

/**
 * Returns current segment plus CTA config for that segment.
 */
function mp_get_personalization()
{
    $seg_response = mp_get_segment();
    $segment      = is_array($seg_response) && !empty($seg_response['segment']) ? $seg_response['segment'] : 'Default';

    return [
        'segment' => $segment,
        'ctas'    => mp_get_all_cta_configs($segment),
    ];
}

// Legacy aliases (tcp_* functions) for backward compatibility.
// Removed to prevent redeclaration conflicts when legacy plugins are present.
