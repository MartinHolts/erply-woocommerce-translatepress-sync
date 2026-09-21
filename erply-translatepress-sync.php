<?php
/**
 * Plugin Name: Erply ERP to WooCommerce TranslatePress Multilingual Sync
 * Plugin URI:  https://github.com/MartinHolts/erply-woocommerce-translatepress-sync
 * Description: Automatically synchronizes multilingual product titles, descriptions, and category hierarchies from Erply PIM into TranslatePress dictionary tables.
 * Version:     1.0.0
 * Author:      Martin Holtsmeier
 * License:     MIT
 */

if (!defined('ABSPATH')) {
    exit;
}

// ==========================================================================
// CONFIGURATION: Set your Erply Credentials & Target Languages
// ==========================================================================
define('ERPLY_SYNC_CLIENT_CODE',   'YOUR_ERPLY_CLIENT_CODE'); // e.g. '560191'
define('ERPLY_SYNC_USERNAME',      'YOUR_ERPLY_USERNAME');    // e.g. 'Martin'
define('ERPLY_SYNC_PASSWORD',      'YOUR_ERPLY_PASSWORD');    // e.g. 'SecretPassword!'
define('ERPLY_SYNC_AUTH_URL',      'https://' . ERPLY_SYNC_CLIENT_CODE . '.erply.com/api/');

// Language codes
define('ERPLY_SYNC_DEFAULT_LANG',  'est');   // Erply default API language
define('ERPLY_SYNC_TARGET_LANG',   'eng');   // Erply target API language
define('TRP_TARGET_LOCALE',        'en_US'); // TranslatePress target locale
define('TRP_TARGET_URL_PREFIX',    '/en/');  // Secondary storefront URL slug

// ==========================================================================
// 1. Cron Registration & Manual Trigger Hook
// ==========================================================================
add_action('admin_init', function () {
    if (!current_user_can('manage_options') || !isset($_GET['sync_erply_multilingual'])) {
        return;
    }
    erply_trp_execute_sync(true);
});

add_action('init', function () {
    if (!wp_next_scheduled('erply_trp_cron_sync_event')) {
        wp_schedule_event(time() + 3600, 'twicedaily', 'erply_trp_cron_sync_event');
    }
});

add_action('erply_trp_cron_sync_event', function () {
    erply_trp_execute_sync(false);
});

// ==========================================================================
// 2. Frontend Breadcrumb & Page Title Translation Hooks
// ==========================================================================
// A. Native WooCommerce Breadcrumbs Filter
add_filter('woocommerce_get_breadcrumb', function ($crumbs, $breadcrumb) {
    if (empty($crumbs) || !is_array($crumbs)) {
        return $crumbs;
    }

    $is_target_lang = false;
    if (function_exists('trp_get_locale')) {
        $is_target_lang = (strpos(trp_get_locale(), substr(TRP_TARGET_LOCALE, 0, 2)) === 0);
    } elseif (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], TRP_TARGET_URL_PREFIX) !== false) {
        $is_target_lang = true;
    } elseif (strpos(determine_locale(), substr(TRP_TARGET_LOCALE, 0, 2)) === 0) {
        $is_target_lang = true;
    }

    if (!$is_target_lang) {
        return $crumbs;
    }

    global $wpdb;
    $dict_table = "{$wpdb->prefix}trp_dictionary_" . strtolower(str_replace('-', '_', ERPLY_SYNC_DEFAULT_LANG)) . "_" . strtolower(TRP_TARGET_LOCALE);
    $has_dict = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $dict_table));
    if (!$has_dict) {
        // Fallback search for any active dictionary table
        $dict_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}trp_dictionary_%'");
        if (!$dict_table) return $crumbs;
    }

    foreach ($crumbs as &$crumb) {
        $original = trim($crumb[0]);
        if ($original === '') continue;

        $texturized = html_entity_decode(wptexturize($original), ENT_QUOTES, 'UTF-8');
        $trans = $wpdb->get_var($wpdb->prepare(
            "SELECT translated FROM {$dict_table} WHERE BINARY original = %s OR BINARY original = %s LIMIT 1",
            $original,
            $texturized
        ));

        if ($trans) {
            $crumb[0] = $trans;
        }
    }

    return $crumbs;
}, 99, 2);

