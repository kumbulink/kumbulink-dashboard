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
    $user_id = 'user_' . $response_data['id'];

    if ($status !== 201 || empty($response_data['id'])) {
        return new WP_Error('user_creation_failed', 'Erro ao criar usuário. Detalhes: ' . $response_body, ['status' => 500]);
    }

   if (!empty($body['birthDate'])) {
        update_field('birth_date', sanitize_text_field($body['birthDate']), $user_id);
    }

    if (!empty($body['documentType'])) {
        update_field('document_type', sanitize_text_field($body['documentType']), $user_id);
    }

    if (!empty($body['documentNumber'])) {
        update_field('document_id', sanitize_text_field($body['documentNumber']), $user_id);
    }

    if (!empty($body['country'])) {
        update_field('country', sanitize_text_field($body['country']), $user_id);
    }

    if (!empty($body['termsAccepted'])) {
        update_field('terms_accepted', sanitize_text_field($body['termsAccepted']), $user_id);
    }

    // Add ACF fields to the response
    $response_data['acf'] = [
        'birth_date' => get_field('birth_date', $user_id),
        'document_type' => get_field('document_type', $user_id),
        'document_id' => get_field('document_id', $user_id),
        'country' => get_field('country', $user_id),
        'terms_accepted' => get_field('terms_accepted', $user_id)
    ];

    return new WP_REST_Response($response_data, $status);
}
