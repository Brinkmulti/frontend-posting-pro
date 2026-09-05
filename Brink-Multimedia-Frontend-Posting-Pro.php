<?php
/**
 * Plugin Name: Brink Multimedia Frontend Posting Pro
 * Description: Versie 5.17.0 - Nieuwe functionaliteit: serverside zoeken/paginering in het dashboard, automatische afbeeldingscompressie, een fotogalerij per advertentie, een wekelijkse digest-mail, interesse-tracking per dag/thema, en automatische SEO meta/OG-tags.
 * Version: 5.17.0
 * Author: Brink Multimedia
 * Update URI: false
 */

if (!defined('ABSPATH')) exit;

define('BRINK_FP_VERSION', '5.17.0');
define('BRINK_FP_DB_VERSION', '1.1');
define('BRINK_FP_GITHUB_REPO', 'Brinkmulti/frontend-posting-pro');

// BEVEILIGING (v5.15.0): bouwt de huidige URL op basis van het vertrouwde, in wp-admin
// ingestelde home_url() in plaats van de door de client aan te leveren $_SERVER['HTTP_HOST']
// header (die bij een niet-strikt geconfigureerde server/proxy vervalst kan worden).
function brink_current_url() {
    return home_url(add_query_arg(null, null));
}

// BEVEILIGING (v5.15.0): eenvoudige transient-based rate limiting per IP + formuliertype,
// zodat een script niet (zonder Turnstile ingesteld) willekeurige e-mailadressen kan
// bestoken met bevestigings-/verificatiemails ("email bombing"). Zelfde patroon als de
// event-rate-limiting in wp-realtime-analytics.
function brink_fp_rate_limit_check($form_key, $max_per_window = 5, $window_seconds = 300) {
    $ip = brink_fp_get_client_ip();
    $transient_key = 'brink_rl_' . $form_key . '_' . md5($ip);
    $count = (int) get_transient($transient_key);
    if ($count >= $max_per_window) {
        return false;
    }
    set_transient($transient_key, $count + 1, $window_seconds);
    return true;
}

function brink_fp_get_client_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
    return $ip;
}

// PERFORMANCE (v5.15.0): Turnstile via wp_enqueue_script i.p.v. een losse front-end <script>-tag,
// zodat het via het reguliere WP-cachingsysteem loopt en enkel geladen wordt op pagina's waar
// daadwerkelijk een van onze formulieren staat.
function brink_fp_enqueue_turnstile() {
    if (!wp_script_is('brink-turnstile', 'enqueued')) {
        wp_enqueue_script('brink-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true);
        add_filter('script_loader_tag', 'brink_fp_turnstile_async_attr', 10, 2);
    }
}
function brink_fp_turnstile_async_attr($tag, $handle) {
    if ($handle === 'brink-turnstile' && strpos($tag, 'async') === false) {
        $tag = str_replace(' src', ' async defer src', $tag);
    }
    return $tag;
}

// ==========================================
// 0. GITHUB UPDATER (Suite-breed, licht en zonder externe library)
// ==========================================
// Consistent met wp-realtime-analytics: geen plugin-update-checker of andere externe
// dependency, maar een eigen lightweight updater die de GitHub Releases API bevraagt,
// resultaten 12u cachet (15 min bij een mislukte call) en de zip-mapnaam corrigeert.
add_filter('pre_set_site_transient_update_plugins', 'brink_fp_check_for_update');
function brink_fp_check_for_update($transient) {
    if (empty($transient) || !is_object($transient) || empty($transient->checked)) return $transient;

    $plugin_file = plugin_basename(__FILE__);
    $release = brink_fp_get_github_release();
    if (!$release || empty($release['tag_name'])) return $transient;

    $remote_version = ltrim($release['tag_name'], 'v');

    if (version_compare($remote_version, BRINK_FP_VERSION, '>')) {
        $package = !empty($release['zipball_url']) ? $release['zipball_url'] : '';
        $transient->response[$plugin_file] = (object) array(
            'slug'        => dirname($plugin_file),
            'plugin'      => $plugin_file,
            'new_version' => $remote_version,
            'url'         => 'https://github.com/' . BRINK_FP_GITHUB_REPO,
            'package'     => $package,
        );
    } else {
        unset($transient->response[$plugin_file]);
    }

    return $transient;
}

// Haalt de laatste release van GitHub op, met caching zodat we de API niet op elke
// admin-load bevragen (voorkomt onnodige externe HTTP-calls = performance + rate limits).
function brink_fp_get_github_release() {
    $cache_key = 'brink_fp_github_release';
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return !empty($cached) ? $cached : false;
    }

    $response = wp_remote_get('https://api.github.com/repos/' . BRINK_FP_GITHUB_REPO . '/releases/latest', array(
        'headers' => array('Accept' => 'application/vnd.github+json'),
        'timeout' => 10,
    ));

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        set_transient($cache_key, array(), 15 * MINUTE_IN_SECONDS);
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body) || !is_array($body) || empty($body['tag_name'])) {
        set_transient($cache_key, array(), 15 * MINUTE_IN_SECONDS);
        return false;
    }

    set_transient($cache_key, $body, 12 * HOUR_IN_SECONDS);
    return $body;
}

// GitHub's zipball plaatst de bestanden in een map als "Brinkmulti-frontend-posting-pro-abc1234"
// i.p.v. de verwachte pluginmap-naam. Zonder deze fix denkt WordPress dat de plugin
// gedeactiveerd/verwijderd is na een update.
add_filter('upgrader_source_selection', 'brink_fp_fix_github_source_dir', 10, 4);
function brink_fp_fix_github_source_dir($source, $remote_source, $upgrader, $hook_extra) {
    global $wp_filesystem;
    if (!is_object($wp_filesystem)) return $source;
    if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) return $source;

    $correct_dir = trailingslashit($remote_source) . dirname(plugin_basename(__FILE__)) . '/';
    if (trailingslashit($source) !== $correct_dir && $wp_filesystem->move($source, $correct_dir)) {
        return $correct_dir;
    }
    return $source;
}

// ==========================================
// 1. MENU, INSTELLINGEN & DATABASE SETUP
// ==========================================

// Creëer/upgrade de Statistieken Tabel (bij activatie, en via een goedkope versie-check daarna)
function brink_fp_create_or_upgrade_stats_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'brink_stats';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        form_type varchar(50) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        KEY form_type_created (form_type, created_at)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    update_option('brink_fp_db_version', BRINK_FP_DB_VERSION);
}

// Goedkope check (geen query) op elke admin-load: alleen bij een écht verschillende db-versie wordt dbDelta uitgevoerd.
add_action('admin_init', 'brink_fp_maybe_upgrade_db');
function brink_fp_maybe_upgrade_db() {
    if (get_option('brink_fp_db_version') !== BRINK_FP_DB_VERSION) {
        brink_fp_create_or_upgrade_stats_table();
    }
}

// WP kent van zichzelf geen "weekly" interval (alleen hourly/twicedaily/daily) — nodig voor de
// wekelijkse digest-mail hieronder.
add_filter('cron_schedules', 'brink_fp_add_weekly_cron_schedule');
function brink_fp_add_weekly_cron_schedule($schedules) {
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = array('interval' => WEEK_IN_SECONDS, 'display' => __('Eén keer per week', 'brink-fp'));
    }
    return $schedules;
}

// Activatie: tabel aanmaken, standaardopties zetten, cronjobs plannen
register_activation_hook(__FILE__, 'brink_fp_activate');
function brink_fp_activate() {
    brink_fp_create_or_upgrade_stats_table();
    brink_ad_settings_init();

    if (!wp_next_scheduled('brink_cleanup_ads')) {
        wp_schedule_event(time(), 'daily', 'brink_cleanup_ads');
    }
    if (!wp_next_scheduled('brink_stats_cleanup_event')) {
        wp_schedule_event(time(), 'daily', 'brink_stats_cleanup_event');
    }
    if (!wp_next_scheduled('brink_fp_weekly_digest_event')) {
        wp_schedule_event(time(), 'weekly', 'brink_fp_weekly_digest_event');
    }
}

// Deactivatie: geplande cronjobs netjes opruimen (voorkomt "spooktaken" na uitschakelen)
register_deactivation_hook(__FILE__, 'brink_fp_deactivate');
function brink_fp_deactivate() {
    $timestamp = wp_next_scheduled('brink_cleanup_ads');
    if ($timestamp) wp_unschedule_event($timestamp, 'brink_cleanup_ads');

    $timestamp2 = wp_next_scheduled('brink_stats_cleanup_event');
    if ($timestamp2) wp_unschedule_event($timestamp2, 'brink_stats_cleanup_event');

    $timestamp3 = wp_next_scheduled('brink_fp_weekly_digest_event');
    if ($timestamp3) wp_unschedule_event($timestamp3, 'brink_fp_weekly_digest_event');
}

// Data-minimalisatie: oude statistiekregels periodiek opruimen (standaard 180 dagen, net als wp-realtime-analytics)
add_action('brink_stats_cleanup_event', 'brink_fp_prune_old_stats');
function brink_fp_prune_old_stats() {
    global $wpdb;
    $table = $wpdb->prefix . 'brink_stats';
    $retention_days = (int) apply_filters('brink_fp_stats_retention_days', 180);
    if ($retention_days < 30) $retention_days = 30;
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $retention_days));
}

// FUNCTIONALITEIT (v5.17.0): wekelijkse digest-mail naar de beheerder met een overzicht van de
// afgelopen 7 dagen — zelfde soort functionaliteit als de wekelijkse stats-mail in
// wp-realtime-analytics, hier toegepast op de formulierinzendingen van deze plugin.
add_action('brink_fp_weekly_digest_event', 'brink_fp_send_weekly_digest');
function brink_fp_send_weekly_digest() {
    if (get_option('brink_fp_weekly_digest_enabled', '1') !== '1') return;

    global $wpdb;
    $table = $wpdb->prefix . 'brink_stats';
    $totals = $wpdb->get_results($wpdb->prepare(
        "SELECT form_type, COUNT(*) as count FROM $table WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) GROUP BY form_type",
        7
    ), ARRAY_A);

    $counts = array('ad' => 0, 'ervaring' => 0, 'contact' => 0, 'inschrijving' => 0, 'reactie' => 0);
    foreach ($totals as $row) { $counts[$row['form_type']] = (int) $row['count']; }
    $grand_total = array_sum($counts);

    if ($grand_total === 0 && apply_filters('brink_fp_skip_empty_digest', true)) return;

    $labels = array('ad' => 'Advertenties', 'ervaring' => 'Ervaringen', 'contact' => 'Contactberichten', 'inschrijving' => 'Inschrijvingen', 'reactie' => 'Reacties');
    $lines = array();
    $lines[] = 'Wekelijks overzicht - Brink Multimedia Frontend Posting Pro';
    $lines[] = '';
    $lines[] = 'Periode: laatste 7 dagen';
    $lines[] = 'Totaal aantal inzendingen: ' . $grand_total;
    $lines[] = '';
    foreach ($labels as $key => $label) {
        $lines[] = '- ' . $label . ': ' . $counts[$key];
    }
    $lines[] = '';
    $lines[] = 'Bekijk het volledige dashboard: ' . admin_url('admin.php?page=brink-posting');

    $body = implode("\n", $lines);
    $subject = 'Wekelijks overzicht: ' . $grand_total . ' nieuwe inzendingen';

    wp_mail(get_option('admin_email'), $subject, $body, array('Content-Type: text/plain; charset=UTF-8'));
}

// Log functie voor statistieken
function brink_log_stat($type) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'brink_stats', array('form_type' => $type, 'created_at' => current_time('mysql')));
}

// Elementor hook voor het tellen van de reacties
add_action('elementor_pro/forms/new_record', 'brink_log_elementor_submission', 10, 2);
function brink_log_elementor_submission($record, $handler) {
    brink_log_stat('reactie');
}

add_action('admin_menu', 'brink_ad_settings_menu');
function brink_ad_settings_menu() {
    add_menu_page('Brink Posting Beheer', 'Brink Posting', 'manage_options', 'brink-posting', 'brink_ad_dashboard_page', 'dashicons-megaphone', 30);
}

add_action('admin_init', 'brink_ad_settings_init');
function brink_ad_settings_init() {
    // Kleuren & Redirects
    register_setting('brink_ad_colors_group', 'mystique_ad_bg_color'); register_setting('brink_ad_colors_group', 'mystique_ad_text_color');
    register_setting('brink_ad_colors_group', 'mystique_ad_primary_color'); register_setting('brink_ad_colors_group', 'mystique_ad_btn_color');
    register_setting('brink_ad_colors_group', 'mystique_ad_btn_hover_color'); register_setting('brink_ad_colors_group', 'brink_ad_allowed_categories');
    
    // Formulier URLs & Delete Redirects
    register_setting('brink_ad_colors_group', 'brink_ad_redirect_url'); 
    register_setting('brink_ad_colors_group', 'brink_ad_form_page_url');
    register_setting('brink_ad_colors_group', 'brink_ad_delete_redirect_url'); 
    
    register_setting('brink_ad_colors_group', 'brink_ad_ervaringen_category'); 
    register_setting('brink_ad_colors_group', 'brink_ad_ervaringen_redirect_url');
    register_setting('brink_ad_colors_group', 'brink_ad_ervaringen_form_page_url');
    register_setting('brink_ad_colors_group', 'brink_ad_ervaringen_delete_redirect_url');
    
    register_setting('brink_ad_colors_group', 'brink_ad_contact_category'); 
    register_setting('brink_ad_colors_group', 'brink_ad_contact_redirect_url');
    
    register_setting('brink_ad_colors_group', 'brink_ad_inschrijving_category'); 
    register_setting('brink_ad_colors_group', 'brink_ad_inschrijving_redirect_url');

    // E-mail Instellingen (Templates)
    register_setting('brink_ad_email_group', 'brink_ad_email_subject'); register_setting('brink_ad_email_group', 'brink_ad_email_body');
    register_setting('brink_ad_email_group', 'brink_ad_ervaringen_email_subject'); register_setting('brink_ad_email_group', 'brink_ad_ervaringen_email_body');
    register_setting('brink_ad_email_group', 'brink_ad_contact_email_subject'); register_setting('brink_ad_email_group', 'brink_ad_contact_email_body');
    register_setting('brink_ad_email_group', 'brink_ad_inschrijving_email_subject'); register_setting('brink_ad_email_group', 'brink_ad_inschrijving_email_body');
    register_setting('brink_ad_email_group', 'brink_ad_contact_emails'); register_setting('brink_ad_email_group', 'brink_ad_inschrijving_emails');
    
    register_setting('brink_ad_email_group', 'brink_ad_delete_reason_email_subject'); register_setting('brink_ad_email_group', 'brink_ad_delete_reason_email_body');
    register_setting('brink_ad_email_group', 'brink_ad_ban_email_subject'); register_setting('brink_ad_email_group', 'brink_ad_ban_email_body');
    
    // No-Reply Instellingen
    register_setting('brink_ad_email_group', 'brink_ad_noreply_enabled');
    register_setting('brink_ad_email_group', 'brink_ad_noreply_email');
    
    // Verificatie E-mail Instellingen
    register_setting('brink_ad_email_group', 'brink_ad_verify_email_subject'); register_setting('brink_ad_email_group', 'brink_ad_verify_email_body');

    // Geavanceerd
    register_setting('brink_ad_advanced_group', 'brink_turnstile_sitekey'); register_setting('brink_ad_advanced_group', 'brink_turnstile_secret');
    register_setting('brink_ad_advanced_group', 'brink_ad_placeholder_image'); register_setting('brink_ad_advanced_group', 'brink_ad_bump_enabled');
    register_setting('brink_ad_advanced_group', 'brink_ad_bump_limit');
    register_setting('brink_ad_advanced_group', 'brink_ad_banned_emails');
    register_setting('brink_ad_advanced_group', 'brink_ad_email_verification_enabled'); // V5.14.2 - E-mail verificatie (Optie 3)
    register_setting('brink_ad_advanced_group', 'brink_ad_image_max_width'); // V5.17.0 - Max breedte bij compressie/resize
    register_setting('brink_ad_advanced_group', 'brink_ad_gallery_max_images'); // V5.17.0 - Max aantal galerij-afbeeldingen
    register_setting('brink_ad_advanced_group', 'brink_fp_weekly_digest_enabled'); // V5.17.0 - Wekelijkse digest-mail aan/uit

    // Openingstijden & Prijzen 
    register_setting('brink_ad_openingstijden_group', 'brink_openingstijden_data');

    // Defaults inladen
    if (!get_option('mystique_ad_bg_color')) update_option('mystique_ad_bg_color', '#ffffff');
    if (!get_option('mystique_ad_primary_color')) update_option('mystique_ad_primary_color', '#b5121b');
    if (!get_option('brink_ad_bump_limit')) update_option('brink_ad_bump_limit', '3');
    if (!get_option('brink_ad_image_max_width')) update_option('brink_ad_image_max_width', '1600');
    if (!get_option('brink_ad_gallery_max_images')) update_option('brink_ad_gallery_max_images', '5');
    if (get_option('brink_fp_weekly_digest_enabled', false) === false) update_option('brink_fp_weekly_digest_enabled', '1');
    
    // E-mail Defaults
    if (!get_option('brink_ad_email_subject')) update_option('brink_ad_email_subject', 'Je advertentie #{advertentienummer} staat online!');
    if (!get_option('brink_ad_email_body')) update_option('brink_ad_email_body', "Beste {naam},\n\nJe advertentie '{advertentietitel}' staat nu live.\n\nBewerken: {edit_link}\nBovenaan plaatsen: {bump_link}\nVerwijderen: {delete_link}");
    if (!get_option('brink_ad_ervaringen_email_subject')) update_option('brink_ad_ervaringen_email_subject', 'Bedankt voor het delen van je ervaring!');
    if (!get_option('brink_ad_ervaringen_email_body')) update_option('brink_ad_ervaringen_email_body', "Beste {naam},\n\nBedankt voor je verhaal. We kijken deze zo snel mogelijk na.\n\nBewerken: {edit_link}");
    if (!get_option('brink_ad_contact_email_subject')) update_option('brink_ad_contact_email_subject', 'Nieuw contactbericht: {naam}');
    if (!get_option('brink_ad_contact_email_body')) update_option('brink_ad_contact_email_body', "Je hebt een nieuw bericht ontvangen.\n\nNaam: {naam}\nE-mail: {email}\nTelefoon: {telefoon}\nNieuwsbrief: {nieuwsbrief}\n\nVraag/Opmerking:\n{bericht}");
    if (!get_option('brink_ad_inschrijving_email_subject')) update_option('brink_ad_inschrijving_email_subject', 'Nieuwe inschrijving: {heer} & {dame}');
    if (!get_option('brink_ad_inschrijving_email_body')) update_option('brink_ad_inschrijving_email_body', "Inschrijving ontvangen.\n\nHeer: {heer}\nDame: {dame}\nPostcode: {postcode}\nLand: {land}\nTelefoon: {telefoon}\nE-mail: {email}\nGeboortedatum: {geboortedatum}\n\nBron: {bron}\nNieuwsbrief: {nieuwsbrief}\nDatum: {datum}");
    
    if (!get_option('brink_ad_delete_reason_email_subject')) update_option('brink_ad_delete_reason_email_subject', 'Belangrijk: Je advertentie is verwijderd of geweigerd');
    if (!get_option('brink_ad_delete_reason_email_body')) update_option('brink_ad_delete_reason_email_body', "Beste {naam},\n\nJe advertentie of inzending '{titel}' is zojuist door een van onze beheerders verwijderd.\n\nReden:\n{reden}\n\nLees onze voorwaarden nog eens goed door voordat je een nieuw bericht plaatst.");
    if (!get_option('brink_ad_ban_email_subject')) update_option('brink_ad_ban_email_subject', 'Je advertentie is verwijderd en je e-mailadres is geblokkeerd');
    if (!get_option('brink_ad_ban_email_body')) update_option('brink_ad_ban_email_body', "Beste {naam},\n\nJe advertentie '{titel}' is zojuist verwijderd en je e-mailadres is tijdelijk of permanent geblokkeerd wegens het overtreden van onze huisregels.\n\nReden van blokkade:\n{reden}\n\nAls je denkt dat dit een fout is, neem dan contact met ons op via onze website.");
    
    if (!get_option('brink_ad_verify_email_subject')) update_option('brink_ad_verify_email_subject', 'Belangrijk: Bevestig je advertentie op onze website');
    if (!get_option('brink_ad_verify_email_body')) update_option('brink_ad_verify_email_body', "Beste {naam},\n\nWe hebben je advertentie '{titel}' in goede orde ontvangen.\n\nOm te voorkomen dat we ongewenste reclame krijgen of dat je e-mailadres wordt misbruikt, vragen we je deze advertentie via de onderstaande veilige link te activeren.\n\nKlik op deze link om je advertentie definitief live te zetten:\n{verify_link}\n\nAls je zelf geen advertentie hebt geplaatst, kun je deze e-mail veilig negeren.");

    // PRE-FILL OPENINGSTIJDEN
    if (get_option('brink_openingstijden_data') === false) {
        $default_ot = array(
            array('day' => 'Vrijdag', 'time' => '20.00 - 02.00', 'prices' => array(array('target' => 'Paren', 'price' => '110€'), array('target' => 'Vrouw alleen', 'price' => '45€'))),
            array('day' => 'Zaterdag', 'time' => '19.00 - 01.00', 'prices' => array(array('target' => 'Paren', 'price' => '130€'), array('target' => 'Vrouw alleen', 'price' => '50€'))),
            array('day' => 'Zondag', 'time' => '16.00 - 23.00', 'prices' => array(array('target' => 'Paren', 'price' => '110€'), array('target' => 'Vrouw alleen', 'price' => '45€'))),
            array('day' => 'Maandag', 'time' => '14.00 - 21.00', 'prices' => array(array('target' => 'Heren', 'price' => '100€'), array('target' => 'Paren', 'price' => '75€'), array('target' => 'Vrouw', 'price' => 'gratis'))),
            array('day' => 'Maandag Hete Godinnen dag', 'time' => '14.00 - 21.00', 'prices' => array(array('target' => 'Heren', 'price' => '100€'), array('target' => 'Paren', 'price' => '75€'), array('target' => 'Actieve paren', 'price' => '35€'), array('target' => 'Actieve dames', 'price' => 'gratis'))),
            array('day' => '2e Woensdag vd maand bi avond', 'time' => '18.00 - 00.00', 'prices' => array(array('target' => 'Heren', 'price' => '100€'), array('target' => 'Paren', 'price' => '75€'), array('target' => 'Vrouw', 'price' => '30€'))),
            array('day' => 'Donderdag zonder thema!', 'time' => '14.00 - 21.00', 'prices' => array(array('target' => 'Heren', 'price' => '100€'), array('target' => 'Paren', 'price' => '75€'), array('target' => 'Vrouw', 'price' => '20€'))),
            array('day' => 'Donderdag gang bang', 'time' => '14.00 - 22.00', 'prices' => array(array('target' => 'Heren', 'price' => '100€'), array('target' => 'Paren', 'price' => '75€'), array('target' => 'Actieve paren', 'price' => '30€'), array('target' => 'Vrouw', 'price' => 'gratis'))),
            array('day' => 'Donderdag hete huisvrouwen', 'time' => '14.00 - 22.00', 'prices' => array(array('target' => 'Heren', 'price' => '100€'), array('target' => 'Actieve Paren', 'price' => '35€'), array('target' => 'Paren', 'price' => '75€'), array('target' => 'Actieve Vrouw', 'price' => 'gratis'))),
            array('day' => 'Donderdag travestie', 'time' => '14.00 - 23.00', 'prices' => array(array('target' => 'Heren', 'price' => '100€'), array('target' => 'Paren', 'price' => '75€'), array('target' => 'Dames', 'price' => '30€'), array('target' => 'Travestie', 'price' => '45€'))),
            array('day' => 'Donderdag bdsm', 'time' => '14.00 - 23.00', 'prices' => array(array('target' => 'Heren', 'price' => '100€'), array('target' => 'Paren', 'price' => '75€'), array('target' => 'Dames', 'price' => '30€'))),
            array('day' => 'Vrijdag black edition', 'time' => '20.00 - 02.00', 'prices' => array(array('target' => 'Heren', 'price' => '100€'), array('target' => 'Paren', 'price' => '110€'), array('target' => 'Dames', 'price' => '40€'))),
        );
        update_option('brink_openingstijden_data', wp_json_encode($default_ot));
    }
}

