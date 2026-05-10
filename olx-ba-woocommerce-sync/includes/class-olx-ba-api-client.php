<?php

if (!defined('ABSPATH')) {
    exit;
}

class OLX_BA_API_Client
{
    private const API_BASE = 'https://api.olx.ba';
    private const OPTION_NAME = 'olx_ba_wc_sync_settings';
    private const TOKEN_OPTION = 'olx_ba_wc_sync_token';
    private string $context_profile_id = '';

    public function use_profile(string $profile_id): void
    {
        $this->context_profile_id = sanitize_key($profile_id);
    }

    public function clear_profile(): void
    {
        $this->context_profile_id = '';
    }

    public function get_settings(): array
    {
        $settings = get_option(self::OPTION_NAME, []);

        $settings = wp_parse_args(is_array($settings) ? $settings : [], [
            'username' => '',
            'password' => '',
            'device_name' => 'woocommerce',
            'country_id' => '49',
            'city_id' => '',
            'default_category_id' => '',
            'default_state' => 'new',
            'default_shipping' => 'no_shipping',
            'auto_publish' => 'no',
            'auto_sync' => 'no',
            'sync_images' => 'yes',
            'use_default_attributes' => 'yes',
            'default_attribute_profiles' => '',
            'active_profile_id' => 'default',
            'profiles_json' => '',
            'category_mappings' => '',
        ]);

        if ($this->context_profile_id !== '') {
            $settings = $this->merge_profile_settings($settings, $this->context_profile_id);
        }

        return $settings;
    }

    public function update_settings(array $settings): void
    {
        $current = $this->get_settings();
        $password = (string) ($settings['password'] ?? '');

        update_option(self::OPTION_NAME, [
            'username' => sanitize_text_field($settings['username'] ?? ''),
            'password' => $password !== '' ? $password : $current['password'],
            'device_name' => sanitize_key($settings['device_name'] ?? 'woocommerce'),
            'country_id' => absint($settings['country_id'] ?? 49),
            'city_id' => absint($settings['city_id'] ?? 0),
            'default_category_id' => absint($settings['default_category_id'] ?? 0),
            'default_state' => in_array(($settings['default_state'] ?? 'new'), ['new', 'used'], true) ? $settings['default_state'] : 'new',
            'default_shipping' => sanitize_key($settings['default_shipping'] ?? 'no_shipping'),
            'auto_publish' => !empty($settings['auto_publish']) ? 'yes' : 'no',
            'auto_sync' => !empty($settings['auto_sync']) ? 'yes' : 'no',
            'sync_images' => !empty($settings['sync_images']) ? 'yes' : 'no',
            'use_default_attributes' => !empty($settings['use_default_attributes']) ? 'yes' : 'no',
            'default_attribute_profiles' => $this->sanitize_attribute_profiles((string) ($settings['default_attribute_profiles'] ?? $current['default_attribute_profiles'])),
            'active_profile_id' => sanitize_key($settings['active_profile_id'] ?? $current['active_profile_id']),
            'profiles_json' => $this->sanitize_profiles_json((string) ($settings['profiles_json'] ?? $current['profiles_json'])),
            'category_mappings' => $this->sanitize_simple_mapping_json((string) ($settings['category_mappings'] ?? $current['category_mappings'])),
        ]);
    }

    public function get_profiles(): array
    {
        $settings = get_option(self::OPTION_NAME, []);
        $settings = is_array($settings) ? $settings : [];
        $profiles = json_decode((string) ($settings['profiles_json'] ?? ''), true);

        if (!is_array($profiles)) {
            $profiles = [];
        }

        $profiles = array_values(array_filter($profiles, 'is_array'));
        if ($profiles === []) {
            $profiles[] = [
                'id' => 'default',
                'name' => __('Default profile', 'olx-ba-woocommerce-sync'),
            ];
        }

        return $profiles;
    }

    public function get_active_profile_id(): string
    {
        $settings = $this->get_settings();
        return sanitize_key($settings['active_profile_id'] ?: 'default');
    }

    public function get_token(): string
    {
        $key = $this->context_profile_id !== '' ? self::TOKEN_OPTION . '_' . $this->context_profile_id : self::TOKEN_OPTION;
        return (string) get_option($key, '');
    }

