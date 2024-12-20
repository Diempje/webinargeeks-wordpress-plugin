<?php
/*
Plugin Name: WebinarGeek Integration
Description: Integreert WebinarGeek met WordPress en JetEngine
Version: 1.0.0
Author: Your Name
Text Domain: webinargeek-integration
*/

if (!defined('ABSPATH')) exit;

class WebinarGeek_Integration {
    private static $instance = null;
    private $api;     // Voeg deze toe
    private $sync;    // Voeg deze toe
    private $admin;   // Voeg deze toe
    private $registration; // Voeg deze toe
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Defineer constantes
        if (!defined('WEBINARGEEK_PLUGIN_PATH')) {
            define('WEBINARGEEK_PLUGIN_PATH', plugin_dir_path(__FILE__));
        }
        if (!defined('WEBINARGEEK_PLUGIN_URL')) {
            define('WEBINARGEEK_PLUGIN_URL', plugin_dir_url(__FILE__));
        }
        
        // Laad de benodigde bestanden
        $this->load_dependencies();
        
        // Initialiseer de plugin
        $this->init();
    }
    
    private function load_dependencies() {
        $files = array(
            'class-api.php',
            'class-sync.php',
            'class-admin.php',
            'class-registration.php'
        );
        
        foreach ($files as $file) {
            $path = WEBINARGEEK_PLUGIN_PATH . 'includes/' . $file;
            if (file_exists($path)) {
                require_once $path;
            } else {
                error_log('WebinarGeek Integration: Missing required file ' . $file);
            }
        }
    }
    
    private function init() {
        try {
            $this->api = new WebinarGeek_API();
            $this->sync = new WebinarGeek_Sync();
            $this->admin = new WebinarGeek_Admin();
            $this->registration = new WebinarGeek_Registration();
        } catch (Exception $e) {
            error_log('WebinarGeek Integration initialization error: ' . $e->getMessage());
        }
    }
}

// Start de plugin
function webinargeek_integration() {
    return WebinarGeek_Integration::get_instance();
}

if (defined('ABSPATH')) {
    add_action('plugins_loaded', 'webinargeek_integration');
}

// Initialiseer bij plugins_loaded
add_action('plugins_loaded', 'webinargeek_integration');

// Register deactivation hook
register_deactivation_hook(__FILE__, 'webinargeek_deactivate');

function webinargeek_deactivate() {
    try {
        $sync = new WebinarGeek_Sync();
        $sync->deactivate();
    } catch (Exception $e) {
        error_log('WebinarGeek Integration deactivation error: ' . $e->getMessage());
    }

}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-sync.php';
$sync = new WebinarGeek_Sync();
add_action('admin_init', array($sync, 'register_jet_meta_fields'));

$sync = new WebinarGeek_Sync();
add_action('admin_init', array($sync, 'register_jet_meta_fields'));