<?php
/**
 * Plugin Name: Product GPT Importer
 * Description: Crea prodotti WooCommerce utilizzando ChatGPT partendo da file di scheda tecnica o da una pagina web esterna.
 * Version: 1.3.0
 * Author: Vittorio Russo
 */

if (!defined('ABSPATH')) exit;

if (!defined('PRODUCT_GPT_IMPORTER_VERSION')) {
    define('PRODUCT_GPT_IMPORTER_VERSION', '1.30');
}

// Includi la settings page e il logger

require_once plugin_dir_path(__FILE__).'/includes/product-gpt-settings.php';
require_once plugin_dir_path(__FILE__).'/includes/product-gpt-logger.php';
require_once plugin_dir_path(__FILE__).'/includes/product-gpt-models.php';
require_once plugin_dir_path(__FILE__).'/includes/product-gpt-batch.php';
require_once plugin_dir_path(__FILE__).'/includes/product-gpt-preview.php';
require_once plugin_dir_path(__FILE__).'/includes/pdf-extract.php';

if (!function_exists('product_gpt_get_brand_colors')) {
function product_gpt_get_brand_colors() {
    return [
        'bg'     => '#f8f9fa',
        'btn'    => '#38b000',
        'hover'  => '#2f8600',
        'active' => '#267000',
        'accent' => '#495057',
    ];
}
}

if (!function_exists('product_gpt_get_brand_color')) {
function product_gpt_get_brand_color($key) {
    $colors = product_gpt_get_brand_colors();
    return $colors[$key] ?? '';
}
}

if (!function_exists('product_gpt_register_request_attempt')) {
function product_gpt_register_request_attempt() {
    $limit   = 3;
    $window  = HOUR_IN_SECONDS;
    $now     = time();
    $records = get_option('product_gpt_request_timestamps', []);
    if (!is_array($records)) {
        $records = [];
    }

    $records = array_filter($records, function($timestamp) use ($now, $window) {
        return ($now - (int) $timestamp) < $window;
    });

    if (count($records) >= $limit) {
        $earliest = min($records);
        $retry_after = max(0, $window - ($now - (int) $earliest));
        return [
            'allowed' => false,
            'retry_after' => $retry_after,
        ];
    }

    $records[] = $now;
    update_option('product_gpt_request_timestamps', array_values($records));

    return [
        'allowed'   => true,
        'remaining' => max(0, $limit - count($records)),
    ];
}
}

// Installazione/aggiornamento: assicura i profili di default
if (!function_exists('product_gpt_install')) {
function product_gpt_install() {
    if (get_option('product_gpt_profiles') === false) {
        update_option('product_gpt_profiles', product_gpt_get_default_profiles());
    }
    update_option('product_gpt_version', PRODUCT_GPT_IMPORTER_VERSION);

    if (!wp_next_scheduled('product_gpt_check_batches')) {
        wp_schedule_event(time() + 300, 'gptbatch', 'product_gpt_check_batches');
    }

    delete_transient('product_gpt_profiles_cache');
}
}

if (!function_exists('product_gpt_ensure_profile_version')) {
function product_gpt_ensure_profile_version() {
    $stored_version = get_option('product_gpt_version');
    if ($stored_version === PRODUCT_GPT_IMPORTER_VERSION) {
        return;
    }

    $profiles = get_option('product_gpt_profiles', []);
    $defaults = product_gpt_get_default_profiles();

    $merged = [];
    // Deduplicate existing profiles by label slug
    foreach ($profiles as $p) {
        $slug = sanitize_title($p['label']);
        if (!isset($merged[$slug])) {
            $merged[$slug] = $p;
        }
    }

    // Aggiorna i profili esistenti con i nuovi valori di default
    foreach ($defaults as $def) {
        $slug = sanitize_title($def['label']);
        if (isset($merged[$slug])) {
            $merged[$slug] = array_merge($merged[$slug], $def);
        }
    }

    update_option('product_gpt_profiles', array_values($merged));
    update_option('product_gpt_version', PRODUCT_GPT_IMPORTER_VERSION);
    delete_transient('product_gpt_profiles_cache');
}
}

register_activation_hook(__FILE__, 'product_gpt_install');
register_deactivation_hook(__FILE__, function(){
    wp_clear_scheduled_hook('product_gpt_check_batches');
});
add_action('admin_init', 'product_gpt_ensure_profile_version');

add_filter('cron_schedules', function($schedules){
    $schedules['gptbatch'] = [
        'interval' => 300,
        'display'  => 'GPT Batch Poll'
    ];
    return $schedules;
});

// === HELPER PER NORMALIZZAZIONE ATTRIBUTI ===
if (!function_exists('normalize_attributes_flatten')) {
    function normalize_attributes_flatten($value) {
        if (is_array($value)) {
            return implode(', ', array_map('normalize_attributes_flatten', $value));
        }
        return trim($value);
    }
}

if (!function_exists('normalize_attributes_deep')) {
function normalize_attributes_deep($attributes) {
    if (!is_array($attributes)) return normalize_attributes_flatten($attributes);
    foreach ($attributes as $k => $v) {
        $attributes[$k] = normalize_attributes_flatten($v);
    }
    return $attributes;
}
}

