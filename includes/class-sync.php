<?php
class WebinarGeek_Sync {
    private $api;
    private $post_type = 'webinar';
    private $error_log_prefix = 'WebinarGeek Sync: ';
    private $jet_fields = array(
        'webinar_id' => array(
            'type' => 'text',
            'title' => 'WebinarGeek ID',
            'is_required' => true
        ),
        'webinar_date' => array(
            'type' => 'datetime-local',
            'title' => 'Datum en tijd'
        ),
        'webinar_duration' => array(
            'type' => 'number',
            'title' => 'Duur (minuten)'
        ),
        'registration_url' => array(
            'type' => 'text',
            'title' => 'Registratie URL'
        ),
        'max_participants' => array(
            'type' => 'number',
            'title' => 'Maximum aantal deelnemers'
        ),
        'webinar_status' => array(
            'type' => 'select',
            'title' => 'Status',
            'options' => array(
                'scheduled' => 'Gepland',
                'live' => 'Live',
                'ended' => 'Beëindigd'
            )
        )
    );
    
    public function __construct() {
        $this->api = new WebinarGeek_API();
        $this->init_hooks();
        add_action('admin_head', array($this, 'add_meta_box_styles'));  // Voeg deze regel toe
    }
    
    private function init_hooks() {
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'register_taxonomies'));
        add_action('admin_init', array($this, 'schedule_sync'));
        add_action('webinargeek_sync_event', array($this, 'sync_webinars'));
        add_action('admin_notices', array($this, 'show_sync_notices'));
        add_action('admin_post_manual_webinar_sync', array($this, 'handle_manual_sync'));
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
        add_action('init', array($this, 'register_jet_meta_fields')); 
    }
    
    public function register_post_type() {
        $labels = array(
            'name'               => __('Webinars', 'webinargeek-integration'),
            'singular_name'      => __('Webinar', 'webinargeek-integration'),
            'add_new'           => __('Nieuw Webinar', 'webinargeek-integration'),
            'add_new_item'      => __('Nieuw Webinar Toevoegen', 'webinargeek-integration'),
            'edit_item'         => __('Bewerk Webinar', 'webinargeek-integration'),
            'view_item'         => __('Bekijk Webinar', 'webinargeek-integration'),
            'search_items'      => __('Zoek Webinars', 'webinargeek-integration'),
            'not_found'         => __('Geen webinars gevonden', 'webinargeek-integration'),
            'menu_name'         => __('Webinars', 'webinargeek-integration')
        );

        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'webinar'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            'supports'           => array('title', 'editor', 'thumbnail', 'excerpt'),
            'show_in_rest'       => true,
            'menu_icon'          => 'dashicons-video-alt2'
        );

        register_post_type($this->post_type, $args);
    }

    public function register_taxonomies() {
        $labels = array(
            'name'              => __('Webinar Categorieën', 'webinargeek-integration'),
            'singular_name'     => __('Webinar Categorie', 'webinargeek-integration'),
            'search_items'      => __('Zoek Categorieën', 'webinargeek-integration'),
            'all_items'         => __('Alle Categorieën', 'webinargeek-integration'),
            'parent_item'       => __('Hoofd Categorie', 'webinargeek-integration'),
            'parent_item_colon' => __('Hoofd Categorie:', 'webinargeek-integration'),
            'edit_item'         => __('Bewerk Categorie', 'webinargeek-integration'),
            'update_item'       => __('Update Categorie', 'webinargeek-integration'),
            'add_new_item'      => __('Voeg Nieuwe Categorie Toe', 'webinargeek-integration'),
            'new_item_name'     => __('Nieuwe Categorie Naam', 'webinargeek-integration'),
            'menu_name'         => __('Categorieën', 'webinargeek-integration'),
        );

        $args = array(
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'          => true,
            'show_admin_column' => true,
            'query_var'        => true,
            'rewrite'          => array('slug' => 'webinar-category'),
            'show_in_rest'     => true
        );

        register_taxonomy('webinar_category', $this->post_type, $args);
    }
    
    public function register_webhook_endpoint() {
        register_rest_route('webinargeek/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => array($this, 'verify_webhook_signature')
        ));
    }

    public function verify_webhook_signature($request) {
        $signature = $request->get_header('X-WebinarGeek-Signature');
        if (empty($signature)) {
            return false;
        }
        
        $webhook_secret = get_option('webinargeek_webhook_secret');
        if (empty($webhook_secret)) {
            return false;
        }
        
        $payload = $request->get_body();
        $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);
        
        return hash_equals($expected_signature, $signature);
    }

    public function handle_webhook($request) {
        $payload = $request->get_json_params();
        
        if (!isset($payload['type']) || !isset($payload['data'])) {
            return new WP_Error('invalid_payload', 'Invalid webhook payload', array('status' => 400));
        }
        
        switch ($payload['type']) {
            case 'webinar.created':
            case 'webinar.updated':
                $webinar = $payload['data'];
                $result = $this->process_webinar($webinar);
                break;
                
            case 'webinar.deleted':
                $webinar_id = $payload['data']['id'];
                $this->delete_webinar($webinar_id);
                break;
        }
        
        return new WP_REST_Response(array('status' => 'success'), 200);
    }
    
    public function register_jet_meta_fields() {
        add_meta_box(
            'webinargeek_details',
            __('WebinarGeek Details', 'webinargeek-integration'),
            array($this, 'render_meta_box'),
            $this->post_type,
            'normal',
            'high'
        );
    }

    public function render_meta_box($post) {
        $fields = array(
            'webinar_id' => array(
                'type' => 'text',
                'title' => __('WebinarGeek ID', 'webinargeek-integration'),
                'value' => get_post_meta($post->ID, 'webinar_id', true)
            ),
            'webinar_date' => array(
                'type' => 'datetime-local',
                'title' => __('Datum en tijd', 'webinargeek-integration'),
                'value' => get_post_meta($post->ID, 'webinar_date', true)
            ),
            'webinar_duration' => array(
                'type' => 'number',
                'title' => __('Duur (minuten)', 'webinargeek-integration'),
                'value' => get_post_meta($post->ID, 'webinar_duration', true)
            ),
            'registration_url' => array(
                'type' => 'text',
                'title' => __('Registratie URL', 'webinargeek-integration'),
                'value' => get_post_meta($post->ID, 'registration_url', true)
            ),
            'max_participants' => array(
                'type' => 'number',
                'title' => __('Maximum aantal deelnemers', 'webinargeek-integration'),
                'value' => get_post_meta($post->ID, 'max_participants', true)
            )
        );
    
        wp_nonce_field('webinargeek_meta_box', 'webinargeek_meta_box_nonce');
    
        foreach ($fields as $key => $field) {
            ?>
            <div class="webinargeek-field">
                <label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($field['title']); ?></label>
                <input 
                    type="<?php echo esc_attr($field['type']); ?>"
                    id="<?php echo esc_attr($key); ?>"
                    name="<?php echo esc_attr($key); ?>"
                    value="<?php echo esc_attr($field['value']); ?>"
                    <?php if ($key === 'webinar_id') echo 'readonly'; ?>
                />
            </div>
            <?php
        }
    }
    
    public function schedule_sync() {
        if (!wp_next_scheduled('webinargeek_sync_event')) {
            $result = wp_schedule_event(time(), 'hourly', 'webinargeek_sync_event');
            if ($result === false) {
                error_log($this->error_log_prefix . 'Failed to schedule sync event');
            }
        }
    }
    
    public function add_meta_box_styles() {
        ?>
        <style>
            .webinargeek-field {
                margin-bottom: 15px;
            }
            .webinargeek-field label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
            }
            .webinargeek-field input {
                width: 100%;
                max-width: 400px;
            }
        </style>
        <?php
    }
    
    public function sync_webinars() {
        try {
            $webinars = $this->api->get_all_webinars();
            
            if (!$webinars) {
                throw new Exception('Geen webinars ontvangen van API');
            }
            
            $sync_stats = array(
                'created' => 0,
                'updated' => 0,
                'failed'  => 0
            );
            
            foreach ($webinars as $webinar) {
                $result = $this->process_webinar($webinar);
                if ($result === 'created') $sync_stats['created']++;
                elseif ($result === 'updated') $sync_stats['updated']++;
                else $sync_stats['failed']++;
            }
            
            $this->log_sync_stats($sync_stats);
            
        } catch (Exception $e) {
            error_log($this->error_log_prefix . $e->getMessage());
            return false;
        }
    }
    
    public function handle_manual_sync() {
        check_admin_referer('manual_webinar_sync');
        $this->sync_webinars();
        
        wp_redirect(add_query_arg(
            array('sync-status' => 'completed'),
            admin_url('edit.php?post_type=' . $this->post_type)
        ));
        exit;
    }
        
    private function process_webinar($webinar) {
        try {
            // Veiligere logging
            error_log('Processing webinar data - ID: ' . (isset($webinar['id']) ? $webinar['id'] : 'not set'));
            error_log('Available fields: ' . (is_array($webinar) ? implode(', ', array_keys($webinar)) : 'webinar is not an array'));
            
            if (!isset($webinar['id']) || !isset($webinar['title'])) {
                throw new Exception('Ongeldige webinar data ontvangen');
            }
            
            $existing_posts = get_posts(array(
                'post_type'      => $this->post_type,
                'meta_key'       => 'webinar_id',
                'meta_value'     => $webinar['id'],
                'posts_per_page' => 1
            ));
            
            $post_data = array(
                'post_type'    => $this->post_type,
                'post_title'   => sanitize_text_field($webinar['title']),
                'post_status'  => 'publish',
                'post_content' => isset($webinar['description']) ? wp_kses_post($webinar['description']) : ''
            );
            
            if (empty($existing_posts)) {
                $post_id = wp_insert_post($post_data, true);
                $action = 'created';
            } else {
                $post_data['ID'] = $existing_posts[0]->ID;
                $post_id = wp_update_post($post_data, true);
                $action = 'updated';
            }
            
            if (is_wp_error($post_id)) {
                throw new Exception($post_id->get_error_message());
            }
            
            $this->update_webinar_meta($post_id, $webinar);
            
            return $action;
            
        } catch (Exception $e) {
            error_log($this->error_log_prefix . 'Error processing webinar: ' . $e->getMessage());
            return false;
        }
    }
    
    private function update_webinar_meta($post_id, $webinar) {
        $field_mapping = array(
            'webinar_id' => 'id',
            'webinar_date' => 'date',
            'webinar_duration' => 'duration',
            'registration_url' => 'registration_url',
            'max_participants' => 'max_participants',
            'webinar_status' => 'status'
        );
    
        foreach ($field_mapping as $meta_key => $api_key) {
            if (isset($webinar[$api_key])) {
                $value = $webinar[$api_key];
                
                switch ($meta_key) {
                    case 'webinar_date':
                        $value = date('Y-m-d H:i', strtotime($value));
                        break;
                    
                    case 'webinar_duration':
                        $value = intval($value);
                        break;
                }
                
                // Gebruik de standaard WordPress update_post_meta functie
                update_post_meta($post_id, $meta_key, sanitize_text_field($value));
            }
        }
    
        do_action('webinargeek_after_update_meta', $post_id, $webinar);
    }
    
    private function delete_webinar($webinar_id) {
        $existing_posts = get_posts(array(
            'post_type' => $this->post_type,
            'meta_key' => 'webinar_id',
            'meta_value' => $webinar_id,
            'posts_per_page' => 1
        ));
        
        if (!empty($existing_posts)) {
            wp_delete_post($existing_posts[0]->ID, true);
        }
    }
    
    public function get_webinar_meta($post_id, $key) {
        if (!function_exists('jet_engine')) {
            return get_post_meta($post_id, $key, true);
        }
        return jet_engine()->meta_boxes->get_meta($post_id, $key);
    }
    
    private function log_sync_stats($stats) {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            error_log($this->error_log_prefix . sprintf(
                'Sync completed. Created: %d, Updated: %d, Failed: %d',
                $stats['created'],
                $stats['updated'],
                $stats['failed']
            ));
        }
    }
    
    public function show_sync_notices() {
        if (isset($_GET['sync-status']) && $_GET['sync-status'] === 'completed') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Webinar synchronisatie voltooid!', 'webinargeek-integration'); ?></p>
            </div>
            <?php
        }
    }

    public function deactivate() {
        try {
            $timestamp = wp_next_scheduled('webinargeek_sync_event');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'webinargeek_sync_event');
            }
        } catch (Exception $e) {
            error_log($this->error_log_prefix . 'Error during deactivation: ' . $e->getMessage());
        }
    }
}

  