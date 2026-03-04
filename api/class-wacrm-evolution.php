<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Evolution {

    private string $base_url;
    private string $api_key;

    public function __construct() {
        $this->base_url = trailingslashit( get_option( 'wacrm_api_url', '' ) );
        $this->api_key  = WACRM_Crypto::decrypt( get_option( 'wacrm_api_key_enc', '' ) );
    }

    // ── Core HTTP ─────────────────────────────────────────────────────────────

    private function request( string $method, string $endpoint, array $body = [] ): array {
        if ( empty( $this->base_url ) || empty( $this->api_key ) ) {
            return [ 'error' => 'Evolution API not configured.' ];
        }

        $args = [
            'method'  => strtoupper( $method ),
            'headers' => [
                'Content-Type' => 'application/json',
                'apikey'       => $this->api_key,
            ],
            'timeout' => 20,
        ];

        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $this->base_url . $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            return [ 'error' => $data['message'] ?? "HTTP $code" ];
        }

        return $data ?? [];
    }

    // ── Instance management ───────────────────────────────────────────────────

    public function create_instance( string $name ): array {
        return $this->request( 'POST', 'instance/create', [
            'instanceName'       => $name,
            'qrcode'             => true,
            'integration'        => 'WHATSAPP-BAILEYS',
        ] );
    }

    public function fetch_instances(): array {
        return $this->request( 'GET', 'instance/fetchInstances' );
    }

    public function connect_instance( string $name ): array {
        return $this->request( 'GET', "instance/connect/$name" );
    }

    public function restart_instance( string $name ): array {
        return $this->request( 'PUT', "instance/restart/$name" );
    }

    public function logout_instance( string $name ): array {
        return $this->request( 'DELETE', "instance/logout/$name" );
    }

    public function delete_instance( string $name ): array {
        return $this->request( 'DELETE', "instance/delete/$name" );
    }

    public function instance_status( string $name ): array {
        return $this->request( 'GET', "instance/connectionState/$name" );
    }

    // ── Messaging ─────────────────────────────────────────────────────────────

    public function send_text( string $instance, string $phone, string $text ): array {
        return $this->request( 'POST', "message/sendText/$instance", [
            'number'  => $phone,
            'options' => [ 'delay' => 1200 ],
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
        return $this->request( 'GET', "instance/connect/$name" );
    }

    // ── Static singleton ──────────────────────────────────────────────────────

    private static ?self $instance = null;
    public static function get(): self {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }
}
