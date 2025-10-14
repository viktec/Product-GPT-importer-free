<?php
// Multi-product batch processing for Product GPT Importer

if (!defined('ABSPATH')) exit;

if (!function_exists('product_gpt_render_batch_page')) {
function product_gpt_render_batch_page() {
    $bg_color     = function_exists('product_gpt_get_brand_color') ? product_gpt_get_brand_color('bg') : '#f8f9fa';
    $btn_color    = function_exists('product_gpt_get_brand_color') ? product_gpt_get_brand_color('btn') : '#38b000';
    $btn_hover    = function_exists('product_gpt_get_brand_color') ? product_gpt_get_brand_color('hover') : '#2f8600';
    $btn_active   = function_exists('product_gpt_get_brand_color') ? product_gpt_get_brand_color('active') : '#267000';
    $accent_color = function_exists('product_gpt_get_brand_color') ? product_gpt_get_brand_color('accent') : '#495057';

    ?>
    <div class="wrap" id="product-gpt-batch">
        <style>
            #product-gpt-batch .custom-section{background-color:<?php echo esc_attr($bg_color); ?>;border-color:<?php echo esc_attr($accent_color); ?>}
            #product-gpt-batch .accent{color:<?php echo esc_attr($accent_color); ?>}
            #product-gpt-batch .btn-primary{background-color:<?php echo esc_attr($btn_color); ?>}
            #product-gpt-batch .btn-primary:hover{background-color:<?php echo esc_attr($btn_hover); ?>}
            #product-gpt-batch .btn-primary:active{background-color:<?php echo esc_attr($btn_active); ?>}
        </style>
        <h1 class="font-playfair text-3xl mb-6">Multiprodotto GPT</h1>
        <div class="custom-section rounded-lg shadow p-6 border border-accent">
            <p class="mb-4">La gestione multiprodotto è disponibile esclusivamente con <strong>Product GPT Premium</strong>.</p>
            <p class="mb-4">Aggiorna per sbloccare caricamenti multipli, anteprime batch e altre funzionalità avanzate.</p>
            <a class="btn-primary text-white px-6 py-2 rounded-md shadow inline-block" href="mailto:russovittorio94@gmail.com?subject=Richiesta%20Product%20GPT%20Premium">Richiedi Premium</a>
        </div>
    </div>
    <?php
}
}

if (!function_exists('product_gpt_handle_batch_submit')) {
function product_gpt_handle_batch_submit() {
    echo '<div class="notice notice-warning"><p>' . esc_html__('La creazione batch è disponibile solo con Product GPT Premium.', 'product-gpt') . '</p></div>';
}
}

if (!function_exists('product_gpt_fetch_url_data')) {
function product_gpt_fetch_url_data($url, $max_tokens = 10000) {
    $resp = wp_remote_get(esc_url_raw($url), [
        'headers' => ['User-Agent' => 'Mozilla/5.0 (ProductGPTImporter)'],
        'timeout' => 20,
        'redirection' => 5,
    ]);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return ['text'=>'', 'images'=>[]];
    $body = wp_remote_retrieve_body($resp);
    $images = product_gpt_extract_images($body, $url);
    $body = wp_strip_all_tags($body);
    // Limita la lunghezza per evitare errori di token
    $chars = $max_tokens * 4; // stima 1 token ~ 4 caratteri
    if (mb_strlen($body, '8bit') > $chars) {
        $body = mb_substr($body, 0, $chars);
    }
    return ['text' => $body, 'images' => $images];
}
}

if (!function_exists('product_gpt_estimate_tokens')) {
function product_gpt_estimate_tokens($text) {
    return (int) ceil(mb_strlen($text, '8bit') / 4);
}
}

if (!function_exists('product_gpt_trim_tokens')) {
function product_gpt_trim_tokens($text, $max_tokens) {
    $chars = $max_tokens * 4;
    return mb_strlen($text, '8bit') > $chars ? mb_substr($text, 0, $chars) : $text;
}
}
