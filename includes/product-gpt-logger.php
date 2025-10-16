<?php

// Log requests and responses
if (!function_exists('product_gpt_log_event')) {
function product_gpt_log_event($request, $response) {
    if (!get_option('product_gpt_debug')) return;

    // Convert everything into JSON-safe strings and limit their length for safety
    $safe_request  = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $safe_response = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

    // Limit the length to 10,000 characters (adjust if needed)
    $max_length = 10000;
    if ($safe_request && strlen($safe_request) > $max_length) {
        $safe_request = substr($safe_request, 0, $max_length) . '... [troncato]';
    }
    if ($safe_response && strlen($safe_response) > $max_length) {
        $safe_response = substr($safe_response, 0, $max_length) . '... [troncato]';
    }

    $logs = get_option('product_gpt_logs', []);
    $logs[] = [
        'timestamp' => current_time('mysql'),
        'request' => $safe_request,
        'response' => $safe_response
    ];

    // Keep only the 50 most recent log entries
    $logs = array_slice($logs, -50);

    update_option('product_gpt_logs', $logs);
}
}

// Retrieve logs
if (!function_exists('product_gpt_get_logs')) {
function product_gpt_get_logs() {
    return array_reverse(get_option('product_gpt_logs', []));
}
}

// Clear logs
if (!function_exists('product_gpt_clear_logs')) {
function product_gpt_clear_logs() {
    update_option('product_gpt_logs', []);
}
}
