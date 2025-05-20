<?php
/**
 * Plugin Name: Kumbulink User Proxy
 * Description: Securely create users using the admin JWT via a proxy.
 * Version: 1.0
 * Author: Kumbulink Dev Team
 */

add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/create-user', [
        'methods'  => ['POST', 'OPTIONS'],
        'callback' => 'kumbulink_create_user_proxy',
        'permission_callback' => '__return_true',
    ]);
});
add_action('show_user_profile', 'kumbulink_extra_user_fields');
add_action('edit_user_profile', 'kumbulink_extra_user_fields');
add_action('personal_options_update', 'kumbulink_save_extra_user_fields');
add_action('edit_user_profile_update', 'kumbulink_save_extra_user_fields');

function kumbulink_create_user_proxy($request) {
    $jwt = getenv('ADMIN_JWT');
    if (!$jwt) {
        return new WP_Error('no_jwt', 'JWT de admin não configurado.', ['status' => 500]);
    }

    $body = $request->get_json_params();

    $required_fields = ['username', 'email', 'password'];
    foreach ($required_fields as $field) {
        if (empty($body[$field])) {
            return new WP_Error('missing_field', "Campo obrigatório ausente: $field", ['status' => 400]);
        }
    }

    $response = wp_remote_post('https://api.kumbulink.com/wp-json/wp/v2/users', [
        'headers' => [
            'Authorization' => 'Bearer ' . $jwt,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode([
            'username' => $body['username'],
            'email'    => $body['email'],
            'password' => $body['password'],
            'name'     => $body['name'] ?? '',
            'roles'    => ['author']
        ]),
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('request_failed', 'Erro ao criar usuário.', ['status' => 500]);
    }

    $status = wp_remote_retrieve_response_code($response);
    $response_body   = wp_remote_retrieve_body($response);
    $response_data = json_decode($response_body, true);
    $user_id = $response_data['id'];

    if ($status !== 201 || empty($response_data['id'])) {
        return new WP_Error('user_creation_failed', 'Erro ao criar usuário. Detalhes: ' . $response_body, ['status' => 500]);
    }

    if (!empty($body['birthDate'])) {
        update_user_meta($user_id, 'birth_date', sanitize_text_field($body['birthDate']));
    }

    if (!empty($body['documentType'])) {
        update_user_meta($user_id, 'document_type', sanitize_text_field($body['documentType']));
    }

    if (!empty($body['documentNumber'])) {
        update_user_meta($user_id, 'document_id', sanitize_text_field($body['documentNumber']));
    }

    if (!empty($body['country'])) {
        update_user_meta($user_id, 'country', sanitize_text_field($body['country']));
    }

    if (!empty($body['termsAccepted'])) {
        update_user_meta($user_id, 'terms_accepted', sanitize_text_field($body['termsAccepted']));
    }

    return new WP_REST_Response($response_data, $status);
}

function kumbulink_format_date_for_input($date_string) {
    if (empty($date_string)) return '';
    $date = new DateTime($date_string);
    return $date->format('Y-m-d');
}

function kumbulink_extra_user_fields($user) {
    ?>
    <br>
    <h2><?php _e('Additional Information', 'kumbulink'); ?></h2>
    <table class="form-table">
        <tr>
            <th><label for="birth_date"><?php _e('Birth Date', 'kumbulink'); ?></label></th>
            <td>
                <input type="date" name="birth_date" id="birth_date" value="<?php echo esc_attr(kumbulink_format_date_for_input(get_user_meta($user->ID, 'birth_date', true))); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th><label for="document_type"><?php _e('Document Type', 'kumbulink'); ?></label></th>
            <td>
                <input type="text" name="document_type" id="document_type" value="<?php echo esc_attr(get_user_meta($user->ID, 'document_type', true)); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th><label for="document_id"><?php _e('Document ID', 'kumbulink'); ?></label></th>
            <td>
                <input type="text" name="document_id" id="document_id" value="<?php echo esc_attr(get_user_meta($user->ID, 'document_id', true)); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th><label for="country"><?php _e('Country', 'kumbulink'); ?></label></th>
            <td>
                <input type="text" name="country" id="country" value="<?php echo esc_attr(get_user_meta($user->ID, 'country', true)); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th><label for="terms_accepted"><?php _e('Accepted Terms', 'kumbulink'); ?></label></th>
            <td>
                <input type="checkbox" name="terms_accepted" id="terms_accepted" value="1" <?php checked(get_user_meta($user->ID, 'terms_accepted', true), '1'); ?> />
                <label for="terms_accepted"><?php _e('Yes', 'kumbulink'); ?></label>
            </td>
        </tr>
    </table>
    <?php
}

function kumbulink_save_extra_user_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) return;

    // Convert the date to ISO 8601 format
    $birth_date = !empty($_POST['birth_date']) ? date('Y-m-d\TH:i:s.000\Z', strtotime($_POST['birth_date'])) : '';
    update_user_meta($user_id, 'birth_date', sanitize_text_field($birth_date));
    
    update_user_meta($user_id, 'document_type', sanitize_text_field($_POST['document_type']));
    update_user_meta($user_id, 'document_id', sanitize_text_field($_POST['document_id']));
    update_user_meta($user_id, 'country', sanitize_text_field($_POST['country']));
    update_user_meta($user_id, 'terms_accepted', isset($_POST['terms_accepted']) ? '1' : '0');
}