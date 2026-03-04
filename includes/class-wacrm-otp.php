<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_OTP {

    public static function init(): void {
        if ( ! get_option( 'wacrm_otp_enabled', 0 ) ) return;
        add_action( 'woocommerce_after_checkout_billing_form', [ __CLASS__, 'render_otp_field' ] );
        add_action( 'wp_ajax_nopriv_wacrm_send_otp', [ __CLASS__, 'ajax_send' ] );
        add_action( 'wp_ajax_wacrm_send_otp',        [ __CLASS__, 'ajax_send' ] );
        add_action( 'wp_ajax_nopriv_wacrm_verify_otp', [ __CLASS__, 'ajax_verify' ] );
        add_action( 'wp_ajax_wacrm_verify_otp',        [ __CLASS__, 'ajax_verify' ] );
        add_action( 'woocommerce_checkout_process', [ __CLASS__, 'block_unverified' ] );
    }

    public static function render_otp_field(): void {
        ?>
        <div id="wacrm-otp-wrap" style="margin-top:16px;">
            <p class="form-row form-row-wide">
                <button type="button" id="wacrm-send-otp" class="button alt">
                    <?php esc_html_e( 'Verify via WhatsApp OTP', 'wa-atlas-crm' ); ?>
                </button>
                <span id="wacrm-otp-status" style="margin-left:10px;"></span>
            </p>
            <div id="wacrm-otp-input-wrap" style="display:none;">
                <label><?php esc_html_e( 'Enter OTP', 'wa-atlas-crm' ); ?></label>
                <input type="text" id="wacrm-otp-code" maxlength="6" placeholder="••••••" autocomplete="one-time-code">
                <button type="button" id="wacrm-verify-otp" class="button"><?php esc_html_e( 'Verify', 'wa-atlas-crm' ); ?></button>
            </div>
            <input type="hidden" name="wacrm_otp_verified" id="wacrm_otp_verified" value="0">
        </div>
        <script>
        (function(){
            var nonce = '<?php echo esc_js( wp_create_nonce( 'wacrm_otp' ) ); ?>';
            document.getElementById('wacrm-send-otp').addEventListener('click', function(){
                var phone = document.getElementById('billing_phone').value;
                if(!phone){alert('<?php esc_html_e( 'Please enter your phone number first.', 'wa-atlas-crm' ); ?>');return;}
                this.disabled = true;
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:'action=wacrm_send_otp&phone='+encodeURIComponent(phone)+'&_nonce='+nonce
                }).then(r=>r.json()).then(d=>{
                    document.getElementById('wacrm-otp-status').textContent = d.data && d.data.message ? d.data.message : '';
                    if(d.success) document.getElementById('wacrm-otp-input-wrap').style.display='block';
                });
            });
            document.getElementById('wacrm-verify-otp').addEventListener('click', function(){
                var code  = document.getElementById('wacrm-otp-code').value;
                var phone = document.getElementById('billing_phone').value;
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:'action=wacrm_verify_otp&phone='+encodeURIComponent(phone)+'&code='+encodeURIComponent(code)+'&_nonce='+nonce
                }).then(r=>r.json()).then(d=>{
                    document.getElementById('wacrm-otp-status').textContent = d.data && d.data.message ? d.data.message : '';
                    if(d.success){
                        document.getElementById('wacrm_otp_verified').value='1';
                        document.getElementById('wacrm-otp-input-wrap').style.display='none';
                        document.getElementById('wacrm-send-otp').textContent='✓ Verified';
                        document.getElementById('wacrm-send-otp').style.background='#22c55e';
                    }
                });
            });
        })();
        </script>
        <?php
    }

    public static function ajax_send(): void {
        check_ajax_referer( 'wacrm_otp', '_nonce' );
        $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        if ( empty( $phone ) ) {
            wp_send_json_error( [ 'message' => 'Invalid phone.' ] );
        }
        if ( WACRM_Quota::is_blocked() ) {
            wp_send_json_error( [ 'message' => 'Message quota exceeded.' ] );
        }

        $otp        = WACRM_Helpers::generate_otp();
        $expires    = date( 'Y-m-d H:i:s', time() + (int) get_option( 'wacrm_otp_expiry', 300 ) );
        global $wpdb;
        $wpdb->insert( WACRM_DB::otp_logs(), [
            'phone'      => $phone,
            'otp_code'   => $otp,
            'attempts'   => 0,
            'verified'   => 0,
            'expires_at' => $expires,
            'created_at' => current_time( 'mysql' ),
        ] );

        $tpl_id  = get_option( 'wacrm_otp_template', 0 );
        $message = "Your verification code is: $otp";
        if ( $tpl_id ) {
            $tpl = WACRM_Templates::get( (int) $tpl_id );
            if ( $tpl ) $message = WACRM_Helpers::parse_tags( $tpl['body'], [ 'otp' => $otp ] );
        }

        $instance = WACRM_Helpers::active_instance();
        if ( empty( $instance ) ) {
            wp_send_json_error( [ 'message' => 'WhatsApp not connected.' ] );
        }

        $res = WACRM_Evolution::get()->send_text( $instance, $phone, $message );
        if ( isset( $res['error'] ) ) {
            wp_send_json_error( [ 'message' => $res['error'] ] );
        }
        WACRM_Quota::increment();
        wp_send_json_success( [ 'message' => 'OTP sent to your WhatsApp.' ] );
    }

    public static function ajax_verify(): void {
        check_ajax_referer( 'wacrm_otp', '_nonce' );
        $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $code  = sanitize_text_field( wp_unslash( $_POST['code']  ?? '' ) );
        global $wpdb;

        $max_attempts = (int) get_option( 'wacrm_otp_max_attempts', 5 );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . WACRM_DB::otp_logs() . " WHERE phone=%s AND verified=0 AND expires_at > %s ORDER BY id DESC LIMIT 1",
            $phone, current_time( 'mysql' )
        ), ARRAY_A );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'OTP expired or not found.' ] );
        }
        if ( $row['attempts'] >= $max_attempts ) {
            wp_send_json_error( [ 'message' => 'Maximum attempts exceeded.' ] );
        }
        if ( $row['otp_code'] !== $code ) {
            $wpdb->update( WACRM_DB::otp_logs(), [ 'attempts' => $row['attempts'] + 1 ], [ 'id' => $row['id'] ] );
            wp_send_json_error( [ 'message' => 'Invalid OTP.' ] );
        }

        $wpdb->update( WACRM_DB::otp_logs(), [ 'verified' => 1 ], [ 'id' => $row['id'] ] );
        // store in session so block_unverified can check
        WC()->session->set( 'wacrm_otp_verified', $phone );
        wp_send_json_success( [ 'message' => 'Phone verified!' ] );
    }

    public static function block_unverified(): void {
        // phpcs:ignore WordPress.Security.NonceVerification
        $verified_flag = sanitize_text_field( wp_unslash( $_POST['wacrm_otp_verified'] ?? '0' ) );
        $phone         = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ?? '' ) );
        $sess_phone    = WC()->session ? WC()->session->get( 'wacrm_otp_verified', '' ) : '';

        if ( $verified_flag !== '1' && $sess_phone !== $phone ) {
            wc_add_notice( __( 'Please verify your WhatsApp number before placing your order.', 'wa-atlas-crm' ), 'error' );
        }
    }
}
