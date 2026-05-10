<?php

if (!defined('ABSPATH')) {
    exit;
}

class OLX_BA_Sync_Service
{
    private OLX_BA_API_Client $client;

    public function __construct(OLX_BA_API_Client $client)
    {
        $this->client = $client;
    }

    public function maybe_auto_sync_product(int $post_id, WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($post_id) || $post->post_status !== 'publish') {
            return;
        }

        $settings = $this->client->get_settings();
        if ($settings['auto_sync'] !== 'yes') {
            return;
        }

        $enabled = get_post_meta($post_id, '_olx_ba_sync_enabled', true);
        if ($enabled !== 'yes') {
            return;
        }

        $this->sync_product($post_id);
    }

    public function sync_product(int $product_id)
    {
        $product = wc_get_product($product_id);

        if (!$product) {
            return $this->store_error($product_id, new WP_Error('olx_invalid_product', __('Product not found.', 'olx-ba-woocommerce-sync')));
        }

        $profile_id = $this->get_product_profile_id($product_id);
        $this->client->use_profile($profile_id);

        $limit_check = $this->check_profile_limit($product_id, $profile_id);
        if (is_wp_error($limit_check)) {
            return $this->store_error($product_id, $limit_check);
        }

        $listing_id = (int) get_post_meta($product_id, '_olx_ba_listing_id', true);
        $payload = $this->build_listing_payload($product);

        if (is_wp_error($payload)) {
            return $this->store_error($product_id, $payload);
        }

        $preflight = $this->validate_payload_mapping($payload);
        if (is_wp_error($preflight)) {
            return $this->store_error($product_id, $preflight);
        }

        $response = $listing_id > 0
            ? $this->client->put('/listings/' . $listing_id, $payload)
            : $this->client->post('/listings', $payload);

        if (is_wp_error($response)) {
            return $this->store_error($product_id, $response);
        }

        $new_listing_id = (int) ($response['id'] ?? $listing_id);
        if ($new_listing_id > 0) {
            update_post_meta($product_id, '_olx_ba_listing_id', $new_listing_id);
            update_post_meta($product_id, '_olx_ba_profile_id', $profile_id);
        }

        update_post_meta($product_id, '_olx_ba_last_sync', current_time('mysql'));
        update_post_meta($product_id, '_olx_ba_last_response', wp_json_encode($response));
        delete_post_meta($product_id, '_olx_ba_last_error');
        update_post_meta($product_id, '_olx_ba_sync_status', 'success');
        OLX_BA_Logger::info('Product synced to OLX.ba.', [
            'product_id' => $product_id,
            'listing_id' => $new_listing_id,
        ]);

        $settings = $this->client->get_settings();
        if ($settings['sync_images'] === 'yes' && $new_listing_id > 0 && $listing_id <= 0) {
            $this->sync_product_images($product, $new_listing_id);
        }

        if ($settings['auto_publish'] === 'yes' && $new_listing_id > 0) {
            $publish = $this->client->post('/listings/' . $new_listing_id . '/publish');
            if (!is_wp_error($publish)) {
                update_post_meta($product_id, '_olx_ba_last_publish', current_time('mysql'));
            }
        }

        return $response;
    }

    public function sync_products(array $product_ids): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach (array_map('absint', $product_ids) as $product_id) {
            if ($product_id <= 0) {
                continue;
            }

            $result = $this->sync_product($product_id);
            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][$product_id] = $result->get_error_message();
                continue;
            }

            $results['success']++;
        }

        return $results;
    }

    public function queue_products(array $product_ids): int
    {
        $queued = 0;
        foreach (array_map('absint', $product_ids) as $product_id) {
            if ($product_id <= 0) {
                continue;
            }

            update_post_meta($product_id, '_olx_ba_queue_status', 'queued');
            update_post_meta($product_id, '_olx_ba_queue_time', current_time('mysql'));
            $queued++;
        }

        return $queued;
    }

    public function process_queue(int $limit = 10): array
    {
        $query = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => max(1, $limit),
            'meta_query' => [
                [
                    'key' => '_olx_ba_queue_status',
                    'value' => 'queued',
                ],
            ],
            'orderby' => 'meta_value',
            'meta_key' => '_olx_ba_queue_time',
            'order' => 'ASC',
        ]);

        $results = [
            'success' => 0,
            'failed' => 0,
            'processed' => 0,
        ];

        foreach ($query->posts as $product_id) {
            update_post_meta($product_id, '_olx_ba_queue_status', 'processing');
            $result = $this->sync_product((int) $product_id);
            $results['processed']++;

            if (is_wp_error($result)) {
                update_post_meta($product_id, '_olx_ba_queue_status', 'failed');
                $results['failed']++;
                continue;
            }

            update_post_meta($product_id, '_olx_ba_queue_status', 'done');
            $results['success']++;
        }

        return $results;
    }

    public function delete_listing(int $product_id)
    {
        $listing_id = (int) get_post_meta($product_id, '_olx_ba_listing_id', true);

        if ($listing_id <= 0) {
            return new WP_Error('olx_missing_listing_id', __('This product is not linked to an OLX.ba listing.', 'olx-ba-woocommerce-sync'));
        }

        $response = $this->client->delete('/listings/' . $listing_id);
        if (is_wp_error($response)) {
            return $response;
        }

        delete_post_meta($product_id, '_olx_ba_listing_id');
        update_post_meta($product_id, '_olx_ba_last_delete', current_time('mysql'));

        return $response;
    }

    public function get_product_status(int $product_id): array
    {
        return [
            'enabled' => get_post_meta($product_id, '_olx_ba_sync_enabled', true) === 'yes',
            'profile_id' => $this->get_product_profile_id($product_id),
            'listing_id' => (int) get_post_meta($product_id, '_olx_ba_listing_id', true),
            'last_sync' => (string) get_post_meta($product_id, '_olx_ba_last_sync', true),
            'last_publish' => (string) get_post_meta($product_id, '_olx_ba_last_publish', true),
            'last_error' => (string) get_post_meta($product_id, '_olx_ba_last_error', true),
            'status' => (string) get_post_meta($product_id, '_olx_ba_sync_status', true),
            'queue_status' => (string) get_post_meta($product_id, '_olx_ba_queue_status', true),
        ];
    }

    private function build_listing_payload(WC_Product $product)
    {
        $settings = $this->client->get_settings();
        $category_id = (int) get_post_meta($product->get_id(), '_olx_ba_category_id', true);
        $city_id = (int) get_post_meta($product->get_id(), '_olx_ba_city_id', true);
        $country_id = (int) get_post_meta($product->get_id(), '_olx_ba_country_id', true);
        $state = (string) get_post_meta($product->get_id(), '_olx_ba_state', true);

        $category_id = $category_id ?: $this->get_mapped_category_id($product, $settings);
        $category_id = $category_id ?: (int) $settings['default_category_id'];
        $city_id = $city_id ?: (int) $settings['city_id'];
        $country_id = $country_id ?: (int) $settings['country_id'];
        $state = in_array($state, ['new', 'used'], true) ? $state : $settings['default_state'];

        if ($category_id <= 0 || $city_id <= 0 || $country_id <= 0) {
            return new WP_Error('olx_missing_mapping', __('OLX.ba category, city, and country IDs are required.', 'olx-ba-woocommerce-sync'));
        }

        $description = $product->get_description() ?: $product->get_short_description();
        $price = (float) wc_get_price_to_display($product);

        $payload = [
            'title' => wp_strip_all_tags($product->get_name()),
            'short_description' => wp_trim_words(wp_strip_all_tags($product->get_short_description()), 24, ''),
            'description' => wp_strip_all_tags($description),
            'country_id' => $country_id,
            'city_id' => $city_id,
            'category_id' => $category_id,
            'price' => $price,
            'available' => $product->is_in_stock(),
            'listing_type' => 'sell',
            'state' => $state,
            'sku_number' => $product->get_sku(),
            'shipping' => $settings['default_shipping'],
            'quantity' => max(1, (int) $product->get_stock_quantity()),
        ];

        $attributes = $this->get_product_attributes($product);
        if ($attributes !== []) {
            $payload['attributes'] = $attributes;
        }

        $payload = array_filter($payload, static function ($value): bool {
            return $value !== '' && $value !== null;
        });

        update_post_meta($product->get_id(), '_olx_ba_last_payload', wp_json_encode($payload));

        return $payload;
    }

    private function get_product_attributes(WC_Product $product): array
    {
        $settings = $this->client->get_settings();
        $category_id = (int) get_post_meta($product->get_id(), '_olx_ba_category_id', true);
        $category_id = $category_id ?: (int) $settings['default_category_id'];
        $raw = get_post_meta($product->get_id(), '_olx_ba_attributes_json', true);
        $attributes = $settings['use_default_attributes'] === 'yes'
            ? $this->get_default_attributes_for_category($category_id, $settings)
            : [];

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $attribute) {
                if (!is_array($attribute) || empty($attribute['id']) || !array_key_exists('value', $attribute)) {
                    continue;
                }

                $attributes[(int) $attribute['id']] = [
                    'id' => absint($attribute['id']),
                    'value' => sanitize_text_field((string) $attribute['value']),
                ];
            }
        }

        return array_values($attributes);
    }

    private function get_default_attributes_for_category(int $category_id, array $settings): array
    {
        if ($category_id <= 0 || empty($settings['default_attribute_profiles'])) {
            return [];
        }

        $profiles = json_decode((string) $settings['default_attribute_profiles'], true);
        if (!is_array($profiles) || empty($profiles[$category_id]) || !is_array($profiles[$category_id])) {
            return [];
        }

        $attributes = [];
        foreach ($profiles[$category_id] as $attribute_id => $value) {
            $attribute_id = absint($attribute_id);
            $value = sanitize_text_field((string) $value);

            if ($attribute_id <= 0 || $value === '') {
                continue;
            }

            $attributes[$attribute_id] = [
                'id' => $attribute_id,
                'value' => $value,
            ];
        }

        return $attributes;
    }

    private function get_mapped_category_id(WC_Product $product, array $settings): int
    {
        if (empty($settings['category_mappings'])) {
            return 0;
        }

        $mapping = json_decode((string) $settings['category_mappings'], true);
        if (!is_array($mapping)) {
            return 0;
        }

        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (!is_array($terms)) {
            return 0;
        }

        foreach ($terms as $term) {
            foreach ([(string) $term->term_id, (string) $term->slug, (string) $term->name] as $key) {
                if (!empty($mapping[$key])) {
                    return absint($mapping[$key]);
                }
            }
        }

        return 0;
    }

    private function sync_product_images(WC_Product $product, int $listing_id): void
    {
        $image_ids = array_filter(array_merge(
            [$product->get_image_id()],
            $product->get_gallery_image_ids()
        ));

        foreach ($image_ids as $image_id) {
            $image_url = wp_get_attachment_url((int) $image_id);
            if (!$image_url) {
                continue;
            }

            $this->client->upload_image_url($listing_id, $image_url);
        }
    }

    private function validate_payload_mapping(array $payload)
    {
        $category_id = (int) ($payload['category_id'] ?? 0);
        $city_id = (int) ($payload['city_id'] ?? 0);

        if ($category_id > 0) {
            $children = $this->client->get('/categories/' . $category_id);
            $child_categories = $this->normalize_category_rows($children);
            if (!is_wp_error($children) && $child_categories !== []) {
                $suggestions = [];
                foreach (array_slice($child_categories, 0, 5) as $child) {
                    if (!is_array($child) || empty($child['id']) || empty($child['name'])) {
                        continue;
                    }
                    $suggestions[] = $child['name'] . ' #' . $child['id'];
                }

                $message = __('OLX.ba category must be a leaf category. Choose one of its child categories.', 'olx-ba-woocommerce-sync');
                if ($suggestions !== []) {
                    $message .= ' ' . sprintf(__('Suggestions: %s', 'olx-ba-woocommerce-sync'), implode(', ', $suggestions));
                }

                return new WP_Error('olx_category_not_leaf', $message);
            }
        }

        if ($city_id > 0) {
            $city = $this->client->get('/cities/' . $city_id);
            if (is_wp_error($city)) {
                return new WP_Error('olx_invalid_city', __('OLX.ba city ID is not valid. Use a real city ID, not a state/canton/country ID.', 'olx-ba-woocommerce-sync'));
            }
        }

        $missing_attributes = $this->get_missing_required_attributes($category_id, $payload['attributes'] ?? []);
        if (is_wp_error($missing_attributes)) {
            return $missing_attributes;
        }

        if ($missing_attributes !== []) {
            return new WP_Error(
                'olx_missing_required_attributes',
                sprintf(
                    __('This OLX.ba category requires attributes: %s. Fill them in the product OLX.ba section.', 'olx-ba-woocommerce-sync'),
                    implode(', ', $missing_attributes)
                )
            );
        }

        return true;
    }

    private function get_missing_required_attributes(int $category_id, array $submitted_attributes)
    {
        if ($category_id <= 0) {
            return [];
        }

        $response = $this->client->get('/categories/' . $category_id . '/attributes');
        if (is_wp_error($response)) {
            return $response;
        }

        $attributes = $response['data'] ?? [];
        if (!is_array($attributes) || $attributes === []) {
            return [];
        }

        $submitted = [];
        foreach ($submitted_attributes as $attribute) {
            if (!is_array($attribute) || empty($attribute['id']) || !array_key_exists('value', $attribute)) {
                continue;
            }

            $submitted[(int) $attribute['id']] = trim((string) $attribute['value']);
        }

        $missing = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || empty($attribute['required']) || empty($attribute['id'])) {
                continue;
            }

            $id = (int) $attribute['id'];
            if (!isset($submitted[$id]) || $submitted[$id] === '') {
                $missing[] = (string) ($attribute['display_name'] ?? $attribute['name'] ?? ('#' . $id));
            }
        }

        return $missing;
    }

    private function normalize_category_rows($response): array
    {
        if (!is_array($response)) {
            return [];
        }

        if (!empty($response['data']) && is_array($response['data'])) {
            return $this->is_list_array($response['data']) ? $response['data'] : [];
        }

        if (isset($response[0]) && is_array($response[0])) {
            return $response;
        }

        return [];
    }

    private function is_list_array(array $items): bool
    {
        if ($items === []) {
            return true;
        }

        return array_keys($items) === range(0, count($items) - 1);
    }

    private function store_error(int $product_id, WP_Error $error): WP_Error
    {
        if ($product_id > 0) {
            update_post_meta($product_id, '_olx_ba_last_error', $error->get_error_message());
            update_post_meta($product_id, '_olx_ba_sync_status', 'failed');
        }

        OLX_BA_Logger::error('Product sync to OLX.ba failed.', [
            'product_id' => $product_id,
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ]);

        return $error;
    }

    private function get_product_profile_id(int $product_id): string
    {
        $profile_id = sanitize_key((string) get_post_meta($product_id, '_olx_ba_profile_id', true));
        if ($profile_id !== '') {
            return $profile_id;
        }

        return $this->client->get_active_profile_id();
    }

    private function check_profile_limit(int $product_id, string $profile_id)
    {
        $settings = $this->client->get_settings();
        $limit = absint($settings['max_active_listings'] ?? 0);
        $listing_id = (int) get_post_meta($product_id, '_olx_ba_listing_id', true);

        if ($limit <= 0 || $listing_id > 0) {
            return true;
        }

        $query = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => false,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_olx_ba_profile_id',
                    'value' => $profile_id,
                ],
                [
                    'key' => '_olx_ba_listing_id',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        if ((int) $query->found_posts >= $limit) {
            return new WP_Error(
                'olx_profile_limit_reached',
                sprintf(__('OLX.ba profile "%1$s" reached its active listing limit of %2$d.', 'olx-ba-woocommerce-sync'), $profile_id, $limit)
            );
        }

        return true;
    }
}
