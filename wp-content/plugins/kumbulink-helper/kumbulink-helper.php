<?php
/**
 * Plugin Name: Kumbulink Backend Helper
 * Description: Adiciona esteroides ao backend.
 * Version: 1.1
 * Author: Kumbulink Dev Team
 */

// Redireciona qualquer acesso ao frontend para o admin
add_action('template_redirect', function () {
	if (!is_admin()) {
		wp_redirect(admin_url());
		exit;
	}
});

// Habilita registro via API
add_filter('rest_user_registration_enabled', function () {
	return true;
});


/*  --------- START JWT AUTH CONFIG  --------- */
add_filter('rest_pre_dispatch', 'inject_jwt_from_cookie', 10, 3);

function inject_jwt_from_cookie($result, $server, $request) {
	if (isset($_COOKIE['jwt_token'])) {
		$token = $_COOKIE['jwt_token'];
		
		$request->set_header('Authorization', 'Bearer ' . $token);
		
		// Try to decode token manually
		$token_parts = explode('.', $token);
		if (count($token_parts) === 3) {
			$payload = json_decode(base64_decode($token_parts[1]), true);
			
			if (isset($payload['data']['user']['id'])) {
				$user_id = $payload['data']['user']['id'];
				if (get_userdata($user_id)) {
					wp_set_current_user($user_id);
					wp_set_auth_cookie($user_id); 
				}
			}
		}
		
	} else {
		error_log('No JWT token found in cookie');
	}

	return $result;
}

add_filter('jwt_auth_token_before_dispatch', 'set_jwt_cookie_http_only', 10, 2);
add_filter('jwt_auth_token_before_dispatch', 'kumbulink_extend_jwt_response', 10, 2);

function set_jwt_cookie_http_only($data, $user) {
    $token = $data['token'];
    
    // If admin, cookie will last for 10 years
    $expiration = user_can($user->ID, 'administrator') 
        ? time() + (10 * 365 * 24 * 60 * 60)  // 10 years
        : time() + 3600;                       // 1 hour for normal users
    
    setcookie(
        'jwt_token',
        $token,
        [
            'expires' => $expiration,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'],
            'secure' => true,            // only HTTPS
            'httponly' => true,          // inacessible via JS
            'samesite' => 'Strict'      // prevent CSRF cross-site
        ]
    );
    
    // If admin, keep the token in the response
    if (user_can($user->ID, 'administrator')) {
        $data['token'] = $token;
    } else {
        // If not admin, remove the token from the response
        // unset($data['token']);
    }
    
    return $data;
}

function kumbulink_extend_jwt_response($data, $user) {
		$user_id = $user->ID;
		
		$data['id'] = $user_id;
		$data['document_id']   = get_field('document_id', 'user_' . $user_id);
    $data['document_type'] = get_field('document_type', 'user_' . $user_id);

    return $data;
}

/*  --------- END JWT AUTH CONFIG  --------- */

/*  --------- START CORS CONFIG  --------- */
add_action('rest_api_init', function () {
	remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');

	add_filter('rest_pre_serve_request', function ($value) {
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		$allowed_origins = [
			'http://localhost:5173',
			'https://local.kumbulink.com:5173',
			'https://localhost:5173',
			'http://127.0.0.1:5173',
			'https://kumbulink.com',
			'https://www.kumbulink.com'
		];

		if (in_array($origin, $allowed_origins)) {
			header("Access-Control-Allow-Origin: $origin");
			header("Access-Control-Allow-Credentials: true");
			header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
			header("Access-Control-Allow-Headers: Content-Type, Authorization");
	}

		// avoid OPTIONS
		if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
			return new WP_REST_Response(null, 200);
	}

		return $value;
	});
});

/*  --------- END CORS CONFIG  --------- */

/*  --------- START CUSTOM ROUTES  --------- */

