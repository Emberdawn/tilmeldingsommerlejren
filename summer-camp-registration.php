<?php
/**
 * Plugin Name: Sommerlejr Tilmelding
 * Description: Giver registrerede brugere mulighed for at tilmelde sig sommerlejr med gem/indsend, prisberegning og admin-godkendelse.
 * Version: 1.0.0
 * Author: Codex
 */

if (!defined('ABSPATH')) {
    exit;
}

class SommerlejrTilmeldingPlugin
{
    private const REG_TABLE = 'summer_camp_registrations';
    private const PRICE_OPTION = 'summer_camp_price_settings';
    private const NONCE_ACTION = 'summer_camp_form_nonce';

    public function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_shortcode('summer_camp_registration', [$this, 'render_registration_shortcode']);
        add_action('admin_menu', [$this, 'register_admin_menus']);
        add_action('admin_post_summer_camp_save_prices', [$this, 'handle_save_prices']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_summer_camp_approve_registration', [$this, 'handle_approve_registration']);
        add_action('plugins_loaded', [$this, 'maybe_upgrade_schema']);
    }

    public function maybe_upgrade_schema(): void
    {
        global $wpdb;

        $table = $this->registrations_table_name();
        $dayTicketsColumn = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'day_tickets'");

        if ($dayTicketsColumn === null) {
            $this->activate();
        }
    }

