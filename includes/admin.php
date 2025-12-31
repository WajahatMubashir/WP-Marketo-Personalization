<?php

defined('ABSPATH') || exit;

add_action('admin_menu', 'mp_register_settings_page');
add_action('admin_init', 'mp_register_settings');

function mp_register_settings_page()
{
    add_options_page(
        __('Marketo Personalization', 'tcp-mp'),
        __('Marketo Personalization', 'tcp-mp'),
        'manage_options',
        'tcp-mp-settings',
        'mp_render_settings_page'
    );
}

function mp_register_settings()
{
    register_setting(
        'mp_settings',
        'mp_settings',
        [
            'type'              => 'array',
            'sanitize_callback' => 'mp_sanitize_settings',
            'default'           => [],
        ]
    );

    add_settings_section(
        'mp_api_section',
        __('Marketo API', 'tcp-mp'),
        function () {
            echo '<p>' . esc_html__('Enter your Marketo credentials. These values are stored in the database, not in code.', 'tcp-mp') . '</p>';
        },
        'mp_settings'
    );

    add_settings_field(
        'mp_identity_url',
        __('Identity URL', 'tcp-mp'),
        'mp_render_text_field',
        'mp_settings',
        'mp_api_section',
        [
            'label_for'   => 'mp_identity_url',
            'option_key'  => 'identity_url',
            'placeholder' => 'https://123-ABC-456.mktorest.com/identity',
        ]
    );

    add_settings_field(
        'mp_rest_url',
        __('REST URL', 'tcp-mp'),
        'mp_render_text_field',
        'mp_settings',
        'mp_api_section',
        [
            'label_for'   => 'mp_rest_url',
            'option_key'  => 'rest_url',
            'placeholder' => 'https://123-ABC-456.mktorest.com/rest',
        ]
    );

    add_settings_field(
        'mp_client_id',
        __('Client ID', 'tcp-mp'),
        'mp_render_text_field',
        'mp_settings',
        'mp_api_section',
        [
            'label_for'  => 'mp_client_id',
            'option_key' => 'client_id',
        ]
    );

    add_settings_field(
        'mp_client_secret',
        __('Client Secret', 'tcp-mp'),
        'mp_render_password_field',
        'mp_settings',
        'mp_api_section',
        [
            'label_for'  => 'mp_client_secret',
            'option_key' => 'client_secret',
        ]
    );

    add_settings_field(
        'mp_segment_field',
        __('Segment Field', 'tcp-mp'),
        'mp_render_text_field',
        'mp_settings',
        'mp_api_section',
        [
            'label_for'   => 'mp_segment_field',
            'option_key'  => 'segment_field',
            'placeholder' => 'web_segment',
        ]
    );

    // Segmentation options.
    add_settings_section(
        'mp_segment_section',
        __('Segmentation', 'tcp-mp'),
        function () {
            echo '<p>' . esc_html__('Match segment keys to your Marketo setup. You can rename segments or map Marketo values to the plugin slugs.', 'tcp-mp') . '</p>';
        },
        'mp_settings'
    );

    add_settings_field(
        'mp_segments',
        __('Segment list', 'tcp-mp'),
        'mp_render_segments_field',
        'mp_settings',
        'mp_segment_section'
    );

    add_settings_field(
        'mp_segment_alias',
        __('Marketo value aliases', 'tcp-mp'),
        'mp_render_segment_alias_field',
        'mp_settings',
        'mp_segment_section'
    );

    // CTA personalization section.
    add_settings_section(
        'mp_cta_section',
        __('CTA Personalization', 'tcp-mp'),
        function () {
            echo '<p>' . esc_html__('Configure CTA text and URLs per Marketo segment. Add your own CTA keys and map them to CSS selectors/classes so you do not need code changes.', 'tcp-mp') . '</p>';
        },
        'mp_settings'
    );

    add_settings_field(
        'mp_cta_keys',
        __('CTA keys', 'tcp-mp'),
        'mp_render_cta_keys_field',
        'mp_settings',
        'mp_cta_section'
    );

    $cta_keys = mp_get_cta_keys();

    foreach ($cta_keys as $cta_key) {
        $label = ucwords(str_replace('_', ' ', $cta_key));

        add_settings_field(
            'mp_cta_' . $cta_key,
            sprintf(esc_html__('%s CTA', 'tcp-mp'), $label),
            'mp_render_cta_table',
            'mp_settings',
            'mp_cta_section',
            [
                'cta_key'   => $cta_key,
                'cta_label' => $label,
            ]
        );

        add_settings_field(
            'mp_cta_selectors_' . $cta_key,
            sprintf(esc_html__('%s selectors', 'tcp-mp'), $label),
            'mp_render_cta_selector_field',
            'mp_settings',
            'mp_cta_section',
            [
                'cta_key'   => $cta_key,
                'cta_label' => $label,
            ]
        );
    }
}