// B. Astra Theme Breadcrumbs Trail Filter
add_filter('astra_breadcrumb_trail_items', function ($items) {
    if (empty($items) || !is_array($items)) return $items;

    $is_target_lang = false;
    if (function_exists('trp_get_locale')) {
        $is_target_lang = (strpos(trp_get_locale(), substr(TRP_TARGET_LOCALE, 0, 2)) === 0);
    } elseif (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], TRP_TARGET_URL_PREFIX) !== false) {
        $is_target_lang = true;
    }

    if (!$is_target_lang) return $items;

    global $wpdb;
    $dict_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}trp_dictionary_%'");
    if (!$dict_table) return $items;

    foreach ($items as &$item) {
        $clean = trim(strip_tags($item));
        if ($clean === '') continue;

        $texturized = html_entity_decode(wptexturize($clean), ENT_QUOTES, 'UTF-8');
        $trans = $wpdb->get_var($wpdb->prepare(
            "SELECT translated FROM {$dict_table} WHERE BINARY original = %s OR BINARY original = %s LIMIT 1",
            $clean,
            $texturized
        ));

        if ($trans) {
            $item = str_replace($clean, $trans, $item);
        }
    }

    return $items;
}, 99);

// C. Browser Document Title Filter
add_filter('document_title_parts', function ($parts) {
    $is_target_lang = false;
    if (function_exists('trp_get_locale')) {
        $is_target_lang = (strpos(trp_get_locale(), substr(TRP_TARGET_LOCALE, 0, 2)) === 0);
    } elseif (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], TRP_TARGET_URL_PREFIX) !== false) {
        $is_target_lang = true;
    }

    if (!$is_target_lang || empty($parts['title'])) return $parts;

    global $wpdb;
    $dict_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}trp_dictionary_%'");
    if (!$dict_table) return $parts;

    $orig = trim($parts['title']);
    $texturized = html_entity_decode(wptexturize($orig), ENT_QUOTES, 'UTF-8');

    $trans = $wpdb->get_var($wpdb->prepare(
        "SELECT translated FROM {$dict_table} WHERE BINARY original = %s OR BINARY original = %s LIMIT 1",
        $orig,
        $texturized
    ));

    if ($trans) {
        $parts['title'] = $trans;
    }

    return $parts;
}, 99);

