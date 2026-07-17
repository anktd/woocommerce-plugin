<?php
/**
 * Blockonomics Setup Wizard
 *
 * Handles the intial setup wizard functionality for Blockonomics plugin
 */
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Blockonomics_Setup {
    private $api_key;

    private function log_http_error($context, $error) {
        if (!function_exists('wc_get_logger') || !is_wp_error($error)) {
            return;
        }

        wc_get_logger()->error(
            $context . ': ' . $error->get_error_message(),
            array('source' => 'blockonomics')
        );
    }

    private function connection_error($context, $error) {
        $this->log_http_error($context, $error);

        return array(
            'error' => __('Could not reach Blockonomics. Please check your connection and try again.', 'blockonomics-bitcoin-payments')
        );
    }

    private function response_error($response, $context, $fallback_message, $use_server_message = false) {
        if (is_wp_error($response)) {
            return $this->connection_error($context, $response);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 200) {
            return null;
        }

        if ($response_code === 401) {
            return array('error' => __('API Key is incorrect', 'blockonomics-bitcoin-payments'));
        }

        if ($response_code === 429 || ($response_code >= 500 && $response_code < 600)) {
            return array(
                'error' => __('Blockonomics is temporarily unavailable. Please try again in a few minutes.', 'blockonomics-bitcoin-payments')
            );
        }

        if ($use_server_message) {
            $response_data = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($response_data)) {
                $server_message = $response_data['message'] ?? $response_data['error'] ?? '';
                if (is_string($server_message) && $server_message !== '') {
                    return array('error' => sanitize_text_field($server_message));
                }
            }
        }

        return array('error' => $fallback_message);
    }

    private function fetch_stores_with_wallets($api_key) {
        // Wallet details are required to select and finalize a configured store.
        $stores_url = add_query_arg(
            'wallets',
            'true',
            Blockonomics::BASE_URL . '/api/v2/stores'
        );

        $response = wp_remote_get(
            $stores_url,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json'
                ),
                'timeout' => 8
            )
        );

        $error = $this->response_error(
            $response,
            'Setup wizard store lookup failed',
            __('Failed to check stores', 'blockonomics-bitcoin-payments')
        );
        if ($error) {
            return $error;
        }

        $response_data = json_decode(wp_remote_retrieve_body($response));
        if (!is_object($response_data) || !isset($response_data->data) || !is_array($response_data->data)) {
            return array(
                'error' => __('Invalid response was received. Please retry.', 'blockonomics-bitcoin-payments')
            );
        }

        foreach ($response_data->data as $store) {
            if (
                !is_object($store) ||
                !isset($store->id) ||
                !property_exists($store, 'name') ||
                !property_exists($store, 'http_callback') ||
                !isset($store->wallets) ||
                !is_array($store->wallets)
            ) {
                return array(
                    'error' => __('Invalid response was received. Please retry.', 'blockonomics-bitcoin-payments')
                );
            }
        }

        return array('stores' => $response_data->data);
    }

    private function get_callback_url() {
        $callback_secret = get_option('blockonomics_callback_secret');
        $api_url = WC()->api_request_url('WC_Gateway_Blockonomics');
        return add_query_arg('secret', $callback_secret, $api_url);
    }

    // Check if API key is valid and has wallets
    public function validate_api_key($api_key) {
        $wallets_url = 'https://www.blockonomics.co/api/v2/wallets';
        $response = wp_remote_get($wallets_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 8
        ));

        if (is_wp_error($response)) {
            return $this->connection_error('Setup wizard wallet validation failed', $response);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 401) {
            return array('error' => __('API Key is incorrect', 'blockonomics-bitcoin-payments'));
        }

        if ($response_code === 429 || ($response_code >= 500 && $response_code < 600)) {
            return array(
                'error' => __('Blockonomics is temporarily unavailable. Please try again in a few minutes.', 'blockonomics-bitcoin-payments')
            );
        }

        if ($response_code !== 200) {
            return array('error' => __('Could not verify API key', 'blockonomics-bitcoin-payments'));
        }

        $wallets = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($wallets) || !isset($wallets['data']) || !is_array($wallets['data'])) {
            return array('error' => __('Could not verify API key', 'blockonomics-bitcoin-payments'));
        }

        if (empty($wallets['data'])) {
            return array(
                'error' => __('Please create a Wallet', 'blockonomics-bitcoin-payments'),
                'error_code' => 'wallet_required'
            );
        }

        $wallet_id = $wallets['data'][0]['id'] ?? null;
        if (!$wallet_id) {
            return array('error' => __('Could not verify API key', 'blockonomics-bitcoin-payments'));
        }

        // Keep the existing single-wallet option until the all-wallet reconciliation change.
        update_option('blockonomics_temp_wallet_id', $wallet_id);

        $this->api_key = $api_key;
        update_option('blockonomics_api_key', $api_key);

        return array('success' => true);
    }

    public function check_store_setup() {
        $api_key = get_option('blockonomics_api_key');
        $stores_result = $this->fetch_stores_with_wallets($api_key);
        if (isset($stores_result['error'])) {
            return $stores_result;
        }

        $stores = $stores_result['stores'];
        if (empty($stores)) {
            return array('needs_store' => true);
        }

        $wordpress_callback_url = $this->get_callback_url();
        $base_url = preg_replace('/https?:\/\//', '', WC()->api_request_url('WC_Gateway_Blockonomics'));

        // if multiple store matches, collect all
        $exact_matches = array();
        $partial_matches = array();

        foreach ($stores as $store) {
            // first we check for exact match
            if ($store->http_callback === $wordpress_callback_url) {
                $exact_matches[] = $store;
            }
            // Check for partial match - only secret or protocol differs
            elseif (!empty($store->http_callback)) {
                $store_base_url = preg_replace('/https?:\/\//', '', $store->http_callback);
                if (strpos($store_base_url, $base_url) === 0) {
                    $partial_matches[] = $store;
                }
            }
        }
        //prefer exact match > partial match
        if (!empty($exact_matches)){
            $best_store = $this->select_best_store($exact_matches);
            return $this->finalize_store_match($best_store, $api_key);
        }
        if (!empty($partial_matches)){
            $best_store = $this->select_best_store($partial_matches);
            $update_response = wp_remote_post(
                Blockonomics::BASE_URL . '/api/v2/stores/' . $best_store->id,
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $api_key,
                        'Content-Type' => 'application/json'
                    ),
                    'body' => wp_json_encode(array(
                        'name' => $best_store->name,
                        'http_callback' => $wordpress_callback_url
                    )),
                    'timeout' => 8
                )
            );

            $update_error = $this->response_error(
                $update_response,
                'Setup wizard callback update failed',
                __("Could not update your store's callback URL. Please try again.", 'blockonomics-bitcoin-payments')
            );
            if ($update_error) {
                return $update_error;
            }

            return $this->finalize_store_match($best_store, $api_key);
        }
        // No matching store found - need to create a new one
        return array('needs_store' => true);
    }

    /*
     * @param object $store The matched store object (with wallets property from ?wallets=true)
     * @param string $api_key The API key for Blockonomics
     * @return array Result array with success or error
     */
    private function finalize_store_match($store, $api_key) {
        $temp_wallet_id = get_option('blockonomics_temp_wallet_id');

        if (empty($store->wallets) && $temp_wallet_id) {
            // Store has no wallets - attach the temp wallet
            $wallet_attach_response = wp_remote_post(
                Blockonomics::BASE_URL . '/api/v2/stores/' . $store->id . '/wallets',
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $api_key,
                        'Content-Type' => 'application/json'
                    ),
                    'body' => wp_json_encode(array(
                        'wallet_id' => (int)$temp_wallet_id
                    ))
                )
            );

            if (wp_remote_retrieve_response_code($wallet_attach_response) === 200) {
                $wallet_attach_data = json_decode(wp_remote_retrieve_body($wallet_attach_response), true);
                if (!empty($wallet_attach_data['data']['wallets'])) {
                    $store->wallets = json_decode(json_encode($wallet_attach_data['data']['wallets']));
                }
            }
        }

        $enabled_cryptos = $this->extract_enabled_cryptos($store);
        if (!empty($enabled_cryptos)) {
            update_option('blockonomics_enabled_cryptos', implode(',', $enabled_cryptos));
        }

        update_option('blockonomics_store_name', $store->name);

        delete_option('blockonomics_temp_wallet_id');

        return array('success' => true);
    }

    /*
     * Extract enabled crypto currencies from store's wallets
     * @param object $store Store object with wallets property
     * @return array Array of lowercase crypto codes (e.g., ['btc', 'usdt'])
     */
    private function extract_enabled_cryptos($store) {
        $enabled_cryptos = array();
        if (!empty($store->wallets)) {
            foreach ($store->wallets as $wallet) {
                if (isset($wallet->crypto)) {
                    $crypto = strtolower($wallet->crypto);
                    if (!in_array($crypto, $enabled_cryptos)) {
                        $enabled_cryptos[] = $crypto;
                    }
                }
            }
        }
        return $enabled_cryptos;
    }

    public function create_store($store_name) {
        if (empty($store_name)) {
            return array('error' => 'Please enter your store name');
        }
        $api_key = get_option('blockonomics_api_key');
        $wallet_id = get_option('blockonomics_temp_wallet_id');
        $callback_url = $this->get_callback_url();
        $store_lookup = $this->find_store_by_callback($api_key, $callback_url);
        if (isset($store_lookup['error'])) {
            return $store_lookup;
        }

        $existing_store = $store_lookup['store'];
        if ($existing_store !== null) {
            // store already exists - use it instead of creating duplicate
            // update store name if user provided a different one
            if ($store_name !== $existing_store->name) {
                $name_update = $this->update_store_name($api_key, $existing_store->id, $store_name);
                if (isset($name_update['error'])) {
                    return $name_update;
                }
                $existing_store->name = $store_name;
            }
            return $this->finalize_store_match($existing_store, $api_key);
        }

        // Step 1: Create store - when no existing store is found
        $store_data = array(
            'name' => $store_name,
            'http_callback' => $callback_url
        );
        $response = wp_remote_post(
            Blockonomics::BASE_URL . '/api/v2/stores',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode($store_data),
                'timeout' => 8
            )
        );

        $create_error = $this->response_error(
            $response,
            'Setup wizard store creation failed',
            __('Failed to create store', 'blockonomics-bitcoin-payments'),
            true
        );
        if ($create_error) {
            return $create_error;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        if (!is_array($response_data) || empty($response_data['data']['id'])) {
            return array('error' => __('Failed to create store', 'blockonomics-bitcoin-payments'));
        }

        $store_id = $response_data['data']['id'];
        if ($wallet_id) {

            // Step 2: Attach wallet to store
            $wallet_attach_response = wp_remote_post(
                Blockonomics::BASE_URL . '/api/v2/stores/' . $store_id . '/wallets',
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $api_key,
                        'Content-Type' => 'application/json'
                    ),
                    'body' => wp_json_encode(array(
                        'wallet_id' => (int)$wallet_id
                    ))
                )
            );

            // Step 3: Update enabled cryptos based on attached wallet
            $wallet_attach_data = json_decode(wp_remote_retrieve_body($wallet_attach_response), true);
            if (!empty($wallet_attach_data['data']['wallets'][0]['crypto'])) {
                $crypto = strtolower($wallet_attach_data['data']['wallets'][0]['crypto']);
                update_option('blockonomics_enabled_cryptos', $crypto);
            }
        }
        update_option('blockonomics_store_name', $store_name);
        delete_option('blockonomics_temp_wallet_id');
        return array('success' => true);
    }

    /* Find a store by its callback URL
     * selects best store when multiple matches exist
     * @param string $api_key The API key for Blockonomics
     * @param string $callback_url The callback URL to search for
     * @return array Result containing a store object/null, or an error
     */
    private function find_store_by_callback($api_key, $callback_url) {
        $stores_result = $this->fetch_stores_with_wallets($api_key);
        if (isset($stores_result['error'])) {
            return $stores_result;
        }

        if (empty($stores_result['stores'])) {
            return array('store' => null);
        }

        // collect all matching stores
        $matching_stores = array();
        foreach ($stores_result['stores'] as $store) {
            if ($store->http_callback === $callback_url) {
                $matching_stores[] = $store;
            }
        }
        if (empty($matching_stores)){
            return array('store' => null);
        }
        //always return best store from matches
        return array('store' => $this->select_best_store($matching_stores));
    }

    /*
     * Select the best store from a list of matching stores
     * determine which store to select based on config
     * @param array $stores Array of store objects
     * @return object Best store from the list
     */
    private function select_best_store($stores) {
        if (count($stores) === 1) {
            return $stores[0];
        }

        $best_store = $stores[0];
        $best_score = $this->score_store($stores[0]);

        for ($i = 1; $i < count($stores); $i++) {
            $score = $this->score_store($stores[$i]);
            if ($score > $best_score) {
                $best_score = $score;
                $best_store = $stores[$i];
            }
        }
        return $best_store;
    }

    /*
     * KEY IDEA is to select store with enabled crypto rather than store w/o any crypto enabled and empty string named store
     * This is so that checkout dont break even when test setup is sucessful. Very edge case type thing but was reported by merchants.
     * Score a store based on its configuration quality
     * Higher score = better configured store
     * Scoring:
     * - Has wallets attached: +10 (only practical requirement as otherwise the checkout breaks)
     * - Has a non-empty name: +1 (tie-breaker only, since unnamed store can be created by multiple clicks on setup wizard)
     * @param object $store Store object with wallets property
     * @return int Score value
     */
    private function score_store($store) {
        $score = 0;
        //  crypto/wallets enabled? +10 (this is only thing we are concerned about)
        if (!empty($store->wallets)) {
            $score += 10;
        }
        // has a non-empty name: +1 (this is never used but we still account for it)
        $name = trim($store->name ?? '');
        if (!empty($name)) {
            $score += 1;
        }
        return $score;
    }

    /*
     * Update a store's name. Its used when user provides a different name for an existing store
     * @param string $api_key The API key for Blockonomics
     * @param int $store_id The store ID to update
     * @param string $new_name The new name for the store
     * @return array Result containing success or an error
     */
    private function update_store_name($api_key, $store_id, $new_name) {
        $response = wp_remote_post(
            Blockonomics::BASE_URL . '/api/v2/stores/' . $store_id,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode(array(
                    'name' => $new_name
                )),
                'timeout' => 8
            )
        );

        $update_error = $this->response_error(
            $response,
            'Setup wizard store name update failed',
            __('Could not update your store name. Please try again.', 'blockonomics-bitcoin-payments')
        );
        if ($update_error) {
            return $update_error;
        }

        return array('success' => true);
    }
}
