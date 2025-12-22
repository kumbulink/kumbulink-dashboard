<?php

use Aws\S3\S3Client;

/**
 * Retorna uma instância singleton do S3Client
 */
function kumbulink_get_s3_client(): S3Client {
	static $client = null;

	if ($client) {
		return $client;
	}

	$client = new S3Client([
		'version' => 'latest',
		'region'  => getenv('AWS_REGION'),
		'credentials' => [
			'key'    => getenv('AWS_ACCESS_KEY_ID'),
			'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
		],
	]);

	return $client;
}

/**
 * Gera uma URL temporária para um objeto privado no S3
 */
function kumbulink_generate_presigned_url(string $s3_key, string $ttl = '+10 minutes'): string {
	$s3 = kumbulink_get_s3_client();

	$cmd = $s3->getCommand('GetObject', [
		'Bucket' => getenv('AWS_BUCKET_NAME'),
		'Key'    => $s3_key,
	]);

	$request = $s3->createPresignedRequest($cmd, $ttl);

	return (string) $request->getUri();
}