    public function activate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $table = $this->registrations_table_name();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            adults INT UNSIGNED NOT NULL DEFAULT 0,
            children INT UNSIGNED NOT NULL DEFAULT 0,
            day_tickets INT UNSIGNED NOT NULL DEFAULT 0,
            days_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            transfer_screenshot_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            submitted_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status)
        ) {$charset};";

        dbDelta($sql);

        if (!get_option(self::PRICE_OPTION)) {
            add_option(self::PRICE_OPTION, $this->default_prices());
        }
    }

    private function registrations_table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::REG_TABLE;
    }

    private function default_prices(): array
    {
        return [
            'adult_full' => 1000,
            'child_full' => 700,
            'day_ticket' => 120,
        ];
    }

    private function get_prices(): array
    {
        $prices = get_option(self::PRICE_OPTION, []);
        $defaults = $this->default_prices();

        return wp_parse_args($prices, $defaults);
    }

    public function enqueue_assets(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        wp_enqueue_script('jquery');
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if ($hook !== 'summerlejr_page_summer-camp-pending') {
            return;
        }

        wp_add_inline_style('wp-admin', '
            .summer-camp-modal {display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,.8); align-items:center; justify-content:center;}
            .summer-camp-modal.active {display:flex;}
            .summer-camp-modal img {max-width:95vw; max-height:95vh; cursor:zoom-in; transform-origin:center center;}
            .summer-camp-modal .close {position:absolute; top:20px; right:20px; color:#fff; font-size:32px; cursor:pointer;}
        ');

        wp_add_inline_script('jquery-core', '
            jQuery(function($){
                let zoom = 1;
                $(document).on("click", ".js-open-proof", function(e){
                    e.preventDefault();
                    const src = $(this).data("src");
                    zoom = 1;
                    $("#summer-camp-proof-image").attr("src", src).css("transform", "scale(1)");
                    $("#summer-camp-modal").addClass("active");
                });
                $(document).on("click", "#summer-camp-modal, #summer-camp-modal .close", function(){
                    $("#summer-camp-modal").removeClass("active");
                });
                $(document).on("click", "#summer-camp-proof-image", function(e){
                    e.stopPropagation();
                    zoom = zoom >= 3 ? 1 : zoom + 0.5;
                    $(this).css("transform", "scale("+zoom+")");
                });
            });
        ');
    }

    private function get_user_latest_registration(int $user_id)
    {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->registrations_table_name()} WHERE user_id = %d ORDER BY id DESC LIMIT 1",
                $user_id
            )
        );
    }

    private function calculate_total(int $adults, int $children, int $dayTickets): float
    {
        $prices = $this->get_prices();

        return ($adults * (float) $prices['adult_full'])
            + ($children * (float) $prices['child_full'])
            + ($dayTickets * (float) $prices['day_ticket']);
    }

    public function render_registration_shortcode(): string
    {
        if (!is_user_logged_in()) {
            return '<p>Du skal være logget ind for at tilmelde dig sommerlejren.</p>';
        }

        $user_id = get_current_user_id();
        $registration = $this->get_user_latest_registration($user_id);

        if (isset($_POST['summer_camp_action']) && check_admin_referer(self::NONCE_ACTION)) {
            $registration = $this->process_form_submission($user_id, $registration);
        }

        $adults = $registration ? (int) $registration->adults : 0;
        $children = $registration ? (int) $registration->children : 0;
        $dayTickets = $registration ? (int) $registration->day_tickets : 0;
        $total = $this->calculate_total($adults, $children, $dayTickets);
        $status = $registration ? $registration->status : 'draft';

        ob_start();
        ?>
        <form method="post" enctype="multipart/form-data" style="max-width:700px;display:grid;gap:12px;padding:16px;border:1px solid #ddd;border-radius:8px;">
            <?php wp_nonce_field(self::NONCE_ACTION); ?>
            <h3>Sommerlejr tilmelding</h3>

            <?php if (isset($_GET['summer_camp_saved'])) : ?>
                <p style="color:#0a7d22;">Dine oplysninger er gemt.</p>
            <?php endif; ?>

            <?php if (isset($_GET['summer_camp_submitted'])) : ?>
                <p style="color:#0a7d22;">Din tilmelding er sendt til godkendelse.</p>
            <?php endif; ?>

            <label>
                Antal voksne
                <input type="number" min="0" max="99" name="adults" value="<?php echo esc_attr((string) $adults); ?>" required style="max-width:90px;">
            </label>

            <label>
                Antal børn
                <input type="number" min="0" max="99" name="children" value="<?php echo esc_attr((string) $children); ?>" required style="max-width:90px;">
            </label>

            <label>
                Dagsbillet
                <input type="number" min="0" max="99" name="day_tickets" value="<?php echo esc_attr((string) $dayTickets); ?>" required style="max-width:90px;">
            </label>

            <label>
                Screenshot af overførsel (png/jpg/pdf)
                <input type="file" name="transfer_screenshot" accept="image/*,application/pdf" <?php echo $status === 'submitted' ? 'disabled' : ''; ?>>
            </label>

            <?php if ($registration && $registration->transfer_screenshot_id) : ?>
                <?php $url = wp_get_attachment_url((int) $registration->transfer_screenshot_id); ?>
                <?php if ($url) : ?>
                    <p>Nuværende fil: <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">Åbn upload</a></p>
                <?php endif; ?>
            <?php endif; ?>

            <p><strong>Samlet pris: <?php echo esc_html(number_format_i18n($total, 2)); ?> kr.</strong></p>

            <?php
            $hasRequired = ($adults + $children + $dayTickets) > 0
                && ($registration && (int) $registration->transfer_screenshot_id > 0);
            ?>

            <div style="display:flex;gap:10px;">
                <button type="submit" name="summer_camp_action" value="save" class="button button-secondary">Gem</button>

                <?php if ($hasRequired && $status !== 'submitted') : ?>
                    <button type="submit" name="summer_camp_action" value="submit" class="button button-primary">Send til godkendelse</button>
                <?php else : ?>
                    <button type="button" class="button button-primary" style="opacity:.5;cursor:not-allowed;" disabled>Send til godkendelse</button>
                <?php endif; ?>
            </div>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    private function process_form_submission(int $user_id, $existing)
    {
        $action = sanitize_text_field((string) $_POST['summer_camp_action']);
        $adults = isset($_POST['adults']) ? min(99, max(0, (int) $_POST['adults'])) : 0;
        $children = isset($_POST['children']) ? min(99, max(0, (int) $_POST['children'])) : 0;
        $dayTickets = isset($_POST['day_tickets']) ? min(99, max(0, (int) $_POST['day_tickets'])) : 0;

        $screenshotId = $existing ? (int) $existing->transfer_screenshot_id : 0;
        if (!empty($_FILES['transfer_screenshot']['name'])) {
            $upload = $this->handle_upload($_FILES['transfer_screenshot']);
            if (!is_wp_error($upload)) {
                $screenshotId = (int) $upload;
            }
        }

        $status = $action === 'submit' ? 'submitted' : 'draft';
        $total = $this->calculate_total($adults, $children, $dayTickets);

        global $wpdb;
        $table = $this->registrations_table_name();

        $data = [
            'user_id' => $user_id,
            'adults' => $adults,
            'children' => $children,
            'day_tickets' => $dayTickets,
            'days_count' => 0,
            'total_price' => $total,
            'transfer_screenshot_id' => $screenshotId ?: null,
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ];

        if ($status === 'submitted') {
            $data['submitted_at'] = current_time('mysql');
        }

        if ($existing) {
            $wpdb->update(
                $table,
                $data,
                ['id' => (int) $existing->id],
                ['%d', '%d', '%d', '%d', '%d', '%f', '%d', '%s', '%s'],
                ['%d']
            );
            $registration_id = (int) $existing->id;
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert(
                $table,
                $data,
                ['%d', '%d', '%d', '%d', '%d', '%f', '%d', '%s', '%s', '%s']
            );
            $registration_id = (int) $wpdb->insert_id;
        }

        $redirectArg = $status === 'submitted' ? 'summer_camp_submitted' : 'summer_camp_saved';
        wp_safe_redirect(add_query_arg([$redirectArg => 1], get_permalink()));
        exit;
    }

    private function handle_upload(array $file)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $overrides = ['test_form' => false];
        $uploaded = wp_handle_upload($file, $overrides);

        if (isset($uploaded['error'])) {
            return new WP_Error('upload_error', $uploaded['error']);
        }

        $attachment = [
            'post_mime_type' => $uploaded['type'],
            'post_title' => sanitize_file_name((string) pathinfo($uploaded['file'], PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $uploaded['file']);
        if (is_wp_error($attach_id)) {
            return $attach_id;
        }

        $attach_data = wp_generate_attachment_metadata($attach_id, $uploaded['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    public function register_admin_menus(): void
    {
        add_menu_page(
            'Sommerlejr',
            'Sommerlejr',
            'manage_options',
            'summer-camp',
            [$this, 'render_all_registrations_page'],
            'dashicons-groups'
        );

        add_submenu_page(
            'summer-camp',
            'Pris indstillinger',
            'Pris indstillinger',
            'manage_options',
            'summer-camp-prices',
            [$this, 'render_prices_page']
        );

        add_submenu_page(
            'summer-camp',
            'Alle tilmeldinger',
            'Alle tilmeldinger',
            'manage_options',
            'summer-camp',
            [$this, 'render_all_registrations_page']
        );

        add_submenu_page(
            'summer-camp',
            'Ikke godkendte',
            'Ikke godkendte',
            'manage_options',
            'summer-camp-pending',
            [$this, 'render_pending_page']
        );
    }

    public function render_prices_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Ingen adgang.');
        }

        $prices = $this->get_prices();
        ?>
        <div class="wrap">
            <h1>Pris indstillinger</h1>
            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success"><p>Priser gemt.</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('summer_camp_save_prices'); ?>
                <input type="hidden" name="action" value="summer_camp_save_prices">
                <table class="form-table">
                    <tr><th><label for="adult_full">Voksne alle dage</label></th><td><input type="number" step="0.01" name="adult_full" value="<?php echo esc_attr((string) $prices['adult_full']); ?>"></td></tr>
                    <tr><th><label for="child_full">Børn alle dage</label></th><td><input type="number" step="0.01" name="child_full" value="<?php echo esc_attr((string) $prices['child_full']); ?>"></td></tr>
                    <tr><th><label for="day_ticket">Dagsbillet</label></th><td><input type="number" step="0.01" name="day_ticket" value="<?php echo esc_attr((string) $prices['day_ticket']); ?>"></td></tr>
                </table>
                <?php submit_button('Gem priser'); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save_prices(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Ingen adgang.');
        }

        check_admin_referer('summer_camp_save_prices');

        $prices = [];
        $keys = array_keys($this->default_prices());

        foreach ($keys as $key) {
            $prices[$key] = isset($_POST[$key]) ? (float) $_POST[$key] : 0;
        }

        update_option(self::PRICE_OPTION, $prices);
        wp_safe_redirect(add_query_arg(['saved' => 1], admin_url('admin.php?page=summer-camp-prices')));
        exit;
    }

    public function render_all_registrations_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Ingen adgang.');
        }

        $rows = $this->fetch_registrations();
        echo '<div class="wrap"><h1>Alle tilmeldinger</h1>';
        $this->render_table($rows, false);
        echo '</div>';
    }

    public function render_pending_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Ingen adgang.');
        }

        $rows = $this->fetch_registrations('submitted');
        echo '<div class="wrap"><h1>Ikke godkendte tilmeldinger</h1>';
        $this->render_table($rows, true);
        echo '</div>';

        echo '<div id="summer-camp-modal" class="summer-camp-modal"><span class="close">&times;</span><img id="summer-camp-proof-image" src="" alt="Proof"/></div>';
    }

    private function fetch_registrations(?string $status = null): array
    {
        global $wpdb;

        $table = $this->registrations_table_name();
        $users = $wpdb->users;

        if ($status) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT r.*, u.display_name, u.user_email FROM {$table} r LEFT JOIN {$users} u ON r.user_id = u.ID WHERE r.status = %s ORDER BY r.updated_at DESC",
                    $status
                )
            );
        }

        return $wpdb->get_results("SELECT r.*, u.display_name, u.user_email FROM {$table} r LEFT JOIN {$users} u ON r.user_id = u.ID ORDER BY r.updated_at DESC");
    }

    private function render_table(array $rows, bool $showApprove): void
    {
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>ID</th><th>Bruger</th><th>Email</th><th>Voksne</th><th>Børn</th><th>Dagsbilletter</th><th>Pris</th><th>Status</th><th>Screenshot</th>';
        if ($showApprove) {
            echo '<th>Handling</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            $colspan = $showApprove ? 10 : 9;
            echo '<tr><td colspan="' . (int) $colspan . '">Ingen tilmeldinger fundet.</td></tr>';
        }

        foreach ($rows as $row) {
            $proof_url = $row->transfer_screenshot_id ? wp_get_attachment_url((int) $row->transfer_screenshot_id) : '';
            echo '<tr>';
            echo '<td>' . (int) $row->id . '</td>';
            echo '<td>' . esc_html((string) $row->display_name) . '</td>';
            echo '<td>' . esc_html((string) $row->user_email) . '</td>';
            echo '<td>' . (int) $row->adults . '</td>';
            echo '<td>' . (int) $row->children . '</td>';
            echo '<td>' . (int) $row->day_tickets . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) $row->total_price, 2)) . ' kr.</td>';
            echo '<td>' . esc_html((string) $row->status) . '</td>';

            if ($proof_url) {
                echo '<td><a href="#" class="js-open-proof" data-src="' . esc_url($proof_url) . '">Åbn screenshot</a></td>';
            } else {
                echo '<td>Ikke uploadet</td>';
            }

            if ($showApprove) {
                echo '<td>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="summer_camp_approve_registration">';
                echo '<input type="hidden" name="registration_id" value="' . (int) $row->id . '">';
                wp_nonce_field('summer_camp_approve_' . (int) $row->id);
                echo '<button class="button button-primary">Godkend</button>';
                echo '</form>';
                echo '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function handle_approve_registration(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Ingen adgang.');
        }

        $registrationId = isset($_POST['registration_id']) ? (int) $_POST['registration_id'] : 0;
        check_admin_referer('summer_camp_approve_' . $registrationId);

        if ($registrationId > 0) {
            global $wpdb;
            $wpdb->update(
                $this->registrations_table_name(),
                ['status' => 'approved', 'updated_at' => current_time('mysql')],
                ['id' => $registrationId],
                ['%s', '%s'],
                ['%d']
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=summer-camp-pending'));
        exit;
    }
}

new SommerlejrTilmeldingPlugin();