if (!function_exists('product_gpt_extract_images')) {
function product_gpt_extract_images($html, $base_url) {
    $urls = [];
    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
        foreach ($matches[1] as $src) {
            if (!preg_match('#^https?://#i', $src)) {
                $src = rtrim(dirname($base_url), '/') . '/' . ltrim($src, '/');
            }
            $urls[] = $src;
        }
    }
    return array_unique($urls);
}
}

if (!function_exists('product_gpt_parse_price')) {
function product_gpt_parse_price($price) {
    $clean = preg_replace('/[^0-9.,]/', '', $price);
    $clean = str_replace(',', '.', $clean);
    return floatval($clean);
}
}

if (!function_exists('product_gpt_download_image')) {
function product_gpt_download_image($url, $post_id = 0, $name = '') {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url);
    if (is_wp_error($tmp)) {
        return 0;
    }

    $ext  = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
    $fname = $name ? sanitize_file_name($name) . ($ext ? '.' . $ext : '') : wp_basename(parse_url($url, PHP_URL_PATH));

    $file = [
        'name'     => $fname,
        'tmp_name' => $tmp
    ];

    $id = media_handle_sideload($file, $post_id);
    if (is_wp_error($id)) {
        @unlink($tmp);
        return 0;
    }

    // Compress without resizing
    $path = get_attached_file($id);
    $editor = wp_get_image_editor($path);
    if (!is_wp_error($editor)) {
        $editor->set_quality(82);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','webp'])) {
            $new_path = preg_replace('/\.[^.]+$/', '.jpg', $path);
            $editor->save($new_path, 'image/jpeg');
            unlink($path);
            update_attached_file($id, $new_path);
            wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $new_path));
        } else {
            $editor->save($path);
        }
    }

    return $id;
}
}

// Admin menu
add_action('admin_menu', function() {
    add_menu_page(
        'Il Mio Prodotto GPT',
        'GPT Prodotto',
        'edit_products',
        'product-gpt-importer',
        'product_gpt_page',
        'dashicons-products',
        56
    );

    // AGGIUNGI la pagina principale anche come primo submenu!
    add_submenu_page(
        'product-gpt-importer',           // parent slug
        'GPT Prodotto',                   // page title
        'Crea prodotto',                  // menu title (come vuoi)
        'edit_products',                  // capability
        'product-gpt-importer',           // slug DEVE essere uguale a quello principale
        'product_gpt_page'                // callback
    );

    // Pagina multiprodotto
    add_submenu_page(
        'product-gpt-importer',
        'Multiprodotto GPT',
        'Multiprodotto',
        'edit_products',
        'product-gpt-batch',
        'product_gpt_render_batch_page'
    );

    // Pagina anteprima batch
    add_submenu_page(
        'product-gpt-importer',
        'Anteprima Batch',
        'Anteprima Batch',
        'edit_products',
        'product-gpt-preview',
        'product_gpt_render_preview_page'
    );

    // Poi la pagina delle impostazioni
    add_submenu_page(
        'product-gpt-importer',
        'Impostazioni GPT',
        'Impostazioni',
        'edit_products',
        'product-gpt-settings',
        'product_gpt_render_settings_page'
    );
});



// Enqueue media uploader JS
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'product-gpt-importer') === false && strpos($hook, 'product-gpt-settings') === false && strpos($hook, 'product-gpt-batch') === false && strpos($hook, 'product-gpt-preview') === false) {
        return;
    }

    wp_enqueue_style(
        'product-gpt-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Playfair+Display:wght@600&display=swap'
    );
    wp_enqueue_script('product-gpt-tailwind', 'https://cdn.tailwindcss.com');
    wp_add_inline_script('product-gpt-tailwind', "tailwind.config={theme:{extend:{fontFamily:{inter:['Inter','sans-serif'],playfair:['Playfair Display','serif']}}}}");
    // admin.css was previously loaded here, but the file is now removed
    // because Tailwind provides all necessary styling

    if ($hook === 'toplevel_page_product-gpt-importer') {
        wp_enqueue_media();
        wp_add_inline_script('jquery-core', <<<JS
jQuery(document).ready(function($){
  let featured = $('#select_featured'), gallery = $('#select_gallery');

  featured.on('click', function(e){
    e.preventDefault();
    const frame = wp.media({ title: 'Seleziona immagine principale', multiple: false });
    frame.on('select', function() {
      const attachment = frame.state().get('selection').first().toJSON();
      $('#featured_image_id').val(attachment.id);
      $('#featured_preview').attr('src', attachment.url).show();
    });
    frame.open();
  });

  gallery.on('click', function(e){
    e.preventDefault();
    const frame = wp.media({ title: 'Seleziona immagini galleria', multiple: true });
    frame.on('select', function() {
      const attachments = frame.state().get('selection').toJSON();
      const ids = attachments.map(img => img.id);
      $('#gallery_image_ids').val(ids.join(","));
      let thumbs = attachments.map(function(img) {
        return '<img src=\'' + img.url + '\' style=\'max-width:80px;margin:2px;\'>';
      }).join('');
      $('#gallery_preview').html(thumbs);
    });
    frame.open();
  });
});
JS
        );
    }
});

