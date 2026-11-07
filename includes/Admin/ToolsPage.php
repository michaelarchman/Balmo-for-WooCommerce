<?php

namespace Balmo\Admin;

use Balmo\Service\Integration;
use Balmo\Service\ProductRemover;

if (!defined('ABSPATH')) exit;

class ToolsPage
{
    public const PAGE_SLUG = 'balmo-tools';

    public static function init(): void
    {
        add_action('wp_ajax_balmo_tools_count', [self::class, 'ajaxCount']);
        add_action('wp_ajax_balmo_tools_start', [self::class, 'ajaxStart']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'balmo'));
        }

        $import_id = absint($_GET['import_id'] ?? 0);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Narzędzia Balmo', 'balmo'); ?></h1>

            <div class="balmo-tools-section" data-by="<?php echo esc_attr(ProductRemover::BY_INTEGRATION); ?>">
                <h2><?php esc_html_e('Usuń produkty integracji', 'balmo'); ?></h2>
                <p>
                    <select class="balmo-tools-source">
                        <option value=""><?php esc_html_e('— wybierz integrację —', 'balmo'); ?></option>
                        <?php foreach (Integration::terms() as $term_id => $name): ?>
                            <option value="<?php echo esc_attr((string) $term_id); ?>">
                                <?php echo esc_html(sprintf(
                                    '%s (%d)',
                                    $name,
                                    ProductRemover::count(ProductRemover::BY_INTEGRATION, (string) $term_id)
                                )); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <?php self::renderControls(); ?>
            </div>

            <div class="balmo-tools-section" data-by="<?php echo esc_attr(ProductRemover::BY_IMPORT); ?>">
                <h2><?php esc_html_e('Usuń produkty importu', 'balmo'); ?></h2>
                <p>
                    <label>
                        <?php esc_html_e('ID importu', 'balmo'); ?>
                        <input type="number" min="1" class="balmo-tools-source small-text"
                               value="<?php echo $import_id ? esc_attr((string) $import_id) : ''; ?>">
                    </label>
                </p>
                <?php self::renderControls(); ?>
            </div>
        </div>
        <?php
    }

    private static function renderControls(): void
    {
        ?>
        <p>
            <label>
                <input type="checkbox" class="balmo-tools-force">
                <?php esc_html_e('Usuń trwale (bez kosza)', 'balmo'); ?>
            </label>
        </p>
        <p>
            <button type="button" class="button balmo-tools-count-btn"><?php esc_html_e('Pokaż liczbę', 'balmo'); ?></button>
            <span class="balmo-tools-count-result"></span>
        </p>
        <div class="balmo-tools-confirm-wrap" style="display:none;">
            <p>
                <label>
                    <span class="balmo-tools-confirm-label"></span><br>
                    <input type="text" class="balmo-tools-confirm regular-text" autocomplete="off">
                </label>
            </p>
            <p>
                <button type="button" class="button button-primary balmo-tools-start" disabled>
                    <?php esc_html_e('Usuń', 'balmo'); ?>
                </button>
            </p>
        </div>
        <div class="balmo-tools-progress" style="display:none;">
            <progress class="balmo-tools-bar" value="0" max="100"></progress>
            <span class="balmo-tools-status"></span>
        </div>
        <?php
    }

    // --- AJAX: product count and the phrase the user must type ---

    public static function ajaxCount(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(__('Brak uprawnień.', 'balmo'));

        $by    = sanitize_key($_POST['by'] ?? '');
        $value = sanitize_text_field(wp_unslash($_POST['value'] ?? ''));

        $expected = self::confirmationPhrase($by, $value);
        if ($expected === '') {
            wp_send_json_error(__('Nie znaleziono integracji lub importu.', 'balmo'));
        }

        wp_send_json_success([
            'count'    => ProductRemover::count($by, $value),
            'expected' => $expected,
        ]);
    }

    // --- AJAX: start the removal ---

    public static function ajaxStart(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(__('Brak uprawnień.', 'balmo'));

        $by      = sanitize_key($_POST['by'] ?? '');
        $value   = sanitize_text_field(wp_unslash($_POST['value'] ?? ''));
        $force   = !empty($_POST['force']) && $_POST['force'] !== 'false';
        $confirm = trim((string) wp_unslash($_POST['confirm'] ?? ''));

        $expected = self::confirmationPhrase($by, $value);
        if ($expected === '' || $confirm !== $expected) {
            wp_send_json_error(__('Potwierdzenie nie pasuje.', 'balmo'));
        }

        $log_id = ProductRemover::schedule($by, $value, $force);
        if (!$log_id) {
            wp_send_json_error(__('Brak produktów do usunięcia.', 'balmo'));
        }

        wp_send_json_success(['import_id' => $log_id]);
    }

    // Name of the integration or ID of the import; '' when the target does not exist
    private static function confirmationPhrase(string $by, string $value): string
    {
        if (!ctype_digit($value) || (int) $value <= 0) return '';

        if ($by === ProductRemover::BY_INTEGRATION) {
            $term = get_term((int) $value, Integration::TAXONOMY);
            return $term instanceof \WP_Term ? wp_specialchars_decode($term->name, ENT_QUOTES) : '';
        }

        if ($by === ProductRemover::BY_IMPORT) {
            global $wpdb;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . \Balmo\table_import_log() . " WHERE id = %d AND import_mode <> %s",
                (int) $value, ProductRemover::MODE
            ));
            return $exists ? (string) (int) $value : '';
        }

        return '';
    }
}