function mp_sanitize_settings($input)
{
    $input = is_array($input) ? $input : [];

    $output = [
        'identity_url'  => mp_normalize_url($input['identity_url'] ?? ''),
        'rest_url'      => mp_normalize_url($input['rest_url'] ?? ''),
        'client_id'     => sanitize_text_field($input['client_id'] ?? ''),
        'client_secret' => sanitize_text_field($input['client_secret'] ?? ''),
        'segment_field' => sanitize_text_field($input['segment_field'] ?? 'web_segment'),
        'segments'      => [],
        'segment_alias' => [],
        'cta'           => [],
        'cta_keys'      => [],
        'cta_selectors' => [],
        'cta_update_href' => [],
    ];

    if (empty($output['segment_field'])) {
        $output['segment_field'] = 'web_segment';
    }

    // Reset cached token when credentials change.
    delete_transient('mp_marketo_token');
    delete_transient('tcp_mp_marketo_token');

    // Segments list (slug|Label per line). Default stays in place even if omitted.
    $raw_segments = isset($input['segments']) ? (string) $input['segments'] : '';
    $segments     = [];

    if ($raw_segments !== '') {
        $lines = preg_split('/[\r\n]+/', $raw_segments);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line);
            $slug  = sanitize_title($parts[0]);
            $label = isset($parts[1]) ? sanitize_text_field($parts[1]) : '';

            if ($slug === '') {
                continue;
            }

            if ($label === '') {
                $label = ucwords(str_replace('_', ' ', $slug));
            }

            if ($slug === 'default') {
                // Preserve existing default label if already defined.
                $segments['default'] = $label ?: 'Default';
                continue;
            }

            $segments[$slug] = $label;
        }
    }

    // Ensure default always exists.
    if (!isset($segments['default'])) {
        $segments['default'] = 'Default';
    }

    $output['segments'] = $segments;

    // Aliases: one per line in the format "Marketo Value => plugin_slug".
    $alias_raw = isset($input['segment_alias']) ? (string) $input['segment_alias'] : '';
    if ($alias_raw !== '') {
        $lines = preg_split('/[\r\n]+/', $alias_raw);
        foreach ($lines as $line) {
            if (strpos($line, '=>') === false) {
                continue;
            }
            list($raw_value, $target_slug) = array_map('trim', explode('=>', $line, 2));
            $slug = sanitize_title($target_slug);
            if ($raw_value === '' || $slug === '') {
                continue;
            }

            $output['segment_alias'][sanitize_text_field($raw_value)] = $slug;
        }
    }

    // Sanitize CTA settings.
    $segment_keys = array_keys($segments);

    $default_cta_keys = ['demo', 'help_choose', 'banner'];
    $custom_cta_input = isset($input['cta_keys']) ? (string) $input['cta_keys'] : '';
    $custom_cta_keys  = [];

    if ($custom_cta_input !== '') {
        $raw_keys = preg_split('/[\s,]+/', $custom_cta_input);
        foreach ($raw_keys as $key) {
            $key = strtolower(trim($key));
            if ($key === '') {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_]/', '', $key);
            if ($key === '' || in_array($key, $default_cta_keys, true)) {
                continue;
            }
            $custom_cta_keys[] = $key;
        }
    }

    $output['cta_keys'] = array_values(array_unique($custom_cta_keys));
    $cta_keys           = array_merge($default_cta_keys, $output['cta_keys']);

    $raw_cta = isset($input['cta']) && is_array($input['cta']) ? $input['cta'] : [];

    foreach ($cta_keys as $cta_key) {
        $output['cta'][$cta_key] = [];
        foreach ($segment_keys as $seg_key) {
            $text = $raw_cta[$cta_key][$seg_key]['text'] ?? '';
            $url  = $raw_cta[$cta_key][$seg_key]['url'] ?? '';

            $output['cta'][$cta_key][$seg_key] = [
                'text' => sanitize_text_field($text),
                'url'  => esc_url_raw($url),
            ];
        }

        // Selectors are comma- or space-separated CSS selectors (ideally classes).
        if (!empty($input['cta_selectors'][$cta_key])) {
            $selectors_raw = (string) $input['cta_selectors'][$cta_key];
            $selectors     = preg_split('/[,]+/', $selectors_raw);
            $cleaned       = [];
            foreach ($selectors as $sel) {
                $sel = trim($sel);
                if ($sel === '') {
                    continue;
                }
                // Allow common selector characters (IDs, classes, attributes, combinators).
                $sel = preg_replace('/[^.#a-z0-9_\-\s\[\]="\':>+~]/i', '', $sel);
                if ($sel !== '') {
                    $cleaned[] = $sel;
                }
            }
            $output['cta_selectors'][$cta_key] = $cleaned;
        }

        $output['cta_update_href'][$cta_key] = !empty($input['cta_update_href'][$cta_key]);
    }

    return $output;
}

