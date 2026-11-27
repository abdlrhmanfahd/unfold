<?php

use PHPUnit\Framework\TestCase;

final class AiConfigTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['unfold_test_options']   = array();
		$GLOBALS['unfold_test_user_meta'] = array();
		$GLOBALS['unfold_test_post_meta'] = array();
		$GLOBALS['unfold_test_posts']     = array();
	}

	public function test_encrypt_and_decrypt_secret_roundtrip(): void {
		$this->assertSame( '', Unfold_AI::encrypt_secret( '' ) );
		$this->assertSame( '', Unfold_AI::encrypt_secret( '   ' ) );
		$this->assertSame( '', Unfold_AI::decrypt_secret( '' ) );

		$plain  = 'demo-secret-value-9876';
		$cipher = Unfold_AI::encrypt_secret( $plain );
		$this->assertNotSame( $plain, $cipher );
		$this->assertNotSame( '', $cipher );
		$this->assertSame( $plain, Unfold_AI::decrypt_secret( $cipher ) );
		$this->assertSame( '9876', Unfold_AI::key_hint( $plain ) );
	}

	public function test_save_user_config_persists_preserves_and_clears_key_safely(): void {
		$cfg = Unfold_AI::save_user_config(
			42,
			array(
				'mode'     => 'byok',
				'provider' => 'openai_compat',
				'model'    => 'llama-3.3-70b-versatile',
				'base_url' => 'https://api.groq.com/openai/v1/',
				'api_key'  => 'demo-user-value-1234',
			)
		);

		$this->assertSame( 'byok', $cfg['mode'] );
		$this->assertSame( 'openai_compat', $cfg['provider'] );
		$this->assertSame( 'https://api.groq.com/openai/v1', $cfg['base_url'] );
		$this->assertTrue( $cfg['has_key'] );
		$this->assertSame( '1234', $cfg['key_hint'] );
		$this->assertArrayNotHasKey( 'api_key', $cfg );
		$this->assertArrayNotHasKey( 'api_key_enc', $cfg );

		// Blank api_key preserves the previously saved key.
		$cfg2 = Unfold_AI::save_user_config(
			42,
			array(
				'mode'     => 'byok',
				'provider' => 'openai_compat',
				'model'    => 'mixtral-8x7b-32768',
			)
		);
		$this->assertTrue( $cfg2['has_key'] );
		$this->assertSame( '1234', $cfg2['key_hint'] );
		$this->assertSame( 'mixtral-8x7b-32768', $cfg2['model'] );

		// clear_key removes the saved key permanently.
		$cfg3 = Unfold_AI::save_user_config( 42, array( 'clear_key' => true ) );
		$this->assertFalse( $cfg3['has_key'] );
		$this->assertSame( '', $cfg3['key_hint'] );
	}

	public function test_public_presets_returns_only_enabled_and_omits_keys(): void {
		$presets = Unfold_AI::raw_presets();
		$presets['lab_gemini']['enabled']     = true;
		$presets['lab_gemini']['api_key_enc'] = Unfold_AI::encrypt_secret( 'demo-lab-value-9999' );
		$presets['lab_gemini']['key_hint']    = '9999';
		$presets['lab_ollama']['enabled']     = false;
		Unfold_AI::save_presets( $presets );

		$pub = Unfold_AI::public_presets();
		$this->assertCount( 1, $pub );
		$this->assertSame( 'lab_gemini', $pub[0]['id'] );
		$this->assertArrayNotHasKey( 'api_key', $pub[0] );
		$this->assertArrayNotHasKey( 'api_key_enc', $pub[0] );
	}

	public function test_resolve_for_job_handles_quick_preset_and_byok(): void {
		$presets = Unfold_AI::raw_presets();
		$presets['lab_gemini']['enabled']     = true;
		$presets['lab_gemini']['api_key_enc'] = Unfold_AI::encrypt_secret( 'demo-preset-value-5555' );
		Unfold_AI::save_presets( $presets );

		Unfold_AI::save_user_config(
			7,
			array(
				'mode'     => 'byok',
				'provider' => 'gemini',
				'model'    => 'gemini-2.5-pro',
				'api_key'  => 'demo-student-value-7777',
			)
		);

		// Quick Extract.
		Unfold_AI::save_project_choice( 101, array( 'mode' => 'quick' ), 7 );
		$q = Unfold_AI::resolve_for_job( 101 );
		$this->assertSame( 'local_rules', $q['provider'] );
		$this->assertSame( 'local_rules', $q['model'] );
		$this->assertSame( '', $q['api_key'] );

		// Built-in Lab Preset.
		Unfold_AI::save_project_choice( 102, array( 'mode' => 'preset', 'preset_id' => 'lab_gemini' ), 7 );
		$p = Unfold_AI::resolve_for_job( 102 );
		$this->assertSame( 'gemini', $p['provider'] );
		$this->assertSame( 'demo-preset-value-5555', $p['api_key'] );

		// User BYOK.
		Unfold_AI::save_project_choice( 103, array( 'mode' => 'byok' ), 7 );
		$b = Unfold_AI::resolve_for_job( 103 );
		$this->assertSame( 'gemini', $b['provider'] );
		$this->assertSame( 'gemini-2.5-pro', $b['model'] );
		$this->assertSame( 'demo-student-value-7777', $b['api_key'] );
	}
}
