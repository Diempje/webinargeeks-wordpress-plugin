<?php
class WebinarGeek_API {
    private $api_key;
    private $api_base_url = 'https://app.webinargeek.com/api/v2';
    private $error_log_prefix = 'WebinarGeek API: ';
    
    public function __construct() {
        $this->api_key = get_option('webinargeek_api_key');
        if (empty($this->api_key)) {
            error_log($this->error_log_prefix . 'API key not configured');
        }
    }
    
    /**
     * Maakt een API verzoek naar WebinarGeek
     * 
     * @param string $endpoint Het API endpoint
     * @param array $args Extra argumenten voor wp_remote_request
     * @return array|false Array met response data of false bij fout
     */
    private function make_request($endpoint, $args = []) {
        $url = $this->api_base_url . '/' . ltrim($endpoint, '/');
        
        error_log("WebinarGeek API Request URL: " . $url);
        
        $default_args = [
            'headers' => [
                'Api-Token' => $this->api_key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            'timeout' => 30
        ];
        
        $args = wp_parse_args($args, $default_args);
        error_log("WebinarGeek API Request Headers: " . print_r($args['headers'], true));
        
        try {
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                error_log("WebinarGeek API Error: " . $response->get_error_message());
                throw new Exception($response->get_error_message());
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            error_log("WebinarGeek API Status Code: " . $status_code);
            error_log("WebinarGeek API Raw Response: " . $body);
            
            if ($status_code !== 200) {
                throw new Exception("API returned status code: {$status_code}");
            }
            
            $data = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("WebinarGeek API JSON Error: " . json_last_error_msg());
                throw new Exception('Failed to parse JSON response');
            }
            
            error_log("WebinarGeek API Parsed Response: " . print_r($data, true));
            return $data;
            
        } catch (Exception $e) {
            error_log("WebinarGeek API Exception: " . $e->getMessage());
            return false;
        }
    }

    public function get_all_webinars() {
        $response = $this->make_request('webinars');
        
        // Check of we een geldige response hebben met webinars
        if (is_array($response) && isset($response['webinars'])) {
            error_log('Found ' . count($response['webinars']) . ' webinars');
            return $response['webinars'];  // Return alleen de webinars array
        } else {
            error_log('Invalid API response structure or no webinars found');
            return false;
        }
    }

    /**
     * Haalt de registratievelden op voor een webinar
     * 
     * @param string|int $webinar_id
     * @return array
     */
    public function get_webinar($webinar_id) {
        return $this->make_request("webinars/{$webinar_id}");
    }

    public function get_webinar_registration_fields($webinar_id) {
        $webinar = $this->get_webinar($webinar_id);
        return is_array($webinar) && isset($webinar['registration_fields']) 
            ? $webinar['registration_fields'] 
            : [];
    }
    
    /**
     * Debug methode om velden op te halen met extra logging
     * 
     * @param string|int $webinar_id
     * @return array
     */
    public function get_webinar_fields($webinar_id) {
        $data = $this->get_webinar($webinar_id);
        
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            error_log($this->error_log_prefix . 'Fields Response: ' . print_r($data, true));
        }
        
        return is_array($data) && isset($data['registration_fields']) 
            ? $data['registration_fields'] 
            : [];
    }
}