function mp_render_cta_table($args)
{
    $settings   = mp_get_settings();
    $cta_key    = $args['cta_key'];
    $cta_config = isset($settings['cta'][$cta_key]) && is_array($settings['cta'][$cta_key]) ? $settings['cta'][$cta_key] : [];
    $segments   = mp_get_segment_keys();

    echo '<table class="widefat striped" style="max-width:900px">';
    echo '<thead><tr><th>' . esc_html__('Segment', 'tcp-mp') . '</th><th>' . esc_html__('Text', 'tcp-mp') . '</th><th>' . esc_html__('URL', 'tcp-mp') . '</th></tr></thead>';
    echo '<tbody>';

    foreach ($segments as $seg_key => $seg_label) {
        $text = $cta_config[$seg_key]['text'] ?? '';
        $url  = $cta_config[$seg_key]['url'] ?? '';

        echo '<tr>';
        echo '<td><strong>' . esc_html($seg_label) . '</strong></td>';
        printf(
            '<td><input type="text" class="regular-text" name="mp_settings[cta][%1$s][%2$s][text]" value="%3$s" /></td>',
            esc_attr($cta_key),
            esc_attr($seg_key),
            esc_attr($text)
        );
        printf(
            '<td><input type="url" class="regular-text" name="mp_settings[cta][%1$s][%2$s][url]" value="%3$s" placeholder="https://..." /></td>',
            esc_attr($cta_key),
            esc_attr($seg_key),
            esc_attr($url)
        );
        echo '</tr>';
    }

    echo '</tbody></table>';
    $default_class = 'mp-cta--' . str_replace('_', '-', $cta_key);
    $data_attr     = 'data-mp-cta="' . $cta_key . '"';

    echo '<p class="description">' . sprintf(
        esc_html__('Target any CSS selectors (no special class required). You can still use %1$s or %2$s if you like, but existing selectors on your site work fine.', 'tcp-mp'),
        '<code>' . esc_html($default_class) . '</code>',
        '<code>' . esc_html($data_attr) . '</code>'
    ) . '</p>';
}

function mp_render_cta_selector_field($args)
{
    $settings  = mp_get_settings();
    $cta_key   = $args['cta_key'];
    $selectors = '';

    if (!empty($settings['cta_selectors'][$cta_key]) && is_array($settings['cta_selectors'][$cta_key])) {
        $selectors = implode(', ', $settings['cta_selectors'][$cta_key]);
    }

    $update_href = true;
    if (isset($settings['cta_update_href'][$cta_key])) {
        $update_href = (bool) $settings['cta_update_href'][$cta_key];
    } elseif ($cta_key === 'help_choose') {
        $update_href = false;
    }

    ?>
    <p>
        <label for="<?php echo esc_attr('mp_cta_selectors_' . $cta_key); ?>"><strong><?php esc_html_e('Selectors (comma separated)', 'tcp-mp'); ?></strong></label><br />
        <input type="text" class="regular-text" id="<?php echo esc_attr('mp_cta_selectors_' . $cta_key); ?>" name="mp_settings[cta_selectors][<?php echo esc_attr($cta_key); ?>]" value="<?php echo esc_attr($selectors); ?>" placeholder=".hero h1, #cta a.btn, .price-card .cta" />
    </p>
    <label>
        <input type="checkbox" name="mp_settings[cta_update_href][<?php echo esc_attr($cta_key); ?>]" value="1" <?php checked($update_href); ?> />
        <?php esc_html_e('Update href attribute (disable if the element opens a modal)', 'tcp-mp'); ?>
    </label>
    <?php
}