// Estrazione testo da DOCX
if (!function_exists('extract_text_from_docx')) {
    function extract_text_from_docx($filename) {
        $zip = new ZipArchive;
        if ($zip->open($filename) === true) {
            if (($index = $zip->locateName('word/document.xml')) !== false) {
                $xml = $zip->getFromIndex($index);
                $zip->close();
                $xml = preg_replace('/<w:.*?>/', '', $xml);
                $xml = preg_replace('/<\/w:.*?>/', '', $xml);
                return strip_tags($xml);
            }
        }
        return false;
    }
}


if (!function_exists('product_gpt_page')) {
function product_gpt_page() {
    $api_key = get_option('product_gpt_api_key');
    $model   = get_option('product_gpt_model', 'gpt-3.5-turbo');
    ?>
    <div class="wrap" id="product-gpt-importer">
        <?php
            $bg_color      = product_gpt_get_brand_color('bg');
            $btn_color     = product_gpt_get_brand_color('btn');
            $btn_hover     = product_gpt_get_brand_color('hover');
            $btn_active    = product_gpt_get_brand_color('active');
            $accent_color  = product_gpt_get_brand_color('accent');
        ?>
        <style>
            #product-gpt-importer .custom-section{background-color:<?php echo esc_attr($bg_color); ?>;border-color:<?php echo esc_attr($accent_color); ?>}
            #product-gpt-importer .accent{color:<?php echo esc_attr($accent_color); ?>}
            #product-gpt-importer .border-accent{border-color:<?php echo esc_attr($accent_color); ?>}
            #product-gpt-importer .btn-primary{background-color:<?php echo esc_attr($btn_color); ?>}
            #product-gpt-importer .btn-primary:hover{background-color:<?php echo esc_attr($btn_hover); ?>}
            #product-gpt-importer .btn-primary:active{background-color:<?php echo esc_attr($btn_active); ?>}
        </style>
        <h1 class="font-playfair text-3xl mb-6">Product GPT Importer</h1>
        
        <?php if (!$api_key): ?>
            <div class="notice notice-warning">
                <p><strong>⚠️ API Key mancante! Configurala nelle <a href="<?php echo admin_url('admin.php?page=product-gpt-settings'); ?>">impostazioni del plugin</a>.</strong></p>
            </div>
        <?php endif; ?>
        <?php

            $profiles_default = function_exists('product_gpt_get_profiles') ? product_gpt_get_profiles() : [];
            $default_idx = function_exists('product_gpt_get_default_profile_index') ? product_gpt_get_default_profile_index($profiles_default) : 0;
            $default_profile = $profiles_default[$default_idx] ?? [];
            $default_prompt = $default_profile['prompt'] ?? 'Estrai dalla seguente descrizione e genera un prodotto WooCommerce completo con tutte le informazioni utili.';
            $default_system_prompt = $default_profile['system'] ?? 'Sei un maestro profumiere che vende profumi di nicchia. Rispondi sempre in formato JSON.';
        ?>
        <form method="post" enctype="multipart/form-data" class="font-inter text-[#1A2A42]">
            <?php
            $profiles = function_exists('product_gpt_get_profiles') ? product_gpt_get_profiles() : get_option('product_gpt_profiles', []);
            $models   = get_option('product_gpt_available_models', []);
            if (empty($models)) $models = ['gpt-3.5-turbo','gpt-4o'];
            $current_model = get_option('product_gpt_model', 'gpt-3.5-turbo');
            ?>

            <div class="grid md:grid-cols-2 gap-6">
                <div class="space-y-6">
                    <div class="custom-section rounded-lg shadow p-6 border border-accent space-y-4">
                        <h3 class="text-lg font-semibold mb-2">Profilo e Modello</h3>
                        <label for="preset_prompt_select" class="font-semibold flex items-center"><span class="accent mr-1">👤</span><span>Seleziona Profilo</span></label>
                        <select id="preset_prompt_select" name="profile_index" class="w-full max-w-xl border border-gray-300 rounded p-2">
                            <option value="">-- Seleziona un profilo --</option>
                            <?php foreach ($profiles as $i => $p): ?>
                                <option value="<?php echo esc_attr($i); ?>" data-system="<?php echo esc_attr($p['system']); ?>" data-prompt="<?php echo esc_attr($p['prompt']); ?>" data-model="<?php echo esc_attr($p['model']); ?>" <?php selected($i, $default_idx); ?>>
                                    <?php echo esc_html($p['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" id="system_prompt" name="system_prompt" value="<?php echo esc_attr($default_system_prompt); ?>">
                        <label for="model_select" class="font-semibold flex items-center"><span class="accent mr-1">⚙️</span><span>Modello</span></label>
                        <select id="model_select" name="selected_model" class="w-full max-w-xs border border-gray-300 rounded p-2">
                            <?php foreach ($models as $m): ?>
                                <option value="<?php echo esc_attr($m); ?>" <?php selected($current_model, $m); ?>><?php echo esc_html($m); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="custom-section rounded-lg shadow p-6 border border-accent space-y-4">
                        <h3 class="text-lg font-semibold mb-2">Import Prodotto</h3>
                        <label class="font-semibold flex items-center" for="product_urls"><span class="accent mr-1">🔗</span><span>URL pagine prodotto (una per riga, opzionale)</span></label>
                        <textarea id="product_urls" name="product_urls" class="w-full max-w-xl h-20 border border-gray-300 rounded p-2"><?php echo esc_textarea($_POST['product_urls'] ?? ''); ?></textarea>
                        <label class="font-semibold flex items-center" for="product_file"><span class="accent mr-1">📄</span><span>File prodotto</span></label>
                        <input type="file" id="product_file" name="product_file" accept=".docx,.pdf,.txt" class="w-full max-w-xl border border-gray-300 rounded p-2">
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="custom-section rounded-lg shadow p-6 border border-accent space-y-4">
                        <h3 class="text-lg font-semibold mb-2">Prompt Personalizzato</h3>
                        <label for="custom_prompt" class="font-semibold flex items-center"><span class="accent mr-1">🧠</span><span>Prompt personalizzato</span></label>
                        <textarea id="custom_prompt" name="custom_prompt" rows="8" class="w-full max-w-xl p-3 rounded bg-[#F1F5F9] font-mono" placeholder="<?php echo esc_attr($default_prompt); ?>"><?php echo esc_textarea($_POST['custom_prompt'] ?? ''); ?></textarea>
                    </div>

                    <div class="custom-section rounded-lg shadow p-6 border border-accent space-y-4">
                        <h3 class="text-lg font-semibold mb-2">Media Aggiuntivi (opzionale)</h3>
                        <label class="font-semibold flex items-center" for="video_url"><span class="accent mr-1">🎥</span><span>URL video (opzionale)</span></label>
                        <input type="url" id="video_url" name="video_url" class="w-full max-w-xl border border-gray-300 rounded p-2">
                        <label class="font-semibold flex items-center"><span class="accent mr-1">🖼️</span><span>Immagine principale</span></label>
                        <button id="select_featured" class="px-4 py-2 rounded-md border border-accent accent hover:bg-gray-200 transition">Seleziona immagine</button>
                        <input type="hidden" id="featured_image_id" name="featured_image_id">
                        <img id="featured_preview" class="max-w-24 mt-2 hidden">
                        <label class="font-semibold mt-4 flex items-center"><span class="accent mr-1">🖼️📚</span><span>Galleria immagini</span></label>
                        <button id="select_gallery" class="px-4 py-2 rounded-md border border-accent accent hover:bg-gray-200 transition">Seleziona immagini</button>
                        <input type="hidden" id="gallery_image_ids" name="gallery_image_ids">
                        <div id="gallery_preview" class="mt-2"></div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-6">
                <input type="submit" name="generate_product" class="btn-primary text-white px-6 py-2 rounded-md shadow" value="Genera Prodotto">
            </div>
        </form>
        <?php
        // INIZIO BLOCCO GENERAZIONE ANTEPRIMA E BOTTONI
        if (isset($_POST['generate_product'])) {
            echo '<div class="notice notice-info"><p>🛠 Elaborazione in corso...</p></div>';

            // 1) Lettura contenuto da file o URL
            $content = '';
            $urls = array_filter(array_map('trim', explode("\n", $_POST['product_urls'] ?? '')));
            $file = $_FILES['product_file'] ?? null;
            $scraped_images = [];
            foreach ($urls as $url) {
                $resp = wp_remote_get(esc_url_raw($url), [
                    'headers' => ['User-Agent' => 'Mozilla/5.0 (ProductGPTImporter)'],
                    'timeout' => 20,
                    'redirection' => 5,
                ]);
                if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                    $body = wp_remote_retrieve_body($resp);
                    $content .= "\n" . wp_strip_all_tags($body);
                    $scraped_images = array_merge($scraped_images, product_gpt_extract_images($body, $url));
                }
            }
            $scraped_images = array_unique($scraped_images);
            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext === 'docx') {
                    $text = extract_text_from_docx($file['tmp_name']);
                } elseif ($ext === 'pdf') {
                    $text = extract_text_from_pdf($file['tmp_name']);
                } else {
                    $text = file_get_contents($file['tmp_name']);
                }
                $content .= "\n" . $text;
            }
            if (!$content) {
                echo '<div class="notice notice-error"><p>❌ Impossibile leggere il contenuto.</p></div>';
                return;
            }

            // 2) Costruzione del prompt (default o personalizzato)
            $profiles = function_exists('product_gpt_get_profiles') ? product_gpt_get_profiles() : [];
            $profile_index = intval($_POST['profile_index'] ?? 0);
            $profile_prompt = $profiles[$profile_index]['prompt'] ?? ($default_profile['prompt'] ?? '');

            if (!empty($_POST['custom_prompt'])) {
                $prompt_template = stripslashes($_POST['custom_prompt']);
            } else {
                $prompt_template = $profile_prompt;
            }

            $prompt = str_replace('{$content}', $content, $prompt_template);

            $system_prompt = sanitize_textarea_field(
                $_POST['system_prompt'] ?? ($profiles[$profile_index]['system'] ?? 'Sei un maestro profumiere che vende profumi di nicchia. Rispondi sempre in formato JSON.')
            );
            $model = sanitize_text_field($_POST['selected_model'] ?? $model);
            $response = product_gpt_call_api($system_prompt, $prompt, $api_key, $model);
            echo '<pre style="background:#eee;max-width:900px;overflow:auto;">' . esc_html($response) . '</pre>';

            if (!$response) {
                echo '<div class="notice notice-error"><p>❌ Errore durante la chiamata a ChatGPT.</p></div>';
                return;
            }
            
            // Pulisci e decodifica il JSON
            if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $response, $matches)) {
                $response_clean = $matches[0];
            } else {
                $response_clean = $response;
            }
            $data = json_decode($response_clean, true);
            if (isset($data['product'])) $data = $data['product'];
            
            // Normalizza attributi ricorsivamente (helper)
            if (!empty($data['attributes']) && is_array($data['attributes'])) {
                $data['attributes'] = normalize_attributes_deep($data['attributes']);
            }
            
            if (empty($data['title'])) {
                echo '<div class="notice notice-error"><p>❌ Risposta non valida:</p><pre>' . esc_html($response) . '</pre></div>';
                return;
            }


            // 3) Anteprima dati modificabili
            $featured = intval($_POST['featured_image_id'] ?? 0);
            $gallery  = array_filter(array_map('intval', explode(',', $_POST['gallery_image_ids'] ?? '')));
            $brand_preview = $data['brand'] ?? ($data['attributes']['Marca'] ?? '');
            echo '<h2 class="font-playfair text-2xl mt-6 mb-4">Anteprima Prodotto</h2>';
            echo '<form method="post">';
            wp_nonce_field('product_gpt_confirm');
            echo '<table class="min-w-full text-left border border-gray-200 rounded"><tbody>';
            echo "<tr class='border-b'><th class='p-2 font-semibold'>Titolo</th><td class='p-2'><input type='text' name='modified_title' value='" . esc_attr($data['title']) . "' class='border border-gray-300 rounded p-1 w-full'></td></tr>";
            echo "<tr class='border-b'><th class='p-2 font-semibold'>Short Description</th><td class='p-2'><textarea name='modified_short_description' class='w-full border border-gray-300 rounded p-1' rows='3'>" . esc_textarea($data['short_description']) . "</textarea></td></tr>";
            echo "<tr class='border-b'><th class='p-2 font-semibold'>Long Description</th><td class='p-2'><textarea name='modified_long_description' class='w-full border border-gray-300 rounded p-1' rows='6'>" . esc_textarea($data['long_description']) . "</textarea></td></tr>";
            echo "<tr class='border-b'><th class='p-2 font-semibold'>Prezzo</th><td class='p-2'><input type='text' name='modified_price' value='" . esc_attr($data['price']) . "' class='border border-gray-300 rounded p-1 w-32'></td></tr>";
            echo "<tr class='border-b'><th class='p-2 font-semibold'>Stock</th><td class='p-2'><input type='text' name='modified_stock_status' value='" . esc_attr($data['stock_status']) . "' class='border border-gray-300 rounded p-1 w-40'></td></tr>";
            echo "<tr class='border-b'><th class='p-2 font-semibold'>Categoria</th><td class='p-2'><input type='text' name='modified_category' value='" . esc_attr($data['category']) . "' class='border border-gray-300 rounded p-1 w-full'></td></tr>";
            if ($brand_preview) {
                echo "<tr class='border-b'><th class='p-2 font-semibold'>Marca</th><td class='p-2'><input type='text' name='modified_brand' value='" . esc_attr($brand_preview) . "' class='border border-gray-300 rounded p-1 w-full'></td></tr>";
            }
            echo "<tr><th class='p-2 font-semibold'>SKU</th><td class='p-2'><input type='text' name='modified_sku' value='" . esc_attr($data['sku']) . "' class='border border-gray-300 rounded p-1 w-40'></td></tr>";
            echo "<tr><th class='p-2 font-semibold'>GTIN</th><td class='p-2'><input type='text' name='modified_gtin' value='" . esc_attr($data['gtin'] ?? '') . "' class='border border-gray-300 rounded p-1 w-40'></td></tr>";
            echo '</tbody></table>';
            if ($featured) echo wp_get_attachment_image($featured, 'medium', false, ['class'=>'mt-4']);
            if ($gallery) foreach ($gallery as $id) echo wp_get_attachment_image($id, 'thumbnail', false, ['class'=>'m-1 inline-block']);


            // 4) Form di conferma con due bottoni
            $video_url = esc_url_raw($_POST['video_url'] ?? '');
            if (!empty($scraped_images)) {
                echo '<div class="mt-4"><p class="font-semibold">Immagini trovate:</p>';
                echo '<div class="mb-2">';
                echo '<button type="button" id="select_all_images" class="px-2 py-1 mr-2 rounded border border-accent">Seleziona tutto</button>';
                echo '<button type="button" id="deselect_all_images" class="px-2 py-1 rounded border border-accent">Deseleziona tutto</button>';
                echo '</div>';
                echo '<div style="display:flex;flex-wrap:wrap;" id="scraped_images_container">';
                foreach ($scraped_images as $img) {
                    echo '<label style="margin:4px;text-align:center;">';
                    echo '<input type="checkbox" class="scraped-image-checkbox" name="scraped_image_urls[]" value="' . esc_attr($img) . '" checked> ';
                    echo '<img src="' . esc_url($img) . '" style="max-width:80px;display:block;margin-top:2px;">';
                    echo '</label>';
                }
                echo '</div></div>';
            }

            echo '<div class="mt-4">';
            echo '<p class="font-semibold">Aggiungi immagini dalla galleria (opzionale):</p>';
            echo '<div class="mb-2">';
            echo '<button type="button" id="confirm_select_featured" class="px-2 py-1 mr-2 rounded border border-accent">Immagine principale</button>';
            echo '<button type="button" id="confirm_select_gallery" class="px-2 py-1 rounded border border-accent">Galleria</button>';
            echo '</div>';
            echo '<input type="hidden" id="confirm_featured_image_id" name="confirm_featured_image_id" value="' . esc_attr($featured) . '">';
            $thumb = $featured ? wp_get_attachment_image_url($featured, 'thumbnail') : '';
            echo '<img id="confirm_featured_preview" src="' . esc_url($thumb) . '" style="max-width:80px;' . ($featured ? '' : 'display:none;') . 'margin-right:4px;">';
            echo '<input type="hidden" id="confirm_gallery_image_ids" name="confirm_gallery_image_ids" value="' . esc_attr(implode(',', $gallery)) . '">';
            echo '<div id="confirm_gallery_preview" class="mt-2">';
            foreach ($gallery as $gid) {
                echo wp_get_attachment_image($gid, 'thumbnail', false, ['class'=>'m-1 inline-block']);
            }
            echo '</div>';
            echo '</div>';

            echo '<input type="hidden" name="confirm_data" value="' . esc_attr(json_encode([$data, $featured, $gallery, $video_url])) . '">';
            echo '<input type="submit" name="confirm_create"    class="px-6 py-2 rounded-md bg-gradient-to-r from-[#32A87D] to-[#2DD4BF] text-white shadow transition-transform hover:scale-105" value="Crea Prodotto">';
            echo '<input type="submit" name="duplicate_product" class="px-4 py-2 ml-2 rounded-md border border-[#C5A768] text-[#C5A768] hover:bg-[#C5A768]/20 transition" value="Duplica Prodotto">';
            echo '<input type="button" name="cancel" class="px-4 py-2 ml-2 rounded-md border border-gray-300" value="Annulla" onclick="history.back();">';
            echo '</form>';
        }
        // FINE BLOCCO GENERAZIONE ANTEPRIMA E BOTTONI

        // INIZIO BLOCCO CREAZIONE/ DUPLICAZIONE
        elseif (isset($_POST['confirm_create']) || isset($_POST['duplicate_product'])) {
            if (! wp_verify_nonce($_POST['_wpnonce'], 'product_gpt_confirm')) wp_die('Nonce non valido');
            list($data, $featured, $gallery, $video_url) = json_decode(stripslashes($_POST['confirm_data']), true);
            $featured = intval($_POST['confirm_featured_image_id'] ?? $featured);
            $gallery  = array_filter(array_map('intval', explode(',', $_POST['confirm_gallery_image_ids'] ?? implode(',', $gallery))));

            $data['title']             = sanitize_text_field($_POST['modified_title'] ?? $data['title']);
            $data['short_description'] = wp_kses_post($_POST['modified_short_description'] ?? $data['short_description']);
            $data['long_description']  = wp_kses_post($_POST['modified_long_description'] ?? $data['long_description']);
            $data['price']             = product_gpt_parse_price($_POST['modified_price'] ?? $data['price']);
            $data['stock_status']      = sanitize_text_field($_POST['modified_stock_status'] ?? $data['stock_status']);
            $data['category']          = sanitize_text_field($_POST['modified_category'] ?? $data['category']);
            if (isset($_POST['modified_brand'])) {
                $data['brand'] = sanitize_text_field($_POST['modified_brand']);
                $data['attributes']['Marca'] = $data['brand'];
            }
            $data['gtin']             = sanitize_text_field($_POST['modified_gtin'] ?? ($data['gtin'] ?? ''));

            $sku_input = sanitize_text_field($_POST['modified_sku'] ?? $data['sku']);
            if (! function_exists('wc_get_product_id_by_sku')) include_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
            $base = $sku_input; $sku = $base; $i = 1;
            while (wc_get_product_id_by_sku($sku)) {
                $i++; $sku = "{$base}-{$i}";
            }
            $data['sku'] = $sku;
            // Normalizza attributi ricorsivamente (helper)
            if (!empty($data['attributes']) && is_array($data['attributes'])) {
                $data['attributes'] = normalize_attributes_deep($data['attributes']);
            }

            $selected_urls = array_filter((array)($_POST['scraped_image_urls'] ?? []));
            $selected_ids = [];
            foreach ($selected_urls as $img_url) {
                $id = product_gpt_download_image($img_url);
                if ($id) $selected_ids[] = $id;
            }

            if ($selected_ids) {
                if (!$featured) {
                    $featured = array_shift($selected_ids);
                }
                $gallery = array_merge($gallery, $selected_ids);
            }

            $gallery = array_filter($gallery);

            product_gpt_create_product($data, $featured, $gallery, $video_url);
            echo '<div class="notice notice-success"><p>✅ Prodotto creato con SKU: ' . esc_html($sku) . '</p></div>';
        }
        // FINE BLOCCO CREAZIONE/ DUPLICAZIONE
        ?>
        <script>
