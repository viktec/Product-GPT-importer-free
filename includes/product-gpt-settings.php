<?php

/**
 * Settings page for Product GPT Importer
 */

add_action('admin_init', 'product_gpt_register_settings');


/**
 * Settings page for Product GPT Importer
 */

add_action('admin_init', 'product_gpt_register_settings');

if (!function_exists('product_gpt_get_default_profiles')) {
function product_gpt_get_default_profiles() {
    $default_model = get_option('product_gpt_model', 'gpt-3.5-turbo');
    return [
        [
            'label'  => 'Orologi e Gioielli',
            'system' => 'Sei un assistente per e-commerce di orologi e gioielli. Rispondi sempre in formato JSON.',
            'prompt' => <<<'PROMPT'
Estrai dalla seguente scheda tecnica TUTTI i dati caratteristici e restituiscili in JSON come campi attributo WooCommerce.
Il JSON deve contenere i campi:
- title
- short_description (descrizione breve nello stile seguente):
  Garanzia 24 mesi Magliozzi
  Referenza: prendere referenza presenti nella scheda
  Anno: prendere anno presenti nella scheda
  Corredo: prendere corredo presenti nella scheda
  Specifiche: prendere specifiche presenti nella scheda
  Tipo: ( specificare se nuovo o secondo polso)
  Disponibilità immediata
- long_description (descrizione arricchita e dettagliata, almeno 100-150 parole)
- price
- stock_status
- category (scegli tra le categorie esistenti, es. "Orologi")
- sku
- gtin (estrai dal contenuto se presente un codice EAN, UPC o ISBN; se non presente, omettilo)
- seo_keywords (array)
Nel campo "attributes" includi tutte le voci trovate, anche se non sono sempre presenti, tra cui (ma non solo):
- Marca
- Modello
- Referenza
- Stato del prodotto
- Anno
- Diametro
- Condizioni
- Garanzia
- Certificato
- Scatola
- Movimento
- Cassa
- Bracciale/Cinturino
- Chiusura
- Calibro
- Quadrante
- Ghiera/Lunetta
- Vetro
- Impermeabilità
- Genere
- Corredo
- Disponibilità
Restituisci gli attributi come oggetto del tipo:
"attributes": {
  "Marca": "Rolex",
  "Modello": "Datejust",
  "Referenza": "16233",
  ...
}
Se un campo non c’è, omettilo.

- shipping_class ("Italia" se presente)

Scheda tecnica:
{$content}
PROMPT,
            'model'  => $default_model,
            'default' => true,
        ],
    ];
}
}

if (!function_exists('product_gpt_get_profiles')) {
function product_gpt_get_profiles() {
    // Usa un transient per evitare letture ripetute di grandi opzioni
    $profiles = get_transient('product_gpt_profiles_cache');
    if ($profiles !== false) {
        return $profiles;
    }

    $profiles = get_option('product_gpt_profiles', []);
    if (empty($profiles)) {
        $profiles = product_gpt_get_default_profiles();
        update_option('product_gpt_profiles', $profiles);
    }

    if (count($profiles) > 2) {
        $profiles = array_slice($profiles, 0, 2);
        update_option('product_gpt_profiles', $profiles);
    }

    // Cache per 15 minuti
    set_transient('product_gpt_profiles_cache', array_values($profiles), 15 * MINUTE_IN_SECONDS);

    return array_values($profiles);
}
}

