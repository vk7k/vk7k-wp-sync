<?php
/**
 * VK7K Sync HTTP Client
 *
 * Unified, type-safe HTTP client wrapper for making authenticated remote REST calls.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VK7K_Sync_Client {

	/**
	 * Send an authenticated JSON POST request to a remote endpoint.
	 *
	 * @param string       $endpoint   Endpoint relative to /vk7k-sync/v1/ (e.g. 'granular/import').
	 * @param array|string $payload    Data to encode as JSON payload.
	 * @param string       $remote_url Target remote WordPress URL (defaults to active target).
	 * @param string       $secret_key HMAC secret key (defaults to active target secret).
	 * @param string       $remote_ip  Optional IP for direct cURL resolution.
	 * @return array|WP_Error Decoded JSON response array or WP_Error on failure.
	 */
	public static function post( $endpoint, $payload = array(), $remote_url = '', $secret_key = '', $remote_ip = '' ) {
		return VK7K_Sync_Runner::send_remote_request(
			$endpoint,
			$payload,
			$remote_url,
			$secret_key,
			$remote_ip
		);
	}

	/**
	 * Send a raw binary payload (application/octet-stream) with automatic Base64 fallback.
	 *
	 * @param string $endpoint   Endpoint relative to /vk7k-sync/v1/ (e.g. 'files/upload-chunk').
	 * @param string $raw_data   Binary data (e.g. raw ZIP contents).
	 * @param string $remote_url Target remote WordPress URL.
	 * @param string $secret_key HMAC secret key.
	 * @param string $remote_ip  Optional IP.
	 * @return array|WP_Error Decoded response array or WP_Error.
	 */
	public static function post_binary( $endpoint, $raw_data, $remote_url = '', $secret_key = '', $remote_ip = '' ) {
		$res = VK7K_Sync_Runner::send_remote_request(
			$endpoint,
			$raw_data,
			$remote_url,
			$secret_key,
			$remote_ip,
			'',
			'application/octet-stream'
		);

		// Fallback to Base64 JSON payload if remote rejected binary stream (HTTP 400)
		if ( is_wp_error( $res ) ) {
			$res = VK7K_Sync_Runner::send_remote_request(
				$endpoint,
				array( 'zip_base64' => base64_encode( $raw_data ) ),
				$remote_url,
				$secret_key,
				$remote_ip
			);
		}

		return $res;
	}

	/**
	 * Download a binary stream from remote directly to a local file.
	 *
	 * @param string $endpoint    Endpoint relative to /vk7k-sync/v1/ (e.g. 'files/download-chunk').
	 * @param array  $payload     Request payload.
	 * @param string $target_file Absolute local file path to save the stream.
	 * @param string $remote_url  Target remote WordPress URL.
	 * @param string $secret_key  HMAC secret key.
	 * @param string $remote_ip   Optional IP.
	 * @return array|WP_Error Stream metadata array or WP_Error.
	 */
	public static function stream_download( $endpoint, $payload, $target_file, $remote_url = '', $secret_key = '', $remote_ip = '' ) {
		return VK7K_Sync_Runner::send_remote_request(
			$endpoint,
			$payload,
			$remote_url,
			$secret_key,
			$remote_ip,
			$target_file
		);
	}
}