add_action('update_option_brink_ad_placeholder_image', 'brink_convert_placeholder_to_webp_hook', 10, 3);
function brink_convert_placeholder_to_webp_hook($old_value, $new_value, $option) {
    if ($new_value && $new_value != $old_value) {
        wp_update_post(array('ID' => (int)$new_value, 'post_parent' => 0));
        brink_force_webp_conversion_and_seo($new_value, 'standaard-placeholder');
    }
}

// ==========================================
// 2. ADMIN ACTIES & DASHBOARD
// ==========================================
add_action('admin_init', 'brink_ad_handle_admin_actions');
function brink_ad_handle_admin_actions() {
    // BEVEILIGING (v5.15.0): admin_init vuurt af vóórdat WP de capability-check van de
    // menupagina zelf uitvoert. Zonder onderstaande regel kon elke ingelogde wp-admin-gebruiker
    // (ook zonder 'manage_options', bijv. een Contributor) deze acties rechtstreeks via URL
    // aanroepen. Alles hieronder is uitsluitend voor sitebeheerders bedoeld.
    if (!current_user_can('manage_options')) return;

    if (isset($_POST['brink_action']) && $_POST['brink_action'] === 'submit_nieuws') {
        if (!isset($_POST['brink_nieuws_nonce']) || !wp_verify_nonce($_POST['brink_nieuws_nonce'], 'brink_submit_nieuws')) { wp_die('Veiligheidscontrole mislukt.'); }
        $title = sanitize_text_field($_POST['nieuws_title']);
        $content = wp_kses_post($_POST['nieuws_content']);
        $category = 18; // Nieuws Categorie ID
        if (!empty($title) && !empty($content)) {
            $post_id = wp_insert_post(array('post_title' => $title, 'post_content' => $content, 'post_status' => 'publish', 'post_category' => array($category), 'post_type' => 'post'), true);
            if (!is_wp_error($post_id)) {
                if (!empty($_POST['nieuws_image_id'])) {
                    $attachment_id = intval($_POST['nieuws_image_id']);
                    set_post_thumbnail($post_id, $attachment_id);
                    brink_force_webp_conversion_and_seo($attachment_id, $title);
                }
                wp_safe_redirect(admin_url('admin.php?page=brink-posting&tab=nieuws&msg=nieuws_geplaatst')); exit;
            }
        }
    }

    if (isset($_POST['brink_action']) && $_POST['brink_action'] === 'submit_vacature') {
        if (!isset($_POST['brink_vacature_nonce']) || !wp_verify_nonce($_POST['brink_vacature_nonce'], 'brink_submit_vacature')) { wp_die('Veiligheidscontrole mislukt.'); }
        $title = sanitize_text_field($_POST['vacature_title']);
        $content = wp_kses_post($_POST['vacature_content']);
        $category = 19; // Vacatures Categorie ID
        if (!empty($title) && !empty($content)) {
            $post_id = wp_insert_post(array('post_title' => $title, 'post_content' => $content, 'post_status' => 'publish', 'post_category' => array($category), 'post_type' => 'post'), true);
            if (!is_wp_error($post_id)) {
                if (!empty($_POST['vacature_image_id'])) {
                    $attachment_id = intval($_POST['vacature_image_id']);
                    set_post_thumbnail($post_id, $attachment_id);
                    brink_force_webp_conversion_and_seo($attachment_id, $title);
                }
                wp_safe_redirect(admin_url('admin.php?page=brink-posting&tab=vacatures&msg=vacature_geplaatst')); exit;
            }
        }
    }

    if (!isset($_GET['page']) || $_GET['page'] !== 'brink-posting') return;
    
    if (isset($_GET['brink_action'])) {
        $action = sanitize_text_field($_GET['brink_action']);

        // BEVEILIGING (v5.15.0): CSRF-bescherming. Deze acties zijn destructief (permanente
        // verwijdering, bannen, truncate) en mogen nooit via een simpele, ongeverifieerde link
        // uitgevoerd kunnen worden.
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'brink_admin_action')) {
            wp_die('Veiligheidscontrole mislukt: ongeldige of verlopen link. Ga terug naar het dashboard en probeer het opnieuw.', 'Beveiligingscontrole mislukt', array('response' => 403));
        }

        if ($action === 'reset_stats') {
            global $wpdb;
            $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . "brink_stats");
            wp_safe_redirect(admin_url('admin.php?page=brink-posting&tab=stats&msg=stats_reset'));
            exit;
        }

        if ($action === 'reset_day_views') {
            delete_option('brink_openingstijden_views');
            wp_safe_redirect(admin_url('admin.php?page=brink-posting&tab=stats&msg=stats_reset'));
            exit;
        }

        if (isset($_GET['ad_id'])) {
            $post_id = intval($_GET['ad_id']);
            
            // Verwijderen & Ban of Alleen Verwijderen met reden
            if ($action === 'delete_reason' || $action === 'delete_ban') {
                $reason = isset($_GET['reason']) ? sanitize_text_field(urldecode($_GET['reason'])) : 'Geen opgave van reden.';
                
                $email = get_post_meta($post_id, 'ad_email', true); 
                $name = get_post_meta($post_id, 'ad_name', true);
                $title = get_the_title($post_id);
                
                $adv_name = $name ? $name : 'adverteerder';
                $replacements = array('{naam}' => $adv_name, '{titel}' => $title, '{reden}' => $reason);

                if ($action === 'delete_ban') {
                    if (is_email($email)) {
                        $banned = get_option('brink_ad_banned_emails', '');
                        $banned_array = array_map('trim', explode("\n", $banned));
                        if (!in_array($email, $banned_array)) {
                            $banned_array[] = $email;
                            update_option('brink_ad_banned_emails', implode("\n", array_filter($banned_array)));
                        }
                    }
                    $subject_template = get_option('brink_ad_ban_email_subject', 'Je advertentie is verwijderd en je e-mailadres is geblokkeerd');
                    $body_template = get_option('brink_ad_ban_email_body', "Beste {naam},\n\nJe advertentie '{titel}' is zojuist verwijderd en je e-mailadres is tijdelijk of permanent geblokkeerd wegens het overtreden van onze huisregels.\n\nReden van blokkade:\n{reden}\n\nAls je denkt dat dit een fout is, neem dan contact met ons op via onze website.");
                } else {
                    $subject_template = get_option('brink_ad_delete_reason_email_subject', 'Belangrijk: Je advertentie is verwijderd of geweigerd');
                    $body_template = get_option('brink_ad_delete_reason_email_body', "Beste {naam},\n\nJe advertentie of inzending '{titel}' is zojuist door een van onze beheerders verwijderd.\n\nReden:\n{reden}\n\nLees onze voorwaarden nog eens goed door voordat je een nieuw bericht plaatst.");
                }
                
                $subject = str_replace(array_keys($replacements), array_values($replacements), $subject_template);
                $body = str_replace(array_keys($replacements), array_values($replacements), $body_template);

                $headers = array('Content-Type: text/plain; charset=UTF-8');
                
                // No-Reply functionaliteit (alleen voor Verwijder & Ban mails)
                if (get_option('brink_ad_noreply_enabled') === '1') {
                    $noreply_email = get_option('brink_ad_noreply_email', 'noreply@' . wp_parse_url(home_url(), PHP_URL_HOST));
                    if (is_email($noreply_email)) {
                        $headers[] = 'Reply-To: No Reply <' . $noreply_email . '>';
                    }
                }

                if (is_email($email)) {
                    wp_mail($email, $subject, $body, $headers);
                }

                $placeholder_id = (int) get_option('brink_ad_placeholder_image');
                $thumb_id = (int) get_post_thumbnail_id($post_id);
                if ($thumb_id && $thumb_id !== $placeholder_id) {
                    wp_delete_attachment($thumb_id, true);
                }
                wp_delete_post($post_id, true);

                $msg = ($action === 'delete_ban') ? 'banned' : 'deleted_reason';
                wp_safe_redirect(admin_url("admin.php?page=brink-posting&tab=dashboard&msg=$msg")); exit;
            }

            if ($action === 'delete') {
                $placeholder_id = (int) get_option('brink_ad_placeholder_image');
                $thumb_id = (int) get_post_thumbnail_id($post_id);
                if ($thumb_id && $thumb_id !== $placeholder_id) {
                    wp_delete_attachment($thumb_id, true);
                }
                wp_delete_post($post_id, true);
                wp_safe_redirect(admin_url('admin.php?page=brink-posting&tab=dashboard&msg=deleted')); exit;
            }
            if ($action === 'extend') {
                update_post_meta($post_id, 'expiration_date', time() + (30 * DAY_IN_SECONDS));
                wp_safe_redirect(admin_url('admin.php?page=brink-posting&tab=dashboard&msg=extended')); exit;
            }
            if ($action === 'resend_mail') {
                $success = brink_send_ad_email($post_id);
                wp_safe_redirect(admin_url('admin.php?page=brink-posting&tab=dashboard&msg=' . ($success ? 'mail_sent' : 'mail_failed'))); exit;
            }
        }
        
        // V5.14.0 - Bulk Verwijderen Contactberichten
        if ($action === 'delete_all_contacts') {
            $contact_cat = intval(get_option('brink_ad_contact_category'));
            if ($contact_cat) {
                $posts = get_posts(array('post_type' => 'post', 'category' => $contact_cat, 'posts_per_page' => -1, 'post_status' => array('publish', 'private')));
                foreach ($posts as $p) { wp_delete_post($p->ID, true); }
            }
            wp_safe_redirect(admin_url('admin.php?page=brink-posting&tab=dashboard&msg=deleted_all')); exit;
        }

        // V5.14.0 - Bulk Verwijderen Inschrijvingen
        if ($action === 'delete_all_inschrijvingen') {
            $ins_cat = intval(get_option('brink_ad_inschrijving_category'));
            if ($ins_cat) {
                $posts = get_posts(array('post_type' => 'post', 'category' => $ins_cat, 'posts_per_page' => -1, 'post_status' => array('publish', 'private')));
                foreach ($posts as $p) { wp_delete_post($p->ID, true); }
            }
            wp_safe_redirect(admin_url('admin.php?page=brink-posting&tab=dashboard&msg=deleted_all')); exit;
        }
    }
}

function brink_ad_dashboard_page() {
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';
    if ($active_tab === 'advanced' || $active_tab === 'nieuws' || $active_tab === 'vacatures') wp_enqueue_media();
    ?>
    <div class="wrap">
        <h1>Brink Multimedia Frontend Posting Pro <span style="font-size:14px; color:#666;">v<?php echo esc_html(BRINK_FP_VERSION); ?></span></h1>
        
        <?php
        if (isset($_GET['msg'])) {
            if ($_GET['msg'] === 'deleted') echo '<div class="notice notice-success is-dismissible"><p>Item succesvol verwijderd.</p></div>';
            if ($_GET['msg'] === 'deleted_all') echo '<div class="notice notice-success is-dismissible"><p>Alle items in deze categorie zijn in één keer succesvol verwijderd.</p></div>';
            if ($_GET['msg'] === 'extended') echo '<div class="notice notice-success is-dismissible"><p>Advertentie met 30 dagen verlengd.</p></div>';
            if ($_GET['msg'] === 'mail_sent') echo '<div class="notice notice-success is-dismissible"><p>E-mail succesvol opnieuw verzonden.</p></div>';
            if ($_GET['msg'] === 'mail_failed') echo '<div class="notice notice-error is-dismissible"><p>Fout: E-mail kon niet worden verzonden.</p></div>';
            if ($_GET['msg'] === 'stats_reset') echo '<div class="notice notice-success is-dismissible"><p>Statistieken zijn volledig gereset en opgeschoond.</p></div>';
            if ($_GET['msg'] === 'nieuws_geplaatst') echo '<div class="notice notice-success is-dismissible"><p>Nieuwsbericht succesvol geplaatst! Afbeelding is SEO-geoptimaliseerd en omgezet naar WebP.</p></div>';
            if ($_GET['msg'] === 'vacature_geplaatst') echo '<div class="notice notice-success is-dismissible"><p>Vacature succesvol geplaatst! Afbeelding is SEO-geoptimaliseerd en omgezet naar WebP.</p></div>';
            if ($_GET['msg'] === 'deleted_reason') echo '<div class="notice notice-success is-dismissible"><p>Bericht is verwijderd en de adverteerder is succesvol op de hoogte gebracht per e-mail met de reden.</p></div>';
            if ($_GET['msg'] === 'banned') echo '<div class="notice notice-success is-dismissible"><p>Bericht verwijderd, e-mailadres toegevoegd aan de Banlijst, en de adverteerder is gemaild.</p></div>';
        }
        ?>

        <h2 class="nav-tab-wrapper">
            <a href="?page=brink-posting&tab=dashboard" class="nav-tab <?php echo $active_tab == 'dashboard' ? 'nav-tab-active' : ''; ?>">Dashboard Overzicht</a>
            <a href="?page=brink-posting&tab=nieuws" class="nav-tab <?php echo $active_tab == 'nieuws' ? 'nav-tab-active' : ''; ?>">Plaatsing Nieuws</a>
            <a href="?page=brink-posting&tab=vacatures" class="nav-tab <?php echo $active_tab == 'vacatures' ? 'nav-tab-active' : ''; ?>">Plaatsing Vacatures</a>
            <a href="?page=brink-posting&tab=openingstijden" class="nav-tab <?php echo $active_tab == 'openingstijden' ? 'nav-tab-active' : ''; ?>">Openingstijden & Prijzen</a>
            <a href="?page=brink-posting&tab=stats" class="nav-tab <?php echo $active_tab == 'stats' ? 'nav-tab-active' : ''; ?>">Statistieken</a>
            <a href="?page=brink-posting&tab=colors" class="nav-tab <?php echo $active_tab == 'colors' ? 'nav-tab-active' : ''; ?>">Instellingen & Formulier</a>
            <a href="?page=brink-posting&tab=email" class="nav-tab <?php echo $active_tab == 'email' ? 'nav-tab-active' : ''; ?>">E-mail Templates</a>
            <a href="?page=brink-posting&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">Geavanceerd</a>
            <a href="?page=brink-posting&tab=shortcodes" class="nav-tab <?php echo $active_tab == 'shortcodes' ? 'nav-tab-active' : ''; ?>">Shortcodes</a>
        </h2>

        <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); margin-top:15px;">
            <?php
            if ($active_tab == 'dashboard') brink_render_dashboard_tab();
            elseif ($active_tab == 'nieuws') brink_render_nieuws_tab();
            elseif ($active_tab == 'vacatures') brink_render_vacatures_tab();
            elseif ($active_tab == 'openingstijden') brink_render_openingstijden_tab();
            elseif ($active_tab == 'stats') brink_render_stats_tab();
            elseif ($active_tab == 'colors') brink_render_colors_tab();
            elseif ($active_tab == 'email') brink_render_email_tab();
            elseif ($active_tab == 'advanced') brink_render_advanced_tab();
            elseif ($active_tab == 'shortcodes') brink_render_shortcodes_tab();
            ?>
        </div>
    </div>
    <?php
}

function brink_render_nieuws_tab() {
    ?>
    <h3>Nieuwsbericht Plaatsen (SEO Geoptimaliseerd)</h3>
    <form method="post" action="" style="margin-top:20px;">
        <?php wp_nonce_field('brink_submit_nieuws', 'brink_nieuws_nonce'); ?>
        <input type="hidden" name="brink_action" value="submit_nieuws">
        <table class="form-table">
            <tr valign="top"><th scope="row"><label for="nieuws_title">Titel van het nieuwsbericht *</label></th><td><input type="text" id="nieuws_title" name="nieuws_title" class="regular-text" style="width:100%; max-width:800px;" required /></td></tr>
            <tr valign="top"><th scope="row"><label for="nieuws_content">Bericht (Inhoud) *</label></th><td><?php wp_editor('', 'nieuws_content', array('textarea_name' => 'nieuws_content', 'media_buttons' => false, 'textarea_rows' => 20)); ?></td></tr>
            <tr valign="top"><th scope="row"><label for="nieuws_image">Uitgelichte Afbeelding (Media)</label></th><td><div class="image-preview-wrapper" style="margin-bottom:10px;"><img id="nieuws-preview" src="" style="max-width:150px; display: none; border-radius:4px;"></div><input type="hidden" name="nieuws_image_id" id="nieuws_image_id" value=""><input type="button" class="button" id="upload-nieuws-btn" value="Kies uit Mediabibliotheek / Upload"><input type="button" class="button" id="remove-nieuws-btn" value="Verwijder" style="display: none;"></td></tr>
        </table>
        <div style="margin-top:20px;"><?php submit_button('Publiceer Nieuwsbericht', 'primary', 'submit', false); ?></div>
    </form>
    <script>
    jQuery(document).ready(function($){
        var nieuwsMediaUploader;
        $('#upload-nieuws-btn').click(function(e) {
            e.preventDefault(); if (nieuwsMediaUploader) { nieuwsMediaUploader.open(); return; }
            nieuwsMediaUploader = wp.media.frames.file_frame = wp.media({ title: 'Kies een Uitgelichte Afbeelding', button: { text: 'Kies Afbeelding' }, multiple: false });
            nieuwsMediaUploader.on('select', function() { var attachment = nieuwsMediaUploader.state().get('selection').first().toJSON(); $('#nieuws_image_id').val(attachment.id); $('#nieuws-preview').attr('src', attachment.url).show(); $('#remove-nieuws-btn').show(); }); nieuwsMediaUploader.open();
        });
        $('#remove-nieuws-btn').click(function(e) { e.preventDefault(); $('#nieuws_image_id').val(''); $('#nieuws-preview').attr('src', '').hide(); $(this).hide(); });
    });
    </script>
    <?php
}

function brink_render_vacatures_tab() {
    ?>
    <h3>Vacature Plaatsen (SEO Geoptimaliseerd)</h3>
    <form method="post" action="" style="margin-top:20px;">
        <?php wp_nonce_field('brink_submit_vacature', 'brink_vacature_nonce'); ?>
        <input type="hidden" name="brink_action" value="submit_vacature">
        <table class="form-table">
            <tr valign="top"><th scope="row"><label for="vacature_title">Titel van de vacature *</label></th><td><input type="text" id="vacature_title" name="vacature_title" class="regular-text" style="width:100%; max-width:800px;" required /></td></tr>
            <tr valign="top"><th scope="row"><label for="vacature_content">Bericht (Inhoud) *</label></th><td><?php wp_editor('', 'vacature_content', array('textarea_name' => 'vacature_content', 'media_buttons' => false, 'textarea_rows' => 20)); ?></td></tr>
            <tr valign="top"><th scope="row"><label for="vacature_image">Uitgelichte Afbeelding (Media)</label></th><td><div class="image-preview-wrapper" style="margin-bottom:10px;"><img id="vacature-preview" src="" style="max-width:150px; display: none; border-radius:4px;"></div><input type="hidden" name="vacature_image_id" id="vacature_image_id" value=""><input type="button" class="button" id="upload-vacature-btn" value="Kies uit Mediabibliotheek / Upload"><input type="button" class="button" id="remove-vacature-btn" value="Verwijder" style="display: none;"></td></tr>
        </table>
        <div style="margin-top:20px;"><?php submit_button('Publiceer Vacature', 'primary', 'submit', false); ?></div>
    </form>
    <script>
    jQuery(document).ready(function($){
        var vacatureMediaUploader;
        $('#upload-vacature-btn').click(function(e) {
            e.preventDefault(); if (vacatureMediaUploader) { vacatureMediaUploader.open(); return; }
            vacatureMediaUploader = wp.media.frames.file_frame = wp.media({ title: 'Kies een Uitgelichte Afbeelding', button: { text: 'Kies Afbeelding' }, multiple: false });
            vacatureMediaUploader.on('select', function() { var attachment = vacatureMediaUploader.state().get('selection').first().toJSON(); $('#vacature_image_id').val(attachment.id); $('#vacature-preview').attr('src', attachment.url).show(); $('#remove-vacature-btn').show(); }); vacatureMediaUploader.open();
        });
        $('#remove-vacature-btn').click(function(e) { e.preventDefault(); $('#vacature_image_id').val(''); $('#vacature-preview').attr('src', '').hide(); $(this).hide(); });
    });
    </script>
    <?php
}