// ==========================================================================
// 3. Core Erply ↔ TranslatePress Sync Function
// ==========================================================================
function erply_trp_execute_sync($is_manual = false) {
    global $wpdb;
    @set_time_limit(600);

    // A. Authenticate with Erply API
    $auth_response = wp_remote_post(ERPLY_SYNC_AUTH_URL, [
        'body' => [
            'request'      => 'verifyUser',
            'clientCode'   => ERPLY_SYNC_CLIENT_CODE,
            'username'     => ERPLY_SYNC_USERNAME,
            'password'     => ERPLY_SYNC_PASSWORD,
            'responseMode' => 'json'
        ],
        'timeout' => 30
    ]);

    if (is_wp_error($auth_response)) {
        if ($is_manual) wp_die('Erply Auth Failed: ' . esc_html($auth_response->get_error_message()));
        return;
    }

    $auth_data = json_decode(wp_remote_retrieve_body($auth_response), true);
    $session_key = $auth_data['records'][0]['sessionKey'] ?? null;

    if (!$session_key) {
        if ($is_manual) wp_die('Could not retrieve Erply session key.');
        return;
    }

    // B. Fetch Top-Level Product Groups (getProductGroups)
    $fetch_groups = function ($lang) use ($session_key) {
        $res = wp_remote_post(ERPLY_SYNC_AUTH_URL, [
            'body' => [
                'request'      => 'getProductGroups',
                'clientCode'   => ERPLY_SYNC_CLIENT_CODE,
                'sessionKey'   => $session_key,
                'lang'         => $lang,
                'responseMode' => 'json'
            ],
            'timeout' => 30
        ]);
        if (is_wp_error($res)) return [];
        $body = json_decode(wp_remote_retrieve_body($res), true);
        return $body['records'] ?? [];
    };

    $groups_default = $fetch_groups(ERPLY_SYNC_DEFAULT_LANG);
    $groups_target  = $fetch_groups(ERPLY_SYNC_TARGET_LANG);
    $groups_target_map = [];
    foreach ($groups_target as $g) {
        if (!empty($g['productGroupID']) && !empty($g['name'])) {
            $groups_target_map[$g['productGroupID']] = $g['name'];
        }
    }

    // C. Paginate getProducts
    $fetch_all_products = function ($lang) use ($session_key) {
        $all_products = [];
        $page = 1;
        $records_per_page = 250;

        while (true) {
            $response = wp_remote_post(ERPLY_SYNC_AUTH_URL, [
                'body' => [
                    'request'            => 'getProducts',
                    'clientCode'         => ERPLY_SYNC_CLIENT_CODE,
                    'sessionKey'         => $session_key,
                    'lang'               => $lang,
                    'pageNo'             => $page,
                    'recordsOnPage'      => $records_per_page,
                    'displayedInWebshop' => 1,
                    'responseMode'       => 'json'
                ],
                'timeout' => 60
            ]);

            if (is_wp_error($response)) break;

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $records = $body['records'] ?? [];
            if (empty($records)) break;

            foreach ($records as $rec) {
                $all_products[] = $rec;
            }

            $total_records = (int)($body['status']['recordsTotal'] ?? 0);
            if (count($all_products) >= $total_records || count($records) < $records_per_page) {
                break;
            }
            $page++;
        }
        return $all_products;
    };

    $prods_default = $fetch_all_products(ERPLY_SYNC_DEFAULT_LANG);
    $prods_target  = $fetch_all_products(ERPLY_SYNC_TARGET_LANG);

    $target_map = [];
    foreach ($prods_target as $p) {
        if (!empty($p['productID'])) {
            $target_map[$p['productID']] = $p;
        }
    }

    // D. Identify TranslatePress Tables
    $prefix = $wpdb->prefix;
    $dict_table = $wpdb->get_var("SHOW TABLES LIKE '{$prefix}trp_dictionary_%'");
    $orig_table = "{$prefix}trp_original_strings";

    if (!$dict_table) {
        if ($is_manual) wp_die('TranslatePress dictionary table not found.');
        return;
    }

    // E. Build Translation Pairs with String Normalization
    $translation_pairs = [];

    $add_pair = function ($def, $tgt) use (&$translation_pairs) {
        $def = trim((string)$def);
        $tgt = trim((string)$tgt);
        if ($def === '' || $tgt === '' || $def === $tgt) {
            return;
        }

        // 1. Raw exact variant
        $translation_pairs[$def] = $tgt;

        // 2. Texturized variant
        $def_texturized = wptexturize($def);
        $tgt_texturized = wptexturize($tgt);
        $translation_pairs[$def_texturized] = $tgt_texturized;

        // 3. HTML Entity decoded variant
        $def_decoded = html_entity_decode($def_texturized, ENT_QUOTES, 'UTF-8');
        $tgt_decoded = html_entity_decode($tgt_texturized, ENT_QUOTES, 'UTF-8');
        $translation_pairs[$def_decoded] = $tgt_decoded;

        // 4. Multiplication sign variations (2x40 -> 2×40)
        $def_times = preg_replace('/(\d+)\s*x\s*(\d+)/i', '$1×$2', $def);
        $tgt_times = preg_replace('/(\d+)\s*x\s*(\d+)/i', '$1×$2', $tgt);
        if ($def_times !== $def) {
            $translation_pairs[$def_times] = $tgt_times;
            $translation_pairs[html_entity_decode(wptexturize($def_times), ENT_QUOTES, 'UTF-8')] = html_entity_decode(wptexturize($tgt_times), ENT_QUOTES, 'UTF-8');
        }

        // 5. Binary casing variations (Title Case vs lowercase)
        $def_lower = mb_strtolower($def, 'UTF-8');
        $tgt_lower = mb_strtolower($tgt, 'UTF-8');
        if (!isset($translation_pairs[$def_lower])) {
            $translation_pairs[$def_lower] = $tgt_lower;
        }
    };

    $stats_titles = 0;
    $stats_categories = 0;

    // 1. Sync Top-Level Groups
    foreach ($groups_default as $g_def) {
        $gid = $g_def['productGroupID'] ?? null;
        if ($gid && isset($groups_target_map[$gid])) {
            $name_def = $g_def['name'];
            $name_tgt = $groups_target_map[$gid];
            if ($name_def && $name_tgt && $name_def !== $name_tgt) {
                $add_pair($name_def, $name_tgt);
                $stats_categories++;
            }
        }
    }

    // 2. Sync Product Titles, Descriptions, Group/Series Classifications
    foreach ($prods_default as $p_def) {
        $pid = $p_def['productID'] ?? null;
        if (!$pid || !isset($target_map[$pid])) continue;

        $p_tgt = $target_map[$pid];

        // Product Title
        $title_def = $p_def['name'] ?? '';
        $title_tgt = $p_tgt['name'] ?? '';
        if ($title_def && $title_tgt && $title_def !== $title_tgt) {
            $add_pair($title_def, $title_tgt);
            $stats_titles++;
        }

        // Product Descriptions
        $desc_def = $p_def['description'] ?? '';
        $desc_tgt = $p_tgt['description'] ?? ($p_tgt['descriptionENG'] ?? '');
        if ($desc_def && $desc_tgt && $desc_def !== $desc_tgt) {
            $add_pair($desc_def, $desc_tgt);
        }

        // Classifications
        foreach (['groupName', 'seriesName', 'categoryName', 'priorityGroupName'] as $field) {
            $val_def = $p_def[$field] ?? '';
            $val_tgt = $p_tgt[$field] ?? '';
            if ($val_def && $val_tgt && $val_def !== $val_tgt) {
                $add_pair($val_def, $val_tgt);
                $stats_categories++;
            }
        }
    }

    // 3. Dynamically discover WooCommerce Category terms
    $wc_categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    if (!is_wp_error($wc_categories) && !empty($wc_categories)) {
        foreach ($wc_categories as $term) {
            $term_name = trim($term->name);
            $term_slug = trim($term->slug);

            if ($term_slug === 'uncategorized' || $term_slug === 'maaramata') continue;

            $words = explode('-', $term_slug);
            $capitalized = array_map(function ($w) {
                if (in_array(strtolower($w), ['and', 'or', 'of', 'in', 'the', 'for', 'to'])) return strtolower($w);
                if (in_array(strtoupper($w), ['box', 'rpm', 'ntc', 'ir', 'okj', 'ok', 'kz', 'sku'])) return strtoupper($w);
                return ucfirst($w);
            }, $words);
            $slug_label = ucfirst(implode(' ', $capitalized));

            if ($term_name !== '' && $slug_label !== '' && $term_name !== $slug_label) {
                $add_pair($term_name, $slug_label);
                $stats_categories++;
            }
        }
    }

    // F. Upsert into TranslatePress tables with BINARY precision
    $synced_count = 0;
    foreach ($translation_pairs as $original => $translated) {
        $original_id = 0;

        $has_orig_table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $orig_table));
        if ($has_orig_table) {
            $existing_orig_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$orig_table} WHERE BINARY original = %s LIMIT 1",
                $original
            ));
            if ($existing_orig_id) {
                $original_id = (int)$existing_orig_id;
            } else {
                $wpdb->insert($orig_table, ['original' => $original]);
                $original_id = (int)$wpdb->insert_id;
            }
        }

        $existing_dict_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$dict_table} WHERE BINARY original = %s LIMIT 1",
            $original
        ));

        if ($existing_dict_id) {
            $update_data = ['translated' => $translated, 'status' => 2];
            if ($original_id > 0) $update_data['original_id'] = $original_id;
            $wpdb->update($dict_table, $update_data, ['id' => $existing_dict_id]);
        } else {
            $insert_data = [
                'original'    => $original,
                'translated'  => $translated,
                'status'      => 2,
                'block_type'  => 0,
                'original_id' => $original_id
            ];
            $wpdb->insert($dict_table, $insert_data);
        }
        $synced_count++;
    }

    wp_cache_flush();

    if ($is_manual) {
        wp_die("
            <div style='font-family: sans-serif; max-width: 500px; margin: 50px auto; background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #ddd;'>
                <h3 style='color: #059669; margin-top: 0;'>✓ Multilingual Sync Completed!</h3>
                <p>Synced: {$stats_titles} titles, {$stats_categories} categories ({$synced_count} casing matrix entries).</p>
            </div>
        ");
    }
}
