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

function kumbulink_create_user_proxy($request) {
    $body = $request->get_json_params();

    $required_fields = ['username', 'email', 'password'];
    foreach ($required_fields as $field) {
        if (empty($body[$field])) {
            return new WP_Error('missing_field', "Campo obrigatório ausente: $field", ['status' => 400]);
        }
    }

    if (username_exists($body['username']) || email_exists($body['email'])) {
        return new WP_Error('user_exists', 'Usuário ou e-mail já cadastrado.', ['status' => 409]);
    }

    $user_id = wp_insert_user([
        'user_login' => sanitize_user($body['username']),
        'user_pass'  => $body['password'],
        'user_email' => sanitize_email($body['email']),
        'display_name' => $body['name'] ?? '',
        'role' => 'author'
    ]);

    if (is_wp_error($user_id)) {
        return new WP_Error('user_creation_failed', 'Erro ao criar usuário: ' . $user_id->get_error_message(), ['status' => 500]);
    }

    // Campos ACF (opcional)
    if (!empty($body['birthDate'])) {
        update_field('birth_date', sanitize_text_field($body['birthDate']), 'user_' . $user_id);
    }

    if (!empty($body['documentType'])) {
        update_field('document_type', sanitize_text_field($body['documentType']), 'user_' . $user_id);
    }

    if (!empty($body['documentNumber'])) {
        update_field('document_id', sanitize_text_field($body['documentNumber']), 'user_' . $user_id);
    }

    if (!empty($body['country'])) {
        update_field('country', sanitize_text_field($body['country']), 'user_' . $user_id);
    }

    if (!empty($body['termsAccepted'])) {
        update_field('terms_accepted', sanitize_text_field($body['termsAccepted']), 'user_' . $user_id);
    }

    return new WP_REST_Response([
        'id'   => $user_id,
        'username' => $body['username'],
        'email'    => $body['email'],
        'acf' => [
            'birth_date' => get_field('birth_date', 'user_' . $user_id),
            'document_type' => get_field('document_type', 'user_' . $user_id),
            'document_id' => get_field('document_id', 'user_' . $user_id),
            'country' => get_field('country', 'user_' . $user_id),
            'terms_accepted' => get_field('terms_accepted', 'user_' . $user_id)
        ]
    ], 201);
}