function brink_render_openingstijden_tab() {
    $data_json = get_option('brink_openingstijden_data', '[]');
    ?>
    <style>
        .ot-builder { max-width: 800px; }
        .ot-day-card { background: #f9f9f9; border: 1px solid #ccd0d4; border-left: 4px solid #b5121b; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .ot-day-header { display: flex; gap: 10px; margin-bottom: 15px; align-items: center; }
        .ot-price-row { display: flex; gap: 10px; margin-bottom: 8px; align-items: center; background: #fff; padding: 8px; border: 1px solid #eee; border-radius: 4px; }
        .ot-input { padding: 6px; border: 1px solid #ccc; border-radius: 4px; width: 100%; }
        .ot-btn-danger { color: #b5121b; cursor: pointer; text-decoration: none; font-weight: bold; }
        .ot-btn-danger:hover { color: #ff0000; }
        .ot-btn-add-price { font-size: 12px; margin-top: 10px; display: inline-block; }
    </style>
    <h3>Openingstijden en Prijzen Beheer</h3>
    <form method="post" action="options.php" id="ot-form">
        <?php settings_fields('brink_ad_openingstijden_group'); ?>
        <input type="hidden" name="brink_openingstijden_data" id="brink_openingstijden_data" value="<?php echo esc_attr($data_json); ?>">
        <div id="ot-container" class="ot-builder"></div>
        <div style="margin-top: 20px;"><button type="button" class="button" id="btn-add-day">+ Voeg Nieuw Thema/Dag Toe</button><?php submit_button('Sla Alle Prijzen Op', 'primary', 'submit', false, array('style' => 'margin-left: 10px;')); ?></div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const hiddenInput = document.getElementById('brink_openingstijden_data'); const container = document.getElementById('ot-container'); let data = JSON.parse(hiddenInput.value || '[]');
        function render() {
            container.innerHTML = '';
            data.forEach((item, dayIndex) => {
                const card = document.createElement('div'); card.className = 'ot-day-card';
                let html = `<div class="ot-day-header"><div style="flex:2;"><label style="font-size:11px; color:#666;">Dag / Thema Naam</label><input type="text" class="ot-input" value="${escapeHtml(item.day)}" onchange="updateData(${dayIndex}, 'day', null, this.value)"></div><div style="flex:1;"><label style="font-size:11px; color:#666;">Tijden</label><input type="text" class="ot-input" value="${escapeHtml(item.time)}" onchange="updateData(${dayIndex}, 'time', null, this.value)"></div><div style="display:flex; gap:4px; align-items:flex-end; padding-bottom:1px;"><button type="button" class="button" title="Omhoog" onclick="moveDayUp(${dayIndex})" ${dayIndex === 0 ? 'disabled' : ''}>↑</button><button type="button" class="button" title="Omlaag" onclick="moveDayDown(${dayIndex})" ${dayIndex === data.length - 1 ? 'disabled' : ''}>↓</button><a href="#" class="ot-btn-danger" style="margin-left: 10px; align-self:center;" onclick="removeDay(${dayIndex}); return false;">Verwijder</a></div></div><div><strong style="font-size:12px;">Prijzen / Entree:</strong></div>`;
                item.prices.forEach((price, priceIndex) => {
                    html += `<div class="ot-price-row"><div style="flex:2;"><input type="text" class="ot-input" placeholder="Bijv. Paren of Heren" value="${escapeHtml(price.target)}" onchange="updateData(${dayIndex}, 'price_target', ${priceIndex}, this.value)"></div><div style="flex:1;"><input type="text" class="ot-input" placeholder="Bijv. 100€ of Gratis" value="${escapeHtml(price.price)}" onchange="updateData(${dayIndex}, 'price_value', ${priceIndex}, this.value)"></div><div><a href="#" class="ot-btn-danger" onclick="removePrice(${dayIndex}, ${priceIndex}); return false;" title="Verwijder Prijs">✖</a></div></div>`;
                });
                html += `<a href="#" class="button ot-btn-add-price" onclick="addPrice(${dayIndex}); return false;">+ Voeg prijsregel toe</a>`;
                card.innerHTML = html; container.appendChild(card);
            });
            hiddenInput.value = JSON.stringify(data);
        }
        window.updateData = function(dIdx, field, pIdx, val) {
            if (field === 'day') data[dIdx].day = val; if (field === 'time') data[dIdx].time = val;
            if (field === 'price_target') data[dIdx].prices[pIdx].target = val; if (field === 'price_value') data[dIdx].prices[pIdx].price = val;
            hiddenInput.value = JSON.stringify(data);
        };
        window.moveDayUp = function(idx) { if(idx > 0) { const temp = data[idx-1]; data[idx-1] = data[idx]; data[idx] = temp; render(); } };
        window.moveDayDown = function(idx) { if(idx < data.length - 1) { const temp = data[idx+1]; data[idx+1] = data[idx]; data[idx] = temp; render(); } };
        window.addDay = function() { data.push({ day: 'Nieuwe Dag', time: '14:00 - 20:00', prices: [{target: 'Paren', price: '0€'}] }); render(); };
        window.removeDay = function(idx) { if(confirm('Zeker weten?')) { data.splice(idx, 1); render(); } };
        window.addPrice = function(dIdx) { data[dIdx].prices.push({target: '', price: ''}); render(); };
        window.removePrice = function(dIdx, pIdx) { data[dIdx].prices.splice(pIdx, 1); render(); };
        function escapeHtml(unsafe) { return (unsafe || '').toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;"); }
        document.getElementById('btn-add-day').addEventListener('click', addDay); render();
    });
    </script>
    <?php
}

function brink_render_stats_tab() {
    global $wpdb; $table = $wpdb->prefix . 'brink_stats';
    $all_stats = $wpdb->get_results("SELECT form_type, DATE(created_at) as date_val, COUNT(*) as count FROM $table GROUP BY form_type, DATE(created_at) ORDER BY date_val ASC", ARRAY_A);
    $totals = array('ad' => 0, 'ervaring' => 0, 'contact' => 0, 'inschrijving' => 0, 'reactie' => 0);
    $raw_totals = $wpdb->get_results("SELECT form_type, COUNT(*) as count FROM $table GROUP BY form_type", ARRAY_A);
    foreach ($raw_totals as $row) { $totals[$row['form_type']] = $row['count']; }
    $grand_total = array_sum($totals);
    ?>
    <?php
    // BEVEILIGING/PERFORMANCE (v5.15.0): via het WP enqueue-systeem i.p.v. een losse CDN-tag —
    // gepinde versie, en de bijbehorende inline JS (hieronder) wordt via wp_add_inline_script()
    // gekoppeld zodat de laadvolgorde (eerst Chart.js, dan onze code) altijd correct is.
    wp_enqueue_script('brink-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', array(), '4.4.4', true);
    ?>
    <style>
        .stats-grid { display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap; }
        .stat-card { flex: 1; min-width: 150px; background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #ddd; text-align: center; }
        .stat-card h3 { margin: 0 0 10px 0; font-size: 14px; color: #666; }
        .stat-card .num { font-size: 32px; font-weight: bold; color: #b5121b; }
        .chart-controls { margin-bottom: 20px; text-align: right; }
        .chart-controls select { padding: 6px; border-radius: 4px; border: 1px solid #ccc; }
    </style>
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2>Formulier Statistieken & Interacties</h2>
        <a href="<?php echo esc_url(wp_nonce_url('?page=brink-posting&tab=stats&brink_action=reset_stats', 'brink_admin_action')); ?>" class="button button-secondary" style="color:red; border-color:red;" onclick="return confirm('Zeker weten?');">Reset & Opruimen</a>
    </div>
    <div class="stats-grid">
        <div class="stat-card"><h3>Totaal Inzendingen</h3><div class="num"><?php echo $grand_total; ?></div></div>
        <div class="stat-card"><h3>Advertenties</h3><div class="num"><?php echo $totals['ad']; ?></div></div>
        <div class="stat-card"><h3>Ervaringen</h3><div class="num"><?php echo $totals['ervaring']; ?></div></div>
        <div class="stat-card"><h3>Contactformulier</h3><div class="num"><?php echo $totals['contact']; ?></div></div>
        <div class="stat-card"><h3>Inschrijvingen</h3><div class="num"><?php echo $totals['inschrijving']; ?></div></div>
        <div class="stat-card" style="background:#eef5fe;"><h3>Reacties</h3><div class="num"><?php echo $totals['reactie']; ?></div></div>
    </div>
    <div class="chart-controls"><label>Weergave: </label><select id="chart-filter"><option value="all">Alle Tijd</option><option value="year">Dit Jaar</option><option value="month">Laatste 30 dagen</option><option value="week">Laatste 7 dagen</option></select></div>
    <div style="position: relative; height:400px; width:100%;"><canvas id="brinkStatsChart"></canvas></div>
    <?php
    ob_start();
    ?>
    document.addEventListener('DOMContentLoaded', function() {
        const rawData = <?php echo wp_json_encode($all_stats); ?>; const ctx = document.getElementById('brinkStatsChart').getContext('2d'); let myChart;
        const colors = { 'ad': '#b5121b', 'ervaring': '#f39c12', 'contact': '#27ae60', 'inschrijving': '#2980b9', 'reactie': '#3498db' };
        const labels = { 'ad': 'Advertenties', 'ervaring': 'Ervaringen', 'contact': 'Contact', 'inschrijving': 'Inschrijving', 'reactie': 'Reacties' };
        function processData(filter) {
            let filtered = []; const now = new Date();
            rawData.forEach(item => {
                const itemDate = new Date(item.date_val); const diffTime = Math.abs(now - itemDate); const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                if(filter === 'week' && diffDays > 7) return; if(filter === 'month' && diffDays > 30) return; if(filter === 'year' && itemDate.getFullYear() !== now.getFullYear()) return;
                let keyDate = item.date_val; if(filter === 'year') { keyDate = item.date_val.substring(0, 7); } filtered.push({ ...item, group_key: keyDate });
            });
            const uniqueDates = [...new Set(filtered.map(item => item.group_key))].sort();
            const datasets = Object.keys(colors).map(type => {
                const dataPoints = uniqueDates.map(date => { const match = filtered.filter(f => f.group_key === date && f.form_type === type); return match.reduce((sum, current) => sum + parseInt(current.count), 0); });
                return { label: labels[type], data: dataPoints, borderColor: colors[type], backgroundColor: colors[type] + '33', tension: 0.3, fill: true };
            }); return { labels: uniqueDates, datasets: datasets };
        }
        function renderChart(filter) {
            const chartData = processData(filter); if(myChart) myChart.destroy();
            myChart = new Chart(ctx, { type: 'line', data: chartData, options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, interaction: { mode: 'index', intersect: false } } });
        }
        renderChart('all'); document.getElementById('chart-filter').addEventListener('change', (e) => renderChart(e.target.value));
    });
    <?php
    $brink_chart_inline_js = ob_get_clean();
    wp_add_inline_script('brink-chartjs', $brink_chart_inline_js, 'after');
    ?>

    <?php
    // FUNCTIONALITEIT (v5.17.0): welk dag/thema uit de Openingstijden & Prijzen-shortcode trekt
    // de meeste interesse (scroll-into-view op de front-end, zie brink_frontend_openingstijden()).
    $day_views = get_option('brink_openingstijden_views', array());
    if (!is_array($day_views)) $day_views = array();
    arsort($day_views);
    $max_day_views = !empty($day_views) ? max($day_views) : 0;
    ?>
    <hr style="margin: 40px 0;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2>Interesse per Dag/Thema (Openingstijden)</h2>
        <?php if (!empty($day_views)): ?>
            <a href="<?php echo esc_url(wp_nonce_url('?page=brink-posting&tab=stats&brink_action=reset_day_views', 'brink_admin_action')); ?>" class="button button-secondary" style="color:red; border-color:red;" onclick="return confirm('Zeker weten?');">Reset & Opruimen</a>
        <?php endif; ?>
    </div>
    <p class="description">Gebaseerd op hoeveel unieke bezoekers per dag een specifiek dag/thema-blok daadwerkelijk in beeld hebben gekregen op de pagina met de <code>[openingstijden_prijzen]</code> shortcode.</p>
    <?php if (empty($day_views)): ?>
        <p>Nog geen data verzameld.</p>
    <?php else: ?>
        <div style="max-width:800px; margin-top:15px;">
            <?php foreach ($day_views as $day_label => $count): $pct = $max_day_views > 0 ? round(($count / $max_day_views) * 100) : 0; ?>
                <div style="margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:4px;">
                        <strong><?php echo esc_html($day_label); ?></strong><span><?php echo (int) $count; ?> weergaven</span>
                    </div>
                    <div style="background:#f0f0f1; border-radius:4px; overflow:hidden; height:10px;">
                        <div style="background:#b5121b; height:100%; width:<?php echo esc_attr($pct); ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
}

add_action('add_meta_boxes', 'brink_add_data_meta_box');
function brink_add_data_meta_box() { add_meta_box('brink_data_meta_box', 'Ingevulde Gegevens', 'brink_render_data_meta_box', 'post', 'normal', 'high'); }
function brink_render_data_meta_box($post) {
    $ervaringen_cat = intval(get_option('brink_ad_ervaringen_category')); $contact_cat = intval(get_option('brink_ad_contact_category')); $inschrijving_cat = intval(get_option('brink_ad_inschrijving_category'));
    echo '<table class="form-table" style="width:100%; text-align:left;">';
    if ($inschrijving_cat && has_category($inschrijving_cat, $post->ID)) {
        brink_display_meta_row('Voor- en achternaam heer', get_post_meta($post->ID, 'inschrijving_heer', true));
        brink_display_meta_row('Voor- en achternaam dame', get_post_meta($post->ID, 'inschrijving_dame', true));
        brink_display_meta_row('Postcode / Woonplaats', get_post_meta($post->ID, 'inschrijving_postcode', true));
        brink_display_meta_row('Land', get_post_meta($post->ID, 'inschrijving_land', true));
        brink_display_meta_row('Telefoonnummer', get_post_meta($post->ID, 'inschrijving_phone', true));
        brink_display_meta_row('E-mailadres', get_post_meta($post->ID, 'inschrijving_email', true));
        brink_display_meta_row('Geboortedatum', get_post_meta($post->ID, 'inschrijving_dob', true));
        brink_display_meta_row('Inschrijfdatum', get_post_meta($post->ID, 'inschrijving_date', true));
    } elseif ($contact_cat && has_category($contact_cat, $post->ID)) {
        brink_display_meta_row('Naam', get_post_meta($post->ID, 'contact_name', true));
        brink_display_meta_row('E-mailadres', get_post_meta($post->ID, 'contact_email', true));
        brink_display_meta_row('Telefoonnummer', get_post_meta($post->ID, 'contact_phone', true));
    } elseif ($ervaringen_cat && has_category($ervaringen_cat, $post->ID)) {
        brink_display_meta_row('Naam Schrijver', get_post_meta($post->ID, 'ad_name', true));
        brink_display_meta_row('E-mailadres', get_post_meta($post->ID, 'ad_email', true));
    } elseif (get_post_meta($post->ID, 'delete_token', true)) {
        brink_display_meta_row('Naam Adverteerder', get_post_meta($post->ID, 'ad_name', true));
        brink_display_meta_row('E-mailadres', get_post_meta($post->ID, 'ad_email', true));
        brink_display_meta_row('Woonplaats', get_post_meta($post->ID, 'ad_location', true));
        $exp = get_post_meta($post->ID, 'expiration_date', true); brink_display_meta_row('Verloopt op (Timestamp)', $exp ? wp_date('d-m-Y H:i', $exp) : '-');
    } else { echo '<tr><td>Algemeen Nieuws of Vacature bericht.</td></tr>'; }
    echo '</table>';
}
function brink_display_meta_row($label, $value) {
    if (!$value) $value = '-';
    echo '<tr><th style="width:200px; padding:10px 0; border-bottom:1px solid #eee;"><strong>' . esc_html($label) . '</strong></th><td style="padding:10px 0; border-bottom:1px solid #eee;">' . wp_kses_post($value) . '</td></tr>';
}

// FUNCTIONALITEIT (v5.17.0): serverside zoeken + paginering voor het dashboard, ter vervanging
// van de oude aanpak (élke rij ongepagineerd laden en met JavaScript filteren) — dat schaalde
// niet meer zodra er veel advertenties/inzendingen zijn. Deze posts_where-filter breidt een
// WP_Query uit met een titel-OF-metaveld zoekopdracht, maar doet niets zolang de query var leeg is.
add_filter('posts_where', 'brink_fp_dashboard_search_where', 10, 2);
function brink_fp_dashboard_search_where($where, $query) {
    $term = $query->get('brink_search_term');
    if ($term === '' || $term === false) return $where;

    global $wpdb;
    $like = '%' . $wpdb->esc_like($term) . '%';
    $conditions = array($wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", $like));

    $meta_keys = (array) $query->get('brink_search_meta_keys');
    foreach ($meta_keys as $meta_key) {
        $conditions[] = $wpdb->prepare(
            "EXISTS (SELECT 1 FROM {$wpdb->postmeta} bpm WHERE bpm.post_id = {$wpdb->posts}.ID AND bpm.meta_key = %s AND bpm.meta_value LIKE %s)",
            $meta_key, $like
        );
    }

    $where .= ' AND (' . implode(' OR ', $conditions) . ')';
    return $where;
}

// Bouwt een gepagineerde, doorzoekbare WP_Query voor één dashboard-sectie.
function brink_fp_query_dashboard_section($args, $search_term, $meta_keys, $paged) {
    $args['posts_per_page'] = 5;
    $args['paged'] = max(1, (int) $paged);
    $args['ignore_sticky_posts'] = true;
    if ($search_term !== '') {
        $args['brink_search_term'] = $search_term;
        $args['brink_search_meta_keys'] = $meta_keys;
    }
    return new WP_Query($args);
}

// Print echte (server-rendered) paginatieknoppen voor één dashboard-sectie.
function brink_fp_render_pagination($param_name, $current_page, $max_pages) {
    if ($max_pages <= 1) return;
    echo '<div class="brink-pagination">';
    for ($i = 1; $i <= $max_pages; $i++) {
        $url = add_query_arg($param_name, $i, brink_current_url());
        $class = ($i === $current_page) ? 'active' : '';
        echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . (int) $i . '</a>';
    }
    echo '</div>';
}

// Print verborgen velden zodat het zoekformulier van één sectie de paginastatus/zoekterm van de
// andere secties niet ongewild reset wanneer het wordt verzonden.
function brink_fp_dashboard_preserve_fields($exclude_suffix) {
    $keys = array('search_ads', 'paged_ads', 'search_erv', 'paged_erv', 'search_con', 'paged_con', 'search_ins', 'paged_ins');
    foreach ($keys as $key) {
        if (substr($key, -strlen($exclude_suffix)) === $exclude_suffix) continue;
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($_GET[$key]))) . '">';
        }
    }
}

function brink_render_dashboard_tab() {
    $ervaringen_cat = intval(get_option('brink_ad_ervaringen_category')); $contact_cat = intval(get_option('brink_ad_contact_category')); $inschrijving_cat = intval(get_option('brink_ad_inschrijving_category'));
    $speciale_cats = array_filter(array($ervaringen_cat, $contact_cat, $inschrijving_cat));

    // FUNCTIONALITEIT (v5.17.0): zoekterm + paginanummer per sectie uit de GET-parameters lezen.
    $search_ads = isset($_GET['search_ads']) ? sanitize_text_field(wp_unslash($_GET['search_ads'])) : '';
    $paged_ads  = isset($_GET['paged_ads']) ? max(1, intval($_GET['paged_ads'])) : 1;
    $search_erv = isset($_GET['search_erv']) ? sanitize_text_field(wp_unslash($_GET['search_erv'])) : '';
    $paged_erv  = isset($_GET['paged_erv']) ? max(1, intval($_GET['paged_erv'])) : 1;
    $search_con = isset($_GET['search_con']) ? sanitize_text_field(wp_unslash($_GET['search_con'])) : '';
    $paged_con  = isset($_GET['paged_con']) ? max(1, intval($_GET['paged_con'])) : 1;
    $search_ins = isset($_GET['search_ins']) ? sanitize_text_field(wp_unslash($_GET['search_ins'])) : '';
    $paged_ins  = isset($_GET['paged_ins']) ? max(1, intval($_GET['paged_ins'])) : 1;

    $args_ads = array('post_type' => 'post', 'post_status' => 'publish', 'meta_query' => array(array('key' => 'expiration_date', 'compare' => 'EXISTS')));
    if (!empty($speciale_cats)) $args_ads['category__not_in'] = $speciale_cats;
    $query_ads = brink_fp_query_dashboard_section($args_ads, $search_ads, array('ad_email', 'ad_name'), $paged_ads);
    $ads = $query_ads->posts;

    $args_ervaringen = array('post_type' => 'post', 'post_status' => array('publish', 'pending', 'draft'), 'meta_query' => array(array('key' => 'delete_token', 'compare' => 'EXISTS')));
    if ($ervaringen_cat) { $args_ervaringen['category__in'] = array($ervaringen_cat); $args_ervaringen['meta_query'][] = array('key' => 'expiration_date', 'compare' => 'NOT EXISTS'); } else { $args_ervaringen['post__in'] = array(0); }
    $query_erv = brink_fp_query_dashboard_section($args_ervaringen, $search_erv, array('ad_email', 'ad_name'), $paged_erv);
    $ervaringen = $query_erv->posts;

    $args_contact = array('post_type' => 'post', 'post_status' => array('publish', 'private'));
    if ($contact_cat) { $args_contact['category__in'] = array($contact_cat); } else { $args_contact['post__in'] = array(0); }
    $query_con = brink_fp_query_dashboard_section($args_contact, $search_con, array('contact_name', 'contact_email'), $paged_con);
    $contacten = $query_con->posts;

    $args_inschrijving = array('post_type' => 'post', 'post_status' => array('publish', 'private'));
    if ($inschrijving_cat) { $args_inschrijving['category__in'] = array($inschrijving_cat); } else { $args_inschrijving['post__in'] = array(0); }
    $query_ins = brink_fp_query_dashboard_section($args_inschrijving, $search_ins, array('inschrijving_heer', 'inschrijving_dame', 'inschrijving_email'), $paged_ins);
    $inschrijvingen = $query_ins->posts;

    $placeholder_id = get_option('brink_ad_placeholder_image');
    ?>
    <style>.brink-search-bar { width: 100%; max-width: 300px; padding: 5px 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px; } .brink-pagination { margin-top: 10px; display: flex; gap: 5px; justify-content: flex-end; } .brink-pagination a { padding: 4px 10px; cursor: pointer; border: 1px solid #ddd; background: #f9f9f9; border-radius: 3px; text-decoration: none; color: #2c3338; } .brink-pagination a.active { background: #b5121b; color: #fff; border-color: #b5121b; }</style>

    <h2>1. Actieve Advertenties</h2>
    <form method="get">
        <input type="hidden" name="page" value="brink-posting"><input type="hidden" name="tab" value="dashboard">
        <?php brink_fp_dashboard_preserve_fields('_ads'); ?>
        <input type="text" name="search_ads" class="brink-search-bar" placeholder="Zoek in advertenties (titel, naam, e-mail)..." value="<?php echo esc_attr($search_ads); ?>">
        <button type="submit" class="button">Zoeken</button>
        <?php if ($search_ads !== ''): ?><a href="<?php echo esc_url(remove_query_arg(array('search_ads', 'paged_ads'))); ?>" class="button">Wissen</a><?php endif; ?>
    </form>
    <table class="wp-list-table widefat fixed striped" id="table-ads">
        <thead><tr><th style="width:60px;">Foto</th><th>Titel</th><th>ID</th><th>Weergaven</th><th>Adverteerder</th><th>Verloopt op</th><th>Acties</th></tr></thead>
        <tbody>
            <?php if (empty($ads)): ?><tr><td colspan="7"><?php echo $search_ads !== '' ? 'Geen resultaten voor "' . esc_html($search_ads) . '".' : 'Geen actieve advertenties gevonden.'; ?></td></tr>
            <?php else: foreach($ads as $ad): 
                    $email = get_post_meta($ad->ID, 'ad_email', true); $name = get_post_meta($ad->ID, 'ad_name', true);
                    $views = get_post_meta($ad->ID, 'ad_views', true) ?: 0; $exp = get_post_meta($ad->ID, 'expiration_date', true);
                    $thumb_id = get_post_thumbnail_id($ad->ID) ?: $placeholder_id; $thumb_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'thumbnail') : '';
                ?>
                <tr class="item-row">
                    <td><?php if($thumb_url): ?><img src="<?php echo esc_url($thumb_url); ?>" style="width:50px; height:50px; object-fit:cover; border-radius:4px;"><?php endif; ?></td>
                    <td><strong><a href="<?php echo get_edit_post_link($ad->ID); ?>"><?php echo esc_html($ad->post_title); ?></a></strong></td>
                    <td>#<?php echo $ad->ID; ?></td><td><?php echo esc_html($views); ?> x</td>
                    <td><?php echo esc_html($name); ?><br><a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></td>
                    <td><?php echo $exp ? wp_date('d-m-Y H:i', $exp) : 'Onbekend'; ?></td>
                    <td>
                        <a href="<?php echo esc_url(wp_nonce_url('?page=brink-posting&tab=dashboard&brink_action=extend&ad_id=' . $ad->ID, 'brink_admin_action')); ?>" class="button button-small">+30 Dagen</a>
                        <a href="<?php echo esc_url(wp_nonce_url('?page=brink-posting&tab=dashboard&brink_action=resend_mail&ad_id=' . $ad->ID, 'brink_admin_action')); ?>" class="button button-small">Mail Link</a>
                        <a href="#" class="button button-small" style="color:#d63638; border-color:#d63638;" onclick="brinkOpenModal('delete_reason', <?php echo $ad->ID; ?>, '<?php echo esc_js($ad->post_title); ?>'); return false;">Reden</a>
                        <a href="#" class="button button-small" style="background:#d63638; color:#fff; border-color:#d63638;" onclick="brinkOpenModal('delete_ban', <?php echo $ad->ID; ?>, '<?php echo esc_js($ad->post_title); ?>'); return false;">Ban</a>
                        <a href="<?php echo esc_url(wp_nonce_url('?page=brink-posting&tab=dashboard&brink_action=delete&ad_id=' . $ad->ID, 'brink_admin_action')); ?>" class="button button-small" style="color:red; border-color:red;" onclick="return confirm('Verwijderen?');">Verwijder</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php brink_fp_render_pagination('paged_ads', $paged_ads, $query_ads->max_num_pages); ?>
    <hr style="margin: 40px 0;">

    <h2>2. Ingestuurde Ervaringen</h2>
    <form method="get">
        <input type="hidden" name="page" value="brink-posting"><input type="hidden" name="tab" value="dashboard">
        <?php brink_fp_dashboard_preserve_fields('_erv'); ?>
        <input type="text" name="search_erv" class="brink-search-bar" placeholder="Zoek in ervaringen (titel, naam, e-mail)..." value="<?php echo esc_attr($search_erv); ?>">
        <button type="submit" class="button">Zoeken</button>
        <?php if ($search_erv !== ''): ?><a href="<?php echo esc_url(remove_query_arg(array('search_erv', 'paged_erv'))); ?>" class="button">Wissen</a><?php endif; ?>
    </form>
    <table class="wp-list-table widefat fixed striped" id="table-erv">
        <thead><tr><th>Titel</th><th>Status</th><th>ID</th><th>Schrijver</th><th>Acties</th></tr></thead>
        <tbody>
            <?php if (empty($ervaringen)): ?><tr><td colspan="5"><?php echo $search_erv !== '' ? 'Geen resultaten voor "' . esc_html($search_erv) . '".' : 'Geen ervaringen gevonden.'; ?></td></tr>
            <?php else: foreach($ervaringen as $erv): 
                    $email = get_post_meta($erv->ID, 'ad_email', true); $name = get_post_meta($erv->ID, 'ad_name', true);
                    $status_label = ($erv->post_status === 'pending') ? '<span style="color:orange; font-weight:bold;">Wacht op keuring</span>' : '<span style="color:green; font-weight:bold;">Live</span>';
                ?>
                <tr class="item-row">
                    <td><strong><a href="<?php echo get_edit_post_link($erv->ID); ?>"><?php echo esc_html($erv->post_title); ?></a></strong></td>
                    <td><?php echo $status_label; ?></td><td>#<?php echo $erv->ID; ?></td>
                    <td><?php echo esc_html($name); ?></td>
                    <td>
                        <a href="<?php echo get_edit_post_link($erv->ID); ?>" class="button button-small button-primary">Keuren / Details</a>
                        <a href="<?php echo esc_url(wp_nonce_url('?page=brink-posting&tab=dashboard&brink_action=resend_mail&ad_id=' . $erv->ID, 'brink_admin_action')); ?>" class="button button-small">Mail Link</a>
                        <a href="#" class="button button-small" style="color:#d63638; border-color:#d63638;" onclick="brinkOpenModal('delete_reason', <?php echo $erv->ID; ?>, '<?php echo esc_js($erv->post_title); ?>'); return false;">Reden</a>
                        <a href="#" class="button button-small" style="background:#d63638; color:#fff; border-color:#d63638;" onclick="brinkOpenModal('delete_ban', <?php echo $erv->ID; ?>, '<?php echo esc_js($erv->post_title); ?>'); return false;">Ban</a>
                        <a href="<?php echo esc_url(wp_nonce_url('?page=brink-posting&tab=dashboard&brink_action=delete&ad_id=' . $erv->ID, 'brink_admin_action')); ?>" class="button button-small" style="color:red; border-color:red;" onclick="return confirm('Verwijderen?');">Verwijder</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php brink_fp_render_pagination('paged_erv', $paged_erv, $query_erv->max_num_pages); ?>
    <hr style="margin: 40px 0;">

    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2>3. Contactformulier Inzendingen</h2>
        <?php if (!empty($contacten)): ?>
            <a href="<?php echo esc_url(wp_nonce_url('?page=brink-posting&tab=dashboard&brink_action=delete_all_contacts', 'brink_admin_action')); ?>" class="button" style="color:red; border-color:red;" onclick="return confirm('Weet je het absoluut zeker? ALLE contactinzendingen worden nu definitief verwijderd.');">Alles Verwijderen</a>
        <?php endif; ?>
    </div>
    <form method="get">
        <input type="hidden" name="page" value="brink-posting"><input type="hidden" name="tab" value="dashboard">
        <?php brink_fp_dashboard_preserve_fields('_con'); ?>
        <input type="text" name="search_con" class="brink-search-bar" placeholder="Zoek in contactinzendingen (onderwerp, naam, e-mail)..." value="<?php echo esc_attr($search_con); ?>">
        <button type="submit" class="button">Zoeken</button>
        <?php if ($search_con !== ''): ?><a href="<?php echo esc_url(remove_query_arg(array('search_con', 'paged_con'))); ?>" class="button">Wissen</a><?php endif; ?>
    </form>
    <table class="wp-list-table widefat fixed striped" id="table-con">
        <thead><tr><th>Onderwerp</th><th>Naam</th><th>E-mailadres</th><th>Datum</th><th>Acties</th></tr></thead>
        <tbody>
            <?php if (empty($contacten)): ?><tr><td colspan="5"><?php echo $search_con !== '' ? 'Geen resultaten voor "' . esc_html($search_con) . '".' : 'Geen inzendingen gevonden.'; ?></td></tr>
            <?php else: foreach($contacten as $con): 
                    $name = get_post_meta($con->ID, 'contact_name', true); $email = get_post_meta($con->ID, 'contact_email', true);
                ?>
                <tr class="item-row">
                    <td><strong><a href="<?php echo get_edit_post_link($con->ID); ?>"><?php echo esc_html($con->post_title); ?></a></strong></td>
                    <td><?php echo esc_html($name); ?></td><td><?php echo esc_html($email); ?></td><td><?php echo wp_date('d-m-Y H:i', strtotime($con->post_date)); ?></td>
                    <td>
                        <a href="<?php echo get_edit_post_link($con->ID); ?>" class="button button-small">Bekijk Details</a>
                        <a href="<?php echo esc_url(wp_nonce_url('?page=brink-posting&tab=dashboard&brink_action=delete&ad_id=' . $con->ID, 'brink_admin_action')); ?>" class="button button-small" style="color:red; border-color:red;" onclick="return confirm('Verwijderen?');">Verwijder</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php brink_fp_render_pagination('paged_con', $paged_con, $query_con->max_num_pages); ?>
    <hr style="margin: 40px 0;">

    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2>4. Inschrijfformulieren</h2>
        <?php if (!empty($inschrijvingen)): ?>
            <a href="<?php echo esc_url(wp_nonce_url('?page=brink-posting&tab=dashboard&brink_action=delete_all_inschrijvingen', 'brink_admin_action')); ?>" class="button" style="color:red; border-color:red;" onclick="return confirm('Weet je het absoluut zeker? ALLE inschrijvingen worden nu definitief verwijderd.');">Alles Verwijderen</a>
        <?php endif; ?>
    </div>
    <form method="get">
        <input type="hidden" name="page" value="brink-posting"><input type="hidden" name="tab" value="dashboard">
        <?php brink_fp_dashboard_preserve_fields('_ins'); ?>
        <input type="text" name="search_ins" class="brink-search-bar" placeholder="Zoek in inschrijvingen (naam, e-mail)..." value="<?php echo esc_attr($search_ins); ?>">
        <button type="submit" class="button">Zoeken</button>
        <?php if ($search_ins !== ''): ?><a href="<?php echo esc_url(remove_query_arg(array('search_ins', 'paged_ins'))); ?>" class="button">Wissen</a><?php endif; ?>
    </form>
    <table class="wp-list-table widefat fixed striped" id="table-ins">
        <thead><tr><th>Koppel / Persoon</th><th>E-mailadres</th><th>Woonplaats</th><th>Inschrijfdatum</th><th>Acties</th></tr></thead>
        <tbody>
            <?php if (empty($inschrijvingen)): ?><tr><td colspan="5"><?php echo $search_ins !== '' ? 'Geen resultaten voor "' . esc_html($search_ins) . '".' : 'Geen inschrijvingen gevonden.'; ?></td></tr>
            <?php else: foreach($inschrijvingen as $ins): 
                    $heer = get_post_meta($ins->ID, 'inschrijving_heer', true); $dame = get_post_meta($ins->ID, 'inschrijving_dame', true);
                    $email = get_post_meta($ins->ID, 'inschrijving_email', true); $stad = get_post_meta($ins->ID, 'inschrijving_postcode', true);
                    $title = trim($heer . ' & ' . $dame, ' &');
                ?>
                <tr class="item-row">
                    <td><strong><a href="<?php echo get_edit_post_link($ins->ID); ?>"><?php echo esc_html($title); ?></a></strong></td>
                    <td><?php echo esc_html($email); ?></td><td><?php echo esc_html($stad); ?></td><td><?php echo wp_date('d-m-Y', strtotime($ins->post_date)); ?></td>
                    <td>
                        <a href="<?php echo get_edit_post_link($ins->ID); ?>" class="button button-small">Bekijk Details</a>
                        <a href="<?php echo esc_url(wp_nonce_url('?page=brink-posting&tab=dashboard&brink_action=delete&ad_id=' . $ins->ID, 'brink_admin_action')); ?>" class="button button-small" style="color:red; border-color:red;" onclick="return confirm('Verwijderen?');">Verwijder</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php brink_fp_render_pagination('paged_ins', $paged_ins, $query_ins->max_num_pages); ?>

    <!-- Modals voor Verwijderen met reden & Ban -->
    <div id="brink-action-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999;">
        <div style="background:#fff; width:450px; margin:100px auto; padding:25px; border-radius:8px; box-shadow:0 4px 15px rgba(0,0,0,0.2);">
            <h3 id="modal-title" style="margin-top:0; color:#d63638;">Actie Uitvoeren</h3>
            <p id="modal-desc" style="font-size:14px; margin-bottom:15px;">Kies een optie.</p>
            <select id="modal-reason-select" style="width:100%; margin-bottom:15px; padding:5px;">
                <option value="Je bericht voldoet niet aan de huisregels.">Voldoet niet aan de huisregels.</option>
                <option value="Commerciële aanbiedingen zijn niet toegestaan.">Commerciële aanbiedingen zijn niet toegestaan.</option>
                <option value="Ongepaste foto of ongepaste tekst.">Ongepaste foto of ongepaste tekst.</option>
                <option value="Anders (vul hieronder in)">Anders (vul hieronder je eigen reden in)</option>
            </select>
            <textarea id="modal-reason-custom" placeholder="Typ hier je eigen reden..." style="width:100%; display:none; margin-bottom:15px;" rows="4"></textarea>
            
            <input type="hidden" id="modal-ad-id" value="">
            <input type="hidden" id="modal-action-type" value="">
            
            <div style="text-align:right;">
                <button class="button" onclick="document.getElementById('brink-action-modal').style.display='none'">Annuleren</button>
                <button id="modal-submit-btn" class="button button-primary" style="margin-left:10px;" onclick="brinkSubmitModalAction()">Bevestigen & Mailen</button>
            </div>
        </div>
    </div>

    <script>
    var brinkAdminNonce = <?php echo wp_json_encode(wp_create_nonce('brink_admin_action')); ?>;
    function brinkOpenModal(action, id, title) {
        document.getElementById('brink-action-modal').style.display = 'block';
        document.getElementById('modal-ad-id').value = id;
        document.getElementById('modal-action-type').value = action;
        
        var isBan = (action === 'delete_ban');
        document.getElementById('modal-title').innerText = isBan ? 'Verwijderen & Verbannen' : 'Weigeren / Verwijderen';
        document.getElementById('modal-title').style.color = isBan ? '#d63638' : '#2271b1';
        
        var descHtml = isBan 
            ? 'Let op: <b>' + title + '</b> wordt permanent verwijderd en de adverteerder wordt <b>op de banlijst geplaatst</b>. Kies de reden voor de e-mail.' 
            : 'Kies een reden waarom <b>' + title + '</b> wordt verwijderd of geweigerd. De adverteerder ontvangt deze uitleg per e-mail.';
        document.getElementById('modal-desc').innerHTML = descHtml;
        
        document.getElementById('modal-reason-select').onchange = function() {
            document.getElementById('modal-reason-custom').style.display = this.value === 'Anders (vul hieronder in)' ? 'block' : 'none';
        };
    }
    
    function brinkSubmitModalAction() {
        var action = document.getElementById('modal-action-type').value;
        var id = document.getElementById('modal-ad-id').value;
        var sel = document.getElementById('modal-reason-select').value;
        var reason = sel === 'Anders (vul hieronder in)' ? document.getElementById('modal-reason-custom').value : sel;
        
        var btn = document.getElementById('modal-submit-btn');
        btn.innerText = 'Verwerken...';
        btn.disabled = true;
        
        window.location.href = '?page=brink-posting&tab=dashboard&brink_action=' + action + '&ad_id=' + id + '&reason=' + encodeURIComponent(reason) + '&_wpnonce=' + encodeURIComponent(brinkAdminNonce);
    }
    </script>
    <?php
}

function brink_render_colors_tab() {
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('brink_ad_colors_group'); ?>
        <h3>Instellingen: Advertenties [mystique_advertentie_formulier]</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Toegestane Categorieën (ID's)</th><td><input type="text" name="brink_ad_allowed_categories" value="<?php echo esc_attr(get_option('brink_ad_allowed_categories')); ?>" placeholder="bijv. 4,7,12" style="width:300px;" /></td></tr>
            <tr valign="top"><th scope="row">Formulier URL</th><td><input type="url" name="brink_ad_form_page_url" value="<?php echo esc_attr(get_option('brink_ad_form_page_url')); ?>" style="width:100%; max-width:400px;" /></td></tr>
            <tr valign="top"><th scope="row">Bedankpagina Redirect (Na Plaatsen)</th><td><input type="url" name="brink_ad_redirect_url" value="<?php echo esc_attr(get_option('brink_ad_redirect_url')); ?>" style="width:100%; max-width:400px;" /></td></tr>
            <tr valign="top"><th scope="row">Verwijderpagina Redirect (Na Wissen)</th><td><input type="url" name="brink_ad_delete_redirect_url" value="<?php echo esc_attr(get_option('brink_ad_delete_redirect_url')); ?>" placeholder="Laat leeg voor standaard melding" style="width:100%; max-width:400px;" /></td></tr>
        </table><hr>
        <h3>Instellingen: Ervaringen [mystique_ervaringen_formulier]</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Categorie ID (Verplicht!)</th><td><input type="number" name="brink_ad_ervaringen_category" value="<?php echo esc_attr(get_option('brink_ad_ervaringen_category')); ?>" style="width:100px;" /></td></tr>
            <tr valign="top"><th scope="row">Formulier URL</th><td><input type="url" name="brink_ad_ervaringen_form_page_url" value="<?php echo esc_attr(get_option('brink_ad_ervaringen_form_page_url')); ?>" style="width:100%; max-width:400px;" /></td></tr>
            <tr valign="top"><th scope="row">Bedankpagina Redirect (Na Plaatsen)</th><td><input type="url" name="brink_ad_ervaringen_redirect_url" value="<?php echo esc_attr(get_option('brink_ad_ervaringen_redirect_url')); ?>" style="width:100%; max-width:400px;" /></td></tr>
            <tr valign="top"><th scope="row">Verwijderpagina Redirect (Na Wissen)</th><td><input type="url" name="brink_ad_ervaringen_delete_redirect_url" value="<?php echo esc_attr(get_option('brink_ad_ervaringen_delete_redirect_url')); ?>" placeholder="Laat leeg voor standaard melding" style="width:100%; max-width:400px;" /></td></tr>
        </table><hr>
        <h3>Instellingen: Contactformulier [mystique_contactformulier]</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Categorie ID (Verplicht!)</th><td><input type="number" name="brink_ad_contact_category" value="<?php echo esc_attr(get_option('brink_ad_contact_category')); ?>" style="width:100px;" /></td></tr>
            <tr valign="top"><th scope="row">Bedankpagina Redirect</th><td><input type="url" name="brink_ad_contact_redirect_url" value="<?php echo esc_attr(get_option('brink_ad_contact_redirect_url')); ?>" style="width:100%; max-width:400px;" /></td></tr>
        </table><hr>
        <h3>Instellingen: Inschrijfformulier [mystique_inschrijfformulier]</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Categorie ID (Verplicht!)</th><td><input type="number" name="brink_ad_inschrijving_category" value="<?php echo esc_attr(get_option('brink_ad_inschrijving_category')); ?>" style="width:100px;" /></td></tr>
            <tr valign="top"><th scope="row">Bedankpagina Redirect</th><td><input type="url" name="brink_ad_inschrijving_redirect_url" value="<?php echo esc_attr(get_option('brink_ad_inschrijving_redirect_url')); ?>" style="width:100%; max-width:400px;" /></td></tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php
}

function brink_render_email_tab() {
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('brink_ad_email_group'); ?>
        <h3>Ontvangers AVG Formulieren (Meerdere? Scheid met komma)</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Mailadres(sen) Contact</th><td><input type="text" name="brink_ad_contact_emails" value="<?php echo esc_attr(get_option('brink_ad_contact_emails')); ?>" style="width:100%; max-width:600px;" /></td></tr>
            <tr valign="top"><th scope="row">Mailadres(sen) Inschrijving</th><td><input type="text" name="brink_ad_inschrijving_emails" value="<?php echo esc_attr(get_option('brink_ad_inschrijving_emails')); ?>" style="width:100%; max-width:600px;" /></td></tr>
        </table><hr>
        
        <h3>Template: E-mail Verificatie (Dubbele Opt-in)</h3>
        <p class="description">Gebruik variabelen: <code>{naam}</code>, <code>{titel}</code>, <code>{verify_link}</code></p>
        <table class="form-table">
            <tr valign="top"><th scope="row">Onderwerp</th><td><input type="text" name="brink_ad_verify_email_subject" value="<?php echo esc_attr(get_option('brink_ad_verify_email_subject')); ?>" style="width:100%; max-width:600px;" /></td></tr>
            <tr valign="top"><th scope="row">Bericht</th><td><textarea name="brink_ad_verify_email_body" rows="4" style="width:100%; max-width:600px;"><?php echo esc_textarea(get_option('brink_ad_verify_email_body')); ?></textarea></td></tr>
        </table><hr>

        <h3>Template: Advertenties (Live)</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Onderwerp</th><td><input type="text" name="brink_ad_email_subject" value="<?php echo esc_attr(get_option('brink_ad_email_subject')); ?>" style="width:100%; max-width:600px;" /></td></tr>
            <tr valign="top"><th scope="row">Bericht</th><td><textarea name="brink_ad_email_body" rows="4" style="width:100%; max-width:600px;"><?php echo esc_textarea(get_option('brink_ad_email_body')); ?></textarea></td></tr>
        </table><hr>
        <h3>Template: Ervaringen</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Onderwerp</th><td><input type="text" name="brink_ad_ervaringen_email_subject" value="<?php echo esc_attr(get_option('brink_ad_ervaringen_email_subject')); ?>" style="width:100%; max-width:600px;" /></td></tr>
            <tr valign="top"><th scope="row">Bericht</th><td><textarea name="brink_ad_ervaringen_email_body" rows="4" style="width:100%; max-width:600px;"><?php echo esc_textarea(get_option('brink_ad_ervaringen_email_body')); ?></textarea></td></tr>
        </table><hr>
        
        <h3>No-Reply Instellingen (Voor Verwijderen & Ban)</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Gebruik No-Reply adres?</th><td>
                <select name="brink_ad_noreply_enabled">
                    <option value="0" <?php selected(get_option('brink_ad_noreply_enabled'), '0'); ?>>UIT: Adverteerders kunnen reageren op waarschuwingsmails</option>
                    <option value="1" <?php selected(get_option('brink_ad_noreply_enabled'), '1'); ?>>AAN: Voorkom dat adverteerders kunnen reageren (No-Reply)</option>
                </select>
            </td></tr>
            <tr valign="top"><th scope="row">No-Reply E-mailadres</th><td><input type="email" name="brink_ad_noreply_email" value="<?php echo esc_attr(get_option('brink_ad_noreply_email', 'noreply@' . wp_parse_url(home_url(), PHP_URL_HOST))); ?>" style="width:100%; max-width:400px;" />
            <p class="description">Naar dit adres worden antwoorden gestuurd als de adverteerder toch op 'Beantwoorden' klikt.</p></td></tr>
        </table><hr>

        <h3>Template: Verwijderd met reden</h3>
        <p class="description">Gebruik variabelen: <code>{naam}</code>, <code>{titel}</code>, <code>{reden}</code></p>
        <table class="form-table">
            <tr valign="top"><th scope="row">Onderwerp</th><td><input type="text" name="brink_ad_delete_reason_email_subject" value="<?php echo esc_attr(get_option('brink_ad_delete_reason_email_subject')); ?>" style="width:100%; max-width:600px;" /></td></tr>
            <tr valign="top"><th scope="row">Bericht</th><td><textarea name="brink_ad_delete_reason_email_body" rows="4" style="width:100%; max-width:600px;"><?php echo esc_textarea(get_option('brink_ad_delete_reason_email_body')); ?></textarea></td></tr>
        </table><hr>
        <h3>Template: Verbannen (Ban)</h3>
        <p class="description">Gebruik variabelen: <code>{naam}</code>, <code>{titel}</code>, <code>{reden}</code></p>
        <table class="form-table">
            <tr valign="top"><th scope="row">Onderwerp</th><td><input type="text" name="brink_ad_ban_email_subject" value="<?php echo esc_attr(get_option('brink_ad_ban_email_subject')); ?>" style="width:100%; max-width:600px;" /></td></tr>
            <tr valign="top"><th scope="row">Bericht</th><td><textarea name="brink_ad_ban_email_body" rows="4" style="width:100%; max-width:600px;"><?php echo esc_textarea(get_option('brink_ad_ban_email_body')); ?></textarea></td></tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php
}

function brink_render_advanced_tab() {
    $placeholder_id = get_option('brink_ad_placeholder_image'); $placeholder_url = $placeholder_id ? wp_get_attachment_image_url($placeholder_id, 'medium') : '';
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('brink_ad_advanced_group'); ?>
        
        <h3>E-mail Verificatie (Dubbele Opt-in)</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Verplichte e-mail verificatie via link?</th><td>
                <select name="brink_ad_email_verification_enabled">
                    <option value="1" <?php selected(get_option('brink_ad_email_verification_enabled'), '1'); ?>>AAN: Adverteerders moeten klikken op een bevestigingslink in de e-mail</option>
                    <option value="0" <?php selected(get_option('brink_ad_email_verification_enabled'), '0'); ?>>UIT: Advertentie is altijd direct live (Aanbevolen voor snelheid)</option>
                </select>
                <p class="description">Als dit 'AAN' staat, worden nieuwe advertenties als 'Concept' opgeslagen totdat de bezoeker op de activatielink in de e-mail klikt.</p>
            </td></tr>
        </table><hr>

        <h3>Geblokkeerde Adverteerders (Banlijst)</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Geblokkeerde E-mailadressen<br><small>(1 per regel)</small></th>
                <td>
                    <textarea name="brink_ad_banned_emails" rows="6" style="width:100%; max-width:400px;"><?php echo esc_textarea(get_option('brink_ad_banned_emails')); ?></textarea>
                    <p class="description">Als je iemand via het Dashboard "Verwijdert & Bant" wordt zijn e-mailadres hier automatisch toegevoegd.<br>
                    Wil je iemand weer een <b>nieuwe kans geven</b>? Haal dan simpelweg zijn e-mailadres hier weg en klik op Opslaan!</p>
                </td>
            </tr>
        </table><hr>
        
        <h3>Bump Functie (Advertenties & Ervaringen)</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Bumpen toestaan?</th><td><select name="brink_ad_bump_enabled"><option value="1" <?php selected(get_option('brink_ad_bump_enabled'), '1'); ?>>Ja</option><option value="0" <?php selected(get_option('brink_ad_bump_enabled'), '0'); ?>>Nee</option></select></td></tr>
            <tr valign="top"><th scope="row">Max bumps</th><td><input type="number" name="brink_ad_bump_limit" value="<?php echo esc_attr(get_option('brink_ad_bump_limit', '3')); ?>" style="width:80px;" /></td></tr>
        </table><hr>
        <h3>Wekelijkse Digest-mail</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Wekelijks overzicht per e-mail?</th><td>
                <select name="brink_fp_weekly_digest_enabled">
                    <option value="1" <?php selected(get_option('brink_fp_weekly_digest_enabled', '1'), '1'); ?>>AAN: stuur elke week een overzichtsmail naar de beheerder</option>
                    <option value="0" <?php selected(get_option('brink_fp_weekly_digest_enabled', '1'), '0'); ?>>UIT: geen wekelijkse mail</option>
                </select>
                <p class="description">Gaat naar het WordPress-beheerder e-mailadres (<?php echo esc_html(get_option('admin_email')); ?>) en bevat het aantal advertenties, ervaringen, contactberichten, inschrijvingen en reacties van de afgelopen 7 dagen.</p>
            </td></tr>
        </table><hr>
        <h3>Afbeeldingen (Compressie & Galerij)</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Max. breedte na compressie (px)</th><td><input type="number" name="brink_ad_image_max_width" value="<?php echo esc_attr(get_option('brink_ad_image_max_width', '1600')); ?>" style="width:100px;" min="0" />
            <p class="description">Geüploade afbeeldingen breder dan dit aantal pixels worden automatisch verkleind vóór de WebP-conversie (beeldverhouding blijft behouden). Zet op 0 om resizen uit te schakelen.</p></td></tr>
            <tr valign="top"><th scope="row">Max. aantal galerij-afbeeldingen</th><td><input type="number" name="brink_ad_gallery_max_images" value="<?php echo esc_attr(get_option('brink_ad_gallery_max_images', '5')); ?>" style="width:100px;" min="0" max="20" />
            <p class="description">Maximum aantal extra foto's dat een adverteerder naast de hoofdfoto kan uploaden (zie het advertentieformulier).</p></td></tr>
        </table><hr>
        <h3>Placeholder Afbeelding (Voor Advertenties & Ervaringen)</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Originele Placeholder</th>
                <td>
                    <div class="image-preview-wrapper" style="margin-bottom:10px;"><img id="placeholder-preview" src="<?php echo esc_url($placeholder_url); ?>" style="max-width:150px; display: <?php echo $placeholder_url ? 'block' : 'none'; ?>;"></div>
                    <input type="hidden" name="brink_ad_placeholder_image" id="brink_ad_placeholder_image" value="<?php echo esc_attr($placeholder_id); ?>">
                    <input type="button" class="button" id="upload-placeholder-btn" value="Kies / Upload">
                    <input type="button" class="button" id="remove-placeholder-btn" value="Verwijder" style="display: <?php echo $placeholder_url ? 'inline-block' : 'none'; ?>;">
                    <p class="description">Upload hier de basis-placeholder. Bij elke nieuwe post zonder foto maakt het systeem automatisch een unieke (WebP) kopie van deze afbeelding, voorzien van SEO titel, die aan de post wordt gekoppeld.</p>
                </td>
            </tr>
        </table><hr>
        <h3>Cloudflare Turnstile (Spam Beveiliging)</h3>
        <table class="form-table">
            <tr valign="top"><th scope="row">Sitekey</th><td><input type="text" name="brink_turnstile_sitekey" value="<?php echo esc_attr(get_option('brink_turnstile_sitekey')); ?>" style="width:100%; max-width:400px;" /></td></tr>
            <tr valign="top"><th scope="row">Secret Key</th><td><input type="text" name="brink_turnstile_secret" value="<?php echo esc_attr(get_option('brink_turnstile_secret')); ?>" style="width:100%; max-width:400px;" /></td></tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <script>
    jQuery(document).ready(function($){
        var mediaUploader;
        $('#upload-placeholder-btn').click(function(e) {
            e.preventDefault(); if (mediaUploader) { mediaUploader.open(); return; }
            mediaUploader = wp.media.frames.file_frame = wp.media({ title: 'Kies een Placeholder', button: { text: 'Kies' }, multiple: false });
            mediaUploader.on('select', function() {
                var attachment = mediaUploader.state().get('selection').first().toJSON();
                $('#brink_ad_placeholder_image').val(attachment.id); $('#placeholder-preview').attr('src', attachment.url).show(); $('#remove-placeholder-btn').show();
            }); mediaUploader.open();
        });
        $('#remove-placeholder-btn').click(function(e) { e.preventDefault(); $('#brink_ad_placeholder_image').val(''); $('#placeholder-preview').attr('src', '').hide(); $(this).hide(); });
    });
    </script>
    <?php
}

// Overzicht van alle shortcodes die deze plugin registreert. Data-driven opgezet: bij een
// nieuwe add_shortcode() hoger in dit bestand hoeft alleen deze array aangevuld te worden
// om hem automatisch in dit tabblad te tonen — voorkomt dat dit overzicht ooit achterloopt.
function brink_render_shortcodes_tab() {
    $shortcodes = array(
        array(
            'tag'          => 'mystique_advertentie_formulier',
            'title'        => 'Advertentie plaatsen',
            'description'  => 'Front-end formulier waarmee bezoekers een advertentie kunnen plaatsen (en, via de edit-link in de bevestigingsmail, later bewerken).',
            'settings_tab' => 'colors',
        ),
        array(
            'tag'          => 'mystique_ervaringen_formulier',
            'title'        => 'Ervaring insturen',
            'description'  => 'Front-end formulier waarmee bezoekers een ervaring/verhaal kunnen insturen. Komt eerst in de keuringswachtrij (status "pending") terecht.',
            'settings_tab' => 'colors',
        ),
        array(
            'tag'          => 'mystique_contactformulier',
            'title'        => 'Contactformulier',
            'description'  => 'Algemeen contactformulier. Inzendingen komen terecht bij de ingestelde categorie en worden gemaild naar de ingestelde ontvanger(s).',
            'settings_tab' => 'colors',
        ),
        array(
            'tag'          => 'mystique_inschrijfformulier',
            'title'        => 'Inschrijfformulier',
            'description'  => 'Inschrijfformulier (heer/dame, contactgegevens, geboortedatum). Inzendingen komen terecht bij de ingestelde categorie en ontvanger(s).',
            'settings_tab' => 'colors',
        ),
        array(
            'tag'          => 'openingstijden_prijzen',
            'title'        => 'Openingstijden & Prijzen',
            'description'  => 'Toont de openingstijden- en prijzentabel zoals ingesteld op het tabblad "Openingstijden & Prijzen".',
            'settings_tab' => 'openingstijden',
        ),
    );
    ?>
    <h3>Beschikbare Shortcodes</h3>
    <p class="description">Plaats één van onderstaande shortcodes in een pagina, bericht of widget om het bijbehorende onderdeel te tonen.</p>
    <style>
        .brink-sc-table { width:100%; border-collapse: collapse; margin-top:15px; }
        .brink-sc-table th, .brink-sc-table td { text-align:left; padding:12px 10px; border-bottom:1px solid #eee; vertical-align:top; }
        .brink-sc-code-wrap { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .brink-sc-code { background:#f0f0f1; padding:6px 10px; border-radius:4px; font-family:monospace; white-space:nowrap; }
        .brink-sc-copy-btn.copied { color:#00a32a; border-color:#00a32a; }
    </style>
    <table class="brink-sc-table">
        <thead><tr><th style="width:300px;">Shortcode</th><th>Waarvoor</th><th style="width:150px;">Instellingen</th></tr></thead>
        <tbody>
        <?php foreach ($shortcodes as $sc): $dom_id = 'sc-' . sanitize_html_class($sc['tag']); ?>
            <tr>
                <td>
                    <div class="brink-sc-code-wrap">
                        <code class="brink-sc-code" id="<?php echo esc_attr($dom_id); ?>">[<?php echo esc_html($sc['tag']); ?>]</code>
                        <button type="button" class="button button-small brink-sc-copy-btn" data-target="<?php echo esc_attr($dom_id); ?>">Kopieer</button>
                    </div>
                </td>
                <td><strong><?php echo esc_html($sc['title']); ?></strong><br><span style="color:#666;"><?php echo esc_html($sc['description']); ?></span></td>
                <td><a href="?page=brink-posting&tab=<?php echo esc_attr($sc['settings_tab']); ?>" class="button button-small">Naar instellingen</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.brink-sc-copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var codeEl = document.getElementById(btn.getAttribute('data-target'));
                var text = codeEl.textContent;
                var restore = function () {
                    var original = 'Kopieer';
                    btn.textContent = 'Gekopieerd!';
                    btn.classList.add('copied');
                    setTimeout(function () { btn.textContent = original; btn.classList.remove('copied'); }, 1500);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(restore);
                } else {
                    var tmp = document.createElement('textarea');
                    tmp.value = text; document.body.appendChild(tmp); tmp.select();
                    document.execCommand('copy'); document.body.removeChild(tmp);
                    restore();
                }
            });
        });
    });
    </script>
    <?php
}

// ==========================================
// 3. CENTRALE E-MAIL FUNCTIE (Voor Ervaring/Advertentie)
// ==========================================
function brink_send_ad_email($post_id) {
    $email = get_post_meta($post_id, 'ad_email', true); $name = get_post_meta($post_id, 'ad_name', true);
    $token = get_post_meta($post_id, 'delete_token', true); $title = get_the_title($post_id);
    if (!$email || !$token) return false;

    $ervaring_cat = intval(get_option('brink_ad_ervaringen_category'));
    if ($ervaring_cat && has_category($ervaring_cat, $post_id)) {
        $form_url = get_option('brink_ad_ervaringen_form_page_url');
        $subject = get_option('brink_ad_ervaringen_email_subject', 'Bedankt voor je ervaring!');
        $body = get_option('brink_ad_ervaringen_email_body', "Beste {naam},\n\nBedankt! Je ervaring is ontvangen.");
    } else {
        $form_url = get_option('brink_ad_form_page_url');
        $subject = get_option('brink_ad_email_subject', 'Je advertentie staat online!');
        $body = get_option('brink_ad_email_body', "Beste {naam},\n\nJe advertentie staat nu live.");
    }

    if (empty($form_url)) $form_url = home_url();
    $delete_link = add_query_arg(array('delete_ad' => $post_id, 'token' => $token), home_url());
    $edit_link = add_query_arg(array('edit_ad' => $post_id, 'token' => $token), $form_url);
    $bump_link = add_query_arg(array('bump_ad' => $post_id, 'token' => $token), home_url());
    
    $replacements = array('{naam}' => ($name ? $name : 'schrijver'), '{advertentienummer}' => $post_id, '{advertentietitel}' => $title, '{delete_link}' => $delete_link, '{edit_link}' => $edit_link, '{bump_link}' => $bump_link);
    $subject = str_replace(array_keys($replacements), array_values($replacements), $subject);
    $body = str_replace(array_keys($replacements), array_values($replacements), $body);

    return wp_mail($email, $subject, $body, array('Content-Type: text/plain; charset=UTF-8'));
}

// Mail voor de Double Opt-In E-mail Verificatie (Optie 3)
function brink_send_verify_email($post_id) {
    $email = get_post_meta($post_id, 'ad_email', true); $name = get_post_meta($post_id, 'ad_name', true);
    $token = get_post_meta($post_id, 'ad_verify_token', true); $title = get_the_title($post_id);
    if (!$email || !$token) return false;

    $subject = get_option('brink_ad_verify_email_subject', 'Belangrijk: Bevestig je advertentie op onze website');
    $body = get_option('brink_ad_verify_email_body', "Klik hier: {verify_link}");
    $verify_link = add_query_arg(array('verify_ad' => $post_id, 'token' => $token), home_url());

    $replacements = array(
        '{naam}' => ($name ? $name : 'adverteerder'),
        '{titel}' => $title,
        '{verify_link}' => $verify_link
    );
    $subject = str_replace(array_keys($replacements), array_values($replacements), $subject);
    $body = str_replace(array_keys($replacements), array_values($replacements), $body);

    return wp_mail($email, $subject, $body, array('Content-Type: text/plain; charset=UTF-8'));
}

// ==========================================
// 4. FRONTEND ACTIES (Delete, Bump & Verify)
// ==========================================
add_action('init', 'brink_handle_frontend_actions');
function brink_handle_frontend_actions() {
    if (isset($_GET['token'])) {
        $token = sanitize_text_field($_GET['token']);
        
        // --- 1. VERIFICATIE (Optie 3 Activeren) ---
        if (isset($_GET['verify_ad'])) {
            $post_id = intval($_GET['verify_ad']);
            $stored_token = get_post_meta($post_id, 'ad_verify_token', true);
            
            if (!empty($stored_token) && hash_equals($stored_token, $token)) {
                // Post live zetten en de verificatie token opruimen
                wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
                delete_post_meta($post_id, 'ad_verify_token');
                
                // Welkomstmail direct versturen omdat hij nu pas 'echt' live is!
                brink_send_ad_email($post_id);
                
                $form_url = get_option('brink_ad_form_page_url') ?: home_url();
                wp_safe_redirect(add_query_arg('ad_verified', '1', $form_url));
                exit;
            } else {
                wp_die("Ongeldige of reeds gebruikte verificatielink. Mogelijk is je advertentie al geactiveerd.", "Verificatie Mislukt");
            }
        }
        
        // --- 2. VERWIJDEREN ---
        if (isset($_GET['delete_ad'])) {
            $post_id = intval($_GET['delete_ad']); 
            $stored_token = get_post_meta($post_id, 'delete_token', true);
            
            if (!empty($stored_token) && hash_equals($stored_token, $token)) {
                $ervaring_cat = intval(get_option('brink_ad_ervaringen_category'));
                $is_ervaring = ($ervaring_cat && has_category($ervaring_cat, $post_id));
                
                $placeholder_id = (int) get_option('brink_ad_placeholder_image');
                $thumb_id = (int) get_post_thumbnail_id($post_id); 
                if ($thumb_id && $thumb_id !== $placeholder_id) {
                    wp_delete_attachment($thumb_id, true);
                }
                
                wp_delete_post($post_id, true); 
                
                $delete_redirect = $is_ervaring ? get_option('brink_ad_ervaringen_delete_redirect_url') : get_option('brink_ad_delete_redirect_url');
                if (!empty($delete_redirect)) { wp_redirect($delete_redirect); exit; } 
                else { wp_die("Succesvol en definitief verwijderd.", "Verwijderd", array('response' => 200)); }
            }
        }
        
        // --- 3. BUMPEN ---
        if (isset($_GET['bump_ad'])) {
            $post_id = intval($_GET['bump_ad']); $stored_token = get_post_meta($post_id, 'delete_token', true);
            if (get_option('brink_ad_bump_enabled') !== '1') wp_die("De bump-functie is uitgeschakeld.", "Fout");
            if (!empty($stored_token) && hash_equals($stored_token, $token)) {
                $bump_limit = intval(get_option('brink_ad_bump_limit', 3)); $current_bumps = intval(get_post_meta($post_id, 'ad_bump_count', true) ?: 0);
                if ($current_bumps >= $bump_limit) wp_die("Max bumps bereikt.", "Limiet bereikt");
                
                $time = current_time('mysql'); $time_gmt = current_time('mysql', 1);
                wp_update_post(array('ID' => $post_id, 'post_date' => $time, 'post_date_gmt' => $time_gmt));
                update_post_meta($post_id, 'ad_bump_count', $current_bumps + 1);
                wp_die("Staat weer bovenaan!", "Succes");
            }
        }
    }
}

// ==========================================
// 5. WEERGAVEN TRACKER & ELEMENTOR CLEANUP
// ==========================================
// FUNCTIONALITEIT (v5.17.0): automatische SEO meta description + Open Graph/Twitter-tags voor
// advertentie- en ervaringsposts, zodat deze er ook zonder los SEO-plugin netjes uitzien bij het
// delen op social media en in zoekresultaten. Slaat over als er al een bekend SEO-plugin actief
// is, om dubbele/conflicterende meta-tags te voorkomen.
add_action('wp_head', 'brink_fp_output_seo_meta_tags', 1);
function brink_fp_output_seo_meta_tags() {
    if (!is_single()) return;
    if (defined('WPSEO_VERSION') || class_exists('RankMath') || defined('AIOSEO_VERSION')) return;

    global $post;
    if (!$post || !get_post_meta($post->ID, 'delete_token', true)) return;

    $description = wp_strip_all_tags($post->post_content);
    $description = wp_trim_words($description, 30, '...');

    $title = get_the_title($post);
    $url = get_permalink($post);
    $image_id = get_post_thumbnail_id($post->ID);
    $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'large') : '';

    echo "\n<!-- Brink Multimedia Frontend Posting Pro: SEO meta -->\n";
    if ($description) {
        echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
    }
    echo '<meta property="og:type" content="article">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    if ($description) {
        echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
    }
    echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    if ($image_url) {
        echo '<meta property="og:image" content="' . esc_url($image_url) . '">' . "\n";
    }
    echo '<meta name="twitter:card" content="' . ($image_url ? 'summary_large_image' : 'summary') . '">' . "\n";
}

add_action('wp_head', 'brink_track_ad_views');
function brink_track_ad_views() {
    if (!is_single()) return;
    global $post;
    if (!$post || !get_post_meta($post->ID, 'delete_token', true)) return;

    // PERFORMANCE (v5.15.0): dedupliceer per bezoeker/dag via een transient in plaats van bij
    // élke pageview te schrijven. Een update_post_meta() op elke view is een DB-write per
    // request én triggert telkens invalidatie van de volledige postmeta-cache van die post;
    // bovendien telde een bezoeker die ververst voorheen onbeperkt mee.
    $visitor_hash = md5(brink_fp_get_client_ip() . '|' . (isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : ''));
    $dedupe_key = 'brink_view_' . $post->ID . '_' . $visitor_hash;
    if (get_transient($dedupe_key)) return;
    set_transient($dedupe_key, 1, DAY_IN_SECONDS);

    $views = (int) get_post_meta($post->ID, 'ad_views', true);
    update_post_meta($post->ID, 'ad_views', $views + 1);
}

// FUNCTIONALITEIT (v5.17.0): ruimt galerij-afbeeldingen op zodra de bijbehorende post ergens
// verwijderd wordt (admin-verwijdering, ban, front-end delete-link, of de expiratie-cron) —
// één centrale hook i.p.v. dit bij elke afzonderlijke wp_delete_post()-aanroep te herhalen.
add_action('before_delete_post', 'brink_fp_cleanup_gallery_on_delete');
function brink_fp_cleanup_gallery_on_delete($post_id) {
    $gallery_raw = get_post_meta($post_id, 'ad_gallery_ids', true);
    if (empty($gallery_raw)) return;
    foreach (array_filter(array_map('intval', explode(',', (string) $gallery_raw))) as $gallery_id) {
        wp_delete_attachment($gallery_id, true);
    }
}

add_action('before_delete_post', 'brink_elementor_submission_cleanup');
function brink_elementor_submission_cleanup($post_id) {
    global $wpdb; $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post') return;

    $submissions_table = $wpdb->prefix . 'e_submissions'; $values_table = $wpdb->prefix . 'e_submissions_values'; $actions_table = $wpdb->prefix . 'e_submissions_actions_log';
    $query = $wpdb->prepare("SELECT id FROM $submissions_table WHERE post_id = %d OR referer LIKE %s", $post_id, '%' . $wpdb->esc_like($post->post_name) . '%');
    $sub_ids = $wpdb->get_col($query);

    if (!empty($sub_ids)) {
        $ids_placeholder = implode(',', array_fill(0, count($sub_ids), '%d'));
        $wpdb->query($wpdb->prepare("DELETE FROM $values_table WHERE submission_id IN ($ids_placeholder)", $sub_ids));
        $wpdb->query($wpdb->prepare("DELETE FROM $actions_table WHERE submission_id IN ($ids_placeholder)", $sub_ids));
        $wpdb->query($wpdb->prepare("DELETE FROM $submissions_table WHERE id IN ($ids_placeholder)", $sub_ids));
    }
}

// ==========================================
// 6. SEO & WEBP AFBEELDING OMVORMER & COPIER
// ==========================================
function brink_force_webp_conversion_and_seo($attachment_id, $ad_title) {
    require_once(ABSPATH . 'wp-admin/includes/image.php'); 
    $file_path = get_attached_file($attachment_id);
    if (!$file_path) return;

    $ext = pathinfo($file_path, PATHINFO_EXTENSION); 
    $dir = dirname($file_path);
    
    // Unieke, strakke bestandsnaam (zonder ID's)
    $clean_title = sanitize_title($ad_title);
    $seo_filename = wp_unique_filename($dir, $clean_title . '.' . $ext);
    $new_path = trailingslashit($dir) . $seo_filename;
    
    rename($file_path, $new_path); 
    update_attached_file($attachment_id, $new_path);
    
    // De 3 belangrijkste SEO attributen direct veilig en onafhankelijk invullen (v5.13.5)
    update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($ad_title)); // Alternatieve tekst (ALT)
    
    wp_update_post(array(
        'ID'           => $attachment_id, 
        'post_title'   => sanitize_text_field($ad_title),  // De Weergave Titel
        'post_name'    => $clean_title,                    // De URL slug
        'post_excerpt' => sanitize_text_field($ad_title)   // Het Bijschrift (Caption)
    ));

    $editor = wp_get_image_editor($new_path);
    if (!is_wp_error($editor)) {
        // FUNCTIONALITEIT (v5.17.0): automatische compressie/resize naast de WebP-conversie.
        // Voorkomt dat bezoekers onnodig grote originele foto's (die de 1MB-uploadcheck net
        // doorstaan, bijv. een kleine bestandsgrootte met hele hoge resolutie) op de site zetten.
        $max_width = (int) get_option('brink_ad_image_max_width', 1600);
        if ($max_width > 0) {
            $size = $editor->get_size();
            if (!empty($size['width']) && $size['width'] > $max_width) {
                $editor->resize($max_width, null, false);
            }
        }
        $editor->set_quality((int) apply_filters('brink_fp_image_quality', 82));

        $webp_file = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $new_path); 
        $editor->save($webp_file, 'image/webp');
        update_attached_file($attachment_id, $webp_file); 
        wp_update_post(array('ID' => $attachment_id, 'post_mime_type' => 'image/webp'));
        $attach_data = wp_generate_attachment_metadata($attachment_id, $webp_file); 
        wp_update_attachment_metadata($attachment_id, $attach_data);
        if (file_exists($webp_file) && $webp_file !== $new_path) @unlink($new_path);
    }
}

