<?php
/**
 * Plugin Name: Kumbulink Backend Helper
 * Description: Restringe o frontend e adiciona suporte a registro de usuários via API.
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