function mp_render_cta_keys_field()
{
    $settings      = mp_get_settings();
    $custom_keys   = isset($settings['cta_keys']) && is_array($settings['cta_keys']) ? $settings['cta_keys'] : [];
    $custom_output = implode(', ', $custom_keys);

    ?>
    <p>
        <label for="mp_cta_keys"><strong><?php esc_html_e('Additional CTA keys', 'tcp-mp'); ?></strong></label><br />
        <input type="text" class="regular-text" id="mp_cta_keys" name="mp_settings[cta_keys]" value="<?php echo esc_attr($custom_output); ?>" placeholder="cta_one, cta_two" />
    </p>
    <p class="description">
        <?php esc_html_e('Add any CTA/text keys (e.g. hero_headline, pricing_cta) to personalize headings, buttons, or links. Use your own selectors below—no special class required.', 'tcp-mp'); ?>
    </p>
    <?php
}

function mp_render_segments_field()
{
    $segments = mp_get_segment_keys();

    // Render as lines: slug|Label (skip default to avoid duplication).
    $lines = [];
    foreach ($segments as $slug => $label) {
        if ($slug === 'default') {
            continue;
        }
        $lines[] = $slug . '|' . $label;
    }

    ?>
    <p>
        <label for="mp_segments"><strong><?php esc_html_e('Segment slugs and labels', 'tcp-mp'); ?></strong></label><br />
        <textarea id="mp_segments" name="mp_settings[segments]" rows="5" cols="60" class="large-text code"><?php echo esc_textarea(implode("\n", $lines)); ?></textarea>
    </p>
    <p class="description">
        <?php esc_html_e('One per line as slug|Label (default is always present). The first value is the slug (auto-lowercased/slugified); the second is the friendly label shown in the block editor. Use the segment value from your Marketo Web Segmentation (e.g. the segment names under Web - Industry) as the basis for the slug. If Marketo returns a different value than your slug, map it below in "Marketo value aliases".', 'tcp-mp'); ?>
    </p>
    <?php
}

function mp_render_segment_alias_field()
{
    $settings = mp_get_settings();
    $aliases  = '';

    if (!empty($settings['segment_alias']) && is_array($settings['segment_alias'])) {
        $lines = [];
        foreach ($settings['segment_alias'] as $raw => $slug) {
            $lines[] = $raw . ' => ' . $slug;
        }
        $aliases = implode("\n", $lines);
    }

    ?>
    <p>
        <label for="mp_segment_alias"><strong><?php esc_html_e('Alias Marketo values to a slug', 'tcp-mp'); ?></strong></label><br />
        <textarea id="mp_segment_alias" name="mp_settings[segment_alias]" rows="4" cols="60" class="large-text code"><?php echo esc_textarea($aliases); ?></textarea>
    </p>
    <p class="description">
        <?php esc_html_e('One per line in the format "Marketo Value => plugin_slug". Put the exact value Marketo returns (from your Web Segmentation, e.g. Education) on the left, and the slug you defined above on the right (e.g. "Education => education"). Leave blank if Marketo already matches your slugs.', 'tcp-mp'); ?>
    </p>
    <?php
}

function mp_render_text_field($args)
{
    $settings = mp_get_settings();
    $key      = $args['option_key'];
    $value    = isset($settings[$key]) ? $settings[$key] : '';
    $id       = esc_attr($args['label_for']);
    $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';

    printf(
        '<input name="mp_settings[%1$s]" id="%2$s" type="text" class="regular-text" value="%3$s" placeholder="%4$s" />',
        esc_attr($key),
        $id,
        esc_attr($value),
        esc_attr($placeholder)
    );
}

function mp_render_password_field($args)
{
    $settings = mp_get_settings();
    $key      = $args['option_key'];
    $value    = isset($settings[$key]) ? $settings[$key] : '';
    $id       = esc_attr($args['label_for']);

    printf(
        '<input name="mp_settings[%1$s]" id="%2$s" type="password" class="regular-text" value="%3$s" autocomplete="off" />',
        esc_attr($key),
        $id,
        esc_attr($value)
    );
}

function mp_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Marketo Personalization', 'tcp-mp'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('mp_settings');
            do_settings_sections('mp_settings');
            submit_button();
            ?>
        </form>
    </div>
    
    <?php
}
