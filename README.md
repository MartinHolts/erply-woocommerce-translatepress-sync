# Erply ERP ↔ WooCommerce TranslatePress Multilingual Sync

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-9.0%2B-purple)](https://woocommerce.com)
[![TranslatePress](https://img.shields.io/badge/TranslatePress-Free%20%2F%20Pro-green)](https://translatepress.com)

An automated, zero-hack multilingual bridge between **Erply ERP/POS (PIM)** and **WooCommerce with TranslatePress**.

---

## 🎯 The Problem This Solves

1. **The 1-Language Connector Limit:** The official *Erply Automat* WooCommerce connector only synchronizes one language per connection (e.g. Estonian). Erply stores multilingual product records (`nameENG`, `descriptionENG`), but Automat cannot push both languages simultaneously to WooCommerce.
2. **The TranslatePress Automation Barrier:** TranslatePress (Free) normally relies on manual frontend visits to translate strings. For e-commerce catalogs with 100 to 5,000+ products, manual clicking is impossible.
3. **Database Case Sensitivity & Breadcrumb Pitfalls:** MySQL collation is often case-insensitive, but TranslatePress's internal PHP array key lookups are strictly case-sensitive. Additionally, WooCommerce breadcrumb trails render outside the main loop, causing standard DOM translation parsers to miss product titles.

---

## 🚀 What This Solution Does

- **Automated API Sync:** Authenticates with the Erply API and extracts all products and product groups in both default and target languages.
- **Direct TranslatePress Matrix Injection:** Upserts translations directly into `wp_trp_dictionary_*` and `wp_trp_original_strings`.
- **Case-Sensitive Binary Collation:** Enforces `BINARY original = %s` matching so both Title Case and lowercase strings resolve flawlessly in PHP memory.
- **100% Dynamic Category Hierarchy:**
  - Extracts top-level groups via Erply `getProductGroups`.
  - Extracts subcategories and series via Erply `getProducts`.
  - Discovers overarching WooCommerce store categories via taxonomy term slugs (`get_terms`).
  - **Zero hardcoded category arrays.**
- **Breadcrumbs & Browser Title Hooks:** Hooks directly into `woocommerce_get_breadcrumb`, `astra_breadcrumb_trail_items`, and `document_title_parts` to ensure breadcrumb trails and browser tab titles translate accurately.
- **Automated Scheduling:** Runs automatically twice daily via WP-Cron, plus provides an instant manual trigger URL for administrators.

---

## 📦 Installation & Setup

### Method 1: Code Snippets Plugin (Recommended)
1. In your WordPress Admin, install and activate [Code Snippets](https://wordpress.org/plugins/code-snippets/).
2. Create a new snippet, paste `erply-translatepress-sync.php`, and select **Run snippet everywhere**.
3. Fill in your credentials in the configuration constants at the top:
   ```php
   define('ERPLY_SYNC_CLIENT_CODE', 'YOUR_ERPLY_CLIENT_CODE');
   define('ERPLY_SYNC_USERNAME',    'YOUR_ERPLY_USERNAME');
   define('ERPLY_SYNC_PASSWORD',    'YOUR_ERPLY_PASSWORD');
   ```
4. Click **Save Changes and Activate**.

### Method 2: Must-Use Plugin
Drop the file into your `/wp-content/mu-plugins/` folder as `erply-multilingual-sync.php`.

---

## ⚡ Usage

- **Automated:** Runs twice daily in the background via WP-Cron (`erply_trp_cron_sync_event`).
- **Manual Trigger:** Log in as an Administrator and visit:
  ```text
  https://yourstore.com/wp-admin/?sync_erply_multilingual=1
  ```

---

## 📄 License
Released under the [MIT License](LICENSE).
