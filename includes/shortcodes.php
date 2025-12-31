<?php

defined('ABSPATH') || exit;

/**
 * [mp_dynamic key="..."] (alias: [tcp_dynamic])
 * Renders a placeholder that the frontend script can replace using the personalization payload.
 *
 * Example:
 *   [mp_dynamic key="hero_headline.text" default="Default headline"]
 */
function mp_shortcode_dynamic($atts = []) {
	$atts = shortcode_atts([
		'key'     => '',
		'default' => '',
		'tag'     => 'span',
	], $atts, 'mp_dynamic');

	$key = trim((string) $atts['key']);
	if ($key === '') {
		return '';
	}

	$tag = preg_replace('/[^a-z0-9\-]/i', '', (string) $atts['tag']);
	if ($tag === '') {
		$tag = 'span';
	}

	$default = (string) $atts['default'];

	return sprintf(
		'<%1$s class="mp-dynamic" data-mp-key="%2$s" data-mp-default="%3$s">%4$s</%1$s>',
		esc_attr($tag),
		esc_attr($key),
		esc_attr($default),
		esc_html($default)
	);
}
add_shortcode('tcp_dynamic', 'mp_shortcode_dynamic');
add_shortcode('mp_dynamic', 'mp_shortcode_dynamic');