if (!function_exists('product_gpt_get_default_profile_index')) {
function product_gpt_get_default_profile_index($profiles = null) {
    if ($profiles === null) {
        $profiles = function_exists('product_gpt_get_profiles') ? product_gpt_get_profiles() : [];
    }
    foreach ($profiles as $i => $p) {
        if (!empty($p['default'])) {
            return $i;
        }
    }
    return 0;
}
}
if (!function_exists('product_gpt_register_settings')) {
function product_gpt_register_settings() {
    register_setting('product_gpt_settings_group', 'product_gpt_api_key', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => '',
    ]);
    register_setting('product_gpt_settings_group', 'product_gpt_debug', [
        'type' => 'boolean',
        'sanitize_callback' => 'absint',
        'default' => 0,
    ]);
    register_setting('product_gpt_settings_group', 'product_gpt_prompts', [
        'type' => 'array',
        'sanitize_callback' => 'product_gpt_sanitize_prompts',
        'default' => [],
    ]);
    register_setting('product_gpt_settings_group', 'product_gpt_profiles', [
        'type' => 'array',
        'sanitize_callback' => 'product_gpt_sanitize_profiles',
        'default' => [],
    ]);
    register_setting('product_gpt_settings_group', 'product_gpt_logs', [
        'type' => 'array',
        'sanitize_callback' => null,
        'default' => [],
    ]);
    register_setting('product_gpt_settings_group', 'product_gpt_model', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'gpt-3.5-turbo',
    ]);
    register_setting('product_gpt_settings_group', 'product_gpt_bg_color', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_hex_color',
        'default' => '#f8f9fa',
    ]);
    register_setting('product_gpt_settings_group', 'product_gpt_btn_color', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_hex_color',
        'default' => '#38b000',
    ]);
    register_setting('product_gpt_settings_group', 'product_gpt_btn_hover_color', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_hex_color',
        'default' => '#2f8600',
    ]);
    register_setting('product_gpt_settings_group', 'product_gpt_btn_active_color', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_hex_color',
        'default' => '#267000',
    ]);
    register_setting('product_gpt_settings_group', 'product_gpt_accent_color', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_hex_color',
        'default' => '#495057',
    ]);
    register_setting('product_gpt_settings_group', 'product_gpt_color_scheme', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'default',
    ]);
}
}

if (!function_exists('product_gpt_sanitize_prompts')) {
function product_gpt_sanitize_prompts($input) {
    if (!is_array($input)) return [];
    foreach ($input as &$p) {
        $p['label'] = sanitize_text_field($p['label'] ?? '');
        $p['text']  = sanitize_textarea_field($p['text'] ?? '');
    }
    return $input;
}
}

if (!function_exists('product_gpt_sanitize_profiles')) {
function product_gpt_sanitize_profiles($input) {
    if (!is_array($input)) return [];
    $result = [];
    foreach ($input as $p) {
        $label  = sanitize_text_field($p['label'] ?? '');
        $system = sanitize_textarea_field($p['system'] ?? '');
        $prompt = sanitize_textarea_field($p['prompt'] ?? '');
        $model  = sanitize_text_field($p['model'] ?? '');

        if ($label === '' && $system === '' && $prompt === '') {
            continue;
        }

        $result[] = [
            'label'   => $label,
            'system'  => $system,
            'prompt'  => $prompt,
            'model'   => $model,
            'default' => !empty($p['default']),
        ];
    }

    if (!$result) {
        return product_gpt_get_default_profiles();
    }

    if (count($result) > 2) {
        $result = array_slice($result, 0, 2);
        if (function_exists('add_settings_error')) {
            add_settings_error(
                'product_gpt_profiles',
                'product_gpt_profiles_limit',
                __('Puoi creare al massimo due profili AI nella versione gratuita.', 'product-gpt'),
                'error'
            );
        }
    }

    $found = false;
    foreach ($result as &$r) {
        if ($r['default'] && !$found) {
            $found = true;
        } else {
            $r['default'] = false;
        }
    }
    unset($r);

    if (!$found) {
        $result[0]['default'] = true;
    }

    return array_values($result);
}
}

if (!function_exists('product_gpt_reset_plugin')) {
function product_gpt_reset_plugin() {
    delete_option('product_gpt_profiles');
    delete_option('product_gpt_prompts');
    delete_option('product_gpt_logs');
    delete_option('product_gpt_available_models');
    update_option('product_gpt_profiles', product_gpt_get_default_profiles());
    update_option('product_gpt_version', PRODUCT_GPT_IMPORTER_VERSION);
    delete_transient('product_gpt_profiles_cache');
}
}

