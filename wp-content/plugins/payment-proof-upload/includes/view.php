<?php

use Aws\S3\S3Client;

add_action('rest_api_init', function () {
	register_rest_route('custom/v1', '/payment-proof/view', [
		'methods'  => 'GET',
		'callback' => 'payment_proof_view_handler',
		'permission_callback' => function () {
			return is_user_logged_in() && current_user_can('manage_options');
		},
	]);
});

function payment_proof_view_handler(WP_REST_Request $request) {
	$post_id = (int) $request->get_param('post_id');
	$type = $request->get_param('type');

	$field = $type === 'seller'
		? 'sellerPaymentProof'
		: 'buyerPaymentProof';

	$s3_key = get_field($field, $post_id);

	if (!$s3_key) {
		return new WP_Error('not_found', 'Payment proof not found.');
	}

	return [
		'url' => kumbulink_generate_presigned_url($s3_key, '+10 minutes'),
	];
}