// Helper: Kopieer originele placeholder, maak hem uniek, converteer & assign
// FUNCTIONALITEIT (v5.17.0): verwerkt meerdere galerij-afbeeldingen naast de hoofdfoto van een
// advertentie. Ongeldige bestanden (te groot, verkeerd type) worden individueel overgeslagen
// i.p.v. de hele inzending te blokkeren — de hoofdfoto blijft leidend voor validatiefouten.
function brink_fp_handle_ad_gallery_upload($post_id, $seo_base_name) {
    if (empty($_FILES['ad_gallery_images']['name']) || !is_array($_FILES['ad_gallery_images']['name'])) return array();

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    $max_images = (int) get_option('brink_ad_gallery_max_images', 5);
    $allowed_mimes = array('image/jpeg', 'image/png');
    $files = $_FILES['ad_gallery_images'];
    $uploaded_ids = array();
    $count = 0;

    foreach ($files['name'] as $idx => $name) {
        if ($count >= $max_images) break;
        if (empty($name)) continue;
        if (!empty($files['error'][$idx]) && $files['error'][$idx] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$idx] > 1 * 1024 * 1024) continue;

        $filetype = wp_check_filetype_and_ext($files['tmp_name'][$idx], $name);
        if (empty($filetype['type']) || !in_array($filetype['type'], $allowed_mimes, true)) continue;

        $_FILES['brink_gallery_tmp_field'] = array(
            'name'     => $files['name'][$idx],
            'type'     => $files['type'][$idx],
            'tmp_name' => $files['tmp_name'][$idx],
            'error'    => $files['error'][$idx],
            'size'     => $files['size'][$idx],
        );
        $attachment_id = media_handle_upload('brink_gallery_tmp_field', $post_id);
        unset($_FILES['brink_gallery_tmp_field']);

        if (!is_wp_error($attachment_id)) {
            brink_force_webp_conversion_and_seo($attachment_id, $seo_base_name . ' ' . ($count + 1));
            $uploaded_ids[] = $attachment_id;
            $count++;
        }
    }

    return $uploaded_ids;
}

