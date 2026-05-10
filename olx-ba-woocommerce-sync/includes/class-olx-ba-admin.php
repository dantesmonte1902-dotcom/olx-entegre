<?php

if (!defined('ABSPATH')) {
    exit;
}

class OLX_BA_Admin
{
    private OLX_BA_API_Client $client;
    private OLX_BA_Sync_Service $sync_service;
    private bool $product_editor_styles_printed = false;

    public function __construct(OLX_BA_API_Client $client, OLX_BA_Sync_Service $sync_service)
    {
        $this->client = $client;
        $this->sync_service = $sync_service;

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_olx_ba_save_settings', [$this, 'save_settings']);
        add_action('admin_post_olx_ba_login', [$this, 'login']);
        add_action('admin_post_olx_ba_find_categories', [$this, 'find_categories']);
        add_action('admin_post_olx_ba_get_child_categories', [$this, 'get_child_categories']);
        add_action('admin_post_olx_ba_get_canton_cities', [$this, 'get_canton_cities']);
        add_action('admin_post_olx_ba_get_attribute_profile_template', [$this, 'get_attribute_profile_template']);
        add_action('admin_post_olx_ba_process_queue', [$this, 'process_queue']);
        add_action('admin_post_olx_ba_sync_product', [$this, 'sync_product']);
        add_action('admin_post_olx_ba_delete_listing', [$this, 'delete_listing']);
        add_action('admin_notices', [$this, 'render_admin_notices']);
        add_action('add_meta_boxes_product', [$this, 'add_product_attribute_metabox']);
        add_action('woocommerce_product_options_general_product_data', [$this, 'render_product_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_fields']);
        add_filter('manage_edit-product_columns', [$this, 'add_product_columns']);
        add_action('manage_product_posts_custom_column', [$this, 'render_product_column'], 10, 2);
        add_action('admin_head-edit.php', [$this, 'print_product_list_styles']);
        add_filter('post_row_actions', [$this, 'add_product_row_actions'], 10, 2);
        add_filter('bulk_actions-edit-product', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle_bulk_actions'], 10, 3);
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('OLX.ba Sync', 'olx-ba-woocommerce-sync'),
            __('OLX.ba Sync', 'olx-ba-woocommerce-sync'),
            'manage_woocommerce',
            'olx-ba-sync',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage OLX.ba sync.', 'olx-ba-woocommerce-sync'));
        }

        $settings = $this->client->get_settings();
        $token = $this->client->get_token();
        $profiles = $this->client->get_profiles();
        $is_connected = $token !== '';
        $active_profile_label = __('Default profile', 'olx-ba-woocommerce-sync');
        $default_route_summary = sprintf(
            __('Default route: category %1$s / city %2$s / country %3$s', 'olx-ba-woocommerce-sync'),
            $this->format_optional_id((int) $settings['default_category_id']),
            $this->format_optional_id((int) $settings['city_id']),
            $this->format_optional_id((int) $settings['country_id'])
        );

        foreach ($profiles as $profile) {
            $profile_id = sanitize_key($profile['id'] ?? '');
            if ($profile_id !== '' && $profile_id === $settings['active_profile_id']) {
                $active_profile_label = (string) ($profile['name'] ?? $profile_id);
                break;
            }
        }
        ?>
        <div class="wrap olx-ba-wrap">
            <?php $this->print_settings_styles(); ?>
            <div class="olx-ba-admin-shell">
                <div class="olx-ba-hero">
                    <div class="olx-ba-hero-copy">
                        <span class="olx-ba-eyebrow"><?php echo esc_html__('WooCommerce integration dashboard', 'olx-ba-woocommerce-sync'); ?></span>
                        <h1><?php echo esc_html__('OLX.ba WooCommerce Sync', 'olx-ba-woocommerce-sync'); ?></h1>
                        <p class="olx-ba-lead"><?php echo esc_html__('Keep your existing OLX.ba workflow, manage mappings faster, and monitor sync readiness from one modern admin screen.', 'olx-ba-woocommerce-sync'); ?></p>
                    </div>
                    <div class="olx-ba-hero-status">
                        <span class="olx-ba-status-badge <?php echo esc_attr($is_connected ? 'is-connected' : 'is-disconnected'); ?>">
                            <?php echo esc_html($is_connected ? __('Connected', 'olx-ba-woocommerce-sync') : __('Disconnected', 'olx-ba-woocommerce-sync')); ?>
                        </span>
                        <div class="olx-ba-hero-meta">
                            <strong><?php echo esc_html($active_profile_label); ?></strong>
                            <span><?php echo esc_html($default_route_summary); ?></span>
                        </div>
                    </div>
                </div>

                <?php $this->render_admin_notices(); ?>

                <div class="olx-ba-summary-grid">
                    <div class="olx-ba-summary-card">
                        <span class="olx-ba-summary-label"><?php echo esc_html__('Active profile', 'olx-ba-woocommerce-sync'); ?></span>
                        <strong><?php echo esc_html($active_profile_label); ?></strong>
                        <span><?php echo esc_html(sprintf(__('Configured profiles: %d', 'olx-ba-woocommerce-sync'), count($profiles))); ?></span>
                    </div>
                    <div class="olx-ba-summary-card">
                        <span class="olx-ba-summary-label"><?php echo esc_html__('Automation', 'olx-ba-woocommerce-sync'); ?></span>
                        <strong><?php echo esc_html($settings['auto_sync'] === 'yes' ? __('Auto sync enabled', 'olx-ba-woocommerce-sync') : __('Manual sync mode', 'olx-ba-woocommerce-sync')); ?></strong>
                        <span><?php echo esc_html($settings['auto_publish'] === 'yes' ? __('Listings publish automatically after sync.', 'olx-ba-woocommerce-sync') : __('Listings stay as drafts after sync.', 'olx-ba-woocommerce-sync')); ?></span>
                    </div>
                    <div class="olx-ba-summary-card">
                        <span class="olx-ba-summary-label"><?php echo esc_html__('Images and attributes', 'olx-ba-woocommerce-sync'); ?></span>
                        <strong><?php echo esc_html($settings['sync_images'] === 'yes' ? __('Images sync on first publish', 'olx-ba-woocommerce-sync') : __('Images sync disabled', 'olx-ba-woocommerce-sync')); ?></strong>
                        <span><?php echo esc_html($settings['use_default_attributes'] === 'yes' ? __('Category defaults fill missing required fields.', 'olx-ba-woocommerce-sync') : __('Only product-level attributes are used.', 'olx-ba-woocommerce-sync')); ?></span>
                    </div>
                </div>

                <div class="olx-ba-layout">
                    <div class="olx-ba-main">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('olx_ba_save_settings'); ?>
                            <input type="hidden" name="action" value="olx_ba_save_settings">

                            <section class="olx-ba-panel">
                                <div class="olx-ba-panel-header">
                                    <div>
                                        <h2><?php echo esc_html__('Connection and default routing', 'olx-ba-woocommerce-sync'); ?></h2>
                                        <p><?php echo esc_html__('Set the active account, credentials, and fallback routing values used while publishing products.', 'olx-ba-woocommerce-sync'); ?></p>
                                    </div>
                                </div>
                                <table class="form-table olx-ba-form-table" role="presentation">
                                    <tr>
                                        <th scope="row"><label for="olx_active_profile_id"><?php echo esc_html__('Active OLX.ba profile', 'olx-ba-woocommerce-sync'); ?></label></th>
                                        <td>
                                            <select id="olx_active_profile_id" name="active_profile_id">
                                                <?php foreach ($profiles as $profile) : ?>
                                                    <?php $profile_id = sanitize_key($profile['id'] ?? ''); ?>
                                                    <option value="<?php echo esc_attr($profile_id); ?>" <?php selected($settings['active_profile_id'], $profile_id); ?>>
                                                        <?php echo esc_html((string) ($profile['name'] ?? $profile_id)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description"><?php echo esc_html__('This profile becomes the default source for manual and automated sync actions.', 'olx-ba-woocommerce-sync'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="olx_username"><?php echo esc_html__('Username or email', 'olx-ba-woocommerce-sync'); ?></label></th>
                                        <td><input id="olx_username" name="username" type="text" class="regular-text" value="<?php echo esc_attr($settings['username']); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="olx_password"><?php echo esc_html__('Password', 'olx-ba-woocommerce-sync'); ?></label></th>
                                        <td>
                                            <input id="olx_password" name="password" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($settings['password'] ? __('Stored password is kept unless you enter a new one', 'olx-ba-woocommerce-sync') : ''); ?>">
                                            <p class="description"><?php echo esc_html__('Leave blank to keep the current password.', 'olx-ba-woocommerce-sync'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="olx_device_name"><?php echo esc_html__('Device name', 'olx-ba-woocommerce-sync'); ?></label></th>
                                        <td>
                                            <input id="olx_device_name" name="device_name" type="text" class="regular-text" value="<?php echo esc_attr($settings['device_name']); ?>">
                                            <p class="description"><?php echo esc_html__('Use a stable device name so OLX.ba can recognize requests coming from this store.', 'olx-ba-woocommerce-sync'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="olx_country_id"><?php echo esc_html__('Default country ID', 'olx-ba-woocommerce-sync'); ?></label></th>
                                        <td><input id="olx_country_id" name="country_id" type="number" min="1" value="<?php echo esc_attr($settings['country_id']); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="olx_city_id"><?php echo esc_html__('Default city ID', 'olx-ba-woocommerce-sync'); ?></label></th>
                                        <td><input id="olx_city_id" name="city_id" type="number" min="1" value="<?php echo esc_attr($settings['city_id']); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="olx_category_id"><?php echo esc_html__('Default category ID', 'olx-ba-woocommerce-sync'); ?></label></th>
                                        <td><input id="olx_category_id" name="default_category_id" type="number" min="1" value="<?php echo esc_attr($settings['default_category_id']); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="olx_state"><?php echo esc_html__('Default condition', 'olx-ba-woocommerce-sync'); ?></label></th>
                                        <td>
                                            <select id="olx_state" name="default_state">
                                                <option value="new" <?php selected($settings['default_state'], 'new'); ?>><?php echo esc_html__('New', 'olx-ba-woocommerce-sync'); ?></option>
                                                <option value="used" <?php selected($settings['default_state'], 'used'); ?>><?php echo esc_html__('Used', 'olx-ba-woocommerce-sync'); ?></option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="olx_shipping"><?php echo esc_html__('Shipping code', 'olx-ba-woocommerce-sync'); ?></label></th>
                                        <td>
                                            <input id="olx_shipping" name="default_shipping" type="text" value="<?php echo esc_attr($settings['default_shipping']); ?>">
                                            <p class="description"><?php echo esc_html__('Set the OLX.ba shipping code sent when a product does not override it.', 'olx-ba-woocommerce-sync'); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </section>

                            <section class="olx-ba-panel">
                                <div class="olx-ba-panel-header">
                                    <div>
                                        <h2><?php echo esc_html__('Automation and mapping rules', 'olx-ba-woocommerce-sync'); ?></h2>
                                        <p><?php echo esc_html__('Keep your existing publishing logic and improve data quality with reusable defaults and category mappings.', 'olx-ba-woocommerce-sync'); ?></p>
                                    </div>
                                </div>
                                <table class="form-table olx-ba-form-table" role="presentation">
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Automation', 'olx-ba-woocommerce-sync'); ?></th>
                                        <td class="olx-ba-stack">
                                            <label><input type="checkbox" name="auto_sync" value="1" <?php checked($settings['auto_sync'], 'yes'); ?>> <?php echo esc_html__('Sync enabled products when they are saved', 'olx-ba-woocommerce-sync'); ?></label>
                                            <label><input type="checkbox" name="auto_publish" value="1" <?php checked($settings['auto_publish'], 'yes'); ?>> <?php echo esc_html__('Publish listings after sync', 'olx-ba-woocommerce-sync'); ?></label>
                                            <label><input type="checkbox" name="sync_images" value="1" <?php checked($settings['sync_images'], 'yes'); ?>> <?php echo esc_html__('Upload product images on first sync', 'olx-ba-woocommerce-sync'); ?></label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Default attribute profiles', 'olx-ba-woocommerce-sync'); ?></th>
                                        <td>
                                            <label><input type="checkbox" name="use_default_attributes" value="1" <?php checked($settings['use_default_attributes'], 'yes'); ?>> <?php echo esc_html__('Use category default attributes when product fields are empty', 'olx-ba-woocommerce-sync'); ?></label>
                                            <p class="description"><?php echo esc_html__('Product-level OLX.ba attributes always override these defaults.', 'olx-ba-woocommerce-sync'); ?></p>
                                            <textarea name="default_attribute_profiles" rows="10" cols="80" class="large-text code" placeholder="<?php echo esc_attr($this->get_attribute_profile_placeholder()); ?>"><?php echo esc_textarea($settings['default_attribute_profiles']); ?></textarea>
                                            <p class="description"><?php echo esc_html__('JSON format: category ID maps to attribute ID/value pairs.', 'olx-ba-woocommerce-sync'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Category mappings', 'olx-ba-woocommerce-sync'); ?></th>
                                        <td>
                                            <textarea name="category_mappings" rows="8" cols="80" class="large-text code" placeholder="<?php echo esc_attr($this->get_category_mapping_placeholder()); ?>"><?php echo esc_textarea($settings['category_mappings']); ?></textarea>
                                            <p class="description"><?php echo esc_html__('Map WooCommerce product category ID, slug, or name to OLX.ba leaf category ID.', 'olx-ba-woocommerce-sync'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('OLX.ba profiles', 'olx-ba-woocommerce-sync'); ?></th>
                                        <td>
                                            <textarea name="profiles_json" rows="12" cols="80" class="large-text code" placeholder="<?php echo esc_attr($this->get_profiles_placeholder()); ?>"><?php echo esc_textarea($settings['profiles_json']); ?></textarea>
                                            <p class="description"><?php echo esc_html__('Optional JSON list. Each profile can have its own credentials, defaults, attribute profiles, and active listing limit.', 'olx-ba-woocommerce-sync'); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </section>

                            <div class="olx-ba-footer-actions">
                                <?php submit_button(__('Save settings', 'olx-ba-woocommerce-sync'), 'primary large', '', false); ?>
                            </div>
                        </form>
                    </div>

                    <aside class="olx-ba-sidebar">
                        <section class="olx-ba-panel">
                            <div class="olx-ba-panel-header">
                                <div>
                                    <h2><?php echo esc_html__('Connection actions', 'olx-ba-woocommerce-sync'); ?></h2>
                                    <p><?php echo esc_html__('Refresh the token and confirm the active profile can still authenticate with OLX.ba.', 'olx-ba-woocommerce-sync'); ?></p>
                                </div>
                            </div>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="olx-ba-mini-form">
                                <?php wp_nonce_field('olx_ba_login'); ?>
                                <input type="hidden" name="action" value="olx_ba_login">
                                <?php submit_button($token ? __('Reconnect OLX.ba', 'olx-ba-woocommerce-sync') : __('Connect OLX.ba', 'olx-ba-woocommerce-sync'), 'secondary', '', false); ?>
                            </form>
                        </section>

                        <section class="olx-ba-panel">
                            <div class="olx-ba-panel-header">
                                <div>
                                    <h2><?php echo esc_html__('OLX.ba lookup tools', 'olx-ba-woocommerce-sync'); ?></h2>
                                    <p><?php echo esc_html__('Find valid leaf category IDs, child categories, city values, and attribute templates before syncing.', 'olx-ba-woocommerce-sync'); ?></p>
                                </div>
                            </div>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="olx-ba-mini-form">
                                <?php wp_nonce_field('olx_ba_find_categories'); ?>
                                <input type="hidden" name="action" value="olx_ba_find_categories">
                                <label for="olx_category_search"><?php echo esc_html__('Find category by name', 'olx-ba-woocommerce-sync'); ?></label>
                                <input id="olx_category_search" name="category_name" type="text" class="regular-text" placeholder="<?php echo esc_attr__('Example: obuca, jakna, felge', 'olx-ba-woocommerce-sync'); ?>">
                                <?php submit_button(__('Find categories', 'olx-ba-woocommerce-sync'), 'secondary', '', false); ?>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="olx-ba-mini-form">
                                <?php wp_nonce_field('olx_ba_get_child_categories'); ?>
                                <input type="hidden" name="action" value="olx_ba_get_child_categories">
                                <label for="olx_parent_category_id"><?php echo esc_html__('List child categories by parent ID', 'olx-ba-woocommerce-sync'); ?></label>
                                <input id="olx_parent_category_id" name="parent_category_id" type="number" min="1" placeholder="<?php echo esc_attr($settings['default_category_id']); ?>">
                                <?php submit_button(__('List child categories', 'olx-ba-woocommerce-sync'), 'secondary', '', false); ?>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="olx-ba-mini-form">
                                <?php wp_nonce_field('olx_ba_get_canton_cities'); ?>
                                <input type="hidden" name="action" value="olx_ba_get_canton_cities">
                                <label for="olx_canton_id"><?php echo esc_html__('List cities by canton ID', 'olx-ba-woocommerce-sync'); ?></label>
                                <input id="olx_canton_id" name="canton_id" type="number" min="1" placeholder="9">
                                <?php submit_button(__('List cities', 'olx-ba-woocommerce-sync'), 'secondary', '', false); ?>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="olx-ba-mini-form">
                                <?php wp_nonce_field('olx_ba_get_attribute_profile_template'); ?>
                                <input type="hidden" name="action" value="olx_ba_get_attribute_profile_template">
                                <label for="olx_profile_category_id"><?php echo esc_html__('Generate default attribute profile template', 'olx-ba-woocommerce-sync'); ?></label>
                                <input id="olx_profile_category_id" name="profile_category_id" type="number" min="1" placeholder="<?php echo esc_attr($settings['default_category_id']); ?>">
                                <?php submit_button(__('Generate template', 'olx-ba-woocommerce-sync'), 'secondary', '', false); ?>
                            </form>
                        </section>

                        <?php $this->render_lookup_results(); ?>
                        <?php $this->render_attribute_profile_template(); ?>

                        <section class="olx-ba-panel">
                            <div class="olx-ba-panel-header">
                                <div>
                                    <h2><?php echo esc_html__('Sync queue', 'olx-ba-woocommerce-sync'); ?></h2>
                                    <p><?php echo esc_html__('Queue products from the Products table, then process them in controlled batches.', 'olx-ba-woocommerce-sync'); ?></p>
                                </div>
                            </div>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="olx-ba-mini-form olx-ba-mini-form-inline">
                                <?php wp_nonce_field('olx_ba_process_queue'); ?>
                                <input type="hidden" name="action" value="olx_ba_process_queue">
                                <label for="olx_queue_limit"><?php echo esc_html__('Batch size', 'olx-ba-woocommerce-sync'); ?></label>
                                <input id="olx_queue_limit" name="queue_limit" type="number" min="1" max="50" value="10">
                                <?php submit_button(__('Process queue', 'olx-ba-woocommerce-sync'), 'secondary', '', false); ?>
                            </form>
                        </section>
                    </aside>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_settings(): void
    {
        $this->require_manage_woocommerce('olx_ba_save_settings');
        $this->client->update_settings(wp_unslash($_POST));
        $this->redirect_with_notice('settings_saved');
    }

    public function login(): void
    {
        $this->require_manage_woocommerce('olx_ba_login');
        $this->client->use_profile($this->client->get_active_profile_id());
        $result = $this->client->login();
        $this->redirect_with_notice(is_wp_error($result) ? 'login_failed' : 'login_success', $result);
    }

    public function find_categories(): void
    {
        $this->require_manage_woocommerce('olx_ba_find_categories');

        $name = sanitize_text_field(wp_unslash($_POST['category_name'] ?? ''));
        if ($name === '') {
            $this->redirect_with_notice('lookup_failed', new WP_Error('olx_lookup_missing_name', __('Enter a category name to search.', 'olx-ba-woocommerce-sync')));
        }

        $result = $this->client->get('/categories/find?name=' . rawurlencode($name));
        if (!is_wp_error($result)) {
            $this->store_lookup_results('category', $result);
        }

        $this->redirect_with_notice(is_wp_error($result) ? 'lookup_failed' : 'lookup_success', $result);
    }

    public function get_canton_cities(): void
    {
        $this->require_manage_woocommerce('olx_ba_get_canton_cities');

        $canton_id = absint($_POST['canton_id'] ?? 0);
        if ($canton_id <= 0) {
            $this->redirect_with_notice('lookup_failed', new WP_Error('olx_lookup_missing_canton', __('Enter a canton ID to list cities.', 'olx-ba-woocommerce-sync')));
        }

        $result = $this->client->get('/cantons/' . $canton_id . '/cities');
        if (!is_wp_error($result)) {
            $this->store_lookup_results('city', $result['data'] ?? []);
        }

        $this->redirect_with_notice(is_wp_error($result) ? 'lookup_failed' : 'lookup_success', $result);
    }

    public function get_child_categories(): void
    {
        $this->require_manage_woocommerce('olx_ba_get_child_categories');

        $parent_category_id = absint($_POST['parent_category_id'] ?? 0);
        if ($parent_category_id <= 0) {
            $this->redirect_with_notice('lookup_failed', new WP_Error('olx_lookup_missing_category', __('Enter a parent category ID to list child categories.', 'olx-ba-woocommerce-sync')));
        }

        $result = $this->client->get('/categories/' . $parent_category_id);
        if (!is_wp_error($result)) {
            $this->store_lookup_results('category', $result['data'] ?? $result);
        }

        $this->redirect_with_notice(is_wp_error($result) ? 'lookup_failed' : 'lookup_success', $result);
    }

    public function get_attribute_profile_template(): void
    {
        $this->require_manage_woocommerce('olx_ba_get_attribute_profile_template');

        $category_id = absint($_POST['profile_category_id'] ?? 0);
        if ($category_id <= 0) {
            $settings = $this->client->get_settings();
            $category_id = (int) $settings['default_category_id'];
        }

        if ($category_id <= 0) {
            $this->redirect_with_notice('lookup_failed', new WP_Error('olx_lookup_missing_category', __('Enter a category ID to generate an attribute profile template.', 'olx-ba-woocommerce-sync')));
        }

        $result = $this->client->get('/categories/' . $category_id . '/attributes');
        if (!is_wp_error($result)) {
            $this->store_attribute_profile_template($category_id, $result['data'] ?? []);
        }

        $this->redirect_with_notice(is_wp_error($result) ? 'lookup_failed' : 'lookup_success', $result);
    }

    public function process_queue(): void
    {
        $this->require_manage_woocommerce('olx_ba_process_queue');

        $limit = absint($_POST['queue_limit'] ?? 10);
        $results = $this->sync_service->process_queue($limit);

        $url = add_query_arg([
            'page' => 'olx-ba-sync',
            'olx_notice' => 'queue_processed',
            'olx_success' => absint($results['success']),
            'olx_failed' => absint($results['failed']),
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    public function render_product_fields(): void
    {
        global $post;
        $settings = $this->client->get_settings();
        $profiles = $this->client->get_profiles();
        $product_category_id = (int) get_post_meta($post->ID, '_olx_ba_category_id', true);
        $product_city_id = (int) get_post_meta($post->ID, '_olx_ba_city_id', true);
        $product_country_id = (int) get_post_meta($post->ID, '_olx_ba_country_id', true);
        $effective_category_id = $product_category_id ?: (int) $settings['default_category_id'];
        $effective_city_id = $product_city_id ?: (int) $settings['city_id'];
        $effective_country_id = $product_country_id ?: (int) $settings['country_id'];
        $status = $this->sync_service->get_product_status((int) $post->ID);
        $this->print_product_editor_styles();

        echo '<div class="options_group">';
        echo '<div class="olx-ba-product-shell">';
        echo '<div class="olx-ba-product-intro">';
        echo '<span class="olx-ba-product-eyebrow">' . esc_html__('OLX.ba publishing', 'olx-ba-woocommerce-sync') . '</span>';
        echo '<p>' . esc_html__('Keep the original sync flow, but manage listing routing, profile selection, and sync status in a clearer product panel.', 'olx-ba-woocommerce-sync') . '</p>';
        echo '</div>';
        woocommerce_wp_select([
            'id' => '_olx_ba_profile_id',
            'label' => __('OLX.ba profile', 'olx-ba-woocommerce-sync'),
            'options' => $this->get_profile_select_options($profiles),
            'description' => __('Choose which OLX.ba profile publishes this product.', 'olx-ba-woocommerce-sync'),
            'desc_tip' => true,
        ]);
        woocommerce_wp_checkbox([
            'id' => '_olx_ba_sync_enabled',
            'label' => __('Sync to OLX.ba', 'olx-ba-woocommerce-sync'),
            'description' => __('Allow this product to be published to OLX.ba.', 'olx-ba-woocommerce-sync'),
        ]);
        woocommerce_wp_text_input([
            'id' => '_olx_ba_listing_id',
            'label' => __('OLX.ba listing ID', 'olx-ba-woocommerce-sync'),
            'type' => 'number',
            'custom_attributes' => ['readonly' => 'readonly'],
        ]);
        woocommerce_wp_text_input([
            'id' => '_olx_ba_category_id',
            'label' => __('OLX.ba category ID', 'olx-ba-woocommerce-sync'),
            'type' => 'number',
            'placeholder' => (string) $settings['default_category_id'],
            'description' => sprintf(__('Leave empty to use default category ID %s.', 'olx-ba-woocommerce-sync'), $settings['default_category_id'] ?: '-'),
            'desc_tip' => true,
        ]);
        woocommerce_wp_text_input([
            'id' => '_olx_ba_city_id',
            'label' => __('OLX.ba city ID', 'olx-ba-woocommerce-sync'),
            'type' => 'number',
            'placeholder' => (string) $settings['city_id'],
            'description' => sprintf(__('Leave empty to use default city ID %s.', 'olx-ba-woocommerce-sync'), $settings['city_id'] ?: '-'),
            'desc_tip' => true,
        ]);
        woocommerce_wp_text_input([
            'id' => '_olx_ba_country_id',
            'label' => __('OLX.ba country ID', 'olx-ba-woocommerce-sync'),
            'type' => 'number',
            'placeholder' => (string) $settings['country_id'],
            'description' => sprintf(__('Leave empty to use default country ID %s.', 'olx-ba-woocommerce-sync'), $settings['country_id'] ?: '-'),
            'desc_tip' => true,
        ]);
        woocommerce_wp_select([
            'id' => '_olx_ba_state',
            'label' => __('OLX.ba condition', 'olx-ba-woocommerce-sync'),
            'options' => [
                '' => __('Use default', 'olx-ba-woocommerce-sync'),
                'new' => __('New', 'olx-ba-woocommerce-sync'),
                'used' => __('Used', 'olx-ba-woocommerce-sync'),
            ],
        ]);

        $attributes = esc_textarea((string) get_post_meta($post->ID, '_olx_ba_attributes_json', true));
        echo '<p class="form-field"><label for="_olx_ba_attributes_json">' . esc_html__('OLX.ba attributes JSON', 'olx-ba-woocommerce-sync') . '</label>';
        echo '<textarea id="_olx_ba_attributes_json" name="_olx_ba_attributes_json" rows="4" cols="20" class="short olx-ba-code-input">' . $attributes . '</textarea>';
        echo '<span class="description">' . esc_html__('Optional raw JSON override for advanced attribute payloads.', 'olx-ba-woocommerce-sync') . '</span></p>';

        $sync_url = wp_nonce_url(admin_url('admin-post.php?action=olx_ba_sync_product&product_id=' . absint($post->ID)), 'olx_ba_sync_product_' . absint($post->ID));
        $delete_url = wp_nonce_url(admin_url('admin-post.php?action=olx_ba_delete_listing&product_id=' . absint($post->ID)), 'olx_ba_delete_listing_' . absint($post->ID));
        echo '<p class="form-field"><label>' . esc_html__('OLX.ba actions', 'olx-ba-woocommerce-sync') . '</label>';
        echo '<span class="olx-ba-action-group">';
        echo '<a class="button button-primary" href="' . esc_url($sync_url) . '">' . esc_html__('Sync now', 'olx-ba-woocommerce-sync') . '</a> ';
        echo '<a class="button" href="' . esc_url($delete_url) . '">' . esc_html__('Delete OLX listing', 'olx-ba-woocommerce-sync') . '</a>';
        echo '</span></p>';

        echo '<div class="olx-ba-product-status-card">';
        echo '<div class="olx-ba-product-status-head">';
        echo '<strong>' . esc_html__('OLX.ba status', 'olx-ba-woocommerce-sync') . '</strong>';
        echo '<span class="olx-ba-status-badge ' . esc_attr($status['listing_id'] > 0 ? 'is-connected' : 'is-disconnected') . '">';
        echo esc_html($status['listing_id'] > 0 ? __('Linked', 'olx-ba-woocommerce-sync') : __('Not linked', 'olx-ba-woocommerce-sync'));
        echo '</span>';
        echo '</div>';
        echo '<ul class="olx-ba-product-status-list">';
        echo '<li><strong>' . esc_html__('Listing', 'olx-ba-woocommerce-sync') . ':</strong> ' . esc_html($status['listing_id'] > 0 ? sprintf(__('#%d', 'olx-ba-woocommerce-sync'), $status['listing_id']) : __('Not linked yet', 'olx-ba-woocommerce-sync')) . '</li>';
        echo '<li><strong>' . esc_html__('Last sync', 'olx-ba-woocommerce-sync') . ':</strong> ' . esc_html($status['last_sync'] !== '' ? $status['last_sync'] : __('Never', 'olx-ba-woocommerce-sync')) . '</li>';
        echo '<li><strong>' . esc_html__('Queue', 'olx-ba-woocommerce-sync') . ':</strong> ' . esc_html($status['queue_status'] !== '' ? $status['queue_status'] : __('Not queued', 'olx-ba-woocommerce-sync')) . '</li>';
        $effective_mapping_summary = sprintf(
            __('category %1$s, city %2$s, country %3$s', 'olx-ba-woocommerce-sync'),
            $this->format_optional_id($effective_category_id),
            $this->format_optional_id($effective_city_id),
            $this->format_optional_id($effective_country_id)
        );
        echo '<li><strong>' . esc_html__('Effective mapping', 'olx-ba-woocommerce-sync') . ':</strong> ' . esc_html($effective_mapping_summary) . '</li>';
        echo '</ul>';
        if ($status['last_error'] !== '') {
            echo '<p class="olx-ba-inline-error"><strong>' . esc_html__('Last error:', 'olx-ba-woocommerce-sync') . '</strong> ' . esc_html($status['last_error']) . '</p>';
        }
        if ($product_category_id > 0 || $product_city_id > 0 || $product_country_id > 0) {
            echo '<p class="description olx-ba-inline-note">' . esc_html__('Product-level OLX IDs override the default settings above.', 'olx-ba-woocommerce-sync') . '</p>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    public function add_product_attribute_metabox(): void
    {
        add_meta_box(
            'olx_ba_required_attributes',
            __('OLX.ba required attributes', 'olx-ba-woocommerce-sync'),
            [$this, 'render_product_attribute_metabox'],
            'product',
            'normal',
            'high'
        );
    }

    public function render_product_attribute_metabox(WP_Post $post): void
    {
        $settings = $this->client->get_settings();
        $product_category_id = (int) get_post_meta($post->ID, '_olx_ba_category_id', true);
        $effective_category_id = $product_category_id ?: (int) $settings['default_category_id'];
        $this->print_product_editor_styles();

        echo '<div class="olx-ba-attribute-shell">';
        echo '<p class="olx-ba-attribute-lead">' . esc_html__('Fill these fields, update the product, then send it to OLX.ba.', 'olx-ba-woocommerce-sync') . '</p>';
        $this->render_category_attribute_fields($effective_category_id, (int) $post->ID, false);
        echo '</div>';
    }

    public function save_product_fields(int $product_id): void
    {
        update_post_meta($product_id, '_olx_ba_sync_enabled', isset($_POST['_olx_ba_sync_enabled']) ? 'yes' : 'no');

        foreach (['_olx_ba_category_id', '_olx_ba_city_id', '_olx_ba_country_id'] as $key) {
            if (isset($_POST[$key])) {
                update_post_meta($product_id, $key, absint($_POST[$key]));
            }
        }

        if (isset($_POST['_olx_ba_state'])) {
            $state = sanitize_key(wp_unslash($_POST['_olx_ba_state']));
            update_post_meta($product_id, '_olx_ba_state', in_array($state, ['new', 'used'], true) ? $state : '');
        }

        if (isset($_POST['_olx_ba_profile_id'])) {
            update_post_meta($product_id, '_olx_ba_profile_id', sanitize_key(wp_unslash($_POST['_olx_ba_profile_id'])));
        }

        if (isset($_POST['_olx_ba_attributes_json'])) {
            update_post_meta($product_id, '_olx_ba_attributes_json', wp_unslash($_POST['_olx_ba_attributes_json']));
        }

        if (isset($_POST['_olx_ba_attr']) && is_array($_POST['_olx_ba_attr'])) {
            $attributes = [];
            foreach (wp_unslash($_POST['_olx_ba_attr']) as $attribute_id => $value) {
                $attribute_id = absint($attribute_id);
                $value = sanitize_text_field((string) $value);

                if ($attribute_id <= 0 || $value === '') {
                    continue;
                }

                $attributes[] = [
                    'id' => $attribute_id,
                    'value' => $value,
                ];
            }

            update_post_meta($product_id, '_olx_ba_attributes_json', wp_json_encode($attributes));
        }
    }

    public function sync_product(): void
    {
        $product_id = absint($_GET['product_id'] ?? 0);
        $this->require_manage_woocommerce('olx_ba_sync_product_' . $product_id);
        $result = $this->sync_service->sync_product($product_id);
        $redirect = isset($_GET['return_to']) && $_GET['return_to'] === 'list'
            ? admin_url('edit.php?post_type=product')
            : (get_edit_post_link($product_id, 'raw') ?: admin_url('edit.php?post_type=product'));
        $this->redirect_with_notice_to($redirect, is_wp_error($result) ? 'sync_failed' : 'sync_success', $result);
    }

    public function delete_listing(): void
    {
        $product_id = absint($_GET['product_id'] ?? 0);
        $this->require_manage_woocommerce('olx_ba_delete_listing_' . $product_id);
        $result = $this->sync_service->delete_listing($product_id);
        $this->redirect_to_product($product_id, is_wp_error($result) ? 'delete_failed' : 'delete_success', $result);
    }

    public function render_admin_notices(): void
    {
        $notice = sanitize_key($_GET['olx_notice'] ?? '');
        if ($notice === '') {
            return;
        }

        $bulk_success = absint($_GET['olx_success'] ?? 0);
        $bulk_failed = absint($_GET['olx_failed'] ?? 0);
        $messages = [
            'settings_saved' => __('Settings saved.', 'olx-ba-woocommerce-sync'),
            'login_success' => __('Connected to OLX.ba.', 'olx-ba-woocommerce-sync'),
            'login_failed' => __('OLX.ba connection failed. Check credentials and API access.', 'olx-ba-woocommerce-sync'),
            'sync_success' => __('Product synced to OLX.ba.', 'olx-ba-woocommerce-sync'),
            'sync_failed' => __('Product sync to OLX.ba failed. Check mappings, credentials, and API access.', 'olx-ba-woocommerce-sync'),
            'delete_success' => __('OLX.ba listing deleted.', 'olx-ba-woocommerce-sync'),
            'delete_failed' => __('OLX.ba listing delete failed.', 'olx-ba-woocommerce-sync'),
            'bulk_sync_finished' => sprintf(__('OLX.ba bulk sync finished. Success: %1$d, failed: %2$d.', 'olx-ba-woocommerce-sync'), $bulk_success, $bulk_failed),
            'lookup_success' => __('Lookup completed.', 'olx-ba-woocommerce-sync'),
            'lookup_failed' => __('Lookup failed.', 'olx-ba-woocommerce-sync'),
            'queue_processed' => sprintf(__('OLX.ba queue processed. Success: %1$d, failed: %2$d.', 'olx-ba-woocommerce-sync'), $bulk_success, $bulk_failed),
            'bulk_queued' => sprintf(__('Products added to OLX.ba queue: %d.', 'olx-ba-woocommerce-sync'), $bulk_success),
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        $class = substr($notice, -6) === 'failed' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' olx-ba-notice-card"><p><strong>' . esc_html($messages[$notice]) . '</strong></p>';

        $detail = $this->consume_notice_detail();
        if ($detail !== '') {
            echo '<p class="olx-ba-notice-detail"><strong>' . esc_html__('Detail:', 'olx-ba-woocommerce-sync') . '</strong> ' . esc_html($detail) . '</p>';
        }

        echo '</div>';
    }

    public function add_product_columns(array $columns): array
    {
        $new_columns = [];

        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ($key === 'sku') {
                $new_columns['olx_ba_status'] = __('OLX', 'olx-ba-woocommerce-sync');
            }
        }

        if (!isset($new_columns['olx_ba_status'])) {
            $new_columns['olx_ba_status'] = __('OLX', 'olx-ba-woocommerce-sync');
        }

        return $new_columns;
    }

    public function render_product_column(string $column, int $post_id): void
    {
        if ($column !== 'olx_ba_status') {
            return;
        }

        $status = $this->sync_service->get_product_status($post_id);
        $sync_url = wp_nonce_url(admin_url('admin-post.php?action=olx_ba_sync_product&return_to=list&product_id=' . absint($post_id)), 'olx_ba_sync_product_' . absint($post_id));

        if ($status['listing_id'] > 0) {
            echo '<span class="olx-ba-pill olx-ba-pill-linked">' . esc_html__('Linked', 'olx-ba-woocommerce-sync') . '</span>';
            echo '<span class="olx-ba-listing-id">#' . esc_html((string) $status['listing_id']) . '</span>';
        } else {
            echo '<span class="olx-ba-pill olx-ba-pill-unlinked">' . esc_html__('Not linked', 'olx-ba-woocommerce-sync') . '</span>';
        }

        if ($status['last_sync'] !== '') {
            echo '<span class="olx-ba-meta">' . esc_html(sprintf(__('Synced: %s', 'olx-ba-woocommerce-sync'), $status['last_sync'])) . '</span>';
        }

        if ($status['last_error'] !== '') {
            echo '<span class="olx-ba-error" title="' . esc_attr($status['last_error']) . '">' . esc_html__('Error', 'olx-ba-woocommerce-sync') . '</span>';
        }

        if ($status['queue_status'] !== '') {
            echo '<span class="olx-ba-meta">' . esc_html(sprintf(__('Queue: %s', 'olx-ba-woocommerce-sync'), $status['queue_status'])) . '</span>';
        }

        echo '<a class="button button-small olx-ba-send-button" href="' . esc_url($sync_url) . '">' . esc_html__('Send', 'olx-ba-woocommerce-sync') . '</a>';
    }

    public function print_product_list_styles(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-product') {
            return;
        }
        ?>
        <style>
            .wp-list-table .column-olx_ba_status {
                width: 132px;
                min-width: 132px;
                white-space: normal;
            }
            .wp-list-table .column-olx_ba_status .olx-ba-pill,
            .wp-list-table .column-olx_ba_status .olx-ba-listing-id,
            .wp-list-table .column-olx_ba_status .olx-ba-meta,
            .wp-list-table .column-olx_ba_status .olx-ba-error,
            .wp-list-table .column-olx_ba_status .olx-ba-send-button {
                display: block;
                width: max-content;
                max-width: 118px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .wp-list-table .column-olx_ba_status .olx-ba-pill {
                border-radius: 999px;
                font-size: 11px;
                font-weight: 700;
                line-height: 1.7;
                margin-bottom: 4px;
                padding: 1px 10px;
            }
            .wp-list-table .column-olx_ba_status .olx-ba-pill-linked {
                background: #dcfce7;
                color: #166534;
            }
            .wp-list-table .column-olx_ba_status .olx-ba-pill-unlinked {
                background: #e5e7eb;
                color: #374151;
            }
            .wp-list-table .column-olx_ba_status .olx-ba-listing-id,
            .wp-list-table .column-olx_ba_status .olx-ba-meta {
                color: #646970;
                font-size: 11px;
                line-height: 1.4;
                margin-bottom: 4px;
            }
            .wp-list-table .column-olx_ba_status .olx-ba-error {
                color: #b32d2e;
                font-size: 11px;
                font-weight: 600;
                margin-bottom: 5px;
            }
            .wp-list-table .column-olx_ba_status .olx-ba-send-button {
                border-radius: 999px;
                margin-top: 4px;
                text-align: center;
            }
        </style>
        <?php
    }

    public function add_product_row_actions(array $actions, WP_Post $post): array
    {
        if ($post->post_type !== 'product' || !current_user_can('manage_woocommerce')) {
            return $actions;
        }

        $url = wp_nonce_url(admin_url('admin-post.php?action=olx_ba_sync_product&return_to=list&product_id=' . absint($post->ID)), 'olx_ba_sync_product_' . absint($post->ID));
        $actions['olx_ba_sync'] = '<a href="' . esc_url($url) . '">' . esc_html__('Send to OLX.ba', 'olx-ba-woocommerce-sync') . '</a>';

        return $actions;
    }

    public function add_bulk_actions(array $actions): array
    {
        $actions['olx_ba_bulk_sync'] = __('Send selected products to OLX.ba', 'olx-ba-woocommerce-sync');
        $actions['olx_ba_bulk_queue'] = __('Add selected products to OLX.ba queue', 'olx-ba-woocommerce-sync');
        return $actions;
    }

    public function handle_bulk_actions(string $redirect_to, string $action, array $post_ids): string
    {
        if (!in_array($action, ['olx_ba_bulk_sync', 'olx_ba_bulk_queue'], true)) {
            return $redirect_to;
        }

        if (!current_user_can('manage_woocommerce')) {
            return $redirect_to;
        }

        if ($action === 'olx_ba_bulk_queue') {
            $queued = $this->sync_service->queue_products($post_ids);
            return add_query_arg([
                'olx_notice' => 'bulk_queued',
                'olx_success' => absint($queued),
            ], $redirect_to);
        }

        $results = $this->sync_service->sync_products($post_ids);
        return add_query_arg([
            'olx_notice' => 'bulk_sync_finished',
            'olx_success' => absint($results['success']),
            'olx_failed' => absint($results['failed']),
        ], $redirect_to);
    }

    private function require_manage_woocommerce(string $nonce_action): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage OLX.ba sync.', 'olx-ba-woocommerce-sync'));
        }

        check_admin_referer($nonce_action);
    }

    private function redirect_with_notice(string $notice, $result = null): void
    {
        $this->store_notice_detail($result);

        $url = add_query_arg([
            'page' => 'olx-ba-sync',
            'olx_notice' => $notice,
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    private function redirect_to_product(int $product_id, string $notice, $result = null): void
    {
        $url = add_query_arg('olx_notice', $notice, get_edit_post_link($product_id, 'raw') ?: admin_url('edit.php?post_type=product'));
        $this->redirect_with_notice_to($url, $notice, $result);
    }

    private function redirect_with_notice_to(string $url, string $notice, $result = null): void
    {
        $this->store_notice_detail($result);
        $url = add_query_arg('olx_notice', $notice, $url);
        wp_safe_redirect($url);
        exit;
    }

    private function store_notice_detail($result): void
    {
        if (!is_wp_error($result)) {
            return;
        }

        set_transient(
            'olx_ba_notice_detail_' . get_current_user_id(),
            $result->get_error_message(),
            MINUTE_IN_SECONDS * 5
        );
    }

    private function store_lookup_results(string $type, array $results): void
    {
        set_transient(
            'olx_ba_lookup_results_' . get_current_user_id(),
            [
                'type' => $type,
                'results' => $results,
            ],
            MINUTE_IN_SECONDS * 10
        );
    }

    private function render_lookup_results(): void
    {
        $lookup = get_transient('olx_ba_lookup_results_' . get_current_user_id());
        if (!is_array($lookup) || empty($lookup['results']) || !is_array($lookup['results'])) {
            return;
        }

        delete_transient('olx_ba_lookup_results_' . get_current_user_id());

        echo '<section class="olx-ba-panel olx-ba-panel-compact">';
        echo '<div class="olx-ba-panel-header"><div><h2>' . esc_html__('Lookup results', 'olx-ba-woocommerce-sync') . '</h2></div></div>';
        echo '<table class="widefat striped olx-ba-results-table"><thead><tr>';
        echo '<th>' . esc_html__('ID', 'olx-ba-woocommerce-sync') . '</th>';
        echo '<th>' . esc_html__('Name', 'olx-ba-woocommerce-sync') . '</th>';
        echo '<th>' . esc_html__('Path / location', 'olx-ba-woocommerce-sync') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($lookup['results'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $location = '';
            if (!empty($row['path'])) {
                $location = (string) $row['path'];
            } elseif (!empty($row['parent_id'])) {
                $location = 'parent #' . (string) $row['parent_id'];
            } elseif (!empty($row['location']) && is_array($row['location'])) {
                $location = trim(($row['location']['lat'] ?? '') . ', ' . ($row['location']['lon'] ?? ''), ', ');
            }

            echo '<tr>';
            echo '<td><code>' . esc_html((string) ($row['id'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($row['name'] ?? '')) . '</td>';
            echo '<td>' . esc_html($location) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    private function store_attribute_profile_template(int $category_id, array $attributes): void
    {
        $profile = [
            $category_id => [],
        ];

        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || empty($attribute['required']) || empty($attribute['id'])) {
                continue;
            }

            $options = $attribute['options'] ?? [];
            $default = '';
            if (is_array($options) && $options !== []) {
                $default = (string) reset($options);
            } else {
                $default = (string) ($attribute['display_name'] ?? $attribute['name'] ?? '');
            }

            $profile[$category_id][(int) $attribute['id']] = (string) ($attribute['display_name'] ?? $attribute['name'] ?? '');
            $profile[$category_id][(int) $attribute['id']] = $default;
        }

        set_transient(
            'olx_ba_attribute_profile_template_' . get_current_user_id(),
            [
                'json' => wp_json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                'attributes' => $attributes,
            ],
            MINUTE_IN_SECONDS * 10
        );
    }

    private function render_attribute_profile_template(): void
    {
        $template = get_transient('olx_ba_attribute_profile_template_' . get_current_user_id());
        if (!$template) {
            return;
        }

        delete_transient('olx_ba_attribute_profile_template_' . get_current_user_id());

        echo '<section class="olx-ba-panel olx-ba-panel-compact">';
        echo '<div class="olx-ba-panel-header"><div><h2>' . esc_html__('Attribute profile template', 'olx-ba-woocommerce-sync') . '</h2>';
        echo '<p>' . esc_html__('Copy this JSON into Default attribute profiles, then replace the label text with the standard values you want to send.', 'olx-ba-woocommerce-sync') . '</p></div></div>';
        $json = is_array($template) ? (string) ($template['json'] ?? '') : (string) $template;
        $attributes = is_array($template) && !empty($template['attributes']) && is_array($template['attributes'])
            ? $template['attributes']
            : [];

        echo '<textarea readonly rows="10" cols="80" class="large-text code olx-ba-template-output">' . esc_textarea($json) . '</textarea>';

        if ($attributes === []) {
            echo '</section>';
            return;
        }

        echo '<table class="widefat striped olx-ba-results-table"><thead><tr>';
        echo '<th>' . esc_html__('Attribute ID', 'olx-ba-woocommerce-sync') . '</th>';
        echo '<th>' . esc_html__('Field', 'olx-ba-woocommerce-sync') . '</th>';
        echo '<th>' . esc_html__('Allowed values', 'olx-ba-woocommerce-sync') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || empty($attribute['required']) || empty($attribute['id'])) {
                continue;
            }

            $options = $attribute['options'] ?? [];
            $allowed = is_array($options) && $options !== []
                ? implode(', ', array_map('strval', $options))
                : __('Free text', 'olx-ba-woocommerce-sync');

            echo '<tr>';
            echo '<td><code>' . esc_html((string) $attribute['id']) . '</code></td>';
            echo '<td>' . esc_html((string) ($attribute['display_name'] ?? $attribute['name'] ?? '')) . '</td>';
            echo '<td>' . esc_html($allowed) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    private function render_category_attribute_fields(int $category_id, int $product_id, bool $woocommerce_layout = true): void
    {
        if ($category_id <= 0 || $this->client->get_token() === '') {
            echo '<p class="' . esc_attr($woocommerce_layout ? 'form-field' : 'olx-ba-empty-state') . '">' . esc_html__('Set a default OLX.ba category and connect OLX.ba to load required attributes.', 'olx-ba-woocommerce-sync') . '</p>';
            return;
        }

        $response = $this->client->get('/categories/' . $category_id . '/attributes');
        if (is_wp_error($response)) {
            echo '<p' . ($woocommerce_layout ? ' class="form-field"' : ' class="olx-ba-empty-state"') . '><label>' . esc_html__('OLX.ba attributes', 'olx-ba-woocommerce-sync') . '</label>';
            echo '<span>' . esc_html($response->get_error_message()) . '</span></p>';
            return;
        }

        $attributes = $response['data'] ?? [];
        if (!is_array($attributes) || $attributes === []) {
            return;
        }

        $saved_values = $this->get_saved_attribute_values($product_id);
        echo '<div class="' . esc_attr($woocommerce_layout ? 'olx-ba-attribute-section' : 'olx-ba-attribute-section is-metabox') . '">';
        echo '<p' . ($woocommerce_layout ? ' class="form-field"' : '') . '><strong>' . esc_html__('Required OLX.ba attributes', 'olx-ba-woocommerce-sync') . '</strong><br>';
        echo '<span class="description">' . esc_html__('These fields come from the selected OLX.ba category and are required by OLX.ba before the listing can be created.', 'olx-ba-woocommerce-sync') . '</span></p>';
        echo '<div class="olx-ba-attribute-grid">';

        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || empty($attribute['required']) || empty($attribute['id'])) {
                continue;
            }

            $attribute_id = (int) $attribute['id'];
            $label = (string) ($attribute['display_name'] ?? $attribute['name'] ?? ('#' . $attribute_id));
            $value = $saved_values[$attribute_id] ?? $this->guess_attribute_value_from_product($product_id, $label);
            $options = $attribute['options'] ?? [];

            echo '<div class="olx-ba-attribute-field' . ($woocommerce_layout ? ' is-woocommerce' : '') . '">';
            echo '<label for="olx_ba_attr_' . esc_attr((string) $attribute_id) . '">' . esc_html($label) . '</label>';

            if (is_array($options) && $options !== []) {
                echo '<select id="olx_ba_attr_' . esc_attr((string) $attribute_id) . '" name="_olx_ba_attr[' . esc_attr((string) $attribute_id) . ']" class="short olx-ba-input">';
                echo '<option value="">' . esc_html__('Select', 'olx-ba-woocommerce-sync') . '</option>';
                foreach ($options as $option) {
                    $option = (string) $option;
                    echo '<option value="' . esc_attr($option) . '" ' . selected($value, $option, false) . '>' . esc_html($option) . '</option>';
                }
                echo '</select>';
            } else {
                echo '<input id="olx_ba_attr_' . esc_attr((string) $attribute_id) . '" name="_olx_ba_attr[' . esc_attr((string) $attribute_id) . ']" type="text" class="short olx-ba-input" value="' . esc_attr($value) . '">';
            }

            echo '<span class="description">' . esc_html__('Attribute ID:', 'olx-ba-woocommerce-sync') . ' ' . esc_html((string) $attribute_id) . '</span>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
    }

    private function get_saved_attribute_values(int $product_id): array
    {
        $raw = (string) get_post_meta($product_id, '_olx_ba_attributes_json', true);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $values = [];
        foreach ($decoded as $attribute) {
            if (!is_array($attribute) || empty($attribute['id']) || !array_key_exists('value', $attribute)) {
                continue;
            }

            $values[(int) $attribute['id']] = (string) $attribute['value'];
        }

        return $values;
    }

    private function guess_attribute_value_from_product(int $product_id, string $olx_label): string
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return '';
        }

        $target = $this->normalize_attribute_label($olx_label);
        foreach ($product->get_attributes() as $attribute) {
            if (!is_a($attribute, 'WC_Product_Attribute')) {
                continue;
            }

            $label = wc_attribute_label($attribute->get_name());
            if ($this->normalize_attribute_label($label) !== $target) {
                continue;
            }

            $values = $attribute->is_taxonomy()
                ? wc_get_product_terms($product_id, $attribute->get_name(), ['fields' => 'names'])
                : $attribute->get_options();

            if (is_array($values) && $values !== []) {
                return sanitize_text_field((string) reset($values));
            }
        }

        return '';
    }

    private function normalize_attribute_label(string $label): string
    {
        $label = remove_accents($label);
        $label = strtolower($label);

        return preg_replace('/[^a-z0-9]+/', '', $label) ?: '';
    }

    private function get_attribute_profile_placeholder(): string
    {
        return "{\n  \"491\": {\n    \"123\": \"Standard value\",\n    \"456\": \"Another value\"\n  }\n}";
    }

    private function format_optional_id(int $value): string
    {
        return $value > 0 ? (string) $value : __('Not set', 'olx-ba-woocommerce-sync');
    }

    private function get_profiles_placeholder(): string
    {
        return "[\n  {\n    \"id\": \"main\",\n    \"name\": \"Main OLX account\",\n    \"username\": \"email@example.com\",\n    \"password\": \"password\",\n    \"device_name\": \"woocommerce-main\",\n    \"country_id\": 49,\n    \"city_id\": 80,\n    \"default_category_id\": 491,\n    \"default_state\": \"new\",\n    \"default_shipping\": \"no_shipping\",\n    \"auto_publish\": false,\n    \"sync_images\": true,\n    \"use_default_attributes\": true,\n    \"max_active_listings\": 100,\n    \"default_attribute_profiles\": {}\n  }\n]";
    }

    private function get_category_mapping_placeholder(): string
    {
        return "{\n  \"haljine\": 491,\n  \"23\": 491,\n  \"Cipele\": 1234\n}";
    }

    private function get_profile_select_options(array $profiles): array
    {
        $options = [
            '' => __('Use active default profile', 'olx-ba-woocommerce-sync'),
        ];

        foreach ($profiles as $profile) {
            $profile_id = sanitize_key($profile['id'] ?? '');
            if ($profile_id === '') {
                continue;
            }

            $options[$profile_id] = (string) ($profile['name'] ?? $profile_id);
        }

        return $options;
    }

    private function print_settings_styles(): void
    {
        ?>
        <style>
            .olx-ba-wrap {
                margin-top: 20px;
            }
            .olx-ba-admin-shell {
                max-width: 1280px;
            }
            .olx-ba-hero {
                align-items: center;
                background: linear-gradient(135deg, #111827 0%, #1f2937 55%, #2563eb 100%);
                border-radius: 18px;
                color: #fff;
                display: flex;
                gap: 24px;
                justify-content: space-between;
                margin: 0 0 20px;
                padding: 28px 30px;
            }
            .olx-ba-hero h1 {
                color: #fff;
                margin: 0 0 10px;
            }
            .olx-ba-eyebrow,
            .olx-ba-product-eyebrow {
                color: rgba(255,255,255,.72);
                display: inline-block;
                font-size: 12px;
                font-weight: 700;
                letter-spacing: .08em;
                margin-bottom: 10px;
                text-transform: uppercase;
            }
            .olx-ba-lead {
                font-size: 14px;
                margin: 0;
                max-width: 760px;
                opacity: .9;
            }
            .olx-ba-hero-status {
                background: rgba(255,255,255,.12);
                border: 1px solid rgba(255,255,255,.12);
                border-radius: 16px;
                min-width: 240px;
                padding: 18px;
            }
            .olx-ba-hero-meta {
                display: flex;
                flex-direction: column;
                gap: 6px;
                margin-top: 14px;
            }
            .olx-ba-hero-meta strong {
                color: #fff;
                font-size: 16px;
            }
            .olx-ba-hero-meta span {
                color: rgba(255,255,255,.8);
            }
            .olx-ba-status-badge {
                border-radius: 999px;
                display: inline-flex;
                font-size: 12px;
                font-weight: 700;
                gap: 6px;
                line-height: 1;
                padding: 8px 12px;
            }
            .olx-ba-status-badge.is-connected {
                background: #dcfce7;
                color: #166534;
            }
            .olx-ba-status-badge.is-disconnected {
                background: #fee2e2;
                color: #991b1b;
            }
            .olx-ba-summary-grid,
            .olx-ba-layout {
                display: grid;
                gap: 18px;
            }
            .olx-ba-summary-grid {
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                margin-bottom: 18px;
            }
            .olx-ba-summary-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 16px;
                box-shadow: 0 10px 28px rgba(15, 23, 42, .06);
                display: flex;
                flex-direction: column;
                gap: 8px;
                padding: 20px;
            }
            .olx-ba-summary-label {
                color: #6b7280;
                font-size: 12px;
                font-weight: 700;
                letter-spacing: .05em;
                text-transform: uppercase;
            }
            .olx-ba-summary-card strong {
                font-size: 16px;
            }
            .olx-ba-layout {
                align-items: start;
                grid-template-columns: minmax(0, 1.7fr) minmax(300px, .9fr);
            }
            .olx-ba-main,
            .olx-ba-sidebar {
                display: grid;
                gap: 18px;
            }
            .olx-ba-panel {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 18px;
                box-shadow: 0 12px 32px rgba(15, 23, 42, .05);
                padding: 22px 24px;
            }
            .olx-ba-panel-compact {
                padding: 18px 20px;
            }
            .olx-ba-panel-header {
                align-items: start;
                display: flex;
                justify-content: space-between;
                gap: 16px;
                margin-bottom: 12px;
            }
            .olx-ba-panel h2,
            .olx-ba-panel-header h2 {
                margin-top: 0;
                margin-bottom: 6px;
            }
            .olx-ba-panel-header p {
                color: #646970;
                margin: 0;
            }
            .olx-ba-form-table th {
                font-weight: 600;
                padding-top: 18px;
            }
            .olx-ba-form-table td {
                padding-top: 16px;
            }
            .olx-ba-form-table input[type="number"],
            .olx-ba-form-table input[type="text"],
            .olx-ba-form-table input[type="password"],
            .olx-ba-form-table select,
            .olx-ba-mini-form input[type="number"],
            .olx-ba-mini-form input[type="text"] {
                min-height: 40px;
                min-width: 220px;
            }
            .olx-ba-panel textarea.code {
                font-family: Consolas, Monaco, monospace;
                min-height: 180px;
            }
            .olx-ba-stack {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .olx-ba-stack label {
                align-items: center;
                display: inline-flex;
                gap: 8px;
            }
            .olx-ba-footer-actions {
                display: flex;
                justify-content: flex-end;
            }
            .olx-ba-mini-form {
                display: grid;
                gap: 10px;
                margin-top: 16px;
            }
            .olx-ba-mini-form:first-of-type {
                margin-top: 0;
            }
            .olx-ba-mini-form label {
                font-weight: 600;
            }
            .olx-ba-mini-form-inline {
                align-items: end;
                grid-template-columns: 1fr auto;
            }
            .olx-ba-results-table {
                margin-top: 10px;
            }
            .olx-ba-template-output {
                min-height: 160px;
            }
            .olx-ba-notice-card {
                border-radius: 14px;
                border-width: 1px;
                box-shadow: 0 10px 24px rgba(15, 23, 42, .04);
                margin: 16px 0;
                padding: 8px 14px;
            }
            .olx-ba-notice-detail {
                margin-top: 8px;
            }
            @media (max-width: 1100px) {
                .olx-ba-layout {
                    grid-template-columns: 1fr;
                }
            }
            @media (max-width: 782px) {
                .olx-ba-hero {
                    align-items: start;
                    flex-direction: column;
                    padding: 24px;
                }
                .olx-ba-hero-status {
                    min-width: 0;
                    width: 100%;
                }
                .olx-ba-mini-form-inline {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }

    private function print_product_editor_styles(): void
    {
        if ($this->product_editor_styles_printed) {
            return;
        }

        $this->product_editor_styles_printed = true;
        ?>
        <style>
            .olx_options .olx-ba-product-shell {
                padding: 6px 12px 18px;
            }
            .olx-ba-product-intro,
            .olx-ba-product-status-card,
            .olx-ba-attribute-shell,
            .olx-ba-attribute-section {
                background: #f8fafc;
                border: 1px solid #dbe3ea;
                border-radius: 16px;
            }
            .olx-ba-product-intro {
                margin: 8px 0 16px;
                padding: 16px 18px;
            }
            .olx-ba-product-eyebrow {
                color: #2563eb;
                margin-bottom: 6px;
            }
            .olx-ba-product-intro p,
            .olx-ba-attribute-lead {
                margin: 0;
            }
            .olx-ba-code-input {
                font-family: Consolas, Monaco, monospace;
                min-height: 110px;
                width: 100%;
            }
            .olx-ba-action-group {
                align-items: center;
                display: inline-flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .olx-ba-product-status-card {
                margin: 12px 20px 0 162px;
                padding: 16px 18px;
            }
            .olx-ba-product-status-head {
                align-items: center;
                display: flex;
                gap: 12px;
                justify-content: space-between;
                margin-bottom: 12px;
            }
            .olx-ba-product-status-list {
                display: grid;
                gap: 8px;
                margin: 0;
                padding: 0;
            }
            .olx-ba-product-status-list li {
                list-style: none;
                margin: 0;
            }
            .olx-ba-inline-error {
                color: #b91c1c;
                margin: 14px 0 0;
            }
            .olx-ba-inline-note {
                margin: 10px 0 0 !important;
            }
            .olx-ba-attribute-shell {
                padding: 18px;
            }
            .olx-ba-attribute-section {
                padding: 16px;
            }
            .olx-ba-attribute-section.is-metabox {
                background: transparent;
                border: 0;
                padding: 0;
            }
            .olx-ba-attribute-grid {
                display: grid;
                gap: 14px;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                margin-top: 14px;
            }
            .olx-ba-attribute-field {
                background: #fff;
                border: 1px solid #dbe3ea;
                border-radius: 14px;
                display: grid;
                gap: 8px;
                padding: 14px;
            }
            .olx-ba-attribute-field label {
                font-weight: 600;
            }
            .olx-ba-attribute-field .olx-ba-input {
                max-width: 100%;
                min-height: 38px;
                width: 100%;
            }
            .olx-ba-empty-state {
                color: #646970;
                margin: 0;
            }
            @media (max-width: 782px) {
                .olx-ba-product-status-card {
                    margin-left: 0;
                }
            }
        </style>
        <?php
    }

    private function consume_notice_detail(): string
    {
        $key = 'olx_ba_notice_detail_' . get_current_user_id();
        $detail = (string) get_transient($key);
        delete_transient($key);

        return $detail;
    }
}
