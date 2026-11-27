<?php
/**
 * Enable the built-in Lab Local Ollama preset (and initialize worker token if missing).
 *
 * Usage:
 *   studio wp eval-file deploy/lab/enable-ollama-preset.php qwen3:14b workers/extractor/worker-token.txt
 */

defined( 'ABSPATH' ) || exit;

$model      = isset( $args[0] ) && '' !== trim( (string) $args[0] ) ? sanitize_text_field( (string) $args[0] ) : 'qwen3:14b';
$token_file = isset( $args[1] ) ? trim( (string) $args[1] ) : '';

$presets = Unfold_AI::raw_presets();
$presets['lab_ollama']['enabled']  = true;
$presets['lab_ollama']['label']    = 'Lab Local Ollama';
$presets['lab_ollama']['provider'] = 'ollama';
$presets['lab_ollama']['model']    = $model;
$presets['lab_ollama']['base_url'] = 'http://127.0.0.1:11434';
Unfold_AI::save_presets( $presets );
echo "OK: Enabled lab_ollama preset (model: $model)\n";

if ( '' !== $token_file && ! is_file( $token_file ) && ! get_option( 'unfold_worker_token_hash' ) ) {
	$token = Unfold_Settings::rotate_worker_token();
	file_put_contents( $token_file, $token . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	echo "OK: Generated initial worker token at $token_file\n";
}