// Toont de galerij-afbeeldingen (indien aanwezig) onderaan de post-inhoud, zodat er geen
// aanpassing aan het thema nodig is om ze zichtbaar te maken.
add_filter('the_content', 'brink_fp_append_gallery_to_content');
function brink_fp_append_gallery_to_content($content) {
    if (is_admin() || !in_the_loop() || !is_main_query() || !is_singular('post')) return $content;

    global $post;
    $gallery_ids_raw = get_post_meta($post->ID, 'ad_gallery_ids', true);
    if (empty($gallery_ids_raw)) return $content;

    $ids = array_filter(array_map('intval', explode(',', $gallery_ids_raw)));
    if (empty($ids)) return $content;

    $html = '<div class="brink-ad-gallery">';
    foreach ($ids as $id) {
        $full_url = wp_get_attachment_image_url($id, 'full');
        if (!$full_url) continue;
        $html .= '<a href="' . esc_url($full_url) . '" class="brink-ad-gallery-item" target="_blank" rel="noopener noreferrer">' . wp_get_attachment_image($id, 'medium') . '</a>';
    }
    $html .= '</div>';
    $html .= '<style>.brink-ad-gallery{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px;}.brink-ad-gallery-item img{width:150px;height:150px;object-fit:cover;border-radius:8px;display:block;}</style>';

    return $content . $html;
}