jQuery(function($){
    const PROFILE_KEY = 'product_gpt_selected_profile';
    const $preset = $('#preset_prompt_select');
    const stored = localStorage.getItem(PROFILE_KEY);
    if (stored !== null && $preset.find('option[value="'+stored+'"]').length) {
        $preset.val(stored);
    }
    $preset.on('change', function(){
        const sel = $(this).find('option:selected');
        $('#custom_prompt').val(sel.data('prompt') || '');
        $('#system_prompt').val(sel.data('system') || '');
        $('#model_select').val(sel.data('model'));
        localStorage.setItem(PROFILE_KEY, $(this).val());
    });
    $preset.trigger('change');
    $(document).on('click', '#select_all_images', function(){
        $('.scraped-image-checkbox').prop('checked', true);
    });
    $(document).on('click', '#deselect_all_images', function(){
        $('.scraped-image-checkbox').prop('checked', false);
    });

    $(document).on('click', '#confirm_select_featured', function(e){
        e.preventDefault();
        const frame = wp.media({ title: 'Seleziona immagine principale', multiple: false });
        frame.on('select', function(){
            const attachment = frame.state().get('selection').first().toJSON();
            $('#confirm_featured_image_id').val(attachment.id);
            $('#confirm_featured_preview').attr('src', attachment.url).show();
        });
        frame.open();
    });

    $(document).on('click', '#confirm_select_gallery', function(e){
        e.preventDefault();
        const frame = wp.media({ title: 'Seleziona immagini galleria', multiple: true });
        frame.on('select', function(){
            const attachments = frame.state().get('selection').toJSON();
            const ids = attachments.map(img => img.id);
            $('#confirm_gallery_image_ids').val(ids.join(','));
            let thumbs = attachments.map(function(img){
                return '<img src="' + img.url + '" style="max-width:80px;margin:2px;">';
            }).join('');
            $('#confirm_gallery_preview').html(thumbs);
        });
        frame.open();
    });
});
</script>

    </div>
    <?php
}
}