if (!function_exists('product_gpt_render_settings_page')) {
function product_gpt_render_settings_page() {
    if (
        isset($_POST['product_gpt_action']) &&
        $_POST['product_gpt_action'] === 'save_settings' &&
        current_user_can('manage_options') &&
        check_admin_referer('product_gpt_save_settings')
    ) {
        update_option('product_gpt_api_key', sanitize_text_field($_POST['product_gpt_api_key'] ?? ''));
        update_option('product_gpt_debug', isset($_POST['product_gpt_debug']) ? 1 : 0);
        update_option('product_gpt_model', sanitize_text_field($_POST['product_gpt_model'] ?? 'gpt-3.5-turbo'));
        $profiles = product_gpt_sanitize_profiles($_POST['product_gpt_profiles'] ?? []);
        update_option('product_gpt_profiles', $profiles);
        delete_transient('product_gpt_profiles_cache');

        // Enforce palette di default nella versione gratuita
        $colors = product_gpt_get_brand_colors();
        update_option('product_gpt_color_scheme', 'default');
        update_option('product_gpt_bg_color', $colors['bg']);
        update_option('product_gpt_btn_color', $colors['btn']);
        update_option('product_gpt_btn_hover_color', $colors['hover']);
        update_option('product_gpt_btn_active_color', $colors['active']);
        update_option('product_gpt_accent_color', $colors['accent']);
        echo '<div class="updated notice"><p>Impostazioni salvate.</p></div>';
    }
    if (isset($_POST['clear_logs']) && current_user_can('manage_options')) {
        product_gpt_clear_logs();
        echo '<div class="updated notice"><p>Log cancellati.</p></div>';
    }
    if (isset($_POST['reset_plugin']) && current_user_can('manage_options')) {
        product_gpt_reset_plugin();
        echo '<div class="updated notice"><p>Impostazioni ripristinate.</p></div>';
    }
    ?>
    <?php
        $bg_color      = product_gpt_get_brand_color('bg');
        $btn_color     = product_gpt_get_brand_color('btn');
        $btn_hover     = product_gpt_get_brand_color('hover');
        $btn_active    = product_gpt_get_brand_color('active');
        $accent_color  = product_gpt_get_brand_color('accent');
    ?>
    <div class="wrap font-inter text-[#1A2A42]" id="product-gpt-settings">
        <style>
            #product-gpt-settings .custom-section{background-color:<?php echo esc_attr($bg_color); ?>;border-color:<?php echo esc_attr($accent_color); ?>}
            #product-gpt-settings .accent{color:<?php echo esc_attr($accent_color); ?>}
            #product-gpt-settings .border-accent{border-color:<?php echo esc_attr($accent_color); ?>}
            #product-gpt-settings .btn-primary{background-color:<?php echo esc_attr($btn_color); ?>}
            #product-gpt-settings .btn-primary:hover{background-color:<?php echo esc_attr($btn_hover); ?>}
            #product-gpt-settings .btn-primary:active{background-color:<?php echo esc_attr($btn_active); ?>}
            #product-gpt-settings .accent-color{accent-color:<?php echo esc_attr($accent_color); ?>}
        </style>
        <h1 class="font-playfair text-3xl mb-6"><?php _e('Impostazioni Product GPT', 'product-gpt'); ?></h1>
        <div class="custom-section rounded-lg shadow p-6 border border-accent mb-6">
            <h2 class="font-playfair text-2xl mb-2">Versione Free</h2>
            <p class="mb-4">Questa versione gratuita ti permette di utilizzare la tua chiave OpenAI con alcune limitazioni pensate per testare il plugin.</p>
            <ul class="list-disc pl-5 space-y-1 text-sm mb-4">
                <li>Massimo 3 richieste all'ora verso l'API.</li>
                <li>Fino a 2 profili AI salvati (incluso quello predefinito).</li>
                <li>Colori personalizzati, multiprodotto e anteprima batch riservati alla versione Premium.</li>
            </ul>

            <div class="flex flex-col sm:flex-row sm:items-center gap-3 w-full sm:w-auto">
                <a
                    class="btn-primary text-white px-6 py-2 rounded-md shadow inline-block w-full sm:w-auto text-center"
                    href="mailto:russovittorio94@gmail.com?subject=Richiesta%20Product%20GPT%20Premium"
                >
                    Richiedi Premium
                </a>
            </div>

        </div>
        <?php settings_errors('product_gpt_profiles'); ?>
        <form method="post" id="gpt-main-settings-form" class="space-y-6">
            <?php wp_nonce_field('product_gpt_save_settings'); ?>
            <input type="hidden" name="product_gpt_action" value="save_settings" />

            <!-- Profili AI -->
            <h2 class="font-playfair text-2xl mb-2">Profili AI</h2>
            <div id="profiles-container" class="custom-section rounded-lg shadow p-6 border border-accent">
                <?php
                $profiles = product_gpt_get_profiles();
                $available_models = get_option('product_gpt_available_models', []);
                ?>
                <p class="text-sm text-gray-600 mb-4">Puoi salvare soltanto un profilo aggiuntivo oltre a quello predefinito.</p>
                <table class="min-w-full text-left border border-gray-200 mb-4">
                    <thead>
                        <tr>
                            <th class="w-1/12 p-2 text-center">Default</th>
                            <th class="w-1/6 p-2">Etichetta</th>
                            <th class="w-1/4 p-2">System Prompt</th>
                            <th class="p-2">Prompt Utente</th>
                            <th class="w-1/6 p-2">Modello</th>
                            <th class="w-1/12 p-2">Azioni</th>
                        </tr>
                    </thead>
                    <tbody id="profiles-list">
                        <?php foreach ($profiles as $index => $p): ?>
                        <?php $is_default = !empty($p['default']); ?>
                        <tr class="profile-item<?php echo $is_default ? ' default' : ''; ?> border-b">
                            <td class="text-center">
                                <input type="radio" name="profile_default" class="default-profile" <?php checked(!empty($p['default'])); ?> />
                                <input type="hidden" name="product_gpt_profiles[<?php echo $index; ?>][default]" class="default-input" value="<?php echo !empty($p['default']) ? '1' : '0'; ?>" />
                            </td>
                            <td>
                                <input type="text" name="product_gpt_profiles[<?php echo $index; ?>][label]" value="<?php echo esc_attr($p['label']); ?>" class="w-full border border-gray-300 rounded p-2" />
                            </td>
                            <td><textarea name="product_gpt_profiles[<?php echo $index; ?>][system]" rows="3" class="w-full border border-gray-300 rounded p-2"><?php echo esc_textarea($p['system']); ?></textarea></td>
                            <td><textarea name="product_gpt_profiles[<?php echo $index; ?>][prompt]" rows="3" class="w-full border border-gray-300 rounded p-2"><?php echo esc_textarea($p['prompt']); ?></textarea></td>
                            <td>
                                <select name="product_gpt_profiles[<?php echo $index; ?>][model]" class="w-full border border-gray-300 rounded p-2 profile-model-select">
                                    <?php
                                    $_models = $available_models;
                                    $current = $p['model'] ?? '';
                                    if ($current && !in_array($current, $_models, true)) {
                                        $_models[] = $current;
                                    }
                                    if ($_models) {
                                        foreach ($_models as $mod) {
                                            echo '<option value="' . esc_attr($mod) . '" ' . selected($current, $mod, false) . '>' . esc_html($mod) . '</option>';
                                        }
                                    } else {
                                        echo '<option value="gpt-3.5-turbo" ' . selected($current, 'gpt-3.5-turbo', false) . '>gpt-3.5-turbo (default)</option>';
                                        echo '<option value="gpt-4o" ' . selected($current, 'gpt-4o', false) . '>gpt-4o</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                            <td>
                                <?php if ($is_default): ?>
                                    <span class="text-xs text-gray-500 uppercase tracking-wide">Predefinito</span>
                                <?php else: ?>
                                    <button class="remove-profile button-link">&times; Rimuovi</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button id="add-profile" class="px-4 py-2 mt-2 rounded-md border border-accent accent hover:bg-gray-200 transition">+ Aggiungi Profilo</button>
            </div>

            <!-- API Key e Debug -->
            <div class="custom-section rounded-lg shadow p-6 space-y-4 border border-accent">
                <div class="grid md:grid-cols-2 gap-4">
                    <label class="font-semibold" for="product_gpt_api_key"><?php _e('OpenAI API Key', 'product-gpt'); ?></label>
                    <div class="flex items-center space-x-2">
                        <input type="password" id="product_gpt_api_key" name="product_gpt_api_key" value="<?php echo esc_attr(get_option('product_gpt_api_key')); ?>" class="w-full max-w-xl border border-gray-300 rounded p-2" />
                        <button type="button" id="toggle_api_key" class="px-3 py-1 rounded-md border border-accent accent hover:bg-gray-200 transition">👁</button>
                        <button type="button" id="copy_api_key" class="px-3 py-1 rounded-md border border-accent accent hover:bg-gray-200 transition">📋</button>
                    </div>
                </div>
                <?php
                $models = get_option('product_gpt_available_models', []);
                $current_model = get_option('product_gpt_model', 'gpt-3.5-turbo');
                ?>
                <div class="grid md:grid-cols-2 gap-4">
                    <label class="font-semibold" for="gpt-model-select"><?php _e('Modello OpenAI', 'product-gpt'); ?></label>
                    <div>
                        <select name="product_gpt_model" id="gpt-model-select" class="w-full max-w-xl border border-gray-300 rounded p-2">
                            <?php if ($models): ?>
                                <?php foreach ($models as $mod): ?>
                                    <option value="<?php echo esc_attr($mod); ?>" <?php selected($current_model, $mod); ?>><?php echo esc_html($mod); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="gpt-3.5-turbo" <?php selected($current_model, 'gpt-3.5-turbo'); ?>>gpt-3.5-turbo (default)</option>
                                <option value="gpt-4o" <?php selected($current_model, 'gpt-4o'); ?>>gpt-4o</option>
                            <?php endif; ?>
                        </select>
                        <button type="button" id="refresh-gpt-models-btn" class="px-3 py-1 mt-2 rounded-md border border-accent accent hover:bg-gray-200 transition">🔄 Aggiorna modelli disponibili</button>
                        <div id="gpt-model-debug" class="mt-2 p-2 border border-gray-300 bg-gray-100"></div>
                        <small>Premi "Aggiorna modelli disponibili" per vedere la lista aggiornata in base alla tua API Key.</small>
                    </div>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                    <label class="font-semibold" for="product_gpt_debug"><?php _e('Modalità Debug', 'product-gpt'); ?></label>
                    <label class="inline-flex items-center">
                        <input type="checkbox" id="product_gpt_debug" name="product_gpt_debug" value="1" <?php checked(1, get_option('product_gpt_debug')); ?> class="h-4 w-4 accent-color" />
                        <span class="ml-2"><?php _e('Abilita log dettagliato', 'product-gpt'); ?></span>
                    </label>
                </div>
            </div>

            <?php submit_button('Salva le modifiche', 'primary', 'submit', false, 'class="btn-primary text-white px-6 py-2 rounded-md shadow"'); ?>
        </form>
        <!-- 🔥 SEZIONE LOG 🔥 -->
        <h2>📜 Log delle richieste/risposte</h2>
        <form method="post" class="mb-2">
            <?php submit_button('🗑️ Cancella log', 'secondary', 'clear_logs'); ?>
        </form>
        <form method="post" class="mb-4">
            <?php submit_button('♻️ Ripristina plugin', 'secondary', 'reset_plugin'); ?>
        </form>
        <div class="max-h-96 overflow-auto bg-gray-100 p-4 border border-gray-200 rounded">
            <?php $logs = function_exists('product_gpt_get_logs') ? product_gpt_get_logs() : []; ?>
            <?php if (empty($logs)): ?>
                <p><em>Nessun log disponibile.</em></p>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <div class="border-b pb-4 mb-4">
                    <strong class="text-sm">🕒 <?php echo esc_html($log['timestamp']); ?></strong><br/>
                    <strong class="text-blue-700">🔹 Richiesta:</strong>
                    <pre class="whitespace-pre-wrap p-2 border border-gray-200 custom-section"><?php
                        echo esc_html($log['request']);
                    ?></pre>
                    <strong class="text-blue-700">🔸 Risposta:</strong>
                    <pre class="whitespace-pre-wrap p-2 border border-gray-200 custom-section"><?php
                        echo esc_html($log['response']);
                    ?></pre>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <script>
    jQuery(function($){
        $('#toggle_api_key').on('click', function(){
            const $inp = $('#product_gpt_api_key');
            $inp.attr('type', $inp.attr('type') === 'password' ? 'text' : 'password');
        });
        $('#copy_api_key').on('click', function(){
            const $inp = $('#product_gpt_api_key');
            const originalType = $inp.attr('type');
            $inp.attr('type', 'text').select();
            document.execCommand('copy');
            $inp.attr('type', originalType);
            alert('API Key copiata negli appunti!');
        });

        // Gestione profili AI
        let profileIdx = $('#profiles-list tr').length;
        const $addProfile = $('#add-profile');

        function refreshProfileLimitState() {
            const count = $('#profiles-list tr').length;
            const disabled = count >= 2;
            $addProfile.prop('disabled', disabled).toggleClass('opacity-50 cursor-not-allowed', disabled);
        }

        refreshProfileLimitState();

        $('#add-profile').on('click', function(e){
            e.preventDefault();
            if ($('#profiles-list tr').length >= 2) {
                alert('La versione Free permette di salvare al massimo due profili.');
                refreshProfileLimitState();
                return;
            }
            let idx = profileIdx++;
            let modelOptions = $('#gpt-model-select').html();
            $('#profiles-list').append(
                '<tr class="profile-item border-b">' +
                '<td class="text-center"><input type="radio" name="profile_default" class="default-profile" />' +
                '<input type="hidden" name="product_gpt_profiles['+idx+'][default]" class="default-input" value="0" /></td>' +
                '<td><input type="text" name="product_gpt_profiles['+idx+'][label]" class="w-full border border-gray-300 rounded p-2" /></td>' +
                '<td><textarea name="product_gpt_profiles['+idx+'][system]" rows="3" class="w-full border border-gray-300 rounded p-2"></textarea></td>' +
                '<td><textarea name="product_gpt_profiles['+idx+'][prompt]" rows="3" class="w-full border border-gray-300 rounded p-2"></textarea></td>' +
                '<td><select name="product_gpt_profiles['+idx+'][model]" class="w-full border border-gray-300 rounded p-2 profile-model-select">'+modelOptions+'</select></td>' +
                '<td><button class="remove-profile button-link">&times; Rimuovi</button></td>' +
                '</tr>'
            );
            $('#profiles-list tr:last .profile-model-select').val($('#gpt-model-select').val());
            refreshProfileLimitState();
        });

        $('#profiles-container').on('click', '.remove-profile', function(e){
            e.preventDefault();
            if ($('#profiles-list tr').length <= 1) {
                alert('Deve esistere almeno un profilo.');
                return;
            }
            $(this).closest('tr').remove();
            refreshProfileLimitState();
        });
        $('#profiles-container').on('change', '.default-profile', function(){
            $('#profiles-container .default-input').val('0');
            $('#profiles-container .profile-item').removeClass('default');
            const row = $(this).closest('tr');
            row.find('.default-input').val('1');
            row.addClass('default');
        });

        // BOTTONE AJAX GPT MODELS
        $('#refresh-gpt-models-btn').on('click', function(){
            $('#gpt-model-debug').html('⏳ Aggiornamento in corso...');
            $.post(ajaxurl, {
                action: 'product_gpt_refresh_models'
            }, function(response){
                if (response.success) {
                    // Forza array anche se oggetto (robustezza massima)
                    if (response.data.models && typeof response.data.models === "object" && !Array.isArray(response.data.models)) {
                        response.data.models = Object.values(response.data.models);
                    }
                    $('#gpt-model-debug').html(
                        '<b>MODELLI:</b><pre>' + JSON.stringify(response.data.models, null, 2) + '</pre>' +
                        '<b>RAW:</b><pre>' + $('<div>').text(response.data.raw).html() + '</pre>'
                    );

                    let $sel = $('#gpt-model-select');
                    let current = $sel.val(); // <-- salva il selezionato
                    let available = response.data.models.length ? response.data.models : ['gpt-3.5-turbo', 'gpt-4o'];
                    let optionsHtml = '';
                    available.forEach(function(m){
                        optionsHtml += '<option value="'+m+'">'+m+'</option>';
                    });

                    $sel.html(optionsHtml);
                    if (available.includes(current)) {
                        $sel.val(current);
                    }

                    $('.profile-model-select').each(function(){
                        let curr = $(this).val();
                        $(this).html(optionsHtml);
                        if (available.includes(curr)) {
                            $(this).val(curr);
                        }
                    });

                    // --- TIMER nascondi log RAW dopo 10 secondi ---
                    setTimeout(function(){
                        $('#gpt-model-debug').fadeOut(400, function(){
                            $(this).empty().show(); // svuota e risetta display (per riutilizzo)
                        });
                    }, 10000);
                } else {
                    $('#gpt-model-debug').html('Errore: ' + (response.data && response.data.message ? response.data.message : 'Errore sconosciuto'));
                }
            });
        });

    });
    </script>
    <?php
}
}
