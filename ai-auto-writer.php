<?php
/*
Plugin Name: AI Auto Writer
Description: Yapay zeka destekli, otomatik günlük makale yazan ve yayımlayan bir eklenti.
Version: 2.0
Author: imitasyonzeka.com
*/

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function() {
    add_menu_page('AI Auto Writer', 'AI Auto Writer', 'manage_options', 'ai-auto-writer', 'ai_auto_writer_settings_page');
});

add_action('admin_init', function() {
    register_setting('ai_writer_group', 'ai_writer_topics');
    register_setting('ai_writer_group', 'ai_writer_api_key');
    register_setting('ai_writer_group', 'ai_writer_publish_hour');
    register_setting('ai_writer_group', 'ai_writer_category');
    register_setting('ai_writer_group', 'ai_writer_last_index');
});

function ai_auto_writer_settings_page() {
    ?>
    <div class="wrap">
        <h1>AI Auto Writer Ayarları</h1>
        <form method="post" action="options.php">
            <?php settings_fields('ai_writer_group'); ?>
            <?php do_settings_sections('ai_writer_group'); ?>
            <table class="form-table">
                <tr><th>Konu Başlıkları (virgülle)</th><td><textarea name="ai_writer_topics" rows="5" cols="50"><?php echo esc_textarea(get_option('ai_writer_topics')); ?></textarea></td></tr>
                <tr><th>OpenAI API Anahtarı</th><td><input type="text" name="ai_writer_api_key" value="<?php echo esc_attr(get_option('ai_writer_api_key')); ?>" size="50"></td></tr>
                <tr><th>Yayın Saati (0-23)</th><td><input type="number" name="ai_writer_publish_hour" value="<?php echo esc_attr(get_option('ai_writer_publish_hour', 10)); ?>" min="0" max="23"></td></tr>
                <tr><th>Kategori ID</th><td><input type="number" name="ai_writer_category" value="<?php echo esc_attr(get_option('ai_writer_category')); ?>"></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <form method="post">
            <input type="hidden" name="ai_manual_trigger" value="1">
            <?php submit_button('Makale Üret ve Yayınla (Manuel)', 'secondary'); ?>
        </form>
    </div>
    <?php
    if (isset($_POST['ai_manual_trigger'])) {
        ai_writer_run_once(true);
    }
}

register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('ai_writer_daily_event')) {
        wp_schedule_event(strtotime('today ' . get_option('ai_writer_publish_hour', 10) . ':00'), 'daily', 'ai_writer_daily_event');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('ai_writer_daily_event');
});

add_action('ai_writer_daily_event', 'ai_writer_run_once');

function ai_writer_run_once($echo = false) {
    $topics_raw = get_option('ai_writer_topics');
    if (!$topics_raw) {
        if ($echo) echo '<div class="notice notice-error"><p><strong>Konu listesi boş!</strong></p></div>';
        return;
    }

    $topics = explode(',', $topics_raw);
    $index = (int) get_option('ai_writer_last_index', 0);
    if (!isset($topics[$index])) {
        if ($echo) echo '<div class="notice notice-warning"><p><strong>Tüm konular işlendi.</strong></p></div>';
        return;
    }

    $topic = trim($topics[$index]);
    $api_key = get_option('ai_writer_api_key');
    if (!$api_key) {
        if ($echo) echo '<div class="notice notice-error"><p><strong>API anahtarı eksik!</strong></p></div>';
        return;
    }

    $content = ai_writer_generate_content($api_key, $topic);
    if (!$content) {
        if ($echo) echo '<div class="notice notice-error"><p><strong>İçerik üretilemedi.</strong></p></div>';
        return;
    }

    $tags = ai_writer_generate_tags($api_key, $content);
    $image_url = ai_writer_generate_image($api_key, $topic);

    $post_id = wp_insert_post([
        'post_title'    => wp_strip_all_tags($topic),
        'post_content'  => $content,
        'post_status'   => 'publish',
        'post_type'     => 'post',
        'post_date'     => current_time('mysql'),
        'post_date_gmt' => get_gmt_from_date(current_time('mysql')),
        'post_category' => [get_option('ai_writer_category')],
        'tags_input'    => $tags
    ]);

    if (!is_wp_error($post_id) && $image_url) {
        ai_writer_set_featured_image($image_url, $post_id);
    }

    update_option('ai_writer_last_index', $index + 1);
    clean_post_cache($post_id);

    if ($echo) echo '<div class="notice notice-success"><p><strong>Makale oluşturuldu ve yayınlandı: ' . esc_html($topic) . '</strong></p></div>';
}

function ai_writer_generate_content($api_key, $topic) {
    $prompt = "Write a comprehensive, SEO-optimized 5000 word article on: $topic";
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json'
        ],
        'body' => json_encode([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]),
        'timeout' => 20
    ]);

    if (is_wp_error($response)) {
        echo '<div class="notice notice-error"><p><strong>API bağlantı hatası: ' . esc_html($response->get_error_message()) . '</strong></p></div>';
        return '';
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['choices'][0]['message']['content'])) {
        echo '<div class="notice notice-error"><p><strong>Boş içerik döndü. API yanıtı:</strong><br>' . esc_html(json_encode($body)) . '</p></div>';
        return '';
    }

    return $body['choices'][0]['message']['content'];
}

function ai_writer_generate_tags($api_key, $content) {
    $prompt = "Generate 5 SEO-friendly keywords from the following article content, return as comma-separated:\n$content";
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json'
        ],
        'body' => json_encode([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]),
        'timeout' => 20
    ]);
    if (is_wp_error($response)) return [];
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $tags = $body['choices'][0]['message']['content'] ?? '';
    return array_map('trim', explode(',', $tags));
}

function ai_writer_generate_image($api_key, $prompt) {
    $response = wp_remote_post('https://api.openai.com/v1/images/generations', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode([
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1024x1024'
        ]),
        'timeout' => 20
    ]);
    if (is_wp_error($response)) return '';
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['data'][0]['url'] ?? '';
}

function ai_writer_set_featured_image($image_url, $post_id) {
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) {
        echo '<div class="notice notice-error"><p>Görsel indirme hatası: ' . esc_html($tmp->get_error_message()) . '</p></div>';
        return;
    }

    $type = 'image/png';
    $editor = wp_get_image_editor($tmp);
    if (!is_wp_error($editor)) {
        $resize_result = $editor->resize(800, 500, true);
        if (!is_wp_error($resize_result)) {
            $editor->save($tmp);
        }
    }

    $file = [
        'name'     => basename(parse_url($image_url, PHP_URL_PATH)),
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => 0,
        'size'     => filesize($tmp),
    ];

    $id = media_handle_sideload($file, $post_id);
    if (!is_wp_error($id)) {
        set_post_thumbnail($post_id, $id);
    } else {
        echo '<div class="notice notice-error"><p>Görsel medya kütüphanesine eklenemedi: ' . esc_html($id->get_error_message()) . '</p></div>';
    }
}
