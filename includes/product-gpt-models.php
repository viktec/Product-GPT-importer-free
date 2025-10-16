<?php
// === Dynamic GPT model management helpers ===

// Requires the API key stored in the plugin options
if (!function_exists('product_gpt_fetch_models')) {
function product_gpt_fetch_models($api_key) {
    if (empty($api_key)) {
        update_option('product_gpt_raw_model_response', 'API KEY VUOTA!');
        return [];
    }
    $response = wp_remote_get('https://api.openai.com/v1/models', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key
        ],
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) {
        update_option('product_gpt_raw_model_response', 'ERRORE: ' . $response->get_error_message());
        return [];
    }
    $body = wp_remote_retrieve_body($response);
    update_option('product_gpt_raw_model_response', $body);
    $data = json_decode($body, true);
    if (empty($data['data'])) return [];
    $models = array_filter($data['data'], function($m) {
        return isset($m['id']) && preg_match('/^gpt/', $m['id']);
    });
    return array_map(function($m) { return $m['id']; }, $models);
}
}



if (!function_exists('product_gpt_update_model_cache')) {
function product_gpt_update_model_cache() {
    $api_key = get_option('product_gpt_api_key');
    if (!$api_key) return [];
    $models = product_gpt_fetch_models($api_key);
    update_option('product_gpt_available_models', $models);
    return $models;
}
}

// AJAX handler that refreshes the available models
add_action('wp_ajax_product_gpt_refresh_models', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Non autorizzato']);
    }
    $api_key = get_option('product_gpt_api_key');
    // Useful debug guard to ensure an API key exists
    if (!$api_key) {
        wp_send_json_error(['message' => 'API Key mancante nel backend!']);
    }
    $models = array_values(product_gpt_update_model_cache());
    $raw    = get_option('product_gpt_raw_model_response');
    wp_send_json_success([
        'models' => $models,
        'raw'    => $raw,
    ]);
});

