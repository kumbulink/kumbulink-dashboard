<?php
/**
 * Plugin Name: Kumbulink Payment Proof Upload
 * Description: Plugin para upload seguro de comprovantes via S3.
 * Version: 1.0
 */


 defined('ABSPATH') || exit;

 require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
 
 require_once __DIR__ . '/includes/s3.php';
 require_once __DIR__ . '/includes/upload.php';
 require_once __DIR__ . '/includes/view.php';
