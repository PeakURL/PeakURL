<?php
/**
 * PeakURL transactional email notifications.
 *
 * Builds app-specific email subjects and bodies, then dispatches them
 * through the shared PeakURL mail helper.
 *
 * @package PeakURL\Services
 * @since 1.0.2
 */

declare(strict_types=1);

namespace PeakURL\Services;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Transactional notification email service.
 *
 * @since 1.0.2
 */
class Notifications {

	/**
	 * Create a new transactional notification service.
	 *
	 * @since 1.0.2
	 */
	public function __construct() {}

	/**
	 * Send a password-reset email.
	 *
	 * @param array<string, mixed> $user  User database row.
	 * @param string               $token Password-reset token.
	 * @return void
	 *
	 * @throws \RuntimeException When email delivery fails.
	 * @since 1.0.2
	 */
	public function send_password_reset_email( array $user, string $token ): void {
		$email = strtolower( trim( (string) ( $user['email'] ?? '' ) ) );

		if ( '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not send the password reset email because the account email address is invalid.', 'peakurl' ),
			);
		}

		$site_name    = \get_site_name();
		$reset_url    = \get_site_url(
			'reset-password/' . rawurlencode( trim( $token ) ),
		);
		$display_name = \get_user_display_name( $user );
		$subject      = (string) \apply_filters(
			'peakurl_password_reset_email_subject',
			sprintf(
				/* translators: %s: site name. */
				__( 'Reset your %s password', 'peakurl' ),
				$site_name,
			),
			$user,
			$token,
			$site_name,
		);
		$context = $this->filter_template_context(
			'peakurl_password_reset_email_context',
			array(
				'recipient' => $display_name,
				'site_name' => $site_name,
				'reset_url' => $reset_url,
			),
			$user,
			$token,
		);
		$bodies  = $this->render_template_pair(
			'password-reset',
			$context,
		);

		\PeakURL_Mail(
			$email,
			$subject,
			$bodies['html'],
			array(
				'to_name'   => $display_name,
				'text_body' => $bodies['text'],
				'html'      => true,
			),
		);

		\do_action(
			'peakurl_password_reset_email_sent',
			$user,
			$email,
			$display_name,
			$subject,
		);
	}

	/**
	 * Send a password-changed security notification.
	 *
	 * @param array<string, mixed> $user User database row.
	 * @return void
	 *
	 * @throws \RuntimeException When email delivery fails.
	 * @since 1.0.6
	 */
	public function send_password_changed_email( array $user ): void {
		$email = strtolower( trim( (string) ( $user['email'] ?? '' ) ) );

		if ( '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not send the password changed email because the account email address is invalid.', 'peakurl' ),
			);
		}

		$site_name     = \get_site_name();
		$site_url      = \get_site_url();
		$login_url     = \get_site_url( 'login' );
		$dashboard_url = \get_site_url( 'dashboard/settings?tab=security' );
		$support_url   = 'https://peakurl.org/contact';
		$display_name  = \get_user_display_name( $user );
		$changed_at    = gmdate( DATE_ATOM );
		$subject       = (string) \apply_filters(
			'peakurl_password_changed_email_subject',
			sprintf(
				/* translators: %s: site name. */
				__( 'Your %s password was changed', 'peakurl' ),
				$site_name,
			),
			$user,
			$site_name,
			$site_url,
		);
		$context = $this->filter_template_context(
			'peakurl_password_changed_email_context',
			array(
				'recipient'     => $display_name,
				'site_name'     => $site_name,
				'site_url'      => $site_url,
				'login_url'     => $login_url,
				'dashboard_url' => $dashboard_url,
				'support_url'   => $support_url,
				'changed_at'    => $changed_at,
			),
			$user,
			$site_name,
			$site_url,
			$changed_at,
		);
		$bodies  = $this->render_template_pair(
			'password-changed',
			$context,
		);

		\PeakURL_Mail(
			$email,
			$subject,
			$bodies['html'],
			array(
				'to_name'   => $display_name,
				'text_body' => $bodies['text'],
				'html'      => true,
			),
		);

		\do_action(
			'peakurl_password_changed_email_sent',
			$user,
			$email,
			$display_name,
			$subject,
		);
	}

	/**
	 * Send the install welcome email to the first admin user.
	 *
	 * @param array<string, mixed> $user User database row.
	 * @return void
	 *
	 * @throws \RuntimeException When email delivery fails.
	 * @since 1.0.2
	 */
	public function send_install_welcome_email( array $user ): void {
		$email = strtolower( trim( (string) ( $user['email'] ?? '' ) ) );

		if ( '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new \RuntimeException(
				__( 'PeakURL could not send the welcome email because the account email address is invalid.', 'peakurl' ),
			);
		}

		$site_name     = \get_site_name();
		$site_url      = \get_site_url();
		$login_url     = \get_site_url( 'login' );
		$dashboard_url = \get_site_url( 'dashboard' );
		$docs_url      = 'https://peakurl.org/docs/getting-started';
		$api_docs_url  = 'https://peakurl.org/docs/api';
		$support_url   = 'https://peakurl.org/contact';
		$display_name  = \get_user_display_name( $user );
		$username      = trim( (string) ( $user['username'] ?? '' ) );
		$subject       = (string) \apply_filters(
			'peakurl_install_welcome_email_subject',
			sprintf(
				/* translators: %s: site name. */
				__( 'Welcome to %s', 'peakurl' ),
				$site_name,
			),
			$user,
			$site_name,
			$site_url,
		);
		$context = $this->filter_template_context(
			'peakurl_install_welcome_email_context',
			array(
				'recipient'     => $display_name,
				'site_url'      => $site_url,
				'dashboard_url' => $dashboard_url,
				'username'      => $username,
				'login_url'     => $login_url,
				'docs_url'      => $docs_url,
				'api_docs_url'  => $api_docs_url,
				'support_url'   => $support_url,
			),
			$user,
		);
		$bodies  = $this->render_template_pair(
			'install-welcome',
			$context,
		);

		\PeakURL_Mail(
			$email,
			$subject,
			$bodies['html'],
			array(
				'to_name'   => $display_name,
				'text_body' => $bodies['text'],
				'html'      => true,
			),
		);

		\do_action(
			'peakurl_install_welcome_email_sent',
			$user,
			$email,
			$display_name,
			$subject,
		);
	}

	/**
	 * Render the HTML and text versions of a named email template.
	 *
	 * @param string               $template_name Template base filename.
	 * @param array<string, mixed> $context       Placeholder values.
	 * @return array{html: string, text: string}
	 *
	 * @throws \RuntimeException When a required template file is missing.
	 * @since 1.0.2
	 */
	private function render_template_pair(
		string $template_name,
		array $context
	): array {
		return array(
			'html' => $this->render_template( $template_name, 'html', 'html', $context ),
			'text' => $this->render_template( $template_name, 'plain', 'txt', $context ),
		);
	}

	/**
	 * Render one email template file with placeholder replacement.
	 *
	 * @param string               $template_name Template base filename.
	 * @param string               $directory     Template subdirectory.
	 * @param string               $extension     File extension.
	 * @param array<string, mixed> $context       Placeholder values.
	 * @return string
	 *
	 * @throws \RuntimeException When the template file cannot be read.
	 * @since 1.0.2
	 */
	private function render_template(
		string $template_name,
		string $directory,
		string $extension,
		array $context
	): string {
		$template_path = $this->get_template_base_directory() .
			'/' .
			$directory .
			'/' .
			$template_name .
			'.' .
			$extension;
		$template      = file_get_contents( $template_path );

		if ( false === $template ) {
			throw new \RuntimeException(
				sprintf(
					'PeakURL could not read the %s email template.',
					$template_name,
				),
			);
		}

		return strtr(
			$template,
			$this->build_template_placeholders( $context, 'html' === $extension ),
		);
	}

	/**
	 * Build placeholder replacements for email templates.
	 *
	 * @param array<string, mixed> $context     Placeholder values.
	 * @param bool                 $escape_html Whether values should be HTML escaped.
	 * @return array<string, string>
	 * @since 1.0.2
	 */
	private function build_template_placeholders(
		array $context,
		bool $escape_html
	): array {
		$placeholders = array();

		foreach ( $context as $key => $value ) {
			$normalized_value = (string) $value;

			if ( $escape_html ) {
				$normalized_value = htmlspecialchars(
					$normalized_value,
					ENT_QUOTES,
					'UTF-8',
				);
			}

			$placeholders[ '{{' . $key . '}}' ] = $normalized_value;
		}

		return $placeholders;
	}

	/**
	 * Get the absolute path to the email templates directory.
	 *
	 * @return string
	 * @since 1.0.2
	 */
	private function get_template_base_directory(): string {
		return dirname( __DIR__ ) . '/templates/emails';
	}

	/**
	 * Apply a filter to a template context and keep array output stable.
	 *
	 * @param string               $hook_name Hook name.
	 * @param array<string, mixed> $context   Template context.
	 * @param mixed                ...$args   Additional filter arguments.
	 * @return array<string, mixed>
	 * @since 1.0.2
	 */
	private function filter_template_context(
		string $hook_name,
		array $context,
		...$args
	): array {
		$filtered = \apply_filters( $hook_name, $context, ...$args );

		return is_array( $filtered ) ? $filtered : $context;
	}
}
