<?php

namespace Balmo\Admin;

if (!defined('ABSPATH')) exit;

class Settings
{
    private const MENU_SLUG = 'balmo';

    // Attribute values dropped at import, one per line (case-insensitive)
    public const DEFAULT_ATTRIBUTE_SKIP_VALUES = "Brak Informacji\nnie dotyczy";

    public static function init(): void
    {
        add_action('admin_menu',    [self::class, 'registerMenu']);
        add_action('admin_init',    [self::class, 'registerSettings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function registerMenu(): void
    {
        add_menu_page(
            __('Balmo Import', 'balmo'),
            __('Balmo', 'balmo'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [ImportPage::class, 'render'],
            'dashicons-database-import',
            56
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Import CSV', 'balmo'),
            __('Import CSV', 'balmo'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [ImportPage::class, 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Profile mapowania', 'balmo'),
            __('Mapowanie', 'balmo'),
            'manage_woocommerce',
            'balmo-mapping',
            [MappingPage::class, 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Harmonogram importu', 'balmo'),
            __('Harmonogram', 'balmo'),
            'manage_woocommerce',
            'balmo-scheduler',
            [SchedulerPage::class, 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Historia importów', 'balmo'),
            __('Historia', 'balmo'),
            'manage_woocommerce',
            'balmo-history',
            [HistoryPage::class, 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Narzędzia Balmo', 'balmo'),
            __('Narzędzia', 'balmo'),
            'manage_woocommerce',
            ToolsPage::PAGE_SLUG,
            [ToolsPage::class, 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Ustawienia Balmo', 'balmo'),
            __('Ustawienia', 'balmo'),
            'manage_woocommerce',
            'balmo-settings',
            [self::class, 'render']
        );
    }

    public static function registerSettings(): void
    {
        register_setting('balmo_settings', 'balmo_batch_size', [
            'type'              => 'integer',
            'sanitize_callback' => function (string $v): int {
                $v = (int) $v;
                return max(50, min(1000, $v));
            },
            'default' => 200,
        ]);

        register_setting('balmo_settings', 'balmo_image_mode', [
            'type'              => 'string',
            'sanitize_callback' => function (string $v): string {
                return in_array($v, ['none', 'external'], true) ? $v : 'none';
            },
            'default' => 'none',
        ]);

        register_setting('balmo_settings', 'balmo_default_status', [
            'type'              => 'string',
            'sanitize_callback' => function (string $v): string {
                return in_array($v, ['publish', 'draft', 'pending'], true) ? $v : 'publish';
            },
            'default' => 'publish',
        ]);

        register_setting('balmo_settings', 'balmo_log_retention', [
            'type'              => 'integer',
            'sanitize_callback' => function (string $v): int {
                return max(1, min(365, (int) $v));
            },
            'default' => 30,
        ]);

        register_setting('balmo_settings', 'balmo_attribute_skip_values', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => self::DEFAULT_ATTRIBUTE_SKIP_VALUES,
        ]);
    }

    public static function enqueueAssets(string $hook): void
    {
        $balmo_pages = [
            'toplevel_page_balmo',
            'balmo_page_balmo-mapping',
            'balmo_page_balmo-scheduler',
            'balmo_page_balmo-history',
            'balmo_page_balmo-tools',
            'balmo_page_balmo-settings',
        ];
        if (!in_array($hook, $balmo_pages, true)) return;

        $style_version  = file_exists(BALMO_PLUGIN_DIR . 'assets/admin.css')
            ? (string) filemtime(BALMO_PLUGIN_DIR . 'assets/admin.css')
            : BALMO_VERSION;
        $script_version = file_exists(BALMO_PLUGIN_DIR . 'assets/admin.js')
            ? (string) filemtime(BALMO_PLUGIN_DIR . 'assets/admin.js')
            : BALMO_VERSION;

        wp_enqueue_style(
            'balmo-admin',
            BALMO_PLUGIN_URL . 'assets/admin.css',
            [],
            $style_version
        );

        wp_enqueue_script(
            'balmo-admin',
            BALMO_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            $script_version,
            true
        );

        wp_localize_script('balmo-admin', 'balmoAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('balmo_ajax'),
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'balmo'));
        }

        $batch_size     = (int) get_option('balmo_batch_size', 200);
        $image_mode     = get_option('balmo_image_mode', 'none');
        $default_status = get_option('balmo_default_status', 'publish');
        $log_retention  = (int) get_option('balmo_log_retention', 30);
        $skip_values    = (string) get_option('balmo_attribute_skip_values', self::DEFAULT_ATTRIBUTE_SKIP_VALUES);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Ustawienia Balmo', 'balmo'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('balmo_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="balmo_batch_size"><?php esc_html_e('Rozmiar transzy (wiersze)', 'balmo'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="balmo_batch_size" name="balmo_batch_size"
                                   value="<?php echo esc_attr($batch_size); ?>" min="50" max="1000" step="50">
                            <p class="description">
                                <?php esc_html_e('Liczba wierszy CSV przetwarzanych w jednym żądaniu AJAX. Zalecane: 200. Przy wolnych serwerach zmniejsz do 50-100.', 'balmo'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="balmo_default_status"><?php esc_html_e('Domyślny status produktu', 'balmo'); ?></label>
                        </th>
                        <td>
                            <select id="balmo_default_status" name="balmo_default_status">
                                <option value="publish" <?php selected($default_status, 'publish'); ?>>
                                    <?php esc_html_e('Opublikowany', 'balmo'); ?>
                                </option>
                                <option value="draft" <?php selected($default_status, 'draft'); ?>>
                                    <?php esc_html_e('Szkic', 'balmo'); ?>
                                </option>
                                <option value="pending" <?php selected($default_status, 'pending'); ?>>
                                    <?php esc_html_e('Oczekujący', 'balmo'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Używany gdy kolumna "active" nie jest zamapowana lub nie istnieje w pliku.', 'balmo'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="balmo_image_mode"><?php esc_html_e('Zdjęcia produktów', 'balmo'); ?></label>
                        </th>
                        <td>
                            <select id="balmo_image_mode" name="balmo_image_mode">
                                <option value="none" <?php selected($image_mode, 'none'); ?>>
                                    <?php esc_html_e('Nie importuj zdjęć', 'balmo'); ?>
                                </option>
                                <option value="external" <?php selected($image_mode, 'external'); ?>>
                                    <?php esc_html_e('Wyświetlaj z zewnętrznych URL (bez pobierania na serwer)', 'balmo'); ?>
                                </option>

                            </select>
                            <p class="description">
                                <?php esc_html_e('Tryb "zewnętrzne URL" — zdjęcia wyświetlane są bezpośrednio z serwera dostawcy bez zajmowania miejsca na dysku. Wymaga mapowania kolumn ze zdjęciami.', 'balmo'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="balmo_log_retention"><?php esc_html_e('Retencja logów (dni)', 'balmo'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="balmo_log_retention" name="balmo_log_retention"
                                   value="<?php echo esc_attr($log_retention); ?>" min="1" max="365">
                            <p class="description">
                                <?php esc_html_e('Po ilu dniach automatycznie usuwać stare logi importu.', 'balmo'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="balmo_attribute_skip_values"><?php esc_html_e('Pomijane wartości atrybutów', 'balmo'); ?></label>
                        </th>
                        <td>
                            <textarea id="balmo_attribute_skip_values" name="balmo_attribute_skip_values"
                                      rows="4" class="large-text"><?php echo esc_textarea($skip_values); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Jedna wartość na linię, bez rozróżniania wielkości liter. Atrybuty z taką wartością nie są importowane.', 'balmo'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Zapisz ustawienia', 'balmo')); ?>
            </form>
        </div>
        <?php
    }
}
