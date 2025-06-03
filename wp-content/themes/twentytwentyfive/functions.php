<?php
/**
 * Twenty Twenty-Five functions and definitions.
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package WordPress
 * @subpackage Twenty_Twenty_Five
 * @since Twenty Twenty-Five 1.0
 */

// Adds theme support for post formats.
if ( ! function_exists( 'twentytwentyfive_post_format_setup' ) ) :
	/**
	 * Adds theme support for post formats.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_post_format_setup() {
		add_theme_support( 'post-formats', array( 'aside', 'audio', 'chat', 'gallery', 'image', 'link', 'quote', 'status', 'video' ) );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_post_format_setup' );

// Enqueues editor-style.css in the editors.
if ( ! function_exists( 'twentytwentyfive_editor_style' ) ) :
	/**
	 * Enqueues editor-style.css in the editors.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_editor_style() {
		add_editor_style( get_parent_theme_file_uri( 'assets/css/editor-style.css' ) );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_editor_style' );

// Enqueues style.css on the front.
if ( ! function_exists( 'twentytwentyfive_enqueue_styles' ) ) :
	/**
	 * Enqueues style.css on the front.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_enqueue_styles() {
		wp_enqueue_style(
			'twentytwentyfive-style',
			get_parent_theme_file_uri( 'style.css' ),
			array(),
			wp_get_theme()->get( 'Version' )
		);
	}
endif;
add_action( 'wp_enqueue_scripts', 'twentytwentyfive_enqueue_styles' );

// Registers custom block styles.
if ( ! function_exists( 'twentytwentyfive_block_styles' ) ) :
	/**
	 * Registers custom block styles.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_block_styles() {
		register_block_style(
			'core/list',
			array(
				'name'         => 'checkmark-list',
				'label'        => __( 'Checkmark', 'twentytwentyfive' ),
				'inline_style' => '
				ul.is-style-checkmark-list {
					list-style-type: "\2713";
				}

				ul.is-style-checkmark-list li {
					padding-inline-start: 1ch;
				}',
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_block_styles' );

// Registers pattern categories.
if ( ! function_exists( 'twentytwentyfive_pattern_categories' ) ) :
	/**
	 * Registers pattern categories.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_pattern_categories() {

		register_block_pattern_category(
			'twentytwentyfive_page',
			array(
				'label'       => __( 'Pages', 'twentytwentyfive' ),
				'description' => __( 'A collection of full page layouts.', 'twentytwentyfive' ),
			)
		);

		register_block_pattern_category(
			'twentytwentyfive_post-format',
			array(
				'label'       => __( 'Post formats', 'twentytwentyfive' ),
				'description' => __( 'A collection of post format patterns.', 'twentytwentyfive' ),
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_pattern_categories' );

// Registers block binding sources.
if ( ! function_exists( 'twentytwentyfive_register_block_bindings' ) ) :
	/**
	 * Registers the post format block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_register_block_bindings() {
		register_block_bindings_source(
			'twentytwentyfive/format',
			array(
				'label'              => _x( 'Post format name', 'Label for the block binding placeholder in the editor', 'twentytwentyfive' ),
				'get_value_callback' => 'twentytwentyfive_format_binding',
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_register_block_bindings' );

// Registers block binding callback function for the post format name.
if ( ! function_exists( 'twentytwentyfive_format_binding' ) ) :
	/**
	 * Callback function for the post format name block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return string|void Post format name, or nothing if the format is 'standard'.
	 */
	function twentytwentyfive_format_binding() {
		$post_format_slug = get_post_format();

		if ( $post_format_slug && 'standard' !== $post_format_slug ) {
			return get_post_format_string( $post_format_slug );
		}
	}
endif;


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