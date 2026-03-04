<?php
/**
 * WA Atlas CRM – Evolution API Wrapper  v1.0.2 (PATCHED)
 * =========================================================
 * FIXES:
 *  - Timeout reduced from 20s → 8s to prevent AJAX 403 timeouts
 *  - fetch_instances() result cached for 20 seconds (transient)
 *  - request() now returns detailed error for auth failures (401/403)
 *  - sslverify set to false for local/dev environments (Laragon)
 *  - create_instance() payload matches Evolution API v2 schema
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Evolution {

    private string $base_url;
    private string $api_key;

    public function __construct() {
        $this->base_url = trailingslashit( get_option( 'wacrm_api_url', 'http://whats.wpseoatlas.com' ) );
        $this->api_key  = WACRM_Crypto::decrypt( get_option( 'wacrm_api_key_enc', '429683C4C977415CAAFCCE10F7D57E11' ) );
    }

    // ── Core HTTP ─────────────────────────────────────────────────────────────

    private function request( string $method, string $endpoint, array $body = [] ): array {
        if ( empty( $this->base_url ) ) {
            return [ 'error' => 'Evolution API URL not configured. Go to Settings and enter your API URL.' ];
        }
        if ( empty( $this->api_key ) ) {
            return [ 'error' => 'Evolution API key not configured. Go to Settings and enter your API key.' ];
        }

        $args = [
            'method'    => strtoupper( $method ),
            'headers'   => [
                'Content-Type' => 'application/json',
                'apikey'       => $this->api_key,
            ],
            'timeout'   => 8,      // PATCHED: was 20 – 8s prevents AJAX 403 timeouts
            'sslverify' => false,  // PATCHED: allows Laragon/local HTTPS without cert errors
        ];

        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $url      = $this->base_url . $endpoint;
        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            // Give more helpful errors for common connection failures
            if ( strpos( $msg, 'timed out' ) !== false ) {
                return [ 'error' => 'Connection timed out. Check that your Evolution API server is running and reachable at: ' . $this->base_url ];
            }
            if ( strpos( $msg, 'Could not resolve' ) !== false || strpos( $msg, 'resolve host' ) !== false ) {
                return [ 'error' => 'Cannot reach Evolution API host. Check the URL in Settings.' ];
            }
            return [ 'error' => 'Connection error: ' . $msg ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $data = json_decode( $body_raw, true );

        if ( $code === 401 ) {
            return [ 'error' => 'Evolution API authentication failed (401). Check your API key in Settings.' ];
        }
        if ( $code === 403 ) {
            return [ 'error' => 'Evolution API access forbidden (403). Your API key may be incorrect or lack permissions.' ];
        }
        if ( $code === 404 ) {
            return [ 'error' => "Endpoint not found (404): $endpoint. Check your Evolution API version and URL." ];
        }
        if ( $code >= 400 ) {
            $msg = $data['message'] ?? $data['error'] ?? $body_raw;
            if ( is_array( $msg ) ) $msg = implode( ', ', $msg );
            return [ 'error' => "API error (HTTP $code): " . wp_strip_all_tags( (string) $msg ) ];
        }

        return is_array( $data ) ? $data : [];
    }

    // ── Instance management ───────────────────────────────────────────────────

    public function create_instance( string $name ): array {
        return $this->request( 'POST', 'instance/create', [
            'instanceName' => $name,
            'qrcode'       => true,
            'integration'  => 'WHATSAPP-BAILEYS',
        ] );
    }

    public function fetch_instances(): array {
        // PATCHED: Cache for 20 seconds to prevent hammering the API on every page load
        $cache_key = 'wacrm_evo_instances';
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $result = $this->request( 'GET', 'instance/fetchInstances' );

        // Only cache successful responses
        if ( ! isset( $result['error'] ) ) {
            set_transient( $cache_key, $result, 20 );
        }

        return $result;
    }

    /**
     * Force-refresh the instances cache and fetch live data.
     * Called after create/delete/restart operations.
     */
    public function fetch_instances_fresh(): array {
        delete_transient( 'wacrm_evo_instances' );
        return $this->fetch_instances();
    }

    public function connect_instance( string $name ): array {
        return $this->request( 'GET', "instance/connect/$name" );
    }

    public function restart_instance( string $name ): array {
        delete_transient( 'wacrm_evo_instances' ); // Bust cache after change
        return $this->request( 'PUT', "instance/restart/$name" );
    }

    public function logout_instance( string $name ): array {
        delete_transient( 'wacrm_evo_instances' );
        return $this->request( 'DELETE', "instance/logout/$name" );
    }

    public function delete_instance( string $name ): array {
        delete_transient( 'wacrm_evo_instances' );
        return $this->request( 'DELETE', "instance/delete/$name" );
    }

    public function instance_status( string $name ): array {
        return $this->request( 'GET', "instance/connectionState/$name" );
    }

    // ── Messaging ─────────────────────────────────────────────────────────────

    public function send_text( string $instance, string $phone, string $text ): array {
        return $this->request( 'POST', "message/sendText/$instance", [
            'number'      => $phone,
            'options'     => [ 'delay' => 1200 ],
            'textMessage' => [ 'text' => $text ],
        ] );
    }

    public function send_image( string $instance, string $phone, string $url, string $caption = '' ): array {
        return $this->request( 'POST', "message/sendMedia/$instance", [
            'number'       => $phone,
            'options'      => [ 'delay' => 1200 ],
            'mediaMessage' => [
                'mediatype' => 'image',
                'media'     => $url,
                'caption'   => $caption,
            ],
        ] );
    }

    public function send_audio( string $instance, string $phone, string $url ): array {
        return $this->request( 'POST', "message/sendWhatsAppAudio/$instance", [
            'number'       => $phone,
            'options'      => [ 'delay' => 1200 ],
            'audioMessage' => [ 'audio' => $url ],
        ] );
    }

    // ── QR code ───────────────────────────────────────────────────────────────

    public function get_qr( string $name ): array {
        // QR fetch uses longer timeout — it may need to generate the code
        $args = [
            'method'    => 'GET',
            'headers'   => [
                'Content-Type' => 'application/json',
                'apikey'       => $this->api_key,
            ],
            'timeout'   => 15,
            'sslverify' => false,
        ];

        $url      = $this->base_url . "instance/connect/$name";
        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            return [ 'error' => $data['message'] ?? "HTTP $code" ];
        }

        return is_array( $data ) ? $data : [];
    }

    // ── Static singleton ──────────────────────────────────────────────────────

    private static ?self $instance = null;

    public static function get(): self {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}