if (!function_exists('product_gpt_get_model_temperature')) {
function product_gpt_get_model_temperature($model) {
    $fixed_models = ['gpt-4.1', 'gpt-4.1-mini', 'gpt-5', 'gpt-5-mini', 'o1', 'o1-mini'];
    foreach ($fixed_models as $prefix) {
        if (stripos($model, $prefix) === 0) {
            return 1;
        }
    }
    return 0.7;
}
}

if (!function_exists('product_gpt_call_api')) {
function product_gpt_call_api($system_prompt, $prompt, $api_key, $model = 'gpt-3.5-turbo') {
    error_log('🚀 Entrata in product_gpt_call_api con prompt: ' . $prompt);

    $max_retries = 2;
    $attempt = 0;

    $limit = product_gpt_register_request_attempt();
    if (!$limit['allowed']) {
        $minutes = max(1, ceil(($limit['retry_after'] ?? HOUR_IN_SECONDS) / MINUTE_IN_SECONDS));
        echo '<div class="notice notice-warning"><p>⏳ Hai raggiunto il limite di 3 richieste all\'ora nella versione gratuita. Riprova tra circa ' . esc_html($minutes) . ' minuti.</p></div>';
        return false;
    }

    $request_data = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => product_gpt_get_model_temperature($model)
    ];

    do {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body'    => json_encode($request_data),
            'timeout' => 60
        ]);
        $attempt++;

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            break;
        }
        sleep(1);
    } while ($attempt <= $max_retries);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        $response_data = [
            'error' => is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response)
        ];
        echo '<div class="notice notice-error"><p>❌ Errore chiamata API:</p><pre>' . esc_html(print_r($response_data['error'], true)) . '</pre></div>';
        product_gpt_log_event($request_data, $response_data);
        error_log('❌ GPT API ERROR: ' . $response_data['error']);
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    product_gpt_log_event($request_data, $data);

    return $data['choices'][0]['message']['content'] ?? false;
}
}

