<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('product_gpt_render_preview_page')) {
function product_gpt_render_preview_page() {
    $bg_color     = function_exists('product_gpt_get_brand_color') ? product_gpt_get_brand_color('bg') : '#f8f9fa';
    $btn_color    = function_exists('product_gpt_get_brand_color') ? product_gpt_get_brand_color('btn') : '#38b000';
    $btn_hover    = function_exists('product_gpt_get_brand_color') ? product_gpt_get_brand_color('hover') : '#2f8600';
    $btn_active   = function_exists('product_gpt_get_brand_color') ? product_gpt_get_brand_color('active') : '#267000';
    $accent_color = function_exists('product_gpt_get_brand_color') ? product_gpt_get_brand_color('accent') : '#495057';
    ?>
    <div class="wrap" id="product-gpt-preview">
        <style>
            #product-gpt-preview .custom-section{background-color:<?php echo esc_attr($bg_color); ?>;border-color:<?php echo esc_attr($accent_color); ?>}
            #product-gpt-preview .accent{color:<?php echo esc_attr($accent_color); ?>}
            #product-gpt-preview .btn-primary{background-color:<?php echo esc_attr($btn_color); ?>}
            #product-gpt-preview .btn-primary:hover{background-color:<?php echo esc_attr($btn_hover); ?>}
            #product-gpt-preview .btn-primary:active{background-color:<?php echo esc_attr($btn_active); ?>}
        </style>
        <h1 class="font-playfair text-3xl mb-6">Anteprima Batch</h1>
        <div class="custom-section rounded-lg shadow p-6 border border-accent">
            <p class="mb-4">L'anteprima dei risultati batch è una funzionalità riservata agli utenti <strong>Product GPT Premium</strong>.</p>
            <p class="mb-4">Aggiorna per revisionare e approvare rapidamente i prodotti generati in blocco prima di pubblicarli.</p>
            <a class="btn-primary text-white px-6 py-2 rounded-md shadow inline-block" href="mailto:russovittorio94@gmail.com?subject=Richiesta%20Product%20GPT%20Premium">Richiedi Premium</a>
        </div>
    </div>
    <?php
}
}
