<?php

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

add_action('rest_api_init', function () {
	register_rest_route('custom/v1', '/payment-proof', [
		'methods'  => 'POST',
		'callback' => 'payment_proof_upload_handler',
		'permission_callback' => 'kumbulink_payment_proof_permission_check',
	]);
});

function kumbulink_payment_proof_permission_check(WP_REST_Request $request) {
	if (!is_user_logged_in()) {
			error_log('User not logged in');
			return false;
	}

	$post_id = (int) $request->get_param('post_id'); // match ID
	$role    = $request->get_param('type');          // buyer | seller
	$user_id = get_current_user_id();
	$post_type = get_post_type($post_id);

	if ($post_type !== 'matches') {
			error_log('Invalid post type');
			return false;
	}

	$buyer = (int) get_field('buyer', $post_id);
	$related_offer_id = (int) get_field('relatedOffer', $post_id);
	$seller = $related_offer_id
			? (int) get_post_field('post_author', $related_offer_id)
			: 0;

	if ($role === 'buyer' && $buyer === $user_id) {
			error_log('Permission granted: buyer');
			return true;
	}

	if ($role === 'seller' && $seller === $user_id) {
			error_log('Permission granted: seller');
			return true;
	}

	error_log('Permission denied');

	return new WP_Error(
			'forbidden',
			'You are not allowed to upload this proof',
			['status' => 403]
	);
}

function payment_proof_upload_handler(WP_REST_Request $request) {
	$file = $request->get_file_params()['file'];
	$post_id = $request->get_param('post_id');
	$type = $request->get_param('type') ?? 'seller'; // seller | buyer

	$key = getenv('AWS_ACCESS_KEY_ID');
	$secret = getenv('AWS_SECRET_ACCESS_KEY');
	$region = getenv('AWS_REGION');
	$bucketName = getenv('AWS_BUCKET_NAME');

	$s3 = new S3Client([
		'version' => 'latest',
		'region'  => $region,
		'credentials' => [
				'key'    => $key,
				'secret' => $secret,
		],
	]);

	$file_name = $file['name'];
	$file_tmp = $file['tmp_name'];
	$ext = pathinfo($file_name, PATHINFO_EXTENSION);
	$uuid = wp_generate_uuid4();
	$s3_key = "payment-proofs/{$type}/{$uuid}.{$ext}";

	// Validate size and format
	$allowed = ['jpg', 'jpeg', 'png', 'pdf'];
	if (!in_array(strtolower($ext), $allowed)) {
			return new WP_Error('invalid_format', 'Formato de arquivo não permitido.', ['status' => 400]);
	}

	if ($file['size'] > 5 * 1024 * 1024) { // 5MB
			return new WP_Error('file_too_large', 'Arquivo muito grande. (máx 5MB)', ['status' => 400]);
	}

	try {
			$result = $s3->putObject([
					'Bucket' => $bucketName,
					'Key'    => $s3_key,
					'SourceFile' => $file_tmp,
					'ACL'    => 'private',
					'ContentType'=> $file['type']
			]);

			// Salvar no campo ACF correto
			$field_name = $type === 'seller' 
				? 'sellerPaymentProof' 
				: 'buyerPaymentProof';

			update_field($field_name, $s3_key, $post_id);

			$cmd = $s3->getCommand('GetObject', [
				'Bucket' => $bucketName,
				'Key' => $s3_key
			]);

			$presignedRequest = $s3->createPresignedRequest($cmd, '+7 days');
			$temporary_url = (string) $presignedRequest->getUri();

			return [
				'success' => true,
				'field' => $field_name,
				'key' => $s3_key,
				'temporary_url' => $temporary_url,
			];
	} catch (AwsException $e) {
			return new WP_Error('upload_failed', $e->getMessage(), ['status' => 500]);
	}
}