if (!function_exists('product_gpt_create_product')) {
function product_gpt_create_product($data, $featured_image_id = 0, $gallery_ids = [], $video_url = '') {
    
    $post_id = wp_insert_post([
        'post_title'   => sanitize_text_field($data['title']),
        'post_content' => wp_kses_post($data['long_description']),
        'post_excerpt' => wp_kses_post($data['short_description']),
        'post_status'  => 'draft',
        'post_type'    => 'product'
    ]);

    if (is_wp_error($post_id)) return;

    update_post_meta($post_id, '_regular_price', $data['price']);
    update_post_meta($post_id, '_price', $data['price']);
    update_post_meta($post_id, '_stock_status', $data['stock_status']);

    if (!empty($data['sku'])) {
        update_post_meta($post_id, '_sku', sanitize_text_field($data['sku']));
    }

    if (!empty($data['gtin'])) {
        $gtin = sanitize_text_field($data['gtin']);
        update_post_meta($post_id, '_global_unique_id', $gtin);
    }

    if (!empty($data['seo_keywords'])) {
        $keywords = is_array($data['seo_keywords']) ? implode(", ", $data['seo_keywords']) : $data['seo_keywords'];
        update_post_meta($post_id, 'rank_math_focus_keyword', $keywords);
    }

    if (!empty($data['shipping_class'])) {
        $term = get_term_by('name', $data['shipping_class'], 'product_shipping_class');
        if ($term) {
            wp_set_object_terms($post_id, intval($term->term_id), 'product_shipping_class');
        }
    }

    if (!empty($data['category'])) {
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        foreach ($categories as $cat) {
            if (stripos($cat->name, $data['category']) !== false || stripos($data['category'], $cat->name) !== false) {
                wp_set_object_terms($post_id, intval($cat->term_id), 'product_cat');
                break;
            }
        }
    }

    $brand_name = $data['brand'] ?? ($data['attributes']['Marca'] ?? '');
    if ($brand_name) {
        foreach (['marche', 'product_brand', 'brands'] as $brand_tax) {
            if (!taxonomy_exists($brand_tax)) continue;
            $terms = get_terms(['taxonomy' => $brand_tax, 'hide_empty' => false]);
            foreach ($terms as $term) {
                if (stripos($term->name, $brand_name) !== false || stripos($brand_name, $term->name) !== false) {
                    wp_set_object_terms($post_id, intval($term->term_id), $brand_tax);
                    break 2;
                }
            }
        }
    }

    if (!empty($data['attributes']) && is_array($data['attributes'])) {
        $attributes = [];
        foreach ($data['attributes'] as $name => $value) {
            $attr_slug = sanitize_title($name);
            $taxonomy = 'pa_' . $attr_slug;

            if (!taxonomy_exists($taxonomy)) continue;

            if (!term_exists($value, $taxonomy)) {
                wp_insert_term($value, $taxonomy);
            }

            wp_set_object_terms($post_id, $value, $taxonomy);

            $attributes[$taxonomy] = array(
                'name'         => $taxonomy,
                'value'        => $value,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 1
            );
        }
        update_post_meta($post_id, '_product_attributes', $attributes);
    }

    if ($featured_image_id) {
        set_post_thumbnail($post_id, $featured_image_id);
    }

    if (!empty($gallery_ids)) {
        update_post_meta($post_id, '_product_image_gallery', implode(',', $gallery_ids));
    }

    if ($video_url) {
        update_post_meta($post_id, 'video_url', esc_url_raw($video_url));
    }
}
}

