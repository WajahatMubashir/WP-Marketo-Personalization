<?php
/**
 * Plugin Name: Marketo Personalization
 * Description: Marketo web-segment personalization for dynamic content and CTAs.
 * Version: 1.0.0
 * Author: Wajahat Mubashir
 * Author URI: https://wajahatmubashir.netlify.app/
 */

defined('ABSPATH') || exit;

define('MP_PATH', plugin_dir_path(__FILE__));
define('MP_URL', plugin_dir_url(__FILE__));

// Legacy aliases to avoid breaking existing references.
if (!defined('mp_PATH')) {
    define('mp_PATH', MP_PATH);
}
if (!defined('mp_URL')) {
    define('mp_URL', MP_URL);
}

require_once MP_PATH . 'includes/helpers.php';
require_once MP_PATH . 'includes/api.php';
require_once MP_PATH . 'includes/rest.php';
require_once MP_PATH . 'includes/admin.php';
require_once MP_PATH . 'includes/shortcodes.php';

add_action('init', function () {
    register_block_type(MP_PATH . 'blocks/dynamic-content');
});

/**
 * Pass dynamic segment list into the block editor for authoring controls.
 */
add_action('enqueue_block_editor_assets', function () {
    $segments = mp_get_segment_keys();
    $payload  = [];

    foreach ($segments as $slug => $label) {
        $payload[] = [
            'key'   => $slug,
            'label' => $label,
        ];
    }

    wp_add_inline_script(
        'wp-blocks',
        'window.mpSegments = ' . wp_json_encode($payload) . '; window.tcpMpSegments = window.mpSegments;',
        'before'
    );
});

/**
 * Enqueue frontend script.
 *
 * NOTE: We enqueue on wp_enqueue_scripts so Kadence/header/footer elements can be personalized
 * even when our block is not present on the page.
 */
function mp_enqueue_frontend_assets() {
    if (is_admin()) {
        return;
    }

    if (wp_script_is('tcp-mp-frontend', 'enqueued')) {
        return;
    }

    wp_enqueue_script(
        'tcp-mp-frontend',
        MP_URL . 'assets/frontend.js',
        [],
        '1.0.3',
        true
    );

    // Provide a reliable REST root even when wpApiSettings is not present.
    wp_localize_script(
        'tcp-mp-frontend',
        'wpApiSettings',
        [
            'root' => esc_url_raw(rest_url()),
        ]
    );
}

add_action('wp_enqueue_scripts', 'mp_enqueue_frontend_assets', 20);
add_action('enqueue_block_assets', 'mp_enqueue_frontend_assets');

/**
 * Reduce FOOC (flash of default content) by setting the segment attribute ASAP when we already have it.
 */
add_action('wp_head', function () {
    if (is_admin()) {
        return;
    }
    if (!empty($_COOKIE['tcp_user_segment'])) {
        $seg = mp_segment_to_key($_COOKIE['tcp_user_segment']);
        echo "<script>(function(){try{document.documentElement.setAttribute('data-web-segment','" . esc_js($seg) . "');}catch(e){}})();</script>";
    }
}, 1);
