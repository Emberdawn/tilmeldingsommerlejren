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
        wp_add_inline_script('jquery', '
            jQuery(function($){
                function initRegistrationForm(form){
                    if (!form.length || form.data("summer-camp-init")) {
                        return;
                    }

                    form.data("summer-camp-init", true);

                    const fileInput = form.find("input[name=\"transfer_screenshot\"]");
                    const adultsInput = form.find("input[name=\"adults\"]");
                    const childrenInput = form.find("input[name=\"children\"]");
                    const dayTicketsInput = form.find("input[name=\"day_tickets\"]");
                    const totalValue = form.find(".js-total-price-value");
                    const previewWrap = form.find(".js-file-preview");
                    const previewImage = form.find(".js-file-preview-image");
                    const previewName = form.find(".js-file-preview-name");
                    const progressWrap = form.find(".js-upload-progress-wrap");
                    const progressBar = form.find(".js-upload-progress");
                    const progressLabel = form.find(".js-upload-progress-label");
                    function parseLocaleNumber(value){
                        if (typeof value === "number") {
                            return Number.isFinite(value) ? value : 0;
                        }

                        if (typeof value !== "string") {
                            return 0;
                        }

                        const normalized = value
                            .trim()
                            .replace(/\./g, "")
                            .replace(",", ".");
                        const parsed = Number(normalized);

                        return Number.isFinite(parsed) ? parsed : 0;
                    }

                    const adultPrice = parseLocaleNumber(String(form.data("adult-price") || "0"));
                    const childPrice = parseLocaleNumber(String(form.data("child-price") || "0"));
                    const dayTicketPrice = parseLocaleNumber(String(form.data("day-ticket-price") || "0"));

                    function toNumber(input){
                        if (!input.length) {
                            return 0;
                        }

                        const parsed = parseLocaleNumber(String(input.val() || "0"));
                        return Number.isNaN(parsed) ? 0 : parsed;
                    }

                    function formatTotal(value){
                        if (typeof Intl !== "undefined" && typeof Intl.NumberFormat === "function") {
                            return new Intl.NumberFormat("da-DK", {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            }).format(value);
                        }

                        return value.toFixed(2).replace(".", ",");
                    }

                    function updateTotalPrice(){
                        if (!totalValue.length) {
                            return;
                        }

                        const adults = toNumber(adultsInput);
                        const children = toNumber(childrenInput);
                        const dayTickets = toNumber(dayTicketsInput);
                        const total = (adults * adultPrice) + (children * childPrice) + (dayTickets * dayTicketPrice);

                        totalValue.text(formatTotal(total));
                    }

                    form.on("input change keyup click", "input[name=\"adults\"], input[name=\"children\"], input[name=\"day_tickets\"]", updateTotalPrice);
                    $(window).on("pageshow", updateTotalPrice);
                    updateTotalPrice();

                    fileInput.on("change", function(){
                        const file = this.files && this.files[0] ? this.files[0] : null;

                        if (!file) {
                            previewWrap.hide();
                            previewImage.hide().attr("src", "");
                            previewName.text("");
                            return;
                        }

                        previewWrap.show();
                        previewName.text(file.name);

                        if (file.type.indexOf("image/") === 0) {
                            previewImage.attr("src", URL.createObjectURL(file)).show();
                        } else {
                            previewImage.hide().attr("src", "");
                        }
                    });

                    form.on("submit", function(e){
                        const submitter = e.originalEvent && e.originalEvent.submitter ? e.originalEvent.submitter : null;
                        const action = submitter ? $(submitter).val() : "";

                        if (action !== "save") {
                            return;
                        }

                        const hasFile = fileInput[0] && fileInput[0].files && fileInput[0].files.length > 0;
                        if (!hasFile) {
                            return;
                        }

                        e.preventDefault();

                        const data = new FormData(form[0]);
                        data.set("summer_camp_action", action);

                        progressWrap.show();
                        progressBar.val(0);
                        progressLabel.text("0%");
                        if (submitter) {
                            $(submitter).prop("disabled", true);
                        }

                        const xhr = new XMLHttpRequest();
                        xhr.open("POST", window.location.href, true);
                        xhr.upload.onprogress = function(event){
                            if (!event.lengthComputable) {
                                return;
                            }

                            const percent = Math.min(100, Math.round((event.loaded / event.total) * 100));
                            progressBar.val(percent);
                            progressLabel.text(percent + "%");
                        };

                        xhr.onload = function(){
                            if (submitter) {
                                $(submitter).prop("disabled", false);
                            }
                            if (xhr.status >= 200 && xhr.status < 400 && xhr.responseURL) {
                                window.location.href = xhr.responseURL;
                                return;
                            }
                            progressWrap.hide();
                            alert("Upload fejlede. Prøv igen.");
                        };

                        xhr.onerror = function(){
                            if (submitter) {
                                $(submitter).prop("disabled", false);
                            }
                            progressWrap.hide();
                            alert("Upload fejlede. Prøv igen.");
                        };

                        xhr.send(data);
                    });
                }

                $(".js-summer-camp-form").each(function(){
                    initRegistrationForm($(this));
                });

                $(document).on("focusin", ".js-summer-camp-form", function(){
                    initRegistrationForm($(this));
                });
            });
        ');
    }

    public function enqueue_admin_assets(string $hook): void
    {
        $isPendingPage = isset($_GET['page']) && sanitize_key((string) $_GET['page']) === 'summer-camp-pending';

        if (
            $hook !== 'summer-camp_page_summer-camp-pending'
            && $hook !== 'summerlejr_page_summer-camp-pending'
            && !$isPendingPage
        ) {
            return;
        }

        wp_register_style('summer-camp-admin-modal', false);
        wp_enqueue_style('summer-camp-admin-modal');
        wp_add_inline_style('summer-camp-admin-modal', '
            .summer-camp-modal {display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,.85); align-items:center; justify-content:center;}
            .summer-camp-modal.active {display:flex;}
            .summer-camp-modal-dialog {position:relative; max-width:96vw; max-height:96vh; width:1100px; background:#111; border-radius:8px; padding:16px;}
            .summer-camp-modal-stage {display:flex; align-items:center; justify-content:center; overflow:auto; max-height:calc(96vh - 90px);}
            .summer-camp-modal img {max-width:100%; max-height:calc(96vh - 110px); cursor:zoom-in; transform-origin:center center; transition:transform .15s ease-out;}
            .summer-camp-modal-toolbar {display:flex; justify-content:flex-end; gap:8px; margin-bottom:10px;}
            .summer-camp-modal .button {min-width:42px; text-align:center;}
            .summer-camp-modal .close {position:absolute; top:6px; right:10px; color:#fff; font-size:30px; cursor:pointer; line-height:1;}
            .summer-camp-proof-thumb {display:inline-block; width:72px; height:72px; border:1px solid #ccd0d4; border-radius:4px; overflow:hidden;}
            .summer-camp-proof-thumb img {width:100%; height:100%; object-fit:cover; display:block;}
        ');

        wp_register_script('summer-camp-admin-modal', false, ['jquery'], null, true);
        wp_enqueue_script('summer-camp-admin-modal');
        wp_add_inline_script('summer-camp-admin-modal', '
            jQuery(function($){
                let zoom = 1;
                const minZoom = 1;
                const maxZoom = 4;
                const step = 0.25;

                function applyZoom(){
                    $("#summer-camp-proof-image").css("transform", "scale(" + zoom + ")");
                }

                function setZoom(nextZoom){
                    zoom = Math.max(minZoom, Math.min(maxZoom, nextZoom));
                    applyZoom();
                }

                $(document).on("click", ".js-open-proof", function(e){
                    e.preventDefault();
                    const src = $(this).data("src");
                    const isImage = Boolean($(this).data("is-image"));

                    if (!isImage) {
                        window.open(src, "_blank", "noopener");
                        return;
                    }

                    zoom = 1;
                    $("#summer-camp-proof-image").attr("src", src);
                    applyZoom();
                    $("#summer-camp-proof-open-original").attr("href", src);
                    $("#summer-camp-modal").addClass("active");
                });

                $(document).on("click", "#summer-camp-modal", function(e){
                    if (e.target === this) {
                        $(this).removeClass("active");
                    }
                });

                $(document).on("click", "#summer-camp-modal .close", function(){
                    $("#summer-camp-modal").removeClass("active");
                });

                $(document).on("click", "#summer-camp-proof-zoom-in", function(){
                    setZoom(zoom + step);
                });

                $(document).on("click", "#summer-camp-proof-zoom-out", function(){
                    setZoom(zoom - step);
                });

                $(document).on("click", "#summer-camp-proof-image", function(e){
                    e.stopPropagation();
                    setZoom(zoom >= maxZoom ? minZoom : zoom + step);
                });

                $(document).on("wheel", "#summer-camp-proof-image", function(e){
                    e.preventDefault();
                    const originalEvent = e.originalEvent || e;
                    const deltaY = originalEvent.deltaY || 0;
                    setZoom(zoom + (deltaY < 0 ? step : -step));
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
        $prices = $this->get_prices();
        $status = $registration ? $registration->status : 'draft';

        ob_start();
        ?>
        <form method="post" enctype="multipart/form-data" class="js-summer-camp-form" style="max-width:700px;display:grid;gap:12px;padding:16px;border:1px solid #ddd;border-radius:8px;" data-adult-price="<?php echo esc_attr((string) $prices['adult_full']); ?>" data-child-price="<?php echo esc_attr((string) $prices['child_full']); ?>" data-day-ticket-price="<?php echo esc_attr((string) $prices['day_ticket']); ?>">
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

            <div class="js-file-preview" style="display:none;gap:8px;align-items:center;">
                <img class="js-file-preview-image" src="" alt="Forhåndsvisning" style="display:none;width:80px;height:80px;object-fit:cover;border:1px solid #ddd;border-radius:6px;">
                <span class="js-file-preview-name" style="font-size:13px;color:#444;"></span>
            </div>

            <div class="js-upload-progress-wrap" style="display:none;">
                <label style="display:block;margin-bottom:4px;">Upload status: <span class="js-upload-progress-label">0%</span></label>
                <progress class="js-upload-progress" max="100" value="0" style="width:100%;"></progress>
            </div>

            <?php if ($registration && $registration->transfer_screenshot_id) : ?>
                <?php $url = wp_get_attachment_url((int) $registration->transfer_screenshot_id); ?>
                <?php if ($url) : ?>
                    <?php if (wp_attachment_is_image((int) $registration->transfer_screenshot_id)) : ?>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <img
                                src="<?php echo esc_url($url); ?>"
                                alt="Nuværende upload"
                                style="width:80px;height:80px;object-fit:cover;border:1px solid #ddd;border-radius:6px;"
                            >
                            <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">Åbn original</a>
                        </div>
                    <?php else : ?>
                        <p>Nuværende fil: <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">Åbn upload</a></p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <p><strong>Samlet pris: <span class="js-total-price-value"><?php echo esc_html(number_format_i18n($total, 2)); ?></span> kr.</strong></p>

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

        echo '<div id="summer-camp-modal" class="summer-camp-modal">';
        echo '<div class="summer-camp-modal-dialog">';
        echo '<span class="close" aria-label="Luk">&times;</span>';
        echo '<div class="summer-camp-modal-toolbar">';
        echo '<a id="summer-camp-proof-open-original" class="button" href="#" target="_blank" rel="noopener noreferrer">Åbn original</a>';
        echo '<button id="summer-camp-proof-zoom-out" class="button" type="button" aria-label="Zoom ud">-</button>';
        echo '<button id="summer-camp-proof-zoom-in" class="button" type="button" aria-label="Zoom ind">+</button>';
        echo '</div>';
        echo '<div class="summer-camp-modal-stage"><img id="summer-camp-proof-image" src="" alt="Proof"/></div>';
        echo '</div>';
        echo '</div>';
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
            $is_image = $row->transfer_screenshot_id ? wp_attachment_is_image((int) $row->transfer_screenshot_id) : false;
            $proof_thumb = '';

            if ($proof_url && $is_image) {
                $proof_thumb = wp_get_attachment_image((int) $row->transfer_screenshot_id, [72, 72], false, ['alt' => 'Screenshot']);
            }

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
                if ($is_image && $proof_thumb) {
                    echo '<td><a href="#" class="js-open-proof summer-camp-proof-thumb" data-src="' . esc_url($proof_url) . '" data-is-image="1">' . $proof_thumb . '</a></td>';
                } else {
                    echo '<td><a href="' . esc_url($proof_url) . '" class="button button-small" target="_blank" rel="noopener noreferrer">Åbn fil</a></td>';
                }
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
