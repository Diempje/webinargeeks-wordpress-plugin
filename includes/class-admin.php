<?php
class WebinarGeek_Admin {
    private $page_slug = 'webinargeek-settings';
    private $option_group = 'webinargeek_settings';
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'show_admin_notices'));
    }
    
    public function add_admin_menu() {
        // Hoofdmenu
        add_menu_page(
            __('WebinarGeek Instellingen', 'webinargeek-integration'),
            __('WebinarGeek', 'webinargeek-integration'),
            'manage_options',
            $this->page_slug,
            array($this, 'render_settings_page'),
            'dashicons-video-alt2'
        );
        
        // Submenu's
        add_submenu_page(
            $this->page_slug,
            __('Instellingen', 'webinargeek-integration'),
            __('Instellingen', 'webinargeek-integration'),
            'manage_options',
            $this->page_slug,
            array($this, 'render_settings_page')
        );
        
        add_submenu_page(
            $this->page_slug,
            __('Test Velden', 'webinargeek-integration'),
            __('Test Velden', 'webinargeek-integration'),
            'manage_options',
            'webinargeek-test-fields',
            array($this, 'render_test_fields_page')
        );
        
        // Handmatige sync pagina
        add_submenu_page(
            $this->page_slug,
            __('Synchronisatie', 'webinargeek-integration'),
            __('Synchronisatie', 'webinargeek-integration'),
            'manage_options',
            'webinargeek-sync',
            array($this, 'render_sync_page')
        );
    }
    
    public function register_settings() {
        register_setting(
            $this->option_group,
            'webinargeek_api_key',
            array(
                'sanitize_callback' => array($this, 'sanitize_api_key'),
                'default' => ''
            )
        );
        
        add_settings_section(
            'webinargeek_main_section',
            __('API Instellingen', 'webinargeek-integration'),
            array($this, 'render_settings_section'),
            $this->page_slug
        );
        
        add_settings_field(
            'webinargeek_api_key',
            __('API Key', 'webinargeek-integration'),
            array($this, 'render_api_key_field'),
            $this->page_slug,
            'webinargeek_main_section',
            array('label_for' => 'webinargeek_api_key')
        );

        register_setting(
            $this->option_group,
            'webinargeek_webhook_secret',
            array(
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            )
        );
        
        add_settings_field(
            'webinargeek_webhook_secret',
            __('Webhook Secret', 'webinargeek-integration'),
            array($this, 'render_webhook_secret_field'),
            $this->page_slug,
            'webinargeek_main_section'
        );
    }
    
    public function sanitize_api_key($value) {
        $value = sanitize_text_field($value);
        if (empty($value)) {
            add_settings_error(
                'webinargeek_api_key',
                'empty_api_key',
                __('API Key mag niet leeg zijn.', 'webinargeek-integration')
            );
        }
        return $value;
    }
    
    public function render_settings_section($args) {
        ?>
        <p><?php _e('Configureer hier je WebinarGeek API instellingen.', 'webinargeek-integration'); ?></p>
        <?php
    }
    
    public function render_api_key_field($args) {
        $api_key = get_option('webinargeek_api_key');
        ?>
        <input type="text" 
               id="webinargeek_api_key"
               name="webinargeek_api_key"
               class="regular-text"
               value="<?php echo esc_attr($api_key); ?>"
               placeholder="<?php esc_attr_e('Voer je API key in', 'webinargeek-integration'); ?>"
        >
        <p class="description">
            <?php _e('Je kunt je API key vinden in je WebinarGeek dashboard.', 'webinargeek-integration'); ?>
        </p>
        <?php
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_group);
                do_settings_sections($this->page_slug);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    public function render_test_fields_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $api = new WebinarGeek_API();
        $webinar_id = isset($_POST['webinar_id']) ? sanitize_text_field($_POST['webinar_id']) : '';
        $fields = array();
        $nonce_field = 'test_fields_nonce';
        
        if (!empty($webinar_id) && check_admin_referer('test_fields_action', $nonce_field)) {
            $fields = $api->get_webinar_fields($webinar_id);
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Test Webinar Velden', 'webinargeek-integration'); ?></h1>
            
            <form method="post">
                <?php wp_nonce_field('test_fields_action', $nonce_field); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="webinar_id"><?php _e('Webinar ID', 'webinargeek-integration'); ?></label>
                        </th>
                        <td>
                            <input name="webinar_id" 
                                   type="text" 
                                   id="webinar_id" 
                                   value="<?php echo esc_attr($webinar_id); ?>" 
                                   class="regular-text"
                                   required
                            >
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Haal velden op', 'webinargeek-integration')); ?>
            </form>
            
            <?php if(!empty($fields)): ?>
                <h2><?php _e('Gevonden velden:', 'webinargeek-integration'); ?></h2>
                <div class="webinargeek-fields-result">
                    <pre><?php print_r($fields); ?></pre>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function render_sync_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php _e('WebinarGeek Synchronisatie', 'webinargeek-integration'); ?></h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('manual_webinar_sync'); ?>
                <input type="hidden" name="action" value="manual_webinar_sync">
                <?php submit_button(__('Start Handmatige Synchronisatie', 'webinargeek-integration')); ?>
            </form>
        </div>
        <?php
    }
    
    public function show_admin_notices() {
        settings_errors('webinargeek_messages');
    }

    public function render_webhook_secret_field() {
        $webhook_secret = get_option('webinargeek_webhook_secret');
        ?>
        <input type="text" 
               id="webinargeek_webhook_secret"
               name="webinargeek_webhook_secret"
               class="regular-text"
               value="<?php echo esc_attr($webhook_secret); ?>"
        >
        <p class="description">
            <?php 
            echo sprintf(
                __('Webhook URL: %s', 'webinargeek-integration'),
                esc_url(rest_url('webinargeek/v1/webhook'))
            );
            ?>
        </p>
        <?php
    }
}