<?php

if (!defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/env.php';

function kumbulink_set_jwt_cookie(string $token, int $expiration): void {
  $is_https     = kumbulink_is_https();
  $is_localhost = kumbulink_is_localhost();

  $cookie_args = [
      'expires'  => $expiration,
      'path'     => '/',
      'secure'   => $is_https,
      'httponly' => true,
      'samesite' => $is_https ? 'Strict' : 'Lax',
  ];

  if (!$is_localhost) {
      $cookie_args['domain'] = parse_url(
        'https://' . $_SERVER['HTTP_HOST'], 
        PHP_URL_HOST
      );
  }

  setcookie('jwt_token', $token, $cookie_args);
}