function brink_assign_placeholder_copy($post_id, $ad_title) {
    $placeholder_id = (int) get_option('brink_ad_placeholder_image');
    if (!$placeholder_id) return;
    
    $orig_path = get_attached_file($placeholder_id);
    if (!$orig_path || !file_exists($orig_path)) return;
    
    $upload_dir = wp_upload_dir();
    $ext = pathinfo($orig_path, PATHINFO_EXTENSION);
    if (empty($ext)) $ext = 'jpg';
    
    $new_filename = wp_unique_filename($upload_dir['path'], 'placeholder-copy-' . $post_id . '.' . $ext);
    $new_filepath = $upload_dir['path'] . '/' . $new_filename;
    
    if (copy($orig_path, $new_filepath)) {
        $attachment = array(
            'guid'           => $upload_dir['url'] . '/' . basename($new_filepath), 
            'post_mime_type' => mime_content_type($new_filepath),
            'post_title'     => sanitize_title($ad_title),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );
        $new_attach_id = wp_insert_attachment($attachment, $new_filepath, $post_id);
        if (!is_wp_error($new_attach_id)) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($new_attach_id, $new_filepath);
            wp_update_attachment_metadata($new_attach_id, $attach_data);
            
            // Nu direct omzetten naar WebP & SEO namen
            brink_force_webp_conversion_and_seo($new_attach_id, $ad_title);
            
            // Plak de unieke kopie aan de post
            set_post_thumbnail($post_id, $new_attach_id);
        }
    }
}

function brink_verify_turnstile($secret, $response) {
    if (empty($response)) return false;
    $verify = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array('body' => array('secret' => $secret, 'response' => $response)));
    if (is_wp_error($verify)) return false;
    $result = json_decode(wp_remote_retrieve_body($verify), true); return $result['success'];
}

// ==========================================
// 7A. FRONTEND FORMULIER: ADVERTENTIES
// ==========================================
function brink_frontend_ad_form() {
    ob_start();
    $bg_color = get_option('mystique_ad_bg_color', '#ffffff'); $text_color = get_option('mystique_ad_text_color', '#333333');
    $primary_color = get_option('mystique_ad_primary_color', '#b5121b'); $btn_color = get_option('mystique_ad_btn_color', '#b5121b');
    $btn_hover = get_option('mystique_ad_btn_hover_color', '#8e0e15'); $turnstile_sitekey = get_option('brink_turnstile_sitekey');
    $turnstile_secret = get_option('brink_turnstile_secret'); $redirect_url = get_option('brink_ad_redirect_url');
    $allowed_cats = get_option('brink_ad_allowed_categories'); $errors = array(); $success_message = false;
    
    // Status Dubbele Opt-In e-mail
    $verify_enabled = (get_option('brink_ad_email_verification_enabled') === '1');
    
    $edit_mode = false; $edit_post_id = 0;
    if (isset($_GET['edit_ad']) && isset($_GET['token'])) {
        $edit_post_id = intval($_GET['edit_ad']); $token = sanitize_text_field($_GET['token']);
        $stored_token = get_post_meta($edit_post_id, 'delete_token', true);
        if (!empty($stored_token) && hash_equals($stored_token, $token)) $edit_mode = true; else $errors[] = "Bewerk-link ongeldig.";
    }

    if (isset($_GET['ad_posted']) && $_GET['ad_posted'] == '1') $success_message = "<strong>Gelukt!</strong> Je advertentie staat direct live. Check je e-mail voor beheer-links.";
    if (isset($_GET['ad_needs_verify']) && $_GET['ad_needs_verify'] == '1') $success_message = "<strong>Bijna klaar!</strong> Om misbruik te voorkomen hebben we je een veilige e-mail gestuurd. Klik in die mail op de bevestigingslink om je advertentie definitief live te zetten.";
    if (isset($_GET['ad_verified']) && $_GET['ad_verified'] == '1') $success_message = "<strong>Geverifieerd!</strong> Bedankt, je advertentie staat nu live op de website!";
    if (isset($_GET['ad_updated']) && $_GET['ad_updated'] == '1') $success_message = "<strong>Gelukt!</strong> Je advertentie is succesvol bijgewerkt.";

    if (isset($_POST['mystique_action']) && ($_POST['mystique_action'] === 'submit_ad' || $_POST['mystique_action'] === 'update_ad')) {
        if (!isset($_POST['mystique_ad_nonce']) || !wp_verify_nonce($_POST['mystique_ad_nonce'], 'submit_ad')) $errors[] = "Sessie verlopen. Ververs de pagina.";
        if (!empty($_POST['mystique_hp_field'])) die("Spam blokkade.");
        if (!brink_fp_rate_limit_check('ad', 5, 300)) $errors[] = "Je hebt te vaak achter elkaar een formulier verstuurd. Probeer het over een paar minuten opnieuw.";
        if ($_POST['mystique_action'] === 'submit_ad' && !empty($turnstile_secret)) {
            if (!brink_verify_turnstile($turnstile_secret, isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '')) $errors[] = "Spamverificatie mislukt.";
        }

        $title = sanitize_text_field($_POST['ad_title']); 
        $email = sanitize_email($_POST['ad_email']);
        $email_confirm = isset($_POST['ad_email_confirm']) ? sanitize_email($_POST['ad_email_confirm']) : '';
        $name = sanitize_text_field($_POST['ad_name']); 
        $location = sanitize_text_field($_POST['ad_location']);
        $content = wp_kses_post($_POST['ad_content']); 
        $category = intval($_POST['ad_category']);
        
        $is_update = ($_POST['mystique_action'] === 'update_ad');
        
        // E-mail Blacklist Beveiliging Check (V5.13.7)
        $banned_emails_raw = get_option('brink_ad_banned_emails', '');
        if (!empty($banned_emails_raw)) {
            $banned_emails = array_map('strtolower', array_map('trim', explode("\n", $banned_emails_raw)));
            if (in_array(strtolower($email), $banned_emails)) {
                $errors[] = "Dit e-mailadres is geblokkeerd wegens het herhaaldelijk overtreden van onze regels.";
            }
        }

        if (empty($title)) $errors[] = "Titel is verplicht."; 
        if (!is_email($email)) $errors[] = "Geldig e-mailadres is verplicht."; 
        if (empty($content)) $errors[] = "Tekst mag niet leeg zijn.";

        // BEVEILIGING (v5.15.0): serverside validatie van de upload. De 1MB-check en het
        // accept-attribuut in het formulier zijn client-side (JavaScript/HTML) en dus triviaal
        // te omzeilen door direct te posten naar deze handler.
        if (!empty($_FILES['ad_image']['name'])) {
            if (!empty($_FILES['ad_image']['error']) && $_FILES['ad_image']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "De afbeelding kon niet worden geüpload.";
            } elseif ($_FILES['ad_image']['size'] > 1 * 1024 * 1024) {
                $errors[] = "De afbeelding is groter dan 1MB.";
            } else {
                $allowed_mimes = array('image/jpeg', 'image/png');
                $filetype = wp_check_filetype_and_ext($_FILES['ad_image']['tmp_name'], $_FILES['ad_image']['name']);
                if (empty($filetype['type']) || !in_array($filetype['type'], $allowed_mimes, true)) {
                    $errors[] = "Alleen JPEG- of PNG-afbeeldingen zijn toegestaan.";
                }
            }
        }

        // --- MX Check & Dubbele Invoer Beveiliging (Nieuwe Aanmeldingen) ---
        if (!$is_update) {
            if ($email !== $email_confirm) {
                $errors[] = "E-mailadressen komen niet overeen. Controleer of je geen typfout hebt gemaakt.";
            } else {
                // Slimme MX Record Check (Controleer of het domein überhaupt mail accepteert)
                $domain = substr(strrchr($email, "@"), 1);
                if (!empty($domain) && function_exists('checkdnsrr') && !checkdnsrr($domain, 'MX')) {
                    $errors[] = "De ingevulde mailserver ('$domain') lijkt niet te bestaan. Controleer op typfouten.";
                }
            }
        }

        if (empty($errors)) {
            $post_id = $is_update ? intval($_POST['edit_post_id']) : 0;
            $post_data = array('post_title' => $title, 'post_content' => $content, 'post_category' => array($category), 'post_type' => 'post');
            
            if ($is_update) { 
                $post_data['ID'] = $post_id; 
                wp_update_post($post_data); 
            } 
            else { 
                if ($verify_enabled) {
                    $post_data['post_status'] = 'draft'; // Wordt pas publish na klik in mail
                    $post_id = wp_insert_post($post_data, true); 
                    $verify_token = wp_generate_password(32, false);
                    update_post_meta($post_id, 'ad_verify_token', $verify_token);
                } else {
                    $post_data['post_status'] = 'publish';
                    $post_id = wp_insert_post($post_data, true); 
                }
                
                $token = wp_generate_password(32, false); 
                update_post_meta($post_id, 'delete_token', $token); 
                update_post_meta($post_id, 'expiration_date', time() + (30 * DAY_IN_SECONDS)); 
                brink_log_stat('ad'); 
            }

            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, 'ad_email', $email); update_post_meta($post_id, 'ad_name', $name); update_post_meta($post_id, 'ad_location', $location);
                
                // Unieke SEO format (Parenclub Mystique + Woonplaats + Titel)
                $seo_loc = !empty($location) ? $location : 'Rucphen';
                $seo_img_name = 'Parenclub Mystique ' . $seo_loc . ' ' . $title;

                if (!empty($_FILES['ad_image']['name'])) {
                    require_once(ABSPATH . 'wp-admin/includes/image.php'); require_once(ABSPATH . 'wp-admin/includes/file.php'); require_once(ABSPATH . 'wp-admin/includes/media.php');
                    
                    if ($is_update) { 
                        $old_thumb = (int) get_post_thumbnail_id($post_id); 
                        $placeholder_id = (int) get_option('brink_ad_placeholder_image');
                        if ($old_thumb && $old_thumb !== $placeholder_id) wp_delete_attachment($old_thumb, true); 
                    }
                    
                    $attachment_id = media_handle_upload('ad_image', $post_id);
                    if (!is_wp_error($attachment_id)) { 
                        set_post_thumbnail($post_id, $attachment_id); 
                        brink_force_webp_conversion_and_seo($attachment_id, $seo_img_name); 
                    }
                } elseif (!$is_update) {
                    brink_assign_placeholder_copy($post_id, $seo_img_name);
                }

                // FUNCTIONALITEIT (v5.17.0): galerij-afbeeldingen naast de hoofdfoto verwerken.
                // Bij een bewerking vervangt een nieuwe galerij-upload de oude (die wordt eerst
                // opgeruimd, consistent met hoe de hoofdfoto bij een update wordt vervangen).
                $new_gallery_ids = brink_fp_handle_ad_gallery_upload($post_id, $seo_img_name);
                if (!empty($new_gallery_ids)) {
                    if ($is_update) {
                        $old_gallery_raw = get_post_meta($post_id, 'ad_gallery_ids', true);
                        foreach (array_filter(array_map('intval', explode(',', (string) $old_gallery_raw))) as $old_gallery_id) {
                            wp_delete_attachment($old_gallery_id, true);
                        }
                    }
                    update_post_meta($post_id, 'ad_gallery_ids', implode(',', $new_gallery_ids));
                }

                if (!$is_update) {
                    if ($verify_enabled) {
                        brink_send_verify_email($post_id);
                        $final_redirect = $redirect_url ? $redirect_url : add_query_arg('ad_needs_verify', '1', get_permalink());
                    } else {
                        brink_send_ad_email($post_id);
                        $final_redirect = $redirect_url ? $redirect_url : add_query_arg('ad_posted', '1', get_permalink());
                    }
                } else {
                    $final_redirect = $redirect_url ? $redirect_url : add_query_arg('ad_updated', '1', get_permalink());
                }
                
                echo '<script type="text/javascript">window.location.href="' . esc_url($final_redirect) . '";</script>'; exit;
            }
        }
    }
    
    if ($success_message) echo '<div class="mystique-alert success">' . $success_message . '</div>';
    if (!empty($errors)) foreach($errors as $error) echo '<div class="mystique-alert error">' . esc_html($error) . '</div>';

    $val_title = $edit_mode ? get_the_title($edit_post_id) : (isset($_POST['ad_title']) ? $_POST['ad_title'] : '');
    $post_cats = $edit_mode ? wp_get_post_categories($edit_post_id) : array(); $val_cat = $edit_mode && !empty($post_cats) ? $post_cats[0] : (isset($_POST['ad_category']) ? $_POST['ad_category'] : '');
    $val_name = $edit_mode ? get_post_meta($edit_post_id, 'ad_name', true) : (isset($_POST['ad_name']) ? $_POST['ad_name'] : '');
    $val_email = $edit_mode ? get_post_meta($edit_post_id, 'ad_email', true) : (isset($_POST['ad_email']) ? $_POST['ad_email'] : '');
    $val_location = $edit_mode ? get_post_meta($edit_post_id, 'ad_location', true) : (isset($_POST['ad_location']) ? $_POST['ad_location'] : '');
    $post_obj = $edit_mode ? get_post($edit_post_id) : null; $val_content = $edit_mode ? $post_obj->post_content : (isset($_POST['ad_content']) ? $_POST['ad_content'] : '');
    $cat_args = array('hide_empty' => 0, 'name' => 'ad_category', 'show_option_none' => 'Maak een keuze...', 'selected' => $val_cat);
    if (!empty($allowed_cats)) $cat_args['include'] = array_map('intval', explode(',', $allowed_cats));
    ?>
    <?php if (!$edit_mode && !empty($turnstile_sitekey)): brink_fp_enqueue_turnstile(); endif; ?>
    <form action="<?php echo esc_url(brink_current_url()); ?>" method="post" enctype="multipart/form-data" class="mystique-form" id="ad-upload-form">
        <?php wp_nonce_field('submit_ad', 'mystique_ad_nonce'); ?>
        <input type="hidden" name="mystique_action" value="<?php echo $edit_mode ? 'update_ad' : 'submit_ad'; ?>"><input type="hidden" name="mystique_ts" value="<?php echo time(); ?>"><input type="text" name="mystique_hp_field" style="display:none !important" tabindex="-1" autocomplete="off">
        <?php if($edit_mode): ?><input type="hidden" name="edit_post_id" value="<?php echo $edit_post_id; ?>"><div class="mystique-alert success">Je bewerkt advertentie <strong>#<?php echo $edit_post_id; ?></strong>.</div><?php endif; ?>
        
        <p><label>Titel advertentie *</label><input type="text" name="ad_title" value="<?php echo esc_attr($val_title); ?>" required></p>
        <p><label>Categorie</label><?php wp_dropdown_categories($cat_args); ?></p>
        <p><label>Foto (Optioneel, Max 1MB)</label><input type="file" name="ad_image" id="ad_image" accept="image/jpeg,image/png">
            <div id="image-preview-container" style="display:none; margin-top:10px;"><img id="image-preview" src="#" style="max-width:150px; border-radius:5px;"><div class="progress-wrapper"><div id="upload-bar"></div></div></div>
        </p>
        <p><label>Extra foto's / Galerij (Optioneel, max <?php echo (int) get_option('brink_ad_gallery_max_images', 5); ?> stuks, elk max 1MB)</label>
            <input type="file" name="ad_gallery_images[]" id="ad_gallery_images" accept="image/jpeg,image/png" multiple>
            <div id="gallery-preview-container" style="display:none; margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;"></div>
        </p>
        
        <div class="form-row">
            <p><label>Naam</label><input type="text" name="ad_name" value="<?php echo esc_attr($val_name); ?>"></p>
            <p><label>E-mailadres *</label><input type="email" name="ad_email" value="<?php echo esc_attr($val_email); ?>" required></p>
        </div>
        <div class="form-row">
            <p><label>Woonplaats</label><input type="text" name="ad_location" value="<?php echo esc_attr($val_location); ?>"></p>
            <?php if(!$edit_mode): ?>
                <p><label>Bevestig E-mailadres *</label><input type="email" name="ad_email_confirm" id="ad_email_confirm" value="<?php echo esc_attr($val_email); ?>" required></p>
            <?php endif; ?>
        </div>
        
        <p><label>Advertentie tekst *</label><textarea name="ad_content" rows="6" required><?php echo esc_textarea($val_content); ?></textarea></p>
        <?php if (!$edit_mode && !empty($turnstile_sitekey)): ?><div class="cf-turnstile" data-sitekey="<?php echo esc_attr($turnstile_sitekey); ?>"></div><?php endif; ?>
        <p><input type="submit" id="submit-btn" value="<?php echo $edit_mode ? 'Opslaan' : 'Plaats Advertentie'; ?>"></p>
    </form>
    <?php brink_render_form_styles($bg_color, $text_color, $primary_color, $btn_color, $btn_hover); ?>
    
    <script>
    if(document.getElementById('ad_image')) {
        document.getElementById('ad_image').onchange = function(evt) {
            const [file] = this.files;
            if (file) {
                if (file.size > 1024 * 1024) { alert('Bestand is groter dan 1MB.'); this.value = ''; return; }
                document.getElementById('image-preview-container').style.display = 'block'; document.getElementById('image-preview').src = URL.createObjectURL(file); document.getElementById('upload-bar').style.width = '100%';
            }
        };
    }
    if(document.getElementById('ad_gallery_images')) {
        document.getElementById('ad_gallery_images').onchange = function(evt) {
            const maxImages = <?php echo (int) get_option('brink_ad_gallery_max_images', 5); ?>;
            const container = document.getElementById('gallery-preview-container');
            container.innerHTML = ''; container.style.display = 'none';
            let files = Array.from(this.files);
            if (files.length > maxImages) { alert('Je kunt maximaal ' + maxImages + ' extra foto\'s uploaden.'); this.value = ''; return; }
            for (const file of files) {
                if (file.size > 1024 * 1024) { alert('"' + file.name + '" is groter dan 1MB.'); this.value = ''; container.style.display = 'none'; return; }
                const img = document.createElement('img');
                img.src = URL.createObjectURL(file); img.style.cssText = 'width:80px; height:80px; object-fit:cover; border-radius:5px;';
                container.appendChild(img);
            }
            if (files.length) container.style.display = 'flex';
        };
    }
    
    // Voorkom 'plakken' in het controle email-veld voor typfout-garantie
    var confirmEmailField = document.getElementById('ad_email_confirm');
    if (confirmEmailField) {
        confirmEmailField.addEventListener('paste', function(e) {
            e.preventDefault();
        });
    }

    if(document.getElementById('ad-upload-form')) {
        document.getElementById('ad-upload-form').onsubmit = function() {
            let btn = document.getElementById('submit-btn'); setTimeout(function() { btn.value = 'Verwerken...'; btn.disabled = true; btn.style.opacity = '0.6'; }, 10); return true;
        };
    }
    </script>
    <?php return ob_get_clean();
}
add_shortcode('mystique_advertentie_formulier', 'brink_frontend_ad_form');

