<?php
/**
 * Plugin Name: 대시보드 플러그인
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'pushup_dashboard_admin_menu' ) ) {
	function pushup_dashboard_admin_menu(): void {
		add_options_page(
			page_title: '구글 인증',
			menu_title: '구글 인증',
			capability: 'manage_options',
			menu_slug: 'pushup-auth',
			callback: 'pushup_dashboard_output_menu_page'
		);
	}

	add_action( 'admin_menu', 'pushup_dashboard_admin_menu' );
}

if ( ! function_exists( 'pushup_dashboard_output_menu_page' ) ) {
	function pushup_dashboard_output_menu_page(): void {
		echo '<div class="wrap">';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<button type="submit" class="button button-primary">인증하기</button>';
		echo '<input type="hidden" name="action" value="pushup_dashboard_authorize">';
		wp_nonce_field( 'pushup_dashboard', '_pdnonce' );
		echo '</form>';
		echo '</div>';

		$token_data = get_site_transient( 'pd_oauth_token' );
		echo '<div><pre>' . print_r( $token_data, 1 ) . '</pre></div>';

		pushup_dashboard_refresh_token();
		$token_data_maybe_refreshed = get_site_transient( 'pd_oauth_token' );
		if ( $token_data['timestamp'] !== $token_data_maybe_refreshed['timestamp'] ) {
			echo '<div><pre>' . print_r( $token_data, 1 ) . '</pre></div>';
			$token_data = $token_data_maybe_refreshed;
		} else {
			echo '<div>Not refreshed yet.</div>';
		}

		if ( $token_data ) {
			$pushup_data = pushup_dashboard_get_spreadsheet_data();
			if ( $pushup_data ) {
				echo '<h3>팔굽펴혀펴기 시트</h3>';
				echo '<div><pre>' . print_r( $pushup_data, 1 ) . '</pre></div>';
			}
		}
	}
}

if ( ! function_exists( 'pushup_dashboard_authorize' ) ) {
	function pushup_dashboard_authorize(): void {
		if ( ! isset( $_GET['code'] ) ) {
			pushup_dashboard_flow_1_authorize();
		} else {
			pushup_dashboard_flow_2_get_token();
		}
	}

	add_action( 'admin_post_pushup_dashboard_authorize', 'pushup_dashboard_authorize' );
	add_action( 'admin_post_nopriv_pushup_dashboard_authorize', 'pushup_dashboard_authorize' );
}

if ( ! function_exists( 'pushup_dashboard_get_redirect_uri' ) ) {
	function pushup_dashboard_get_redirect_uri(): string {
		return esc_url_raw(
			add_query_arg(
				'action',
				'pushup_dashboard_authorize',
				admin_url( 'admin-post.php' )
			)
		);
	}
}

if ( ! function_exists( 'pushup_dashboard_get_scope' ) ) {
	function pushup_dashboard_get_scope(): string {
		return implode(
			' ',
			[
				'https://www.googleapis.com/auth/userinfo.profile',
				'https://www.googleapis.com/auth/drive.readonly',
				'https://www.googleapis.com/auth/spreadsheets.readonly'
			]
		);
	}
}

if ( ! function_exists( 'pushup_dashboard_flow_1_authorize' ) ) {
	function pushup_dashboard_flow_1_authorize(): never {
		check_admin_referer( 'pushup_dashboard', '_pdnonce' );

		$credentials  = pushup_dashboard_get_credentials();
		$state        = wp_generate_password();
		$redirect_uri = pushup_dashboard_get_redirect_uri();
		$scope        = pushup_dashboard_get_scope();
		$prompt       = 'production' === wp_get_environment_type() ? '' : 'consent';

		set_transient( '_pdstate', $state, 10 * MINUTE_IN_SECONDS );

		$redirect_url = add_query_arg(
			urlencode_deep(
				[
					'client_id'     => $credentials['client_id'],
					'redirect_uri'  => $redirect_uri,
					'response_type' => 'code',
					'scope'         => $scope,
					'access_type'   => 'offline',
					'prompt'        => $prompt,
					'state'         => $state,
				]
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		);

		wp_redirect( $redirect_url );
		exit;
	}
}

if ( ! function_exists( 'pushup_dashboard_flow_2_get_token' ) ) {
	function pushup_dashboard_flow_2_get_token(): never {
		$state    = wp_unslash( $_GET['state'] );
		$expected = get_transient( '_pdstate' );

		delete_transient( '_pdstate' );

		if ( $state !== $expected ) {
			wp_die( 'State mismatch!' );
		}

		$credentials = pushup_dashboard_get_credentials();
		$code        = wp_unslash( $_GET['code'] );
		$response    = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'body' => [
					'client_id'     => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
					'code'          => $code,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => pushup_dashboard_get_redirect_uri(),
				],
			]
		);

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			wp_die( 'Wrong response code.' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		set_site_transient(
			'pd_oauth_token',
			[
				'timestamp' => time(),
				'body'      => $body,
			]
		);

		wp_redirect( admin_url( 'options-general.php?page=pushup-auth' ) );
		exit;
	}
}

if ( ! function_exists( 'pushup_dashboard_flow_3_refresh_token' ) ) {
	function pushup_dashboard_refresh_token(): void {
		$credentials = pushup_dashboard_get_credentials();
		$token_info  = get_site_transient( 'pd_oauth_token' );

		$body          = (array) $token_info['body'];
		$timestamp     = $token_info['timestamp'];
		$expires_in    = $body['expires_in'];
		$refresh_token = $body['refresh_token'];
		$expiration    = $timestamp + $expires_in - 600;
		$now           = time();

		if ( $now < $expiration ) {
			return;
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'body' => [
					'client_id'     => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
				]
			]
		);

		$refresh_body = json_decode( wp_remote_retrieve_body( $response ), true );

		$token_info = [
			'timestamp' => time(),
			'body'      => array_merge( $body, $refresh_body )
		];

		set_site_transient( 'pd_oauth_token', $token_info );
	}
}

if ( ! function_exists( 'pushup_dashboard_get_credentials' ) ) {
	function pushup_dashboard_get_credentials(): array {
		return [
			'client_id'     => defined( 'PD_CLIENT_ID' ) ? PD_CLIENT_ID : '',
			'client_secret' => defined( 'PD_CLIENT_SECRET' ) ? PD_CLIENT_SECRET : '',
		];
	}
}

if ( ! function_exists( 'pushup_dashboard_get_profile' ) ) {
	function pushup_dashboard_get_profile(): array {
		$token_info   = get_site_transient( 'pd_oauth_token' );
		$access_token = $token_info['body']['access_token'] ?? false;

		if ( $access_token ) {
			$resp = wp_remote_get(
				'https://www.googleapis.com/oauth2/v2/userinfo',
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $access_token,
					],
				]
			);
			$body = wp_remote_retrieve_body( $resp );
		} else {
			$body = '';
		}

		return json_decode( $body, true );
	}
}

if ( ! function_exists( 'pushup_dashboard_get_spreadsheet_data' ) ) {
	function pushup_dashboard_get_spreadsheet_data(): array {
		$token_info     = get_site_transient( 'pd_oauth_token' );
		$access_token   = $token_info['body']['access_token'] ?? false;
		$spreadsheet_id = defined( 'PD_SHEET_ID' ) ? PD_SHEET_ID : '';

		if ( ! $spreadsheet_id || ! $access_token ) {
			return [];
		}

		$response = wp_remote_get(
			"https://sheets.googleapis.com/v4/spreadsheets/$spreadsheet_id/values/A:E",
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
				]
			]
		);

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
