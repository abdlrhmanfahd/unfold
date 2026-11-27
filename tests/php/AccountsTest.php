<?php

use PHPUnit\Framework\TestCase;

final class AccountsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['unfold_test_options'] = array();
	}

	public function test_allowed_domains_defaults_to_kau_domains(): void {
		$this->assertSame( array( 'kau.edu.sa', 'stu.kau.edu.sa' ), Unfold_Accounts::allowed_domains() );
	}

	public function test_email_allowed_enforces_exact_kau_domains(): void {
		$this->assertTrue( Unfold_Accounts::email_allowed( 'student@stu.kau.edu.sa' ) );
		$this->assertTrue( Unfold_Accounts::email_allowed( 'DR.AHMED@KAU.EDU.SA' ) );

		$this->assertFalse( Unfold_Accounts::email_allowed( 'user@gmail.com' ) );
		$this->assertFalse( Unfold_Accounts::email_allowed( 'x@fake-kau.edu.sa' ) );
		$this->assertFalse( Unfold_Accounts::email_allowed( 'x@kau.edu.sa.evil.com' ) );
		$this->assertFalse( Unfold_Accounts::email_allowed( 'not-an-email' ) );
		$this->assertFalse( Unfold_Accounts::email_allowed( '' ) );
	}

	public function test_smtp_config_reads_wp_options_when_constants_unset(): void {
		$GLOBALS['unfold_test_options'] = array(
			'unfold_smtp_host'   => 'smtp.office365.com',
			'unfold_smtp_port'   => 587,
			'unfold_smtp_secure' => 'tls',
			'unfold_smtp_user'   => 'unfold@kau.edu.sa',
			'unfold_smtp_pass'   => 'app-secret',
			'unfold_smtp_from'   => 'unfold@kau.edu.sa',
		);

		$cfg = Unfold_Accounts::smtp_config();
		$this->assertTrue( $cfg['configured'] );
		$this->assertSame( 'smtp.office365.com', $cfg['host'] );
		$this->assertSame( 587, $cfg['port'] );
		$this->assertSame( 'tls', $cfg['secure'] );
		$this->assertSame( 'unfold@kau.edu.sa', $cfg['from'] );
	}

	public function test_smtp_config_reports_unconfigured_when_empty(): void {
		$cfg = Unfold_Accounts::smtp_config();
		$this->assertFalse( $cfg['configured'] );
		$this->assertSame( '', $cfg['host'] );
	}
}