// ==========================================
// 7B. FRONTEND FORMULIER: ERVARINGEN
// ==========================================
function brink_frontend_ervaringen_form() {
    ob_start();
    $bg_color = get_option('mystique_ad_bg_color', '#ffffff'); $text_color = get_option('mystique_ad_text_color', '#333333');
    $primary_color = get_option('mystique_ad_primary_color', '#b5121b'); $btn_color = get_option('mystique_ad_btn_color', '#b5121b');
    $btn_hover = get_option('mystique_ad_btn_hover_color', '#8e0e15'); $turnstile_sitekey = get_option('brink_turnstile_sitekey');
    $turnstile_secret = get_option('brink_turnstile_secret'); $ervaring_cat = get_option('brink_ad_ervaringen_category');
    $redirect_url = get_option('brink_ad_ervaringen_redirect_url'); $errors = array(); $success_message = false;
    
    $edit_mode = false; $edit_post_id = 0;
    if (isset($_GET['edit_ad']) && isset($_GET['token'])) {
        $edit_post_id = intval($_GET['edit_ad']); $token = sanitize_text_field($_GET['token']);
        $stored_token = get_post_meta($edit_post_id, 'delete_token', true);
        if (!empty($stored_token) && hash_equals($stored_token, $token)) $edit_mode = true; else $errors[] = "Bewerk-link ongeldig.";
    }

    if (isset($_GET['ervaring_posted']) && $_GET['ervaring_posted'] == '1') $success_message = "<strong>Bedankt!</strong> Je ervaring is succesvol ingestuurd.";
    if (isset($_GET['ervaring_updated']) && $_GET['ervaring_updated'] == '1') $success_message = "<strong>Gelukt!</strong> Je ervaring is succesvol bijgewerkt.";

    if (isset($_POST['mystique_action']) && ($_POST['mystique_action'] === 'submit_ervaring' || $_POST['mystique_action'] === 'update_ervaring')) {
        if (!isset($_POST['mystique_erv_nonce']) || !wp_verify_nonce($_POST['mystique_erv_nonce'], 'submit_ervaring')) $errors[] = "Sessie verlopen.";
        if (!empty($_POST['mystique_hp_field'])) die("Spam blokkade.");
        if (!brink_fp_rate_limit_check('ervaring', 5, 300)) $errors[] = "Je hebt te vaak achter elkaar een formulier verstuurd. Probeer het over een paar minuten opnieuw.";
        if ($_POST['mystique_action'] === 'submit_ervaring' && !empty($turnstile_secret)) {
            if (!brink_verify_turnstile($turnstile_secret, isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '')) $errors[] = "Spamverificatie mislukt.";
        }

        $title = sanitize_text_field($_POST['erv_title']); $email = sanitize_email($_POST['erv_email']);
        $name = sanitize_text_field($_POST['erv_name']); $content = wp_kses_post($_POST['erv_content']);
        
        // E-mail Blacklist Beveiliging Check
        $banned_emails_raw = get_option('brink_ad_banned_emails', '');
        if (!empty($banned_emails_raw)) {
            $banned_emails = array_map('strtolower', array_map('trim', explode("\n", $banned_emails_raw)));
            if (in_array(strtolower($email), $banned_emails)) {
                $errors[] = "Dit e-mailadres is geblokkeerd wegens het herhaaldelijk overtreden van onze regels.";
            }
        }

        if (empty($title)) $errors[] = "Titel verplicht."; if (!is_email($email)) $errors[] = "Geldig e-mailadres verplicht."; if (empty($content)) $errors[] = "Verhaal mag niet leeg zijn.";
        if (empty($ervaring_cat)) $errors[] = "Systeemfout: Beheerder heeft categorie niet ingesteld.";

        if (empty($errors)) {
            $is_update = ($_POST['mystique_action'] === 'update_ervaring'); $post_id = $is_update ? intval($_POST['edit_post_id']) : 0;
            $post_data = array('post_title' => $title, 'post_content' => $content, 'post_category' => array($ervaring_cat), 'post_type' => 'post');
            if ($is_update) { $post_data['ID'] = $post_id; $post_data['post_status'] = 'pending'; wp_update_post($post_data); } 
            else { $post_data['post_status'] = 'pending'; $post_id = wp_insert_post($post_data, true); $token = wp_generate_password(32, false); update_post_meta($post_id, 'delete_token', $token); brink_log_stat('ervaring'); }

            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, 'ad_email', $email); update_post_meta($post_id, 'ad_name', $name);
                if (!$is_update) {
                    brink_send_ad_email($post_id);
                    $admin_email = get_option('admin_email'); $admin_subject = 'Nieuwe Ervaring ter keuring: ' . $title;
                    $admin_body = "Hoi Beheerder,\n\nEr is een nieuw verhaal ingestuurd door $name.\nLees via deze link:\n" . admin_url('post.php?post=' . $post_id . '&action=edit');
                    wp_mail($admin_email, $admin_subject, $admin_body, array('Content-Type: text/plain; charset=UTF-8'));
                }
                $final_redirect = $redirect_url ? $redirect_url : add_query_arg($is_update ? 'ervaring_updated' : 'ervaring_posted', '1', get_permalink());
                echo '<script type="text/javascript">window.location.href="' . esc_url($final_redirect) . '";</script>'; exit;
            }
        }
    }
    if ($success_message) echo '<div class="mystique-alert success">' . $success_message . '</div>';
    if (!empty($errors)) foreach($errors as $error) echo '<div class="mystique-alert error">' . esc_html($error) . '</div>';

    $val_title = $edit_mode ? get_the_title($edit_post_id) : (isset($_POST['erv_title']) ? $_POST['erv_title'] : '');
    $val_name = $edit_mode ? get_post_meta($edit_post_id, 'ad_name', true) : (isset($_POST['erv_name']) ? $_POST['erv_name'] : '');
    $val_email = $edit_mode ? get_post_meta($edit_post_id, 'ad_email', true) : (isset($_POST['erv_email']) ? $_POST['erv_email'] : '');
    $post_obj = $edit_mode ? get_post($edit_post_id) : null; $val_content = $edit_mode ? $post_obj->post_content : (isset($_POST['erv_content']) ? $_POST['erv_content'] : '');
    ?>
    <?php if (!$edit_mode && !empty($turnstile_sitekey)): brink_fp_enqueue_turnstile(); endif; ?>
    <form action="<?php echo esc_url(brink_current_url()); ?>" method="post" class="mystique-form" id="ervaring-upload-form">
        <?php wp_nonce_field('submit_ervaring', 'mystique_erv_nonce'); ?>
        <input type="hidden" name="mystique_action" value="<?php echo $edit_mode ? 'update_ervaring' : 'submit_ervaring'; ?>">
        <input type="hidden" name="mystique_ts" value="<?php echo time(); ?>"><input type="text" name="mystique_hp_field" style="display:none !important" tabindex="-1" autocomplete="off">
        <p><label>Titel van je / jullie ervaring(en) *</label><input type="text" name="erv_title" value="<?php echo esc_attr($val_title); ?>" required></p>
        <div class="form-row"><p><label>Naam</label><input type="text" name="erv_name" value="<?php echo esc_attr($val_name); ?>"></p><p><label>E-mailadres *</label><input type="email" name="erv_email" value="<?php echo esc_attr($val_email); ?>" required></p></div>
        <p><label>Jouw Ervaring *</label><textarea name="erv_content" rows="10" required><?php echo esc_textarea($val_content); ?></textarea></p>
        <?php if (!$edit_mode && !empty($turnstile_sitekey)): ?><div class="cf-turnstile" data-sitekey="<?php echo esc_attr($turnstile_sitekey); ?>"></div><?php endif; ?>
        <p><input type="submit" id="submit-erv-btn" value="<?php echo $edit_mode ? 'Opslaan' : 'Deel mijn ervaring'; ?>"></p>
    </form>
    <?php brink_render_form_styles($bg_color, $text_color, $primary_color, $btn_color, $btn_hover); ?>
    <?php return ob_get_clean();
}
add_shortcode('mystique_ervaringen_formulier', 'brink_frontend_ervaringen_form');

// ==========================================
// 7C. FRONTEND FORMULIER: CONTACTFORMULIER
// ==========================================
function brink_frontend_contact_form() {
    ob_start();
    $bg_color = get_option('mystique_ad_bg_color', '#ffffff'); $text_color = get_option('mystique_ad_text_color', '#333333');
    $primary_color = get_option('mystique_ad_primary_color', '#b5121b'); $btn_color = get_option('mystique_ad_btn_color', '#b5121b');
    $btn_hover = get_option('mystique_ad_btn_hover_color', '#8e0e15'); $turnstile_sitekey = get_option('brink_turnstile_sitekey');
    $turnstile_secret = get_option('brink_turnstile_secret'); $contact_cat = get_option('brink_ad_contact_category');
    $redirect_url = get_option('brink_ad_contact_redirect_url'); $receivers = get_option('brink_ad_contact_emails', get_option('admin_email'));

    $errors = array(); $success_message = false;
    if (isset($_GET['contact_posted']) && $_GET['contact_posted'] == '1') $success_message = "<strong>Bedankt!</strong> We hebben je bericht in goede orde ontvangen.";

    if (isset($_POST['mystique_action']) && $_POST['mystique_action'] === 'submit_contact') {
        if (!isset($_POST['mystique_con_nonce']) || !wp_verify_nonce($_POST['mystique_con_nonce'], 'submit_contact')) $errors[] = "Sessie verlopen.";
        if (!empty($_POST['mystique_hp_field'])) die("Spam blokkade.");
        if (!brink_fp_rate_limit_check('contact', 5, 300)) $errors[] = "Je hebt te vaak achter elkaar een formulier verstuurd. Probeer het over een paar minuten opnieuw.";
        if (!empty($turnstile_secret)) {
            if (!brink_verify_turnstile($turnstile_secret, isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '')) $errors[] = "Spamverificatie mislukt.";
        }

        $name = sanitize_text_field($_POST['con_name']); $phone = sanitize_text_field($_POST['con_phone']);
        $email = sanitize_email($_POST['con_email']); $content = sanitize_textarea_field($_POST['con_content']);
        $news = isset($_POST['con_news']) ? 1 : 0;
        
        // E-mail Blacklist Beveiliging Check
        $banned_emails_raw = get_option('brink_ad_banned_emails', '');
        if (!empty($banned_emails_raw)) {
            $banned_emails = array_map('strtolower', array_map('trim', explode("\n", $banned_emails_raw)));
            if (in_array(strtolower($email), $banned_emails)) {
                $errors[] = "Dit e-mailadres is geblokkeerd wegens het herhaaldelijk overtreden van onze regels.";
            }
        }

        if (empty($email) || !is_email($email)) $errors[] = "Geldig e-mailadres is verplicht.";
        if (empty($content)) $errors[] = "Vraag of opmerking is verplicht.";
        if (empty($contact_cat)) $errors[] = "Systeemfout: Categorie niet ingesteld.";

        if (empty($errors)) {
            $title = 'Contact: ' . ($name ? $name : $email) . ' (' . current_time('d-m-Y H:i') . ')';
            $post_data = array('post_title' => $title, 'post_content' => $content, 'post_category' => array($contact_cat), 'post_status' => 'private', 'post_type' => 'post');
            
            $post_id = wp_insert_post($post_data, true);
            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, 'contact_name', $name); update_post_meta($post_id, 'contact_email', $email);
                update_post_meta($post_id, 'contact_phone', $phone); update_post_meta($post_id, 'contact_newsletter', $news);
                update_post_meta($post_id, 'expiration_date', time() + (30 * DAY_IN_SECONDS));
                brink_log_stat('contact');

                $subject_template = get_option('brink_ad_contact_email_subject', 'Nieuw contactbericht: {naam}');
                $body_template = get_option('brink_ad_contact_email_body', "Naam: {naam}\nE-mail: {email}\nTelefoon: {telefoon}\nNieuwsbrief: {nieuwsbrief}\n\nVraag/Opmerking:\n{bericht}");
                
                $replacements = array('{naam}' => $name, '{email}' => $email, '{telefoon}' => $phone, '{nieuwsbrief}' => ($news ? 'Ja' : 'Nee'), '{bericht}' => $content);
                $mail_sub = str_replace(array_keys($replacements), array_values($replacements), $subject_template);
                $mail_body = str_replace(array_keys($replacements), array_values($replacements), $body_template);
                
                $headers = array('Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $name . ' <' . $email . '>');
                wp_mail($receivers, $mail_sub, $mail_body, $headers);
                
                $final_redirect = $redirect_url ? $redirect_url : add_query_arg('contact_posted', '1', get_permalink());
                echo '<script type="text/javascript">window.location.href="' . esc_url($final_redirect) . '";</script>'; exit;
            }
        }
    }
    if ($success_message) echo '<div class="mystique-alert success">' . $success_message . '</div>';
    if (!empty($errors)) foreach($errors as $error) echo '<div class="mystique-alert error">' . esc_html($error) . '</div>';
    ?>
    <?php if (!empty($turnstile_sitekey)): brink_fp_enqueue_turnstile(); endif; ?>
    <form action="<?php echo esc_url(brink_current_url()); ?>" method="post" class="mystique-form" id="contact-upload-form">
        <?php wp_nonce_field('submit_contact', 'mystique_con_nonce'); ?>
        <input type="hidden" name="mystique_action" value="submit_contact">
        <input type="hidden" name="mystique_ts" value="<?php echo time(); ?>"><input type="text" name="mystique_hp_field" style="display:none !important" tabindex="-1" autocomplete="off">
        <p><label>Naam</label><input type="text" name="con_name" value="<?php echo isset($_POST['con_name']) ? esc_attr($_POST['con_name']) : ''; ?>"></p>
        <div class="form-row">
            <p><label>Telefoonnummer</label><input type="tel" name="con_phone" value="<?php echo isset($_POST['con_phone']) ? esc_attr($_POST['con_phone']) : ''; ?>"></p>
            <p><label>E-mailadres *</label><input type="email" name="con_email" value="<?php echo isset($_POST['con_email']) ? esc_attr($_POST['con_email']) : ''; ?>" required></p>
        </div>
        <p><label>Vraag of opmerking *</label><textarea name="con_content" rows="6" required><?php echo isset($_POST['con_content']) ? esc_textarea($_POST['con_content']) : ''; ?></textarea></p>
        <p><label style="display:inline-block; font-weight:normal; cursor:pointer;"><input type="checkbox" name="con_news" value="1" style="margin-right:8px;">Wilt u onze nieuwsbrief ontvangen?</label></p>
        <?php if (!empty($turnstile_sitekey)): ?><div class="cf-turnstile" data-sitekey="<?php echo esc_attr($turnstile_sitekey); ?>" style="margin-bottom: 20px;"></div><?php endif; ?>
        <p><input type="submit" id="submit-con-btn" value="Verstuur Bericht"></p>
    </form>
    <?php brink_render_form_styles($bg_color, $text_color, $primary_color, $btn_color, $btn_hover); ?>
    <?php return ob_get_clean();
}
add_shortcode('mystique_contactformulier', 'brink_frontend_contact_form');

