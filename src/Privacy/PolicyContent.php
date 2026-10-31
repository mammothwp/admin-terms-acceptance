<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Privacy;

use MammothWP\AcceptanceLogs\Retention\RetentionPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contributes suggested privacy-policy text (Settings -> Privacy).
 *
 * The plugin collects an IP address and a User-Agent against a named account,
 * so the site's notice has to say so — Art. 13 transparency is the operator's
 * obligation, and this gives them the wording rather than leaving them to
 * discover the fields by reading the schema.
 *
 * Deliberately spells out the legal bases, since the whole point of the
 * design is that this is Art. 6(1)(b)/(f) processing and not consent. A site
 * that copies this text into a "Consent" section of its policy has
 * mischaracterised its own processing.
 */
final class PolicyContent {

	private RetentionPolicy $policy;

	public function __construct( ?RetentionPolicy $policy = null ) {
		$this->policy = $policy ?? new RetentionPolicy();
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_content' ) );
	}

	public function add_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Terms & Conditions acceptance', 'mammoth-acceptance-logs' ),
			wp_kses_post( wpautop( $this->content() ) )
		);
	}

	private function content(): string {
		$sections = array(
			'<strong>' . esc_html__( 'What we record and why', 'mammoth-acceptance-logs' ) . '</strong>',

			esc_html__( 'When you accept our Terms & Conditions we record that acceptance so that both you and we can later establish which version of the terms was agreed, and when. This is an access-control and contractual-compliance record. It is not marketing profiling and it is not used to make automated decisions about you.', 'mammoth-acceptance-logs' ),

			'<strong>' . esc_html__( 'Legal basis', 'mammoth-acceptance-logs' ) . '</strong>',

			esc_html__( 'We rely on Article 6(1)(b) GDPR, because accepting the terms is necessary for the performance of the contract with you and for providing you access to the platform, and on Article 6(1)(f) GDPR, our legitimate interests in the security of the platform, in accountability, and in being able to demonstrate and defend what was agreed. We do not rely on consent (Article 6(1)(a)) for this processing, so you are not asked to consent and cannot withdraw consent to it; you can instead object on the grounds described below.', 'mammoth-acceptance-logs' ),

			'<strong>' . esc_html__( 'Data recorded for each acceptance', 'mammoth-acceptance-logs' ) . '</strong>',

			esc_html__( 'Your user account identifier, and your username and email address as they stood at that moment; the date and time in UTC together with the local time and time zone shown to you; the identifier, number and effective date of the version accepted; the action taken; the site the acceptance was made on; your IP address; and your browser identification (User-Agent). We do not use device fingerprinting and we do not collect MAC addresses or location data.', 'mammoth-acceptance-logs' ),

			'<strong>' . esc_html__( 'Who else receives it', 'mammoth-acceptance-logs' ) . '</strong>',

			esc_html__( 'A copy of the terms you accepted is emailed to you as a receipt. That email is handled by our email provider, which acts as a processor on our behalf. If that provider operates outside the European Economic Area, the transfer is covered by the safeguards described elsewhere in this policy. [Site operator: name your email provider here, and confirm where it stores and processes data.]', 'mammoth-acceptance-logs' ),

			'<strong>' . esc_html__( 'How long we keep it', 'mammoth-acceptance-logs' ) . '</strong>',

			esc_html( $this->policy->justification() ),

			'<strong>' . esc_html__( 'Your rights', 'mammoth-acceptance-logs' ) . '</strong>',

			esc_html__( 'You can request a copy of the acceptance records we hold about you. Because these records exist to establish and defend what was agreed, Article 17(3) GDPR limits the right to have them erased before the retention period above has elapsed; we will explain this if you ask us to delete them. You may object to processing based on our legitimate interests, and you may complain to the Hellenic Data Protection Authority.', 'mammoth-acceptance-logs' ),
		);

		return implode( "\n\n", $sections );
	}
}
