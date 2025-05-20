<?php
/**
 * Plugin Name: Kumbulink Payment Proof Upload
 * Description: Plugin para upload seguro de comprovantes via S3.
 * Version: 1.0
 */

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

add_action('rest_api_init', function () {
	register_rest_route('custom/v1', '/payment-proof', [
		'methods'  => 'POST',
		'callback' => 'payment_proof_upload_handler',
		'permission_callback' => function () {
				return is_user_logged_in() && current_user_can('upload_files');
		},
	]);
});

function payment_proof_upload_handler(WP_REST_Request $request) {
	error_log('Current user ID: ' . get_current_user_id());
	error_log('Is logged in? ' . (is_user_logged_in() ? 'yes' : 'no'));

	$file = $request->get_file_params()['file'];
	$post_id = $request->get_param('post_id');
	$type = $request->get_param('type') ?? 'seller';

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

	// Verificação de tamanho e formato
	$allowed = ['jpg', 'jpeg', 'png', 'pdf'];
	if (!in_array(strtolower($ext), $allowed)) {
			return new WP_Error('invalid_format', 'Formato de arquivo não permitido.');
	}

	if ($file['size'] > 5 * 1024 * 1024) { // 5MB
			return new WP_Error('file_too_large', 'Arquivo muito grande.');
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
			$field_name = $type === 'seller' ? 'seller_payment_proof' : 'buyer_payment_proof';
			update_field($field_name, $s3_key, $post_id);

			$cmd = $s3->getCommand('GetObject', [
				'Bucket' => $bucketName,
				'Key' => $s3_key
			]);

			$presignedRequest = $s3->createPresignedRequest($cmd, '+5 minutes');
			$temporary_url = (string) $presignedRequest->getUri();

			return [
				'success' => true,
				'field' => $field_name,
				'key' => $s3_key,
				'temporary_url' => $temporary_url,
			];
	} catch (AwsException $e) {
			return new WP_Error('upload_failed', $e->getMessage());
	}
}