// ==========================================
// 7D. FRONTEND FORMULIER: INSCHRIJFFORMULIER
// ==========================================
function brink_frontend_inschrijving_form() {
    ob_start();
    $bg_color = get_option('mystique_ad_bg_color', '#ffffff'); $text_color = get_option('mystique_ad_text_color', '#333333');
    $primary_color = get_option('mystique_ad_primary_color', '#b5121b'); $btn_color = get_option('mystique_ad_btn_color', '#b5121b');
    $btn_hover = get_option('mystique_ad_btn_hover_color', '#8e0e15'); $turnstile_sitekey = get_option('brink_turnstile_sitekey');
    $turnstile_secret = get_option('brink_turnstile_secret'); $inschrijving_cat = get_option('brink_ad_inschrijving_category');
    $redirect_url = get_option('brink_ad_inschrijving_redirect_url'); $receivers = get_option('brink_ad_inschrijving_emails', get_option('admin_email'));

    $errors = array(); $success_message = false;
    if (isset($_GET['ins_posted']) && $_GET['ins_posted'] == '1') $success_message = "<strong>Bedankt voor uw inschrijving!</strong>";

    if (isset($_POST['mystique_action']) && $_POST['mystique_action'] === 'submit_inschrijving') {
        if (!isset($_POST['mystique_ins_nonce']) || !wp_verify_nonce($_POST['mystique_ins_nonce'], 'submit_inschrijving')) $errors[] = "Sessie verlopen.";
        if (!empty($_POST['mystique_hp_field'])) die("Spam blokkade.");
        if (!brink_fp_rate_limit_check('inschrijving', 5, 300)) $errors[] = "Je hebt te vaak achter elkaar een formulier verstuurd. Probeer het over een paar minuten opnieuw.";
        if (!empty($turnstile_secret)) {
            if (!brink_verify_turnstile($turnstile_secret, isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '')) $errors[] = "Spamverificatie mislukt.";
        }

        $heer = sanitize_text_field($_POST['ins_heer']); $dame = sanitize_text_field($_POST['ins_dame']);
        $postc = sanitize_text_field($_POST['ins_postcode']);
        $land = sanitize_text_field($_POST['ins_land']); $phone = sanitize_text_field($_POST['ins_phone']);
        $dob = sanitize_text_field($_POST['ins_dob']); $source = sanitize_text_field($_POST['ins_source']);
        $email = sanitize_email($_POST['ins_email']); $news = isset($_POST['ins_news']) ? 1 : 0;
        
        // E-mail Blacklist Beveiliging Check
        $banned_emails_raw = get_option('brink_ad_banned_emails', '');
        if (!empty($banned_emails_raw)) {
            $banned_emails = array_map('strtolower', array_map('trim', explode("\n", $banned_emails_raw)));
            if (in_array(strtolower($email), $banned_emails)) {
                $errors[] = "Dit e-mailadres is geblokkeerd wegens het herhaaldelijk overtreden van onze regels.";
            }
        }

        if (empty($email) || !is_email($email)) $errors[] = "Geldig e-mailadres is verplicht.";
        if (empty($inschrijving_cat)) $errors[] = "Systeemfout: Categorie niet ingesteld.";

        if (empty($errors)) {
            $titel_naam = trim($heer . ' & ' . $dame, ' &'); $titel_naam = empty($titel_naam) ? $email : $titel_naam;
            $title = 'Inschrijving: ' . $titel_naam . ' (' . current_time('d-m-Y') . ')';
            $post_data = array('post_title' => $title, 'post_content' => '', 'post_category' => array($inschrijving_cat), 'post_status' => 'private', 'post_type' => 'post');
            $post_id = wp_insert_post($post_data, true);
            
            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, 'inschrijving_heer', $heer); update_post_meta($post_id, 'inschrijving_dame', $dame);
                update_post_meta($post_id, 'inschrijving_postcode', $postc);
                update_post_meta($post_id, 'inschrijving_land', $land); update_post_meta($post_id, 'inschrijving_phone', $phone);
                update_post_meta($post_id, 'inschrijving_dob', $dob); update_post_meta($post_id, 'inschrijving_source', $source);
                update_post_meta($post_id, 'inschrijving_email', $email); update_post_meta($post_id, 'inschrijving_newsletter', $news);
                update_post_meta($post_id, 'inschrijving_date', current_time('d-m-Y H:i:s'));
                update_post_meta($post_id, 'expiration_date', time() + (30 * DAY_IN_SECONDS));
                brink_log_stat('inschrijving');

                $subject_template = get_option('brink_ad_inschrijving_email_subject', 'Nieuwe inschrijving: {heer} & {dame}');
                $body_template = get_option('brink_ad_inschrijving_email_body', "Heer: {heer}\nDame: {dame}");
                
                $replacements = array(
                    '{heer}' => $heer, '{dame}' => $dame, '{postcode}' => $postc, '{land}' => $land, 
                    '{telefoon}' => $phone, '{email}' => $email, '{geboortedatum}' => $dob, '{bron}' => $source, 
                    '{nieuwsbrief}' => ($news ? 'Ja' : 'Nee'), '{datum}' => current_time('d-m-Y H:i:s')
                );
                $mail_sub = str_replace(array_keys($replacements), array_values($replacements), $subject_template);
                $mail_body = str_replace(array_keys($replacements), array_values($replacements), $body_template);
                
                $headers = array('Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $titel_naam . ' <' . $email . '>');
                wp_mail($receivers, $mail_sub, $mail_body, $headers);
                
                $final_redirect = $redirect_url ? $redirect_url : add_query_arg('ins_posted', '1', get_permalink());
                echo '<script type="text/javascript">window.location.href="' . esc_url($final_redirect) . '";</script>'; exit;
            }
        }
    }

    if ($success_message) echo '<div class="mystique-alert success">' . $success_message . '</div>';
    if (!empty($errors)) foreach($errors as $error) echo '<div class="mystique-alert error">' . esc_html($error) . '</div>';
    ?>
    <?php if (!empty($turnstile_sitekey)): brink_fp_enqueue_turnstile(); endif; ?>
    <form action="<?php echo esc_url(brink_current_url()); ?>" method="post" class="mystique-form" id="inschrijving-upload-form">
        <?php wp_nonce_field('submit_inschrijving', 'mystique_ins_nonce'); ?>
        <input type="hidden" name="mystique_action" value="submit_inschrijving">
        <input type="hidden" name="mystique_ts" value="<?php echo time(); ?>"><input type="text" name="mystique_hp_field" style="display:none !important" tabindex="-1" autocomplete="off">
        
        <div class="form-row">
            <p><label>Voor- en achternaam heer</label><input type="text" name="ins_heer" value="<?php echo isset($_POST['ins_heer']) ? esc_attr($_POST['ins_heer']) : ''; ?>"></p>
            <p><label>Voor- en achternaam dame</label><input type="text" name="ins_dame" value="<?php echo isset($_POST['ins_dame']) ? esc_attr($_POST['ins_dame']) : ''; ?>"></p>
        </div>
        <div class="form-row">
            <p><label>Postcode en woonplaats</label><input type="text" name="ins_postcode" value="<?php echo isset($_POST['ins_postcode']) ? esc_attr($_POST['ins_postcode']) : ''; ?>"></p>
            <p><label>Land</label><input type="text" name="ins_land" value="<?php echo isset($_POST['ins_land']) ? esc_attr($_POST['ins_land']) : 'Nederland'; ?>"></p>
        </div>
        <div class="form-row">
            <p><label>Telefoonnummer</label><input type="tel" name="ins_phone" value="<?php echo isset($_POST['ins_phone']) ? esc_attr($_POST['ins_phone']) : ''; ?>"></p>
            <p><label>E-mailadres *</label><input type="email" name="ins_email" value="<?php echo isset($_POST['ins_email']) ? esc_attr($_POST['ins_email']) : ''; ?>" required></p>
        </div>
        <div class="form-row">
            <p><label>Geboortedatum</label><input type="text" name="ins_dob" placeholder="dd-mm-jjjj" value="<?php echo isset($_POST['ins_dob']) ? esc_attr($_POST['ins_dob']) : ''; ?>"></p>
        </div>
        <p><label>Waar kent u ons van?</label><input type="text" name="ins_source" value="<?php echo isset($_POST['ins_source']) ? esc_attr($_POST['ins_source']) : ''; ?>"></p>
        <p><label style="display:inline-block; font-weight:normal; cursor:pointer;"><input type="checkbox" name="ins_news" value="1" style="margin-right:8px;">Wilt u onze digitale nieuwsbrief ontvangen?</label></p>
        <?php if (!empty($turnstile_sitekey)): ?><div class="cf-turnstile" data-sitekey="<?php echo esc_attr($turnstile_sitekey); ?>" style="margin-bottom: 20px;"></div><?php endif; ?>
        <p><input type="submit" id="submit-ins-btn" value="Verstuur Inschrijving"></p>
    </form>
    <?php brink_render_form_styles($bg_color, $text_color, $primary_color, $btn_color, $btn_hover); ?>
    <?php return ob_get_clean();
}
add_shortcode('mystique_inschrijfformulier', 'brink_frontend_inschrijving_form');

// ==========================================
// 7E. FRONTEND SHORTCODE: OPENINGSTIJDEN & PRIJZEN
// ==========================================
function brink_frontend_openingstijden() {
    $data = get_option('brink_openingstijden_data');
    if (empty($data)) return '';
    $items = json_decode($data, true);
    if (!is_array($items) || empty($items)) return '';

    ob_start();
    ?>
    <style>
        .brink-ot-container {
            font-family: inherit;
            color: #ffffff;
            line-height: 1.8;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            font-size: 15px;
            background: transparent;
            max-width: 800px;
        }
        .brink-ot-container * { text-transform: uppercase; }
        .brink-ot-block { margin-bottom: 30px; }
        .brink-ot-header { font-weight: bold; margin-bottom: 5px; }
        .brink-ot-price-row { display: flex; gap: 15px; }
        .brink-ot-target { min-width: 140px; }
    </style>
    
    <div class="brink-ot-container">
        <?php foreach ($items as $item): ?>
            <div class="brink-ot-block" data-brink-day="<?php echo esc_attr($item['day']); ?>">
                <div class="brink-ot-header"><?php echo esc_html($item['day']); ?> VAN <?php echo esc_html($item['time']); ?></div>
                <?php if(!empty($item['prices'])) { 
                    foreach($item['prices'] as $price): ?>
                        <div class="brink-ot-price-row">
                            <span class="brink-ot-target"><?php echo esc_html($price['target']); ?></span>
                            <span class="brink-ot-value"><?php echo esc_html($price['price']); ?></span>
                        </div>
                <?php endforeach; } ?>
            </div>
        <?php endforeach; ?>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // FUNCTIONALITEIT (v5.17.0): meet per dag/thema hoeveel bezoekers dat blok daadwerkelijk
        // te zien krijgen (scroll-into-view), zodat de beheerder kan zien welk dag/thema de
        // meeste interesse trekt. Eén melding per dag/thema per bezoeker per dag (dedupe serverside).
        if (!('IntersectionObserver' in window)) return;
        var seen = new Set();
        var nonce = <?php echo wp_json_encode(wp_create_nonce('brink_fp_ot_track')); ?>;
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var day = entry.target.getAttribute('data-brink-day');
                if (!day || seen.has(day)) return;
                seen.add(day);
                observer.unobserve(entry.target);
                var formData = new FormData();
                formData.append('action', 'brink_fp_track_day_view');
                formData.append('nonce', nonce);
                formData.append('day', day);
                fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
            });
        }, { threshold: 0.5 });
        document.querySelectorAll('.brink-ot-block').forEach(function (el) { observer.observe(el); });
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('openingstijden_prijzen', 'brink_frontend_openingstijden');

// AJAX-handler die de "interesse per dag/thema"-view verwerkt (feature 16). Rate-limited en
// per bezoeker/dag/dag-thema gedupliceerd, zodat dit niet te misbruiken is om tellers op te
// blazen en de database niet nodeloos belast wordt.
add_action('wp_ajax_brink_fp_track_day_view', 'brink_fp_track_day_view');
add_action('wp_ajax_nopriv_brink_fp_track_day_view', 'brink_fp_track_day_view');
function brink_fp_track_day_view() {
    if (!check_ajax_referer('brink_fp_ot_track', 'nonce', false)) {
        wp_send_json_error(null, 403);
    }
    if (!brink_fp_rate_limit_check('ot_track', 60, 60)) {
        wp_send_json_error(null, 429);
    }

    $day = isset($_POST['day']) ? sanitize_text_field(wp_unslash($_POST['day'])) : '';
    if (empty($day) || strlen($day) > 200) {
        wp_send_json_error(null, 400);
    }

    $dedupe_key = 'brink_ot_view_' . md5(brink_fp_get_client_ip() . '|' . $day);
    if (!get_transient($dedupe_key)) {
        set_transient($dedupe_key, 1, DAY_IN_SECONDS);
        $counts = get_option('brink_openingstijden_views', array());
        if (!is_array($counts)) $counts = array();
        if (!isset($counts[$day])) $counts[$day] = 0;
        $counts[$day]++;
        update_option('brink_openingstijden_views', $counts);
    }

    wp_send_json_success();
}

// Helper CSS
function brink_render_form_styles($bg_color, $text_color, $primary_color, $btn_color, $btn_hover) {
    ?>
    <style>
        :root { --p-color: <?php echo $primary_color; ?>; --btn-color: <?php echo $btn_color; ?>; --btn-h: <?php echo $btn_hover; ?>; --bg-color: <?php echo $bg_color; ?>; --text-color: <?php echo $text_color; ?>; }
        .mystique-form { background: var(--bg-color); color: var(--text-color); padding: 30px; border-radius: 12px; border: 1px solid #eee; box-shadow: 0 4px 15px rgba(0,0,0,0.05); box-sizing: border-box; font-family: sans-serif; margin-bottom:20px; }
        .mystique-form p { margin-bottom: 20px; }
        .mystique-form label { font-weight: 600; display: block; margin-bottom: 8px; color: var(--text-color); font-size: 14px; }
        .mystique-form input[type="text"], .mystique-form input[type="email"], .mystique-form input[type="tel"], .mystique-form textarea, .mystique-form select { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; background: #fff; color: #333; }
        .mystique-form input:focus, .mystique-form textarea:focus { border-color: var(--p-color); outline: none; }
        .mystique-form input[type="submit"] { background: var(--btn-color); color: #fff; padding: 16px; border: none; cursor: pointer; border-radius: 6px; font-weight: bold; width: 100%; font-size: 18px; transition: 0.3s; }
        .mystique-form input[type="submit"]:hover { background: var(--btn-h); transform: translateY(-1px); }
        .mystique-alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; font-family: sans-serif; line-height: 1.5; }
        .mystique-alert.success { background: #e7f4e9; color: #1e4620; border: 1px solid #c3e6cb; }
        .mystique-alert.error { background: #fbe9e9; color: #761b18; border: 1px solid #f5c6cb; }
        .progress-wrapper { width: 100%; background: #eee; height: 6px; border-radius: 3px; overflow: hidden; margin-bottom: 5px; }
        #upload-bar { width: 0%; height: 100%; background: var(--p-color); transition: 0.5s; }
        @media (min-width: 600px) { .form-row { display: flex; gap: 20px; } .form-row p { flex: 1; } }
    </style>
    <?php
}

// ==========================================
// 8. AUTOMATISCHE SCHOONMAAK (Cron Job)
// ==========================================
// Let op: de cron zelf wordt uitsluitend bij activatie ingepland (zie brink_fp_activate hierboven),
// nooit op elke pageload — dat voorkomt onnodige wp_next_scheduled()-checks bij elk verzoek.
add_action('brink_cleanup_ads', 'brink_do_cleanup');
function brink_do_cleanup() {
    $expired_posts = get_posts(array(
        'post_type' => 'post', 'posts_per_page' => 50,
        'meta_query' => array(array('key' => 'expiration_date', 'value' => time(), 'compare' => '<', 'type' => 'NUMERIC'))
    ));
    foreach ($expired_posts as $post) {
        $placeholder_id = (int) get_option('brink_ad_placeholder_image');
        $thumb_id = (int) get_post_thumbnail_id($post->ID); 
        if ($thumb_id && $thumb_id !== $placeholder_id) {
            wp_delete_attachment($thumb_id, true);
        }
        wp_delete_post($post->ID, true);
    }
}

// ==========================================
// 9. SEO 301 REDIRECT ENGINE (Voor Ervaringen)
// ==========================================
add_action('add_meta_boxes', 'brink_add_redirect_meta_box');
function brink_add_redirect_meta_box() { add_meta_box('brink_redirect_meta_box', 'SEO Redirect (/ervaring/)', 'brink_render_redirect_meta_box', 'post', 'side', 'default'); }
function brink_render_redirect_meta_box($post) {
    wp_nonce_field('brink_save_redirect_meta', 'brink_redirect_nonce');
    $checked = (get_post_meta($post->ID, '_brink_ervaring_redirect', true) === '1') ? 'checked' : '';
    echo '<label style="display:block; margin-bottom:10px;"><input type="checkbox" name="brink_ervaring_redirect" value="1" ' . $checked . ' /> Activeer 301 redirect vanaf oude map.</label>';
    echo '<p class="description" style="font-size:12px; color:#666;">Stuurt <code>/ervaring/' . esc_html($post->post_name) . '</code> door naar de nieuwe URL.</p>';
}
add_action('save_post', 'brink_save_redirect_meta_box');
function brink_save_redirect_meta_box($post_id) {
    if (!isset($_POST['brink_redirect_nonce']) || !wp_verify_nonce($_POST['brink_redirect_nonce'], 'brink_save_redirect_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return; if (!current_user_can('edit_post', $post_id)) return;
    update_post_meta($post_id, '_brink_ervaring_redirect', isset($_POST['brink_ervaring_redirect']) ? '1' : '0');
}
add_action('parse_request', 'brink_ervaring_redirect_engine');
function brink_ervaring_redirect_engine($wp) {
    if (is_admin()) return;
    $request = $_SERVER['REQUEST_URI'];
    if (strpos($request, '/ervaring/') !== false) {
        $parsed_url = parse_url($request);
        if (preg_match('#/ervaring/([^/]+)/?$#i', $parsed_url['path'], $matches)) {
            $post = get_page_by_path($matches[1], OBJECT, 'post');
            if ($post && $post->post_status === 'publish' && get_post_meta($post->ID, '_brink_ervaring_redirect', true) === '1') {
                $new_url = get_permalink($post->ID) . (isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '');
                wp_redirect($new_url, 301); exit;
            }
        }
    }
}

// ==========================================
// 10. AVG BEVEILIGING (Formulier Gegevens Afschermen)
// ==========================================
// PERFORMANCE (v5.15.0): één gecachte check i.p.v. has_category() drie keer los aan te roepen
// (in template_redirect, pre_get_posts en wp_headers) voor dezelfde post binnen één request.
function brink_fp_is_avg_protected_post($post_id) {
    static $cache = array();
    if (isset($cache[$post_id])) return $cache[$post_id];

    $contact_cat = (int) get_option('brink_ad_contact_category');
    $inschrijving_cat = (int) get_option('brink_ad_inschrijving_category');
    $result = (($contact_cat && has_category($contact_cat, $post_id)) || ($inschrijving_cat && has_category($inschrijving_cat, $post_id)));

    $cache[$post_id] = $result;
    return $result;
}

add_action('template_redirect', 'brink_avg_block_frontend');
function brink_avg_block_frontend() {
    if (is_admin() || !is_single()) return; global $post;
    if ($post && brink_fp_is_avg_protected_post($post->ID)) {
        global $wp_query; $wp_query->set_404(); status_header(404); nocache_headers(); include(get_query_template('404')); exit;
    }
}
add_action('pre_get_posts', 'brink_avg_exclude_queries');
function brink_avg_exclude_queries($query) {
    if (!is_admin() && $query->is_main_query()) {
        $contact_cat = get_option('brink_ad_contact_category'); $inschrijving_cat = get_option('brink_ad_inschrijving_category');
        $exclude = array();
        if ($contact_cat) $exclude[] = '-' . $contact_cat; if ($inschrijving_cat) $exclude[] = '-' . $inschrijving_cat;
        if (!empty($exclude)) {
            $current_cat = $query->get('cat'); $new_cat = $current_cat ? $current_cat . ',' . implode(',', $exclude) : implode(',', $exclude);
            $query->set('cat', $new_cat);
        }
    }
}
add_filter('wp_headers', 'brink_avg_noindex_headers');
function brink_avg_noindex_headers($headers) {
    if (is_single()) {
        global $post;
        if ($post && brink_fp_is_avg_protected_post($post->ID)) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }
    }
    return $headers;
}

// ==========================================
// 11. UNINSTALL (Data-minimalisatie)
// ==========================================
// Verwijdert alle opties en de statistiektabel bij volledige verwijdering van de plugin
// (dus niet bij deactiveren) — we bewaren geen data langer dan de plugin daadwerkelijk gebruikt.
register_uninstall_hook(__FILE__, 'brink_fp_uninstall');
function brink_fp_uninstall() {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . "brink_stats");

    $brink_fp_options = array(
        'mystique_ad_bg_color', 'mystique_ad_text_color', 'mystique_ad_primary_color', 'mystique_ad_btn_color', 'mystique_ad_btn_hover_color',
        'brink_ad_allowed_categories', 'brink_ad_redirect_url', 'brink_ad_form_page_url', 'brink_ad_delete_redirect_url',
        'brink_ad_ervaringen_category', 'brink_ad_ervaringen_redirect_url', 'brink_ad_ervaringen_form_page_url', 'brink_ad_ervaringen_delete_redirect_url',
        'brink_ad_contact_category', 'brink_ad_contact_redirect_url',
        'brink_ad_inschrijving_category', 'brink_ad_inschrijving_redirect_url',
        'brink_ad_email_subject', 'brink_ad_email_body', 'brink_ad_ervaringen_email_subject', 'brink_ad_ervaringen_email_body',
        'brink_ad_contact_email_subject', 'brink_ad_contact_email_body', 'brink_ad_inschrijving_email_subject', 'brink_ad_inschrijving_email_body',
        'brink_ad_contact_emails', 'brink_ad_inschrijving_emails',
        'brink_ad_delete_reason_email_subject', 'brink_ad_delete_reason_email_body', 'brink_ad_ban_email_subject', 'brink_ad_ban_email_body',
        'brink_ad_noreply_enabled', 'brink_ad_noreply_email',
        'brink_ad_verify_email_subject', 'brink_ad_verify_email_body',
        'brink_turnstile_sitekey', 'brink_turnstile_secret', 'brink_ad_placeholder_image', 'brink_ad_bump_enabled', 'brink_ad_bump_limit',
        'brink_ad_banned_emails', 'brink_ad_email_verification_enabled',
        'brink_openingstijden_data', 'brink_fp_db_version',
    );
    foreach ($brink_fp_options as $brink_fp_option) {
        delete_option($brink_fp_option);
    }
}