    public function clear_token(): void
    {
        $key = $this->context_profile_id !== '' ? self::TOKEN_OPTION . '_' . $this->context_profile_id : self::TOKEN_OPTION;
        delete_option($key);
    }

    public function login()
    {
        $settings = $this->get_settings();

        if ($settings['username'] === '' || $settings['password'] === '') {
            return new WP_Error('olx_missing_credentials', __('OLX.ba username and password are required.', 'olx-ba-woocommerce-sync'));
        }

        $body = [
            'username' => $settings['username'],
            'password' => $settings['password'],
            'device_name' => $settings['device_name'] ?: 'woocommerce',
        ];

        $response = $this->request('POST', '/auth/login', $body, false);

        if (is_wp_error($response)) {
            $response = $this->request('POST', '/auth/login', $body, false, 'form');
        }

        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['token'])) {
            return new WP_Error('olx_missing_token', __('OLX.ba login response did not include a token.', 'olx-ba-woocommerce-sync'));
        }

        $key = $this->context_profile_id !== '' ? self::TOKEN_OPTION . '_' . $this->context_profile_id : self::TOKEN_OPTION;
        update_option($key, sanitize_text_field($response['token']), false);

        return $response;
    }

    public function get(string $path)
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $body = [])
    {
        return $this->request('POST', $path, $body);
    }

    public function put(string $path, array $body = [])
    {
        return $this->request('PUT', $path, $body);
    }

    public function delete(string $path)
    {
        return $this->request('DELETE', $path);
    }

    public function upload_image_url(int $listing_id, string $image_url)
    {
        return $this->post('/listings/' . $listing_id . '/image-upload', [
            'image_url' => esc_url_raw($image_url),
        ]);
    }

    private function request(string $method, string $path, array $body = [], bool $authenticated = true, string $body_format = 'json')
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($body_format === 'json') {
            $headers['Content-Type'] = 'application/json';
        }

        if ($authenticated) {
            $token = $this->get_token();

            if ($token === '') {
                $login = $this->login();
                if (is_wp_error($login)) {
                    return $login;
                }
                $token = $this->get_token();
            }

            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
        ];

        if ($body !== []) {
            $args['body'] = $body_format === 'form' ? $body : wp_json_encode($body);
        }

        $response = wp_remote_request(self::API_BASE . $path, $args);

        if (is_wp_error($response)) {
            OLX_BA_Logger::error('OLX.ba HTTP transport error.', [
                'method' => $method,
                'path' => $path,
                'message' => $response->get_error_message(),
            ]);
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw_body, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($authenticated && in_array($status, [401, 403], true)) {
            $this->clear_token();
        }

        if ($status < 200 || $status >= 300) {
            $message = $this->format_error_message($decoded, $status);
            OLX_BA_Logger::error('OLX.ba API request failed.', [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'response' => $decoded,
            ]);
            return new WP_Error('olx_api_error', $message, [
                'status' => $status,
                'response' => $decoded,
            ]);
        }

        return $decoded;
    }

    private function format_error_message(array $decoded, int $status): string
    {
        $messages = [];

        if (!empty($decoded['message']) && is_string($decoded['message'])) {
            $messages[] = $decoded['message'];
        }

        foreach (['errors', 'error'] as $key) {
            if (!empty($decoded[$key])) {
                $messages = array_merge($messages, $this->flatten_error_messages($decoded[$key]));
            }
        }

        $messages = array_values(array_unique(array_filter(array_map('trim', $messages))));

        if ($messages !== []) {
            return implode(' ', $messages);
        }

        return sprintf(__('OLX.ba API request failed with HTTP %d.', 'olx-ba-woocommerce-sync'), $status);
    }

    private function flatten_error_messages($value, string $prefix = ''): array
    {
        if (is_string($value)) {
            return [$prefix !== '' ? $prefix . ': ' . $value : $value];
        }

        if (!is_array($value)) {
            return [];
        }

        $messages = [];
        foreach ($value as $key => $item) {
            $label = is_string($key) ? $key : $prefix;
            $messages = array_merge($messages, $this->flatten_error_messages($item, $label));
        }

        return $messages;
    }

    private function sanitize_attribute_profiles(string $profiles): string
    {
        $profiles = trim($profiles);
        if ($profiles === '') {
            return '';
        }

        $decoded = json_decode($profiles, true);
        if (!is_array($decoded)) {
            return $profiles;
        }

        $sanitized = [];
        foreach ($decoded as $category_id => $attributes) {
            if (!is_array($attributes)) {
                continue;
            }

            $category_id = absint($category_id);
            if ($category_id <= 0) {
                continue;
            }

            $sanitized[$category_id] = [];
            foreach ($attributes as $attribute_id => $value) {
                $attribute_id = absint($attribute_id);
                $value = sanitize_text_field((string) $value);

                if ($attribute_id <= 0 || $value === '') {
                    continue;
                }

                $sanitized[$category_id][$attribute_id] = $value;
            }
        }

        return wp_json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function merge_profile_settings(array $settings, string $profile_id): array
    {
        foreach ($this->get_profiles() as $profile) {
            if (sanitize_key($profile['id'] ?? '') !== $profile_id) {
                continue;
            }

            foreach ([
                'username',
                'password',
                'device_name',
                'country_id',
                'city_id',
                'default_category_id',
                'default_state',
                'default_shipping',
                'auto_publish',
                'sync_images',
                'use_default_attributes',
                'default_attribute_profiles',
                'max_active_listings',
                'category_mappings',
            ] as $key) {
                if (array_key_exists($key, $profile) && $profile[$key] !== '') {
                    $settings[$key] = $profile[$key];
                }
            }

            $settings['active_profile_id'] = $profile_id;
            break;
        }

        return $settings;
    }

    private function sanitize_profiles_json(string $profiles_json): string
    {
        $profiles_json = trim($profiles_json);
        if ($profiles_json === '') {
            return '';
        }

        $decoded = json_decode($profiles_json, true);
        if (!is_array($decoded)) {
            return $profiles_json;
        }

        $profiles = [];
        foreach ($decoded as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $id = sanitize_key($profile['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $profiles[] = [
                'id' => $id,
                'name' => sanitize_text_field((string) ($profile['name'] ?? $id)),
                'username' => sanitize_text_field((string) ($profile['username'] ?? '')),
                'password' => (string) ($profile['password'] ?? ''),
                'device_name' => sanitize_key((string) ($profile['device_name'] ?? 'woocommerce')),
                'country_id' => absint($profile['country_id'] ?? 49),
                'city_id' => absint($profile['city_id'] ?? 0),
                'default_category_id' => absint($profile['default_category_id'] ?? 0),
                'default_state' => in_array(($profile['default_state'] ?? 'new'), ['new', 'used'], true) ? $profile['default_state'] : 'new',
                'default_shipping' => sanitize_key((string) ($profile['default_shipping'] ?? 'no_shipping')),
                'auto_publish' => !empty($profile['auto_publish']) ? 'yes' : 'no',
                'sync_images' => !empty($profile['sync_images']) ? 'yes' : 'no',
                'use_default_attributes' => !empty($profile['use_default_attributes']) ? 'yes' : 'no',
                'default_attribute_profiles' => $this->sanitize_attribute_profiles(
                    is_array($profile['default_attribute_profiles'] ?? null)
                        ? wp_json_encode($profile['default_attribute_profiles'], JSON_UNESCAPED_UNICODE)
                        : (string) ($profile['default_attribute_profiles'] ?? '')
                ),
                'max_active_listings' => absint($profile['max_active_listings'] ?? 0),
                'category_mappings' => $this->sanitize_simple_mapping_json(
                    is_array($profile['category_mappings'] ?? null)
                        ? wp_json_encode($profile['category_mappings'], JSON_UNESCAPED_UNICODE)
                        : (string) ($profile['category_mappings'] ?? '')
                ),
            ];
        }

        return wp_json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function sanitize_simple_mapping_json(string $mapping_json): string
    {
        $mapping_json = trim($mapping_json);
        if ($mapping_json === '') {
            return '';
        }

        $decoded = json_decode($mapping_json, true);
        if (!is_array($decoded)) {
            return $mapping_json;
        }

        $mapping = [];
        foreach ($decoded as $source => $target) {
            $source = sanitize_text_field((string) $source);
            $target = absint($target);

            if ($source === '' || $target <= 0) {
                continue;
            }

            $mapping[$source] = $target;
        }

        return wp_json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
