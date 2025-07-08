<?php
/**
 * Plugin Name: Kumbulink Mail Helper
 * Description: Plugin para envio de emails.
 * Version: 1.0
 */

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

function get_resend_client() {
	return Resend::client('re_3fcdHkX1_DHt2BaMezsQvGP2tx7KjrGyP');
}

add_action('transition_post_status', function ($new_status, $old_status, $post) {
  // Only trigger for classifieds posts that are being published for the first time
  if ($post->post_type === 'classifieds' && $new_status === 'publish' && $old_status !== 'publish') {
    error_log('🔍 Disparou transition_post_status para classifieds post ID: ' . $post->ID);
    $author = get_userdata($post->post_author);

    $client = get_resend_client();

    try {
      $client->emails->send([
        'from'    => 'Kumbulink <no-reply@kumbulink.com>',
        'to'      => [$author->user_email],
        'subject' => 'Seu anúncio foi publicado!',
        'html'    => file_get_contents(plugin_dir_path(__FILE__) . 'templates/new_offer.html'),
      ]);
    } catch (\Throwable $e) {
      error_log('Erro ao enviar email com Resend: ' . $e->getMessage());
    }
  }
}, 10, 3);

add_action('transition_post_status', function ($new_status, $old_status, $post) {
    // Only trigger for matches posts that are being published for the first time
    if ($post->post_type === 'matches' && $new_status === 'publish' && $old_status !== 'publish') {
        error_log('🔍 Disparou transition_post_status para matches post ID: ' . $post->ID);

        // Get the match post and its author
        $match_post = $post;
        $match_author = get_userdata($match_post->post_author);

        // Get the related offer
        $related_offer_id = get_field('relatedOffer', $post->ID);

        // Get the offer post and its author
        $offer_post = get_post($related_offer_id);
        $offer_author = get_userdata($offer_post->post_author);

        $client = get_resend_client();

        try {
            // Email to the match author
            $client->emails->send([
                'from'    => 'Kumbulink <no-reply@kumbulink.com>',
                'to'      => [$match_author->user_email],
                'subject' => 'Aceitaste uma proposta!',
                'html'    => file_get_contents(plugin_dir_path(__FILE__) . 'templates/new_match.html'),
            ]);

            // Email to the offer author
            $client->emails->send([
                'from'    => 'Kumbulink <no-reply@kumbulink.com>',
                'to'      => [$offer_author->user_email],
                'subject' => 'Anúncio aceite!',
                'html'    => file_get_contents(plugin_dir_path(__FILE__) . 'templates/accepted-offer.html'),
            ]);
        } catch (\Throwable $e) {
            error_log('Erro ao enviar email com Resend: ' . $e->getMessage());
        }
    }
}, 10, 3);

// Hook to ACF save for status changes on 'match' post type
add_action('acf/save_post', function($post_id) {
    // Only run for 'match' post type
    if (get_post_type($post_id) !== 'matches') {
        return;
    }

    // Get the new status value
    $new_status = get_field('status', $post_id);
    // Get the previous status value
    $old_status = get_post_meta($post_id, '_old_status', true);

    // Only proceed if status actually changed
    if ($new_status && $new_status !== $old_status) {
        $match_post = get_post($post_id);
        $match_author = get_userdata($match_post->post_author);
        
        // Get the related offer
        $related_offer_id = get_field('relatedOffer', $post_id);
        
        // Get the offer post and its author
        $offer_post = get_post($related_offer_id);
        $offer_author = get_userdata($offer_post->post_author);

        $client = get_resend_client();

        if ($new_status === 'canceled') {
            try {
                // Email to the match author
                $client->emails->send([
                    'from'    => 'Kumbulink <no-reply@kumbulink.com>',
                    'to'      => [$match_author->user_email],
                    'subject' => 'Seu match foi cancelado',
                    'html'    => file_get_contents(plugin_dir_path(__FILE__) . 'templates/payment-canceled.html'),
                ]);

                // Email to the offer author
                $client->emails->send([
                    'from'    => 'Kumbulink <no-reply@kumbulink.com>',
                    'to'      => [$offer_author->user_email],
                    'subject' => 'Seu anúncio foi cancelado',
                    'html'    => file_get_contents(plugin_dir_path(__FILE__) . 'templates/payment-canceled.html'),
                ]);
            } catch (\Throwable $e) {
                error_log('Erro ao enviar email de cancelamento: ' . $e->getMessage());
            }
        } elseif ($new_status === 'done') {
            try {
                // Email to the match author
                $client->emails->send([
                    'from'    => 'Kumbulink <no-reply@kumbulink.com>',
                    'to'      => [$match_author->user_email],
                    'subject' => 'Seu match foi concluído',
                    'html'    => file_get_contents(plugin_dir_path(__FILE__) . 'templates/done.html'),
                ]);

                // Email to the offer author
                $client->emails->send([
                    'from'    => 'Kumbulink <no-reply@kumbulink.com>',
                    'to'      => [$offer_author->user_email],
                    'subject' => 'Seu anúncio foi concluído',
                    'html'    => file_get_contents(plugin_dir_path(__FILE__) . 'templates/done.html'),
                ]);
            } catch (\Throwable $e) {
                error_log('Erro ao enviar email de conclusão: ' . $e->getMessage());
            }
        }
    }

    // Update the old status meta for next time
    if ($new_status) {
        update_post_meta($post_id, '_old_status', $new_status);
    }
}, 20);

// Send email when a new user is registered
add_action('user_register', function($user_id) {
    $user = get_userdata($user_id);
    $client = get_resend_client();

    try {
        $client->emails->send([
            'from'    => 'Kumbulink <no-reply@kumbulink.com>',
            'to'      => [$match_author->user_email],
            'subject' => 'Boas vindas à Kumbulink!',
            'html'    => file_get_contents(plugin_dir_path(__FILE__) . 'templates/new_user.html'),
        ]);
    } catch (\Throwable $e) {
        error_log('Erro ao enviar email de boas-vindas: ' . $e->getMessage());
    }
});

