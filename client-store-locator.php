<?php
/**
 * Plugin Name: Client Store Locator
 * Description: Store locator with keyword and CSV sources, combined map results, and optional store-source filters.
 * Version: 1.2.0
 * Author: Neon Cactus Media
 * Author URI: https://neoncactusmedia.com
 */

if (!defined('ABSPATH')) {
    exit;
}

class Client_Store_Locator {
    const OPTION_KEY = 'csl_client_configs';
    const CACHE_OPTION_KEY = 'csl_csv_source_cache';
    const VERSION = '1.2.0';

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_csl_regenerate_csv_cache', [$this, 'handle_regenerate_csv_cache']);
        add_action('admin_post_csl_clear_csv_cache', [$this, 'handle_clear_csv_cache']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);

        add_shortcode('client_store_locator', [$this, 'render_locator_shortcode']);

        add_action('wp_ajax_csl_get_csv_locations', [$this, 'ajax_get_csv_locations']);
        add_action('wp_ajax_nopriv_csl_get_csv_locations', [$this, 'ajax_get_csv_locations']);
        add_action('wp_ajax_csl_update_row_coords', [$this, 'ajax_update_row_coords']);
        add_action('wp_ajax_nopriv_csl_update_row_coords', [$this, 'ajax_update_row_coords']);
    }

    public function admin_menu() {
        add_menu_page(
            'Store Locator',
            'Store Locator',
            'manage_options',
            'client-store-locator',
            [$this, 'render_admin_page'],
            'dashicons-location-alt',
            25
        );
    }

    public function register_settings() {
        register_setting(
            'csl_settings_group',
            self::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_client_configs'],
                'default' => [],
            ]
        );
    }

    public function register_assets() {
        wp_register_style(
            'csl-locator',
            plugin_dir_url(__FILE__) . 'assets/locator.css',
            [],
            self::VERSION
        );

        wp_register_script(
            'csl-locator',
            plugin_dir_url(__FILE__) . 'assets/locator.js',
            [],
            self::VERSION,
            true
        );
    }

    public function sanitize_client_configs($input) {
        if (!is_array($input)) {
            return [];
        }

        $clean = [];

        foreach ($input as $client) {
            if (!is_array($client)) {
                continue;
            }

            $name = sanitize_text_field($client['name'] ?? '');
            $slug_raw = $client['slug'] ?? $name;
            $slug = sanitize_title($slug_raw);

            if ($name === '' || $slug === '') {
                continue;
            }

            $source_type = ($client['source_type'] ?? 'keywords') === 'csv' ? 'csv' : 'keywords';

            $clean[$slug] = [
                'name' => $name,
                'slug' => $slug,
                'enabled' => empty($client['enabled']) ? '0' : '1',
                'include_in_all' => empty($client['include_in_all']) ? '0' : '1',
                'source_type' => $source_type,
                'include_keywords' => $this->sanitize_multiline_field($client['include_keywords'] ?? ''),
                'exclude_terms' => $this->sanitize_multiline_field($client['exclude_terms'] ?? ''),
                'csv_url' => esc_url_raw($client['csv_url'] ?? ''),
                'csv_name_column' => sanitize_text_field($client['csv_name_column'] ?? 'Name'),
                'csv_address_column' => sanitize_text_field($client['csv_address_column'] ?? ''),
                'csv_address_1_column' => sanitize_text_field($client['csv_address_1_column'] ?? 'Address Line 1'),
                'csv_address_2_column' => sanitize_text_field($client['csv_address_2_column'] ?? 'Address Line 2'),
                'csv_city_column' => sanitize_text_field($client['csv_city_column'] ?? 'City'),
                'csv_state_column' => sanitize_text_field($client['csv_state_column'] ?? 'State'),
                'csv_postcode_column' => sanitize_text_field($client['csv_postcode_column'] ?? 'Postcode'),
                'csv_country_column' => sanitize_text_field($client['csv_country_column'] ?? 'Country'),
                'csv_phone_column' => sanitize_text_field($client['csv_phone_column'] ?? 'Phone'),
                'csv_email_column' => sanitize_text_field($client['csv_email_column'] ?? 'Email'),
                'csv_url_column' => sanitize_text_field($client['csv_url_column'] ?? 'URL'),
                'csv_visible_column' => sanitize_text_field($client['csv_visible_column'] ?? 'Visible'),
                'csv_filters_column' => sanitize_text_field($client['csv_filters_column'] ?? 'Filters'),
                'csv_marker_id_column' => sanitize_text_field($client['csv_marker_id_column'] ?? 'Marker ID'),
                'csv_notes_column' => sanitize_text_field($client['csv_notes_column'] ?? 'Notes'),
                'csv_lat_column' => sanitize_text_field($client['csv_lat_column'] ?? 'Latitude'),
                'csv_lng_column' => sanitize_text_field($client['csv_lng_column'] ?? 'Longitude'),
            ];
        }

        return $clean;
    }

    private function sanitize_multiline_field($value) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        $lines = array_map('sanitize_text_field', $lines);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, static function ($line) {
            return $line !== '';
        });

        return implode("\n", $lines);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $configs = get_option(self::OPTION_KEY, []);
        $cache = $this->get_cache_store();
        ?>
        <div class="wrap">
            <h1>Client Store Locator Settings</h1>
            <p>Use each item below as a store source. A source can use Google keyword search or a CSV file. CSV sources are normalized into a cached store index so the front end does not geocode every row on every search.</p>

            <?php if (isset($_GET['csl_notice'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['csl_notice'])); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('csl_settings_group'); ?>

                <div id="csl-client-list">
                    <?php
                    if (!empty($configs)) {
                        $i = 0;
                        foreach ($configs as $client) {
                            $client_cache = isset($cache[$client['slug']]) ? $cache[$client['slug']] : [];
                            $this->render_client_fields($i, $client, $client_cache);
                            $i++;
                        }
                    } else {
                        $this->render_client_fields(0, [], []);
                    }
                    ?>
                </div>

                <p>
                    <button type="button" class="button" id="csl-add-client">Add Store Source</button>
                </p>

                <?php submit_button('Save Store Sources'); ?>
            </form>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const addBtn = document.getElementById('csl-add-client');
            const list = document.getElementById('csl-client-list');

            if (!addBtn || !list) return;

            addBtn.addEventListener('click', function () {
                const index = list.querySelectorAll('.csl-client-card').length;
                const optionKey = <?php echo wp_json_encode(self::OPTION_KEY); ?>;

                const template = `
                    <div class="csl-client-card" style="border:1px solid #ddd;padding:16px;margin:16px 0;background:#fff;">
                        <h2>Store Source</h2>

                        <p>
                            <label><input type="checkbox" name="${optionKey}[${index}][enabled]" value="1" checked> <strong>Enabled</strong></label><br>
                            <label><input type="checkbox" name="${optionKey}[${index}][include_in_all]" value="1" checked> Include in default/all map mode</label>
                        </p>

                        <p><label><strong>Name</strong><br>
                        <input type="text" name="${optionKey}[${index}][name]" class="regular-text"></label></p>

                        <p><label><strong>Slug</strong><br>
                        <input type="text" name="${optionKey}[${index}][slug]" class="regular-text"></label></p>

                        <p><label><strong>Source Type</strong><br>
                            <select name="${optionKey}[${index}][source_type]">
                                <option value="keywords">Keywords</option>
                                <option value="csv">CSV</option>
                            </select>
                        </label></p>

                        <p><label><strong>Include Keywords</strong><br>
                        <textarea name="${optionKey}[${index}][include_keywords]" rows="5" cols="60"></textarea><br>
                        <em>One search term per line.</em></label></p>

                        <p><label><strong>Exclude Terms</strong><br>
                        <textarea name="${optionKey}[${index}][exclude_terms]" rows="5" cols="60"></textarea><br>
                        <em>Any result containing one of these terms will be removed.</em></label></p>

                        <p><label><strong>CSV URL</strong><br>
                        <input type="url" name="${optionKey}[${index}][csv_url]" class="regular-text code"></label></p>

                        <p><strong>CSV Column Mapping</strong></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_name_column]" placeholder="Name" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_address_column]" placeholder="Full Address (optional)" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_address_1_column]" placeholder="Address Line 1" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_address_2_column]" placeholder="Address Line 2" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_city_column]" placeholder="City" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_state_column]" placeholder="State" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_postcode_column]" placeholder="Postcode" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_country_column]" placeholder="Country" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_phone_column]" placeholder="Phone" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_email_column]" placeholder="Email" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_url_column]" placeholder="URL" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_visible_column]" placeholder="Visible" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_filters_column]" placeholder="Filters" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_marker_id_column]" placeholder="Marker ID" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_notes_column]" placeholder="Notes" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_lat_column]" placeholder="Latitude" class="regular-text"></p>
                        <p><input type="text" name="${optionKey}[${index}][csv_lng_column]" placeholder="Longitude" class="regular-text"></p>
                    </div>
                `;

                list.insertAdjacentHTML('beforeend', template);
            });
        });
        </script>
        <?php
    }

    private function render_client_fields($i, $client, $client_cache) {
        $defaults = [
            'name' => '',
            'slug' => '',
            'enabled' => '1',
            'include_in_all' => '1',
            'source_type' => 'keywords',
            'include_keywords' => '',
            'exclude_terms' => '',
            'csv_url' => '',
            'csv_name_column' => 'Name',
            'csv_address_column' => '',
            'csv_address_1_column' => 'Address Line 1',
            'csv_address_2_column' => 'Address Line 2',
            'csv_city_column' => 'City',
            'csv_state_column' => 'State',
            'csv_postcode_column' => 'Postcode',
            'csv_country_column' => 'Country',
            'csv_phone_column' => 'Phone',
            'csv_email_column' => 'Email',
            'csv_url_column' => 'URL',
            'csv_visible_column' => 'Visible',
            'csv_filters_column' => 'Filters',
            'csv_marker_id_column' => 'Marker ID',
            'csv_notes_column' => 'Notes',
            'csv_lat_column' => 'Latitude',
            'csv_lng_column' => 'Longitude',
        ];

        $client = wp_parse_args($client, $defaults);
        $slug = $client['slug'];
        $cache_rows = isset($client_cache['rows']) && is_array($client_cache['rows']) ? count($client_cache['rows']) : 0;
        $cache_missing = isset($client_cache['missing_coords']) ? (int) $client_cache['missing_coords'] : 0;
        $cache_generated = !empty($client_cache['generated_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $client_cache['generated_at']) : 'Not generated yet';
        ?>
        <div class="csl-client-card" style="border:1px solid #ddd;padding:16px;margin:16px 0;background:#fff;">
            <h2><?php echo esc_html($client['name'] ?: 'Store Source'); ?></h2>

            <?php if ($slug !== '' && $client['source_type'] === 'csv') : ?>
                <p><strong>Cache:</strong> <?php echo esc_html($cache_rows); ?> rows, <?php echo esc_html($cache_missing); ?> missing coordinates. Last generated: <?php echo esc_html($cache_generated); ?>.</p>
                <p>
                    <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=csl_regenerate_csv_cache&source=' . rawurlencode($slug)), 'csl_regenerate_csv_cache_' . $slug)); ?>">Regenerate CSV Cache</a>
                    <a class="button button-link-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=csl_clear_csv_cache&source=' . rawurlencode($slug)), 'csl_clear_csv_cache_' . $slug)); ?>" onclick="return confirm('Clear the cached rows for this source?');">Clear Cache</a>
                </p>
            <?php endif; ?>

            <p>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][enabled]" value="1" <?php checked($client['enabled'], '1'); ?>> <strong>Enabled</strong></label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][include_in_all]" value="1" <?php checked($client['include_in_all'], '1'); ?>> Include in default/all map mode</label>
            </p>

            <p>
                <label><strong>Name</strong><br>
                    <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][name]" value="<?php echo esc_attr($client['name']); ?>" class="regular-text">
                </label>
            </p>

            <p>
                <label><strong>Slug</strong><br>
                    <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][slug]" value="<?php echo esc_attr($client['slug']); ?>" class="regular-text">
                </label>
            </p>

            <p>
                <label><strong>Source Type</strong><br>
                    <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][source_type]">
                        <option value="keywords" <?php selected($client['source_type'], 'keywords'); ?>>Keywords</option>
                        <option value="csv" <?php selected($client['source_type'], 'csv'); ?>>CSV</option>
                    </select>
                </label>
            </p>

            <p>
                <label><strong>Include Keywords</strong><br>
                    <textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][include_keywords]" rows="5" cols="60"><?php echo esc_textarea($client['include_keywords']); ?></textarea><br>
                    <em>One search term per line.</em>
                </label>
            </p>

            <p>
                <label><strong>Exclude Terms</strong><br>
                    <textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][exclude_terms]" rows="5" cols="60"><?php echo esc_textarea($client['exclude_terms']); ?></textarea><br>
                    <em>Any result containing one of these terms will be removed.</em>
                </label>
            </p>

            <p>
                <label><strong>CSV URL</strong><br>
                    <input type="url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_url]" value="<?php echo esc_attr($client['csv_url']); ?>" class="regular-text code">
                </label>
            </p>

            <p><strong>CSV Column Mapping</strong></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_name_column]" value="<?php echo esc_attr($client['csv_name_column']); ?>" placeholder="Name" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_address_column]" value="<?php echo esc_attr($client['csv_address_column']); ?>" placeholder="Full Address (optional)" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_address_1_column]" value="<?php echo esc_attr($client['csv_address_1_column']); ?>" placeholder="Address Line 1" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_address_2_column]" value="<?php echo esc_attr($client['csv_address_2_column']); ?>" placeholder="Address Line 2" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_city_column]" value="<?php echo esc_attr($client['csv_city_column']); ?>" placeholder="City" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_state_column]" value="<?php echo esc_attr($client['csv_state_column']); ?>" placeholder="State" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_postcode_column]" value="<?php echo esc_attr($client['csv_postcode_column']); ?>" placeholder="Postcode" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_country_column]" value="<?php echo esc_attr($client['csv_country_column']); ?>" placeholder="Country" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_phone_column]" value="<?php echo esc_attr($client['csv_phone_column']); ?>" placeholder="Phone" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_email_column]" value="<?php echo esc_attr($client['csv_email_column']); ?>" placeholder="Email" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_url_column]" value="<?php echo esc_attr($client['csv_url_column']); ?>" placeholder="URL" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_visible_column]" value="<?php echo esc_attr($client['csv_visible_column']); ?>" placeholder="Visible" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_filters_column]" value="<?php echo esc_attr($client['csv_filters_column']); ?>" placeholder="Filters" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_marker_id_column]" value="<?php echo esc_attr($client['csv_marker_id_column']); ?>" placeholder="Marker ID" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_notes_column]" value="<?php echo esc_attr($client['csv_notes_column']); ?>" placeholder="Notes" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_lat_column]" value="<?php echo esc_attr($client['csv_lat_column']); ?>" placeholder="Latitude" class="regular-text"></p>
            <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($i); ?>][csv_lng_column]" value="<?php echo esc_attr($client['csv_lng_column']); ?>" placeholder="Longitude" class="regular-text"></p>
        </div>
        <?php
    }

    public function render_locator_shortcode($atts) {
        $atts = shortcode_atts([
            'client' => '',
            'clients' => '',
            'all_clients' => 'false',
            'show_filters' => 'true',
            'default_radius' => '16093',
            'zoom' => '12',
        ], $atts, 'client_store_locator');

        $configs = get_option(self::OPTION_KEY, []);
        $resolved_clients = $this->resolve_clients_for_shortcode($atts, $configs);

        if (empty($resolved_clients)) {
            return '<p>No enabled store sources were found for this locator.</p>';
        }

        $instance_id = 'csl-' . wp_generate_password(8, false, false);

        wp_enqueue_style('csl-locator');
        wp_enqueue_script('csl-locator');

        $client_payload = array_map(function ($client) {
            return [
                'name' => $client['name'],
                'slug' => $client['slug'],
                'source_type' => $client['source_type'],
                'include_keywords_array' => $this->textarea_to_array($client['include_keywords'] ?? ''),
                'exclude_terms_array' => $this->textarea_to_array($client['exclude_terms'] ?? ''),
            ];
        }, $resolved_clients);

        $payload = [
            'instanceId' => $instance_id,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'clients' => array_values($client_payload),
            'defaultCenter' => [
                'lat' => 33.4484,
                'lng' => -112.0740,
            ],
            'defaultRadius' => (int) $atts['default_radius'],
            'defaultZoom' => max(1, (int) $atts['zoom']),
            'showFilters' => $atts['show_filters'] !== 'false',
            'strings' => [
                'searching' => 'Searching…',
                'noResults' => 'No locations found nearby.',
                'geocodeError' => 'Couldn’t find that location. Try a different ZIP or city/state.',
                'locationError' => 'Couldn’t access your location. Try searching by ZIP instead.',
                'emptyAddress' => 'Enter a ZIP code or city/state.',
                'directions' => 'Directions',
                'allStores' => 'All Stores',
            ],
        ];

        wp_add_inline_script('csl-locator', 'window.CSL_LOCATORS = window.CSL_LOCATORS || {}; window.CSL_LOCATORS[' . wp_json_encode($instance_id) . '] = ' . wp_json_encode($payload) . ';', 'before');

        ob_start();
        ?>
        <div class="csl-locator" id="<?php echo esc_attr($instance_id); ?>" data-instance-id="<?php echo esc_attr($instance_id); ?>">
            <div class="csl-controls">
                <input type="text" class="csl-address" placeholder="Enter city, zip, or address">
                <select class="csl-radius">
                    <option value="8047" <?php selected((int) $atts['default_radius'], 8047); ?>>5 miles</option>
                    <option value="16093" <?php selected((int) $atts['default_radius'], 16093); ?>>10 miles</option>
                    <option value="32187" <?php selected((int) $atts['default_radius'], 32187); ?>>20 miles</option>
                    <option value="48280" <?php selected((int) $atts['default_radius'], 48280); ?>>30 miles</option>
                    <option value="80467" <?php selected((int) $atts['default_radius'], 80467); ?>>50 miles</option>
                </select>
                <button type="button" class="csl-search-btn">Search</button>
                <button type="button" class="csl-use-location-btn">Use My Location</button>
            </div>

            <?php if ($atts['show_filters'] !== 'false') : ?>
                <div class="csl-filters" hidden></div>
            <?php endif; ?>

            <div class="csl-map"></div>
            <ul class="csl-results"></ul>
        </div>
        <?php
        return ob_get_clean();
    }

    private function resolve_clients_for_shortcode($atts, $configs) {
        $resolved = [];
        $mode_all = $atts['all_clients'] === 'true' || ($atts['client'] === '' && $atts['clients'] === '');

        if ($mode_all) {
            foreach ($configs as $client) {
                if (($client['enabled'] ?? '0') !== '1') {
                    continue;
                }
                if (($client['include_in_all'] ?? '0') !== '1') {
                    continue;
                }
                $resolved[] = $client;
            }
            return $resolved;
        }

        $slugs = [];

        if ($atts['client'] !== '') {
            $slugs[] = sanitize_title($atts['client']);
        }

        if ($atts['clients'] !== '') {
            $parts = array_map('trim', explode(',', $atts['clients']));
            foreach ($parts as $part) {
                if ($part !== '') {
                    $slugs[] = sanitize_title($part);
                }
            }
        }

        $slugs = array_values(array_unique($slugs));

        foreach ($slugs as $slug) {
            if (!isset($configs[$slug])) {
                continue;
            }
            if (($configs[$slug]['enabled'] ?? '0') !== '1') {
                continue;
            }
            $resolved[] = $configs[$slug];
        }

        return $resolved;
    }

    public function ajax_get_csv_locations() {
        $client_slug = sanitize_title(wp_unslash($_GET['client'] ?? ''));
        $configs = get_option(self::OPTION_KEY, []);

        if ($client_slug === '' || empty($configs[$client_slug])) {
            wp_send_json_error(['message' => 'Store source not found.'], 404);
        }

        $config = $configs[$client_slug];

        if (($config['enabled'] ?? '0') !== '1') {
            wp_send_json_error(['message' => 'Store source is disabled.'], 403);
        }

        if (($config['source_type'] ?? '') !== 'csv') {
            wp_send_json_error(['message' => 'This source is not configured for CSV mode.'], 400);
        }

        $cache_entry = $this->get_or_build_csv_cache($client_slug, $config);
        if (is_wp_error($cache_entry)) {
            wp_send_json_error(['message' => $cache_entry->get_error_message()], 500);
        }

        wp_send_json_success(isset($cache_entry['rows']) ? $cache_entry['rows'] : []);
    }

    public function ajax_update_row_coords() {
        $client_slug = sanitize_title(wp_unslash($_POST['client'] ?? ''));
        $row_key = sanitize_text_field(wp_unslash($_POST['row_key'] ?? ''));
        $address_hash = sanitize_text_field(wp_unslash($_POST['address_hash'] ?? ''));
        $lat = isset($_POST['lat']) ? (float) wp_unslash($_POST['lat']) : null;
        $lng = isset($_POST['lng']) ? (float) wp_unslash($_POST['lng']) : null;

        if ($client_slug === '' || $row_key === '' || $address_hash === '' || $lat === null || $lng === null) {
            wp_send_json_error(['message' => 'Missing required coordinate payload.'], 400);
        }

        $cache = $this->get_cache_store();
        if (empty($cache[$client_slug]['rows']) || !is_array($cache[$client_slug]['rows'])) {
            wp_send_json_error(['message' => 'Cache entry not found.'], 404);
        }

        $updated = false;
        $missing = 0;

        foreach ($cache[$client_slug]['rows'] as &$row) {
            if (($row['row_key'] ?? '') !== $row_key) {
                if (empty($row['lat']) || empty($row['lng'])) {
                    $missing++;
                }
                continue;
            }

            if (($row['address_hash'] ?? '') !== $address_hash) {
                wp_send_json_error(['message' => 'Cached row no longer matches this address.'], 409);
            }

            $row['lat'] = (string) $lat;
            $row['lng'] = (string) $lng;
            $updated = true;
        }
        unset($row);

        if (!$updated) {
            wp_send_json_error(['message' => 'Row not found in cache.'], 404);
        }

        $cache[$client_slug]['missing_coords'] = $missing;
        $cache[$client_slug]['generated_at'] = time();
        update_option(self::CACHE_OPTION_KEY, $cache, false);

        wp_send_json_success(['message' => 'Coordinates cached.']);
    }

    public function handle_regenerate_csv_cache() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $slug = sanitize_title(wp_unslash($_GET['source'] ?? ''));
        check_admin_referer('csl_regenerate_csv_cache_' . $slug);

        $configs = get_option(self::OPTION_KEY, []);
        if ($slug === '' || empty($configs[$slug])) {
            wp_safe_redirect(add_query_arg('csl_notice', rawurlencode('Store source not found.'), admin_url('admin.php?page=client-store-locator')));
            exit;
        }

        $result = $this->build_csv_cache($slug, $configs[$slug], true);
        $notice = is_wp_error($result) ? $result->get_error_message() : 'CSV cache regenerated.';
        wp_safe_redirect(add_query_arg('csl_notice', rawurlencode($notice), admin_url('admin.php?page=client-store-locator')));
        exit;
    }

    public function handle_clear_csv_cache() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $slug = sanitize_title(wp_unslash($_GET['source'] ?? ''));
        check_admin_referer('csl_clear_csv_cache_' . $slug);

        $cache = $this->get_cache_store();
        if ($slug !== '' && isset($cache[$slug])) {
            unset($cache[$slug]);
            update_option(self::CACHE_OPTION_KEY, $cache, false);
        }

        wp_safe_redirect(add_query_arg('csl_notice', rawurlencode('CSV cache cleared.'), admin_url('admin.php?page=client-store-locator')));
        exit;
    }

    private function get_or_build_csv_cache($slug, $config) {
        $cache = $this->get_cache_store();
        $current_hash = $this->build_source_hash($config);

        if (!empty($cache[$slug]['source_hash']) && $cache[$slug]['source_hash'] === $current_hash && isset($cache[$slug]['rows'])) {
            return $cache[$slug];
        }

        return $this->build_csv_cache($slug, $config, true);
    }

    private function build_csv_cache($slug, $config, $force = false) {
        if (($config['source_type'] ?? '') !== 'csv') {
            return new WP_Error('invalid_source_type', 'This source is not configured for CSV mode.');
        }

        $csv_url = trim((string) ($config['csv_url'] ?? ''));
        if ($csv_url === '') {
            return new WP_Error('missing_csv_url', 'CSV URL is missing.');
        }

        $cache = $this->get_cache_store();
        $current_hash = $this->build_source_hash($config);

        if (!$force && !empty($cache[$slug]['source_hash']) && $cache[$slug]['source_hash'] === $current_hash && isset($cache[$slug]['rows'])) {
            return $cache[$slug];
        }

        $response = wp_remote_get($csv_url, [
            'timeout' => 20,
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('csv_fetch_failed', 'Could not fetch the CSV file.');
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            return new WP_Error('csv_bad_status', 'CSV request returned an unexpected status code.');
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return new WP_Error('csv_empty', 'CSV response was empty.');
        }

        $rows = $this->parse_csv_string($body);
        if (count($rows) < 2) {
            return new WP_Error('csv_not_enough_rows', 'CSV must include a header row and at least one data row.');
        }

        $existing_rows = !empty($cache[$slug]['rows']) && is_array($cache[$slug]['rows']) ? $cache[$slug]['rows'] : [];
        $existing_by_key = [];
        foreach ($existing_rows as $existing_row) {
            if (!empty($existing_row['row_key'])) {
                $existing_by_key[$existing_row['row_key']] = $existing_row;
            }
        }

        $normalized_rows = $this->normalize_csv_rows($rows, $config, $existing_by_key);
        $missing_coords = 0;
        foreach ($normalized_rows as $row) {
            if ($row['lat'] === '' || $row['lng'] === '') {
                $missing_coords++;
            }
        }

        $cache[$slug] = [
            'source_hash' => $current_hash,
            'csv_hash' => md5($body),
            'generated_at' => time(),
            'missing_coords' => $missing_coords,
            'rows' => $normalized_rows,
        ];

        update_option(self::CACHE_OPTION_KEY, $cache, false);

        return $cache[$slug];
    }

    private function normalize_csv_rows($rows, $config, $existing_by_key) {
        $headers = array_map('trim', $rows[0]);
        $normalized = [];

        $name_col = $config['csv_name_column'] ?? 'Name';
        $address_col = $config['csv_address_column'] ?? '';
        $address_1_col = $config['csv_address_1_column'] ?? 'Address Line 1';
        $address_2_col = $config['csv_address_2_column'] ?? 'Address Line 2';
        $city_col = $config['csv_city_column'] ?? 'City';
        $state_col = $config['csv_state_column'] ?? 'State';
        $postcode_col = $config['csv_postcode_column'] ?? 'Postcode';
        $country_col = $config['csv_country_column'] ?? 'Country';
        $phone_col = $config['csv_phone_column'] ?? 'Phone';
        $email_col = $config['csv_email_column'] ?? 'Email';
        $url_col = $config['csv_url_column'] ?? 'URL';
        $visible_col = $config['csv_visible_column'] ?? 'Visible';
        $filters_col = $config['csv_filters_column'] ?? 'Filters';
        $marker_id_col = $config['csv_marker_id_column'] ?? 'Marker ID';
        $notes_col = $config['csv_notes_column'] ?? 'Notes';
        $lat_col = $config['csv_lat_column'] ?? 'Latitude';
        $lng_col = $config['csv_lng_column'] ?? 'Longitude';

        for ($i = 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            if (empty(array_filter($row, static function ($value) {
                return trim((string) $value) !== '';
            }))) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $index => $header) {
                $assoc[$header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
            }

            if (!$this->row_is_visible($assoc[$visible_col] ?? '')) {
                continue;
            }

            $full_address = trim((string) ($assoc[$address_col] ?? ''));
            $address_1 = trim((string) ($assoc[$address_1_col] ?? ''));
            $address_2 = trim((string) ($assoc[$address_2_col] ?? ''));
            $city = trim((string) ($assoc[$city_col] ?? ''));
            $state = trim((string) ($assoc[$state_col] ?? ''));
            $postcode = trim((string) ($assoc[$postcode_col] ?? ''));
            $country = trim((string) ($assoc[$country_col] ?? ''));

            if ($full_address === '') {
                $parts = array_filter([$address_1, $address_2, $city, $state, $postcode, $country], static function ($value) {
                    return trim((string) $value) !== '';
                });
                $full_address = implode(', ', $parts);
            }

            $name = trim((string) ($assoc[$name_col] ?? ''));
            $marker_id = trim((string) ($assoc[$marker_id_col] ?? ''));
            $row_key_seed = $marker_id !== '' ? $marker_id : implode('|', [$name, $address_1, $city, $state, $postcode, $country]);
            $row_key = md5(strtolower($row_key_seed));
            $address_hash = md5(strtolower($full_address));
            $existing = isset($existing_by_key[$row_key]) ? $existing_by_key[$row_key] : null;

            $lat = trim((string) ($assoc[$lat_col] ?? ''));
            $lng = trim((string) ($assoc[$lng_col] ?? ''));

            if (($lat === '' || $lng === '') && $existing && ($existing['address_hash'] ?? '') === $address_hash) {
                $lat = (string) ($existing['lat'] ?? '');
                $lng = (string) ($existing['lng'] ?? '');
            }

            $filters = $this->parse_filters($assoc[$filters_col] ?? '');

            $normalized[] = [
                'row_key' => $row_key,
                'name' => $name,
                'address' => $full_address,
                'address_1' => $address_1,
                'address_2' => $address_2,
                'city' => $city,
                'state' => $state,
                'postcode' => $postcode,
                'country' => $country,
                'phone' => trim((string) ($assoc[$phone_col] ?? '')),
                'email' => sanitize_email($assoc[$email_col] ?? ''),
                'url' => esc_url_raw($assoc[$url_col] ?? ''),
                'visible' => true,
                'filters' => $filters,
                'marker_id' => $marker_id,
                'notes' => trim((string) ($assoc[$notes_col] ?? '')),
                'lat' => $lat,
                'lng' => $lng,
                'address_hash' => $address_hash,
                'raw' => $assoc,
            ];
        }

        return $normalized;
    }

    private function row_is_visible($value) {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return true;
        }

        return !in_array($value, ['0', 'false', 'no', 'hidden', 'off'], true);
    }

    private function parse_filters($value) {
        $parts = preg_split('/[,|]/', (string) $value);
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts, static function ($value) {
            return $value !== '';
        });

        return array_values($parts);
    }

    private function build_source_hash($config) {
        $hashable = [
            'csv_url' => (string) ($config['csv_url'] ?? ''),
            'csv_name_column' => (string) ($config['csv_name_column'] ?? ''),
            'csv_address_column' => (string) ($config['csv_address_column'] ?? ''),
            'csv_address_1_column' => (string) ($config['csv_address_1_column'] ?? ''),
            'csv_address_2_column' => (string) ($config['csv_address_2_column'] ?? ''),
            'csv_city_column' => (string) ($config['csv_city_column'] ?? ''),
            'csv_state_column' => (string) ($config['csv_state_column'] ?? ''),
            'csv_postcode_column' => (string) ($config['csv_postcode_column'] ?? ''),
            'csv_country_column' => (string) ($config['csv_country_column'] ?? ''),
            'csv_phone_column' => (string) ($config['csv_phone_column'] ?? ''),
            'csv_email_column' => (string) ($config['csv_email_column'] ?? ''),
            'csv_url_column' => (string) ($config['csv_url_column'] ?? ''),
            'csv_visible_column' => (string) ($config['csv_visible_column'] ?? ''),
            'csv_filters_column' => (string) ($config['csv_filters_column'] ?? ''),
            'csv_marker_id_column' => (string) ($config['csv_marker_id_column'] ?? ''),
            'csv_notes_column' => (string) ($config['csv_notes_column'] ?? ''),
            'csv_lat_column' => (string) ($config['csv_lat_column'] ?? ''),
            'csv_lng_column' => (string) ($config['csv_lng_column'] ?? ''),
        ];

        return md5(wp_json_encode($hashable));
    }

    private function get_cache_store() {
        $cache = get_option(self::CACHE_OPTION_KEY, []);
        return is_array($cache) ? $cache : [];
    }

    private function textarea_to_array($text) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines);
        return array_values($lines);
    }

    private function parse_csv_string($csv) {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', (string) $csv);
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = str_getcsv($line);
        }
        return $rows;
    }
}

new Client_Store_Locator();