add_action('rest_api_init', function () {
	register_rest_route('custom/v1', '/logout', [
			'methods' => 'POST',
			'callback' => function () {
				$params = session_get_cookie_params();
					setcookie('jwt_token', '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);

					return new WP_REST_Response(['message' => 'Logout successful'], 200);
			},
			'permission_callback' => '__return_true',
	]);
});

add_action('rest_after_insert_matches', function ($post, $request, $creating) {
	error_log('🔍 Disparou rest_after_insert_match para post ID: ' . $post->ID);
	if (!$creating) return;

	$post_id = $post->ID;
	$related_offer = get_field('relatedOffer', $post_id);

	if (!$related_offer) {
		error_log("❌ relatedOffer não encontrado no match $post_id.");
		return;
	}

	if (get_post_type($related_offer) !== 'classifieds') {
		error_log("❌ Post #$related_offer não é classifieds.");
		return;
	}

	update_field('status', 'pending', $related_offer);
	error_log("✅ Post classifieds #$related_offer atualizado para 'pending' a partir do match #$post_id.");
}, 10, 3);





/*  --------- END CUSTOM ROUTES  --------- */

/*  --------- START CUSTOM FILTERS  --------- */

add_action('restrict_manage_posts', function () {
	global $typenow;

	$post_types = ['classifieds', 'match'];

	if (!in_array($typenow, $post_types)) return;

	$authors = get_users(['who' => 'authors']);

	$status_options = [
		'created' => __('No Matches'),
		'matched' => __('Matched'),
		'pending' => __('Awaiting Payments'),
		'paid' => __('Paid'),
		'confirmed' => __('Completed')
	];

	$selected = $_GET['custom_status'] ?? '';

	// author filter
	echo '<select name="author" id="author" class="postform">';
	echo '<option value="">' . esc_html__( 'All authors' ) . '</option>';
	foreach ($authors as $author) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr($author->ID),
			(isset($_GET['author']) && $_GET['author'] == $author->ID) ? ' selected="selected"' : '',
			esc_html($author->display_name)
		);
	}
	echo '</select>';

	// status filter
	echo '<select name="custom_status">';
	echo '<option value="">' . esc_html__( 'All statuses' ) . '</option>';
	foreach ($status_options as $key => $label) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr($key),
			selected($selected, $key, false),
			esc_html($label)
		);
	}
	echo '</select>';
});

// apply the filter in the admin
add_filter('pre_get_posts', function ($query) {
	global $pagenow;

	if (!is_admin() || $pagenow !== 'edit.php' || !$query->is_main_query()) {
		return;
	}

	$custom_status = $_GET['custom_status'] ?? '';

	if ($custom_status) {
		$query->set('meta_query', [
			[
				'key' => 'status',
				'value' => $custom_status,
				'compare' => '='
			]
		]);
	}
});

// Add status filter to classifieds REST API
add_action('rest_api_init', function () {
	register_rest_field('classifieds', 'status', [
			'get_callback' => function ($post_arr) {
					return get_field('status', $post_arr['id']);
			},
			'schema' => [
					'description' => 'Classified status',
					'type'        => 'string',
					'context'     => ['view', 'edit'],
			],
	]);
});

// Apply status filter to classifieds REST API
add_filter('rest_classifieds_query', function ($args, $request) {
	if (!empty($request['offer_status'])) {
			$args['meta_query'][] = [
					'key' => 'status',
					'value' => sanitize_text_field($request['offer_status']),
					'compare' => '='
			];
	}

	return $args;
}, 10, 2);

/*  --------- END CUSTOM FILTERS  --------- */

/*  --------- START CUSTOM API RESPONSE  --------- */

// return sellerTo and sellerFrom with bank name
add_filter('rest_prepare_classifieds', function ($response, $post, $request) {
	$acf = get_fields($post->ID);

	$sellerToId = get_field('sellerTo', $post->ID, false); 
	if ($sellerToId) {
		$acf['sellerTo'] = [
			'id'   => $sellerToId,
			'bank' => get_field('bank', $sellerToId),
		];
	}

	$sellerFromId = get_field('sellerFrom', $post->ID, false); 
	if ($sellerFromId) {
		$acf['sellerFrom'] = [
			'id'   => $sellerFromId,
			'bank' => get_field('bank', $sellerFromId),
		];
	}

	$response->data['acf'] = $acf;

	return $response;
}, 30, 3);





