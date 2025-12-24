<?php
/**
 * Plugin Name: Kumbulink Backend Helper
 * Description: Adiciona esteroides ao backend.
 * Version: 1.1
 * Author: Kumbulink Dev Team
 */

require_once __DIR__ . '/utils/cookie.php';

// Redirects any frontend access to the dashboard
add_action('template_redirect', function () {
	if (!is_admin()) {
		wp_redirect(admin_url());
		exit;
	}
});

// Enable user registration via API
add_filter('rest_user_registration_enabled', function () {
	return true;
});


########################################################################
#########     START JWT AUTH CONFIG   ##################################
########################################################################
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

		kumbulink_set_jwt_cookie($token, $expiration);
    
    // If admin, keep the token in the response
    if (user_can($user->ID, 'administrator')) {
        $data['token'] = $token;
    } else {
        unset($data['token']);
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

########################################################################
##############   START CORS CONFIG   ###################################
########################################################################

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


########################################################################
##############  START CUSTOM ROUTES  ###################################
########################################################################

# LOGOUT
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

# MATCHES - Update match status right after its creation
add_action('rest_after_insert_matches', function ($post, $request, $creating) {
	if (!$creating) return;

	$post_id = $post->ID;
	$related_offer = get_field('relatedOffer', $post_id);

	if (!$related_offer) {
		return;
	}

	if (get_post_type($related_offer) !== 'classifieds') {
		return;
	}

	update_field('status', 'pending', $related_offer);
}, 10, 3);


# MATCH BY OFFER - Find match Idd for offer payment proof upload
add_action('rest_api_init', function () {
  register_rest_route('custom/v1', '/match-by-offer/(?P<offer_id>\d+)', [
    'methods' => 'GET',
    'callback' => 'get_match_by_offer',
    'permission_callback' => function () {
      return is_user_logged_in();
    }
  ]);
});

function get_match_by_offer(WP_REST_Request $request) {
  $offer_id = intval($request['offer_id']);

  $matches = get_posts([
    'post_type' => 'matches',
    'posts_per_page' => 1,
    'meta_query' => [
      [
        'key' => 'relatedOffer',
        'value' => $offer_id,
        'compare' => '='
      ]
    ]
  ]);

  if (empty($matches)) {
    return new WP_Error('no_match', 'Nenhum match encontrado');
  }

  return [
    'match_id' => $matches[0]->ID
  ];
}

########################################################################
##############  START CUSTOM FILTERS  ##################################
########################################################################

# CLASSIFIEDS FILTERS - Add Author and Status to the Classifieds filter
add_action('restrict_manage_posts', function () {
	global $typenow;

	$post_types = ['classifieds', 'match'];

	if (!in_array($typenow, $post_types)) return;

	$authors = get_users(['who' => 'authors']);

	$status_options = [
		'created' => __('No Matches'),
		'pending' => __('Awaiting Payments'),
		'done' => __('Completed')
	];

	$selected = $_GET['custom_status'] ?? '';

	# AUTHOR FILTER
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

	# STATUS FILTER
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


########################################################################
##############  START CUSTOM API RESPONSE  #############################
########################################################################

// return sellerTo and sellerFrom with bank name
function kumbulink_prepare_response($response, $post, $request) {
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

	$buyerToId = get_field('buyerTo', $post->ID, false); 
	if ($buyerToId) {
		$acf['buyerTo'] = [
			'id'   => $buyerToId,
			'bank' => get_field('bank', $buyerToId),
		];
	}

	$buyerFromId = get_field('buyerFrom', $post->ID, false); 
	if ($buyerFromId) {
		$acf['buyerFrom'] = [
			'id'   => $buyerFromId,
			'bank' => get_field('bank', $buyerFromId),
		];
	}

	$response->data['acf'] = $acf;

	return $response;
}

add_filter('rest_prepare_classifieds', 'kumbulink_prepare_response' , 30, 3);
add_filter('rest_prepare_matches', 'kumbulink_prepare_response' , 30, 3);

add_filter('rest_prepare_matches', function ($response, $post, $request) {
	$match_id = $post->ID;
	$related_id = get_field('relatedOffer', $match_id);

	if ($related_id && get_post_type($related_id) === 'classifieds') {
		$related_fields = get_fields($related_id);

		// Sanitize sellerTo
		if (!empty($related_fields['sellerTo']) && is_object($related_fields['sellerTo'])) {
			$sellerToId = $related_fields['sellerTo']->ID ?? null;

			if ($sellerToId) {
				$related_fields['sellerTo'] = [
					'id'   => $sellerToId,
					'bank' => get_field('bank', $sellerToId),
				];
			}
		}

		// Sanitize sellerFrom
		if (!empty($related_fields['sellerFrom']) && is_object($related_fields['sellerFrom'])) {
			$sellerFromId = $related_fields['sellerFrom']->ID ?? null;

			if ($sellerFromId) {
				$related_fields['sellerFrom'] = [
					'id'   => $sellerFromId,
					'bank' => get_field('bank', $sellerFromId),
				];
			}
		}

		// Includes the data as nested object into the offer object
		$response->data['offer'] = [
			'id' => $related_id,
			'fields' => $related_fields
		];
	}

	return $response;
}, 10, 3);

########################################################################
#####################  START CUSTOM FIELDS #############################
########################################################################

/* Hide bank fields and display read-only information */
add_action('admin_head', function () {
	echo '<style>
			.acf-field[data-name="buyerTo"] {
					display: none;
			}
			.acf-field[data-name="sellerTo"] {
				display: none;
			}
	</style>';
});

add_filter('acf/load_field/name=buyerBankDetails', function ($field) {

	$post_id = get_the_ID();
	$bank_id = get_field('buyerTo', $post_id);

	if (!$bank_id) {
			$field['message'] = '<em>Nenhum banco linkado</em>';
			return $field;
	}

	$country        = get_field('country', $bank_id);
	$recipient_name = get_field('recipient_name', $bank_id);
	$bank           = get_field('bank', $bank_id);
	$branch         = get_field('branch', $bank_id);
	$account        = get_field('account_number', $bank_id);
	$payment_key    = get_field('payment_key', $bank_id);

	$rows = [];

	$country        && $rows[] = "<strong>País:</strong> {$country}";
	$recipient_name && $rows[] = "<strong>Recebedor:</strong> {$recipient_name}";
	$bank           && $rows[] = "<strong>Banco:</strong> {$bank}";
	$branch          && $rows[] = "<strong>Agencia:</strong> {$branch}";
	$account         && $rows[] = "<strong>Conta:</strong> {$account}";
	$payment_key    && $rows[] = "<strong>Chave de Pagamento:</strong> {$payment_key}";

	$field['message'] = implode('<br>', $rows);

	return $field;
});

add_filter('acf/load_field/name=sellerBankDetails', function ($field) {

	$post_id = get_the_ID();
	$bank_id = get_field('sellerTo', $post_id);

	if (!$bank_id) {
			$field['message'] = '<em>Nenhum banco linkado</em>';
			return $field;
	}

	$country        = get_field('country', $bank_id);
	$recipient_name = get_field('recipient_name', $bank_id);
	$bank           = get_field('bank', $bank_id);
	$branch         = get_field('branch', $bank_id);
	$account        = get_field('account_number', $bank_id);
	$payment_key    = get_field('payment_key', $bank_id);

	$rows = [];

	$country        && $rows[] = "<strong>País:</strong> {$country}";
	$recipient_name && $rows[] = "<strong>Recebedor:</strong> {$recipient_name}";
	$bank           && $rows[] = "<strong>Banco:</strong> {$bank}";
	$branch          && $rows[] = "<strong>Agencia:</strong> {$branch}";
	$account         && $rows[] = "<strong>Conta:</strong> {$account}";
	$payment_key    && $rows[] = "<strong>Chave de Pagamento:</strong> {$payment_key}";

	$field['message'] = implode('<br>', $rows);

	return $field;
});

// Set read-only fields inside match posts
add_filter('acf/load_field', function ($field) {
	$readonly_fields = [
			'buyer',
			'relatedOffer',
			'exchangeRate',
			'tax',
			'totalToBuyer',
			'totalToSeller'
	];

	if (in_array($field['name'], $readonly_fields, true)) {
			$field['disabled'] = 1;
	}

	return $field;
});


// Add temporary url for payment proof view (/payment-proof/view)
add_action('acf/render_field/name=sellerPaymentProof', 'render_payment_proof_admin_link');
add_action('acf/render_field/name=buyerPaymentProof', 'render_payment_proof_admin_link');

function render_payment_proof_admin_link($field) {
	if (empty($field['value'])) {
		return;
	}

	if (!current_user_can('manage_options')) {
		return;
	}

	$type = $field['_name'] === 'sellerPaymentProof' ? 'seller' : 'buyer';

	$url = add_query_arg([
		'post_id' => get_the_ID(),
		'type'    => $type,
	], rest_url('custom/v1/payment-proof/view'));

	echo '<p style="margin-top:6px">';
	echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">';
	echo '🔐 Ver comprovante';
	echo '</a>';
	echo '</p>';
}
