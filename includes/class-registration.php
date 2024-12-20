<?php
class WebinarGeek_Registration {
    private $api;
    private $version = '1.0.0';

    public function __construct() {
        $this->api = new WebinarGeek_API();
        $this->init_hooks();
    }

    private function init_hooks() {
        // AJAX handlers
        add_action('wp_ajax_register_webinar', array($this, 'handle_registration'));
        add_action('wp_ajax_nopriv_register_webinar', array($this, 'handle_registration'));
        
        // Shortcode
        add_shortcode('webinar_registration', array($this, 'registration_shortcode'));
        
        // Scripts en styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function enqueue_scripts() {
        wp_enqueue_script(
            'webinargeek-registration',
            WEBINARGEEK_PLUGIN_URL . 'assets/js/registration.js',
            array('jquery'),
            $this->version,
            true
        );

        wp_localize_script('webinargeek-registration', 'webinargeekAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('webinar_registration'),
            'messages' => array(
                'success' => __('Registratie succesvol!', 'webinargeek-integration'),
                'error' => __('Er ging iets mis. Probeer het opnieuw.', 'webinargeek-integration'),
                'required' => __('Dit veld is verplicht.', 'webinargeek-integration')
            )
        ));

        wp_enqueue_style(
            'webinargeek-registration',
            WEBINARGEEK_PLUGIN_URL . 'assets/css/registration.css',
            array(),
            $this->version
        );
    }

    public function registration_shortcode($atts) {
        $atts = shortcode_atts(array(
            'webinar_id' => '',
            'success_redirect' => '', // Optionele redirect URL na succesvolle registratie
            'button_text' => __('Registreren', 'webinargeek-integration')
        ), $atts);

        if(empty($atts['webinar_id'])) {
            return '<p class="webinargeek-error">' . __('Webinar ID is vereist.', 'webinargeek-integration') . '</p>';
        }

        return $this->render_registration_form($atts);
    }

    public function handle_registration() {
        try {
            if (!check_ajax_referer('webinar_registration', 'nonce', false)) {
                throw new Exception(__('Ongeldige beveiligingstoken.', 'webinargeek-integration'));
            }

            $broadcast_id = isset($_POST['broadcast_id']) ? sanitize_text_field($_POST['broadcast_id']) : '';
            if (empty($broadcast_id)) {
                throw new Exception(__('Geen webinar ID opgegeven.', 'webinargeek-integration'));
            }

            // Valideer verplichte velden
            $required_fields = array('email', 'name');
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception(sprintf(__('Veld %s is verplicht.', 'webinargeek-integration'), $field));
                }
            }
            
            // Verzamel en saniteer form data
            $participant_data = array(
                'email' => sanitize_email($_POST['email']),
                'name' => sanitize_text_field($_POST['name'])
            );

            // Voeg extra velden toe als ze bestaan
            $registration_fields = $this->api->get_webinar_registration_fields($broadcast_id);
            foreach ($registration_fields as $field) {
                if (isset($_POST[$field['name']])) {
                    $participant_data[$field['name']] = sanitize_text_field($_POST[$field['name']]);
                }
            }

            $registration = $this->api->register_participant($broadcast_id, $participant_data);
            if (!$registration) {
                throw new Exception(__('Registratie mislukt.', 'webinargeek-integration'));
            }

            wp_send_json_success(array(
                'message' => __('Registratie succesvol!', 'webinargeek-integration'),
                'data' => $registration
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }

    public function render_registration_form($atts) {
        try {
            $registration_fields = $this->api->get_webinar_registration_fields($atts['webinar_id']);
            
            ob_start();
            ?>
            <div class="webinargeek-registration-wrapper">
                <form id="webinar-registration-form" class="webinargeek-form">
                    <?php wp_nonce_field('webinar_registration', 'webinar_nonce'); ?>
                    <input type="hidden" name="broadcast_id" value="<?php echo esc_attr($atts['webinar_id']); ?>">
                    <?php if (!empty($atts['success_redirect'])): ?>
                        <input type="hidden" name="success_redirect" value="<?php echo esc_url($atts['success_redirect']); ?>">
                    <?php endif; ?>
                    
                    <div class="webinargeek-form-group">
                        <label for="webinargeek-email"><?php _e('Email', 'webinargeek-integration'); ?> *</label>
                        <input type="email" id="webinargeek-email" name="email" required>
                    </div>
            
                    <div class="webinargeek-form-group">
                        <label for="webinargeek-name"><?php _e('Naam', 'webinargeek-integration'); ?> *</label>
                        <input type="text" id="webinargeek-name" name="name" required>
                    </div>
            
                    <?php foreach($registration_fields as $field): ?>
                        <div class="webinargeek-form-group">
                            <label for="webinargeek-<?php echo esc_attr($field['name']); ?>">
                                <?php echo esc_html($field['label']); ?>
                                <?php if($field['required']): ?> *<?php endif; ?>
                            </label>
                            <?php echo $this->render_field($field); ?>
                        </div>
                    <?php endforeach; ?>
            
                    <div class="webinargeek-form-group">
                        <button type="submit" class="webinargeek-submit">
                            <?php echo esc_html($atts['button_text']); ?>
                        </button>
                    </div>

                    <div class="webinargeek-messages" style="display: none;"></div>
                </form>
            </div>
            <?php
            return ob_get_clean();

        } catch (Exception $e) {
            return '<p class="webinargeek-error">' . esc_html($e->getMessage()) . '</p>';
        }
    }

    private function render_field($field) {
        $field_html = '';
        $field_id = 'webinargeek-' . esc_attr($field['name']);
        
        switch($field['type']) {
            case 'text':
                $field_html = sprintf(
                    '<input type="text" id="%s" name="%s" %s>',
                    $field_id,
                    esc_attr($field['name']),
                    $field['required'] ? 'required' : ''
                );
                break;
            case 'textarea':
                $field_html = sprintf(
                    '<textarea id="%s" name="%s" %s></textarea>',
                    $field_id,
                    esc_attr($field['name']),
                    $field['required'] ? 'required' : ''
                );
                break;
            // Voeg hier meer veldtypes toe indien nodig
        }
        
        return $field_html;
    }
}