if (!function_exists('product_gpt_parse_response')) {
function product_gpt_parse_response($response) {
    if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $response, $m)) {
        $response = $m[0];
    }
    $data = json_decode($response, true);
    if (isset($data['product'])) $data = $data['product'];
    if (!empty($data['attributes']) && is_array($data['attributes'])) {
        $data['attributes'] = normalize_attributes_deep($data['attributes']);
    }
    return $data;
}
}

if (!function_exists('product_gpt_check_batches')) {
function product_gpt_check_batches() {
    $batches = get_option('product_gpt_pending_batches', []);
    if (!$batches) return;

    $api_key = get_option('product_gpt_api_key');
    if (!$api_key) return;

    $pending = get_option('product_gpt_pending_products', []);

    foreach ($batches as $batch_id => $maps) {
        $resp = wp_remote_get('https://api.openai.com/v1/batches/' . $batch_id, [
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
            'timeout' => 60
        ]);
        product_gpt_log_event(['endpoint'=>'batch_status','id'=>$batch_id], is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_body($resp));

        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            continue;
        }

        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (($data['status'] ?? '') !== 'completed') {
            continue;
        }

        $file_id = $data['output_file_id'] ?? '';
        if (!$file_id) {
            unset($batches[$batch_id]);
            continue;
        }

        $file_resp = wp_remote_get('https://api.openai.com/v1/files/' . $file_id . '/content', [
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
            'timeout' => 60
        ]);
        product_gpt_log_event(['endpoint'=>'batch_file','id'=>$file_id], is_wp_error($file_resp) ? $file_resp->get_error_message() : wp_remote_retrieve_body($file_resp));

        if (is_wp_error($file_resp) || wp_remote_retrieve_response_code($file_resp) !== 200) {
            continue;
        }

        $body    = trim(wp_remote_retrieve_body($file_resp));
        $url_map = $maps['urls'] ?? [];
        $img_map = $maps['images'] ?? [];
        foreach (explode("\n", $body) as $line) {
            if (!$line) continue;
            $obj = json_decode($line, true);
            if (!$obj) continue;
            $content = $obj['response']['body']['choices'][0]['message']['content'] ?? '';
            if (!$content) continue;
            $product = product_gpt_parse_response($content);
            if (!empty($product['title'])) {
                $cid = $obj['custom_id'] ?? '';
                if ($cid && !empty($url_map[$cid])) {
                    $product['source_url'] = $url_map[$cid];
                }
                if ($cid && !empty($img_map[$cid])) {
                    $product['scraped_images'] = $img_map[$cid];
                }
                $product['batch_id'] = $batch_id;
                $pending[] = $product;
            }
        }

        unset($batches[$batch_id]);
        $recent = get_option('product_gpt_recent_batches', []);
        array_unshift($recent, $batch_id);
        $recent = array_slice(array_unique($recent), 0, 4);
        update_option('product_gpt_recent_batches', $recent);
    }

    update_option('product_gpt_pending_products', $pending);
    update_option('product_gpt_pending_batches', $batches);
}
}

add_action('product_gpt_check_batches', 'product_gpt_check_batches');

