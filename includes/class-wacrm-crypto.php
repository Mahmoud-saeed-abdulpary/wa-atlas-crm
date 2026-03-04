<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Crypto {

    private static function key(): string {
        $k = get_option( 'wacrm_enc_key', '' );
        if ( empty( $k ) ) {
            $k = wp_generate_password( 32, false );
            update_option( 'wacrm_enc_key', $k );
        }
        return substr( hash( 'sha256', $k ), 0, 32 );
    }

    public static function encrypt( string $plain ): string {
        if ( empty( $plain ) ) return '';
        $iv  = openssl_random_pseudo_bytes( 16 );
        $enc = openssl_encrypt( $plain, 'AES-256-CBC', self::key(), 0, $iv );
        return base64_encode( $iv . $enc );
    }

    public static function decrypt( string $cipher ): string {
        if ( empty( $cipher ) ) return '';
        $raw = base64_decode( $cipher );
        if ( strlen( $raw ) < 17 ) return '';
        $iv  = substr( $raw, 0, 16 );
        $enc = substr( $raw, 16 );
        return (string) openssl_decrypt( $enc, 'AES-256-CBC', self::key(), 0, $iv );
    }
}
