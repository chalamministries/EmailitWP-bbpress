<?php
/*
Plugin Name: EmailIt BBPress Integration
Plugin URI: 
Description: BBPress integration for EmailIt Mailer
Version: 2.3.0
Author: Steven Gauerke
Requires at least: 5.8
Requires PHP: 7.4
License: GPL2
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('EmailIt_bbpress_Plugin_Updater')) {
	require_once plugin_dir_path(__FILE__) . 'class-emailit-updater.php';
}


class EmailItBBPress {
	private static $instance = null;
	/**
	 * Cached EmailIt client instance when available.
	 *
	 * @var \EmailIt\EmailItClient|null
	 */
	private $emailit_client = null;

	/**
	 * Tracks whether we've attempted to resolve the SDK client to avoid repeated work.
	 *
	 * @var bool
	 */
	private $emailit_client_checked = false;

	/**
	 * Stores account-level metadata discovered via DomainManager / ApiKeyManager accessors.
	 *
	 * @var array|null
	 */
	private $emailit_account_cache = null;
	
	public static function get_instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('plugins_loaded', [$this, 'init']);
		add_action('emailit_register_tabs', [$this, 'register_bbpress_tab']);
		add_action('emailit_bbpress_process_batch', [$this, 'handle_scheduled_batch'], 10, 1);
		
		if (is_admin()) {
		   new EmailIt_bbpress_Plugin_Updater(__FILE__, 'chalamministries', 'EmailitWP-bbpress');
	   }
	}
	
	public function register_bbpress_tab() {
		$emailit = EmailItMailer::get_instance();
		// Register before docs (100) but after settings (10)
		$emailit->register_tab('bbpress', 'BBPress', [$this, 'render_bbpress_tab'], 90);
	}

	public function init() {
		// Check if required plugins are active
		if (!$this->check_dependencies()) {
			return;
		}

		// Remove bbPress's default notification functions
		remove_action('bbp_new_reply', 'bbp_notify_topic_subscribers', 11);
		remove_action('bbp_new_topic', 'bbp_notify_forum_subscribers', 11);
		
		// Add our custom notification handlers
		add_action('bbp_new_reply', [$this, 'custom_bbp_notify_topic_subscribers'], 11, 5);
		add_action('bbp_new_topic', [$this, 'custom_bbp_notify_forum_subscribers'], 11, 3);
	}

	private function check_dependencies() {
		if (!function_exists('is_plugin_active')) {
			include_once(ABSPATH . 'wp-admin/includes/plugin.php');
		}

		$missing_plugins = [];

		if (!is_plugin_active('bbpress/bbpress.php')) {
			$missing_plugins[] = 'BBPress';
		}

		if (!is_plugin_active('EmailitWP/emailit_mailer.php')) {
			$missing_plugins[] = 'EmailIt Mailer';
		}

		if (!empty($missing_plugins)) {
			add_action('admin_notices', function() use ($missing_plugins) {
				$message = 'EmailIt BBPress Integration requires the following plugins: ' . implode(', ', $missing_plugins);
				echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
			});
			return false;
		}

		return true;
	}
	
	public function render_bbpress_tab() {
		?>
		<div class="card" style="max-width: 800px; margin-top: 20px;">
			<h2>BBPress Integration Settings</h2>
			<!-- Your BBPress settings content here -->
			Custom Email Template Coming Soon!
		</div>
		<?php
	}
	
	public function custom_bbp_notify_forum_subscribers($topic_id = 0, $forum_id = 0, $anonymous_data = false) {
		// Get subscriber IDs for the forum
		$user_ids = bbp_get_forum_subscribers($forum_id, true);
		
		if (empty($user_ids)) {
			return false;
		}
		
		if(WP_DEBUG) {
			error_log("BB Press notify Forum Users for new topic");
			error_log(print_r($user_ids, true));
		}
		
		// Get topic author ID
		$topic_author = bbp_get_topic_author_id($topic_id);
		
		$recipients = [];
		foreach ($user_ids as $user_id) {
			// Don't notify the topic author
			if ($user_id != $topic_author) {
				$user = get_userdata($user_id);
				if (!empty($user->user_email)) {
					$recipients[] = $user->user_email;
				}
			}
		}
		
		if (empty($recipients)) {
			return false;
		}
		
		// Get topic details
		$topic_author_name = bbp_get_topic_author_display_name($topic_id);
		$topic_title = bbp_get_topic_title($topic_id);
		$topic_url = bbp_get_topic_permalink($topic_id);
		$forum_title = bbp_get_forum_title($forum_id);
		
		$custom_logo_id = get_theme_mod('custom_logo');
		$logo_url = '';
		if ($custom_logo_id) {
			$logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
		}
		
		// Build HTML email
		$html_message = $this->get_new_topic_email_template([
			'logo_url' => $logo_url,
			'forum_title' => $forum_title,
			'topic_title' => $topic_title,
			'topic_author_name' => $topic_author_name,
			'topic_content' => bbp_get_topic_content($topic_id),
			'topic_url' => $topic_url
		]);
	
		// Create plain text version
		$text_message = $this->get_new_topic_text_email_content([
			'topic_author_name' => $topic_author_name,
			'topic_content' => bbp_get_topic_content($topic_id),
			'forum_title' => $forum_title,
			'topic_title' => $topic_title,
			'topic_url' => $topic_url
		]);
		
		// Batch size - how many emails to process in each cron job
		$batch_size = 50;
		
		// Split recipients into batches and schedule a job for each batch
		$batches = array_chunk($recipients, $batch_size);
		
		foreach ($batches as $index => $batch) {
			if (WP_DEBUG) {
				error_log('Processing new topic notification batch');
				error_log(json_encode($batch));
			}

			$payload = [
				'batch' => $batch,
				'subject' => '[' . wp_specialchars_decode($forum_title, ENT_QUOTES) . '] New Topic: ' . $topic_title,
				'message' => $html_message,
				'headers' => [
					'Content-Type: text/html; charset=UTF-8',
					'X-EmailIt-Source: BBPress',
					'X-bbPress: ' . bbp_get_version()
				],
				'text_message' => $text_message,
				'metadata' => [
					'forum_id' => $forum_id,
					'topic_id' => $topic_id,
					'trigger' => 'bbpress_new_topic',
				],
				'tags' => ['bbpress', 'bbpress-new-topic'],
				'context' => [
					'batch_index' => $index,
					'forum_id' => $forum_id,
					'topic_id' => $topic_id,
				],
				'bcc' => $this->get_bcc_addresses('new_topic', [
					'forum_id' => $forum_id,
					'topic_id' => $topic_id,
				]),
				'cc' => $this->get_cc_addresses('new_topic', [
					'forum_id' => $forum_id,
					'topic_id' => $topic_id,
				]),
			];

			$this->schedule_email_batch(time() + ($index * $this->get_schedule_interval()), $payload);
		}
		
		return true;
	}
	
	private function get_new_topic_email_template($data) {
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<style>
				body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
				.container { max-width: 600px; margin: 20px auto; padding: 20px; }
				.header img { max-height: 60px; width: auto; }
				.header { border-bottom: 2px solid #15c182; padding-bottom: 10px; margin-bottom: 20px; }
				<?php echo empty($data['logo_url']) ? '.header { text-align: center; font-size: 24px; font-weight: bold; }' : ''; ?>
				.forum-title { color: #666; font-size: 14px; margin-bottom: 10px; }
				.topic-title { font-size: 20px; font-weight: bold; margin-bottom: 20px; color: #333; }
				.topic-content { background: #f9f9f9; padding: 20px; border-left: 4px solid #15c182; margin-bottom: 20px; }
				.author { color: #15c182; font-weight: bold; margin-bottom: 10px; }
				.footer { border-top: 1px solid #eee; margin-top: 20px; padding-top: 20px; font-size: 12px; color: #666; }
				.button { display: inline-block; padding: 10px 20px; background-color: #15c182; color: white; text-decoration: none; border-radius: 5px; margin-top: 15px; }
			</style>
		</head>
		<body>
			<div class="container">
				<div class="header">
					<?php if (!empty($data['logo_url'])): ?>
						<img src="<?php echo esc_url($data['logo_url']); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" style="height: 60px;">
					<?php else: ?>
						<?php echo esc_html(get_bloginfo('name')); ?>
					<?php endif; ?>
				</div>
				<div class="forum-title">
					Forum: <?php echo esc_html($data['forum_title']); ?>
				</div>
				<div class="topic-title">
					New Topic: <?php echo esc_html($data['topic_title']); ?>
				</div>
				<div class="topic-content">
					<div class="author"><?php echo esc_html($data['topic_author_name']); ?> posted:</div>
					<?php echo wpautop(stripslashes($data['topic_content'])); ?>
				</div>
				<a href="<?php echo esc_url($data['topic_url']); ?>" class="button">View Topic</a>
				<div class="footer">
					<p>You are receiving this email because you subscribed to this forum.</p>
					<p>To unsubscribe from these notifications, visit the forum and click "Unsubscribe".</p>
				</div>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}
	
	private function get_new_topic_text_email_content($data) {
		$text = sprintf("New Topic in %s\n\n", $data['forum_title']);
		$text .= sprintf("%s posted:\n\n", $data['topic_author_name']);
		$text .= sprintf("Topic: %s\n\n", $data['topic_title']);
		$text .= sprintf("%s\n\n", wp_strip_all_tags($data['topic_content']));
		$text .= sprintf("Topic Link: %s\n\n", $data['topic_url']);
		$text .= "-----------\n\n";
		$text .= "You are receiving this email because you subscribed to this forum.\n";
		$text .= "Login and visit the forum to unsubscribe from these emails.";
		
		return $text;
	}

	public function custom_bbp_notify_topic_subscribers($reply_id = 0, $topic_id = 0, $forum_id = 0, $anonymous_data = false, $reply_author = 0) {
		// Get subscriber IDs
		$user_ids = bbp_get_topic_subscribers($topic_id, true);
		
		if (empty($user_ids)) {
			return false;
		}
		
		if(WP_DEBUG) {
			error_log("BB Press notify Users");
			error_log(print_r($user_ids, true));
		}
		
		// Get reply author ID if not provided
		if (!$reply_author) {
			$reply_author = bbp_get_reply_author_id($reply_id);
		}
		
		$recipients = [];
		foreach ($user_ids as $user_id) {
			if ($user_id != $reply_author) {
				$user = get_userdata($user_id);
				if (!empty($user->user_email)) {
					$recipients[] = $user->user_email;
				}
			}
		}
		
		if (empty($recipients)) {
			return false;
		}
		
		// Get reply details
		$reply_author_name = bbp_get_reply_author_display_name($reply_id);
		$topic_title = bbp_get_topic_title($topic_id);
		$reply_url = bbp_get_reply_url($reply_id);
		$forum_id = bbp_get_topic_forum_id($topic_id);
		$forum_title = bbp_get_forum_title($forum_id);
		
		$custom_logo_id = get_theme_mod('custom_logo');
		$logo_url = '';
		if ($custom_logo_id) {
			$logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
		}
		
		// Build HTML email
		$html_message = $this->get_email_template([
			'logo_url' => $logo_url,
			'forum_title' => $forum_title,
			'topic_title' => $topic_title,
			'reply_author_name' => $reply_author_name,
			'reply_content' => bbp_get_reply_content($reply_id),
			'reply_url' => $reply_url
		]);
	
		// Create plain text version
		$text_message = $this->get_text_email_content([
			'reply_author_name' => $reply_author_name,
			'reply_content' => bbp_get_reply_content($reply_id),
			'forum_title' => $forum_title,
			'topic_title' => $topic_title,
			'reply_url' => $reply_url
		]);
		
		// Batch size - how many emails to process in each cron job
		$batch_size = 10;
		
		// Split recipients into batches and schedule a job for each batch
		$batches = array_chunk($recipients, $batch_size);
		
		foreach ($batches as $index => $batch) {
			if (WP_DEBUG) {
				error_log("Here's the batch");
				error_log(json_encode($batch));
			}

			$payload = [
				'batch' => $batch,
				'subject' => '[' . wp_specialchars_decode($forum_title, ENT_QUOTES) . '] ' . $topic_title,
				'message' => $html_message,
				'headers' => [
					'Content-Type: text/html; charset=UTF-8',
					'X-bbPress: ' . bbp_get_version(),
					'From: ' . get_bloginfo('name') . ' <noreply@' . parse_url(home_url(), PHP_URL_HOST) . '>'
				],
				'text_message' => $text_message,
				'metadata' => [
					'forum_id' => $forum_id,
					'topic_id' => $topic_id,
					'reply_id' => $reply_id,
					'trigger' => 'bbpress_new_reply',
				],
				'tags' => ['bbpress', 'bbpress-new-reply'],
				'context' => [
					'batch_index' => $index,
					'forum_id' => $forum_id,
					'topic_id' => $topic_id,
					'reply_id' => $reply_id,
				],
				'bcc' => $this->get_bcc_addresses('new_reply', [
					'forum_id' => $forum_id,
					'topic_id' => $topic_id,
					'reply_id' => $reply_id,
				]),
				'cc' => $this->get_cc_addresses('new_reply', [
					'forum_id' => $forum_id,
					'topic_id' => $topic_id,
					'reply_id' => $reply_id,
				]),
			];

			$this->schedule_email_batch(time() + ($index * $this->get_schedule_interval()), $payload);
		}
		
		return true;
	}

	private function get_email_template($data) {
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<style>
				body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
				.container { max-width: 600px; margin: 20px auto; padding: 20px; }
				.header img { max-height: 60px; width: auto; }
				.header { border-bottom: 2px solid #15c182; padding-bottom: 10px; margin-bottom: 20px; }
				<?php echo empty($data['logo_url']) ? '.header { text-align: center; font-size: 24px; font-weight: bold; }' : ''; ?>
				.forum-title { color: #666; font-size: 14px; margin-bottom: 10px; }
				.topic-title { font-size: 20px; font-weight: bold; margin-bottom: 20px; color: #333; }
				.reply-content { background: #f9f9f9; padding: 20px; border-left: 4px solid #15c182; margin-bottom: 20px; }
				.author { color: #15c182; font-weight: bold; margin-bottom: 10px; }
				.footer { border-top: 1px solid #eee; margin-top: 20px; padding-top: 20px; font-size: 12px; color: #666; }
				.button { display: inline-block; padding: 10px 20px; background-color: #15c182; color: white; text-decoration: none; border-radius: 5px; margin-top: 15px; }
			</style>
		</head>
		<body>
			<div class="container">
				<div class="header">
					<?php if (!empty($data['logo_url'])): ?>
						<img src="<?php echo esc_url($data['logo_url']); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" style="height: 60px;">
					<?php else: ?>
						<?php echo esc_html(get_bloginfo('name')); ?>
					<?php endif; ?>
				</div>
				<div class="forum-title">
					Forum: <?php echo esc_html($data['forum_title']); ?>
				</div>
				<div class="topic-title">
					<?php echo esc_html($data['topic_title']); ?>
				</div>
				<div class="reply-content">
					<div class="author"><?php echo esc_html($data['reply_author_name']); ?> wrote:</div>
					<?php echo wpautop(stripslashes($data['reply_content'])); ?>
				</div>
				<a href="<?php echo esc_url($data['reply_url']); ?>" class="button">View Post</a>
				<div class="footer">
					<p>You are receiving this email because you subscribed to this forum topic.</p>
					<p>To unsubscribe from these notifications, visit the topic and click "Unsubscribe".</p>
				</div>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	private function get_text_email_content($data) {
		$text = sprintf("%s wrote:\n\n", $data['reply_author_name']);
		$text .= sprintf("%s\n\n", wp_strip_all_tags($data['reply_content']));
		$text .= sprintf("Forum: %s\n", $data['forum_title']);
		$text .= sprintf("Topic: %s\n", $data['topic_title']);
		$text .= sprintf("Post Link: %s\n\n", $data['reply_url']);
		$text .= "-----------\n\n";
		$text .= "You are receiving this email because you subscribed to this forum topic.\n";
		$text .= "Login and visit the topic to unsubscribe from these emails.";
		
		return $text;
	}
	
	private function get_schedule_interval() {
		return defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
	}
	
	private function schedule_email_batch($timestamp, array $payload) {
		if (!isset($payload['context']) || !is_array($payload['context'])) {
			$payload['context'] = [];
		}

		if (!isset($payload['context']['job_id'])) {
			if (function_exists('wp_generate_uuid4')) {
				$payload['context']['job_id'] = wp_generate_uuid4();
			} else {
				$payload['context']['job_id'] = uniqid('emailit_bbpress_', true);
			}
		}

		wp_schedule_single_event($timestamp, 'emailit_bbpress_process_batch', [$payload]);
	}
	
	public function handle_scheduled_batch($payload) {
		if (empty($payload) || !is_array($payload)) {
			return;
		}

		$normalized = $this->normalize_batch_payload($payload);

		if ($this->send_batch_via_emailit($normalized)) {
			return;
		}

		$this->dispatch_legacy_batch($normalized);
	}
	
	private function normalize_batch_payload(array $payload) {
		$payload['batch'] = $this->clean_email_list($payload['batch'] ?? []);
		$payload['cc'] = $this->clean_email_list($payload['cc'] ?? []);
		$payload['bcc'] = $this->clean_email_list($payload['bcc'] ?? []);

		$payload['cc'] = array_values(array_diff($payload['cc'], $payload['batch']));
		$payload['bcc'] = array_values(array_diff($payload['bcc'], array_merge($payload['batch'], $payload['cc'])));

		if (!isset($payload['headers'])) {
			$payload['headers'] = [];
		} elseif (is_string($payload['headers'])) {
			$payload['headers'] = preg_split("/\r?\n/", $payload['headers'], -1, PREG_SPLIT_NO_EMPTY);
		} elseif (is_array($payload['headers'])) {
			$payload['headers'] = array_map('strval', $payload['headers']);
		} else {
			$payload['headers'] = [];
		}

		if (!isset($payload['metadata']) || !is_array($payload['metadata'])) {
			$payload['metadata'] = [];
		}

		if (!isset($payload['tags']) || !is_array($payload['tags'])) {
			$payload['tags'] = [];
		}

		if (!isset($payload['context']) || !is_array($payload['context'])) {
			$payload['context'] = [];
		}

		return apply_filters('emailit_bbpress_normalize_payload', $payload);
	}
	
	private function clean_email_list($emails) {
		if (empty($emails)) {
			return [];
		}

		if (!is_array($emails)) {
			if (is_string($emails)) {
				$emails = preg_split('/[,;\s]+/', $emails, -1, PREG_SPLIT_NO_EMPTY);
			} else {
				$emails = (array) $emails;
			}
		}

		$normalized = [];
		foreach ($emails as $email) {
			$sanitized = sanitize_email(trim((string) $email));
			if (!empty($sanitized)) {
				$normalized[] = $sanitized;
			}
		}

		return array_values(array_unique($normalized));
	}
	
	private function get_bcc_addresses($scenario, array $context = []) {
		$bcc = apply_filters('emailit_bbpress_bcc_addresses', [], $scenario, $context);
		return $this->clean_email_list($bcc);
	}
	
	private function get_cc_addresses($scenario, array $context = []) {
		$cc = apply_filters('emailit_bbpress_cc_addresses', [], $scenario, $context);
		return $this->clean_email_list($cc);
	}
	
	private function prime_emailit_account_cache($client) {
		if ($this->emailit_account_cache !== null) {
			return;
		}

		$cache = [
			'domains_available' => method_exists($client, 'domains'),
			'api_keys_available' => method_exists($client, 'apiKeys'),
		];

		if ($cache['domains_available']) {
			try {
				$domain_manager = $client->domains();
				if (is_object($domain_manager)) {
					$domains = null;
					if (method_exists($domain_manager, 'list')) {
						$domains = $domain_manager->list(['limit' => 1, 'verified' => true]);
					} elseif (method_exists($domain_manager, 'all')) {
						$domains = $domain_manager->all();
					}
					$first_domain = $this->extract_first_entry($domains);
					if ($first_domain !== null) {
						if (is_array($first_domain)) {
							$status = isset($first_domain['status']) ? strtolower((string) $first_domain['status']) : null;
							$cache['has_verified_domain'] = !empty($first_domain['verified']) || in_array($status, ['active', 'verified'], true);
						} else {
							$cache['has_verified_domain'] = true;
						}
					}
				}
			} catch (\Throwable $e) {
				error_log('EmailIt BBPress: domain manager probe failed - ' . $e->getMessage());
			}
		}

		if ($cache['api_keys_available']) {
			try {
				$api_key_manager = $client->apiKeys();
				if (is_object($api_key_manager)) {
					$current_key = null;
					if (method_exists($api_key_manager, 'current')) {
						$current_key = $api_key_manager->current();
					} elseif (method_exists($api_key_manager, 'list')) {
						$current_key = $this->extract_first_entry($api_key_manager->list(['limit' => 1]));
					}
					if (is_array($current_key)) {
						$cache['api_key_label'] = $current_key['label'] ?? ($current_key['name'] ?? null);
					}
				}
			} catch (\Throwable $e) {
				error_log('EmailIt BBPress: api key manager probe failed - ' . $e->getMessage());
			}
		}

		$this->emailit_account_cache = apply_filters('emailit_bbpress_account_cache', $cache, $client);
	}
	
	private function build_v2_email_payload(array $payload) {
		$headers = isset($payload['headers']) && is_array($payload['headers']) ? $payload['headers'] : [];
		$from = $this->determine_from_address($headers);
		$reply_to = $this->determine_reply_to($headers, $from);
		$metadata = $payload['metadata'];

		if (is_array($this->emailit_account_cache)) {
			$metadata['emailit_account'] = $this->emailit_account_cache;
		}

		return [
			'from' => $from,
			'reply_to' => $reply_to,
			'to' => $this->transform_recipients($payload['batch']),
			'cc' => $this->transform_recipients($payload['cc']),
			'bcc' => $this->transform_recipients($payload['bcc']),
			'subject' => $payload['subject'] ?? '',
			'html' => $payload['message'] ?? '',
			'text' => $payload['text_message'] ?? '',
			'headers' => $headers,
			'metadata' => $metadata,
			'tags' => array_values(array_unique(array_merge(['bbpress'], $payload['tags']))),
			'context' => $payload['context'],
		];
	}
	
	private function determine_from_address(array $headers) {
		$default_email = 'noreply@' . parse_url(home_url(), PHP_URL_HOST);
		$default_name = get_bloginfo('name');
		$from = [
			'email' => $default_email,
			'name' => $default_name,
		];

		foreach ($headers as $header) {
			if (stripos($header, 'from:') === 0) {
				$value = trim(substr($header, 5));
				if (preg_match('/(.*)<([^>]+)>/', $value, $matches)) {
					$from['name'] = trim(trim($matches[1]), "\"' ");
					$from['email'] = sanitize_email($matches[2]);
				} else {
					$from['email'] = sanitize_email($value);
				}
				break;
			}
		}

		if (empty($from['name'])) {
			$from['name'] = $default_name;
		}

		if (empty($from['email'])) {
			$from['email'] = $default_email;
		}

		return $from;
	}
	
	private function determine_reply_to(array $headers, array $fallback) {
		foreach ($headers as $header) {
			if (stripos($header, 'reply-to:') === 0) {
				$value = trim(substr($header, 9));
				if (preg_match('/(.*)<([^>]+)>/', $value, $matches)) {
					return [
						'email' => sanitize_email($matches[2]),
						'name' => trim(trim($matches[1]), "\"' "),
					];
				}

				return [
					'email' => sanitize_email($value),
					'name' => $fallback['name'] ?? '',
				];
			}
		}

		return $fallback;
	}
	
	private function transform_recipients(array $emails) {
		$recipients = [];
		foreach ($emails as $email) {
			$recipients[] = ['email' => $email];
		}

		return $recipients;
	}
	
	private function extract_first_entry($collection) {
		if (empty($collection)) {
			return null;
		}

		if (is_array($collection)) {
			if (isset($collection['data']) && is_array($collection['data'])) {
				return $collection['data'][0] ?? null;
			}

			$first = reset($collection);
			return $first === false ? null : $first;
		}

		if ($collection instanceof \Traversable) {
			foreach ($collection as $item) {
				return $item;
			}
		}

		return null;
	}
	
	private function resolve_emailit_client() {
		if ($this->emailit_client_checked) {
			return $this->emailit_client;
		}

		$this->emailit_client_checked = true;

		if (!class_exists(\EmailIt\EmailItClient::class)) {
			return null;
		}

		$client = null;

		if (class_exists('EmailItMailer')) {
			$mailer = EmailItMailer::get_instance();
			$methods = ['get_client', 'client', 'getApiClient', 'api_client', 'get_emailit_client'];

			foreach ($methods as $method) {
				if (method_exists($mailer, $method)) {
					try {
						$candidate = $mailer->{$method}();
						if ($candidate instanceof \EmailIt\EmailItClient) {
							$client = $candidate;
							break;
						}
					} catch (\Throwable $e) {
						error_log('EmailIt BBPress: unable to fetch EmailIt client via ' . $method . ' - ' . $e->getMessage());
					}
				}
			}
		}

		if (!$client instanceof \EmailIt\EmailItClient) {
			$filtered = apply_filters('emailit_bbpress_email_client', null);
			if ($filtered instanceof \EmailIt\EmailItClient) {
				$client = $filtered;
			}
		}

		if (!$client instanceof \EmailIt\EmailItClient) {
			$api_key = defined('EMAILIT_API_KEY') ? EMAILIT_API_KEY : '';
			if (!$api_key) {
				$api_key = get_option('emailit_api_key');
			}

			if ($api_key) {
				try {
					if (method_exists(\EmailIt\EmailItClient::class, 'fromApiKey')) {
						$client = \EmailIt\EmailItClient::fromApiKey($api_key);
					} else {
						$client = new \EmailIt\EmailItClient($api_key);
					}
				} catch (\Throwable $e) {
					error_log('EmailIt BBPress: unable to instantiate EmailItClient - ' . $e->getMessage());
					$client = null;
				}
			}
		}

		if (!$client instanceof \EmailIt\EmailItClient) {
			$client = null;
		}

		$this->emailit_client = $client;

		return $client;
	}
	
	private function send_batch_via_emailit(array $payload) {
		$client = $this->resolve_emailit_client();

		if (!$client instanceof \EmailIt\EmailItClient) {
			return false;
		}

		$this->prime_emailit_account_cache($client);

		$message = $this->build_v2_email_payload($payload);
		$message = apply_filters('emailit_bbpress_sdk_payload', $message, $payload);

		if (empty($message['to'])) {
			return true;
		}

		try {
			if (method_exists($client, 'sendEmail')) {
				$client->sendEmail($message);
				return true;
			}

			if (class_exists(\EmailIt\EmailBuilder::class)) {
				$builder = $this->create_email_builder($client, $message);
				if ($builder && method_exists($builder, 'send')) {
					$builder->send();
					return true;
				}
			}
		} catch (\Throwable $e) {
			error_log('EmailIt BBPress: EmailIt SDK send failed - ' . $e->getMessage());
		}

		return false;
	}
	
	private function create_email_builder($client, array $message) {
		try {
			if (!class_exists(\EmailIt\EmailBuilder::class)) {
				return null;
			}

			if (method_exists(\EmailIt\EmailBuilder::class, 'forClient')) {
				$builder = \EmailIt\EmailBuilder::forClient($client);
			} elseif (method_exists(\EmailIt\EmailBuilder::class, 'make')) {
				$builder = \EmailIt\EmailBuilder::make($client);
			} else {
				$builder = new \EmailIt\EmailBuilder($client);
			}

			if (method_exists($builder, 'from')) {
				$from = $message['from'];
				$builder = $builder->from($from['email'], $from['name'] ?? null);
			}

			if (method_exists($builder, 'to') && !empty($message['to'])) {
				$builder = $builder->to(array_column($message['to'], 'email'));
			}

			if (method_exists($builder, 'cc') && !empty($message['cc'])) {
				$builder = $builder->cc(array_column($message['cc'], 'email'));
			}

			if (method_exists($builder, 'bcc') && !empty($message['bcc'])) {
				$builder = $builder->bcc(array_column($message['bcc'], 'email'));
			}

			if (method_exists($builder, 'subject')) {
				$builder = $builder->subject($message['subject'] ?? '');
			}

			if (method_exists($builder, 'html') && !empty($message['html'])) {
				$builder = $builder->html($message['html']);
			}

			if (method_exists($builder, 'text') && !empty($message['text'])) {
				$builder = $builder->text($message['text']);
			}

			if (method_exists($builder, 'replyTo') && !empty($message['reply_to'])) {
				$reply_to = $message['reply_to'];
				$builder = $builder->replyTo($reply_to['email'], $reply_to['name'] ?? null);
			}

			if (method_exists($builder, 'headers') && !empty($message['headers'])) {
				$builder = $builder->headers($message['headers']);
			}

			if (method_exists($builder, 'metadata') && !empty($message['metadata'])) {
				$builder = $builder->metadata($message['metadata']);
			}

			if (method_exists($builder, 'tags') && !empty($message['tags'])) {
				$builder = $builder->tags($message['tags']);
			}

			if (method_exists($builder, 'context') && !empty($message['context'])) {
				$builder = $builder->context($message['context']);
			}

			return $builder;
		} catch (\Throwable $e) {
			error_log('EmailIt BBPress: unable to build email with EmailBuilder - ' . $e->getMessage());
			return null;
		}
	}
	
	private function dispatch_legacy_batch(array $payload) {
		if (has_action('emailit_process_email_batch')) {
			do_action('emailit_process_email_batch', $payload);
			return;
		}

		if (function_exists('emailit_process_email_batch')) {
			emailit_process_email_batch($payload);
			return;
		}

		$headers = isset($payload['headers']) ? $payload['headers'] : [];
		$subject = $payload['subject'] ?? '';
		$message = $payload['message'] ?? '';
		$text_message = $payload['text_message'] ?? '';

		foreach ($payload['batch'] as $recipient) {
			$headers_with_text = $headers;
			if (!empty($text_message)) {
				$headers_with_text[] = 'X-EmailIt-Text-Body: ' . base64_encode($text_message);
			}
			wp_mail($recipient, $subject, $message, $headers_with_text);
		}
	}
	
}

// Initialize the plugin
EmailItBBPress::get_instance();