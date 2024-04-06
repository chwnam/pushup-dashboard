<?php
/**
 * Plugin Name: 대시보드 플러그인
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PD_MAIN       = __FILE__;
const PD_DB_VERSION = '1.0.0';
const PD_VERSION    = '1.0.0';


if ( ! function_exists( 'pushup_dashboard_on_activation' ) ) {
	function pushup_dashboard_on_activation(): void {
		global $wpdb;

		$query = "CREATE TABLE {$wpdb->prefix}pushup_counts (\n" .
		         "datetime DATETIME NOT NULL COMMENT '작성시각',\n" .
		         "email varchar(255) NOT NULL COMMENT '이메일',\n" .
		         "submit_date date NOT NULL COMMENT '제출한 날짜',\n" .
		         "submit_time time DEFAULT NULL COMMENT '제출한 시간',\n" .
		         "count int(10) unsigned NOT NULL COMMENT '횟수',\n" .
		         "PRIMARY KEY  (datetime, email),\n" .
		         "KEY idx_email (email) COMMENT '이메일 인덱스',\n" .
		         "KEY idx_submit_date (submit_date) COMMENT '제출한 날짜 인덱스'\n" .
		         ") ENGINE=InnoDB " . $wpdb->get_charset_collate() . " COMMENT='푸시업기록 테이블'";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $query );

		$db_version = get_option( 'pd_db_version' );
		if ( ! $db_version ) {
			update_option( 'pd_db_version', PD_DB_VERSION );
		}
	}

	register_activation_hook( __FILE__, 'pushup_dashboard_on_activation' );
}

if ( ! function_exists( 'pushup_dashboard_on_deactivation' ) ) {
	function pushup_dashboard_on_deactivation(): void {
	}

	register_deactivation_hook( __FILE__, 'pushup_dashboard_on_deactivation' );
}

if (
	! function_exists( 'pushup_dashboard_invalid_client' ) && (
		! defined( 'PD_CLIENT_ID' ) || empty( PD_CLIENT_ID ) ||
		! defined( 'PD_CLIENT_SECRET' ) || empty( PD_CLIENT_SECRET )
	)
) {
	function pushup_dashboard_invalid_client(): void {
		echo '<div class="notice notice-error"><p>';

		$message = '';

		if ( ! defined( 'PD_CLIENT_ID' ) || empty( PD_CLIENT_ID ) ) {
			$message .= ' PD_CLIENT_ID';
		}

		if ( ! defined( 'PD_CLIENT_SECRET' ) || empty( PD_CLIENT_SECRET ) ) {
			$message .= empty( $message ) ? '' : ', ';
			$message .= ' PD_CLIENT_SECRET 값을 채워 주세요.';
		}

		echo esc_html( trim( $message ) );

		echo '</p></div>';
	}

	add_action( 'admin_notices', 'pushup_dashboard_invalid_client' );

	return;
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

		set_transient( '_pdstate', $state, 10 * MINUTE_IN_SECONDS );

		$redirect_url = add_query_arg(
			urlencode_deep(
				[
					'client_id'     => $credentials['client_id'],
					'redirect_uri'  => $redirect_uri,
					'response_type' => 'code',
					'scope'         => $scope,
					'access_type'   => 'offline',
					'prompt'        => 'consent',
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

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

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

		if ( false === $token_info ) {
			return;
		}

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
	/**
	 * @return array|WP_Error
	 *
	 * @example 아래는 응답 샘플
	 *
	 * array(
	 *     'range'          => "'설문지 응답 시트1'!A1:E137",
	 *     'majorDimension' => 'ROWS',
	 *     'values'         => array(
	 *         array(
	 *             '타임스탬프',
	 *             '이메일 주소',
	 *             '[필수] 기록할 날짜는?',
	 *             '[선택] 푸쉬업한 시간은? (하루에 여러번 입력한 경우 나누어 기록할 수 있어요)',
	 *             '[필수] 횟수를 적어 주세요.',
	 *         ),
	 *         array(
	 *             '2024. 2. 8 오전 10:19:54',
	 *             'john@email.com',
	 *             '2024. 2. 8',
	 *             '오후 3:00:00',
	 *             '50',
	 *         ),
	 *         array(
	 *             '2024. 2. 8 오후 9:03:57',
	 *             'jane@email.com',
	 *             '2024. 1. 28',
	 *             '',
	 *             '20',
	 *         ),
	 *         ....
	 *     ),
	 * );
	 *
	 * 1. values 첫번째는 헤더.
	 * 2. 타임스탬프는 구글 폼이 기록한 폼 접수일시.
	 * 3. 날짜는 필수, 시간은 옵션
	 */
	function pushup_dashboard_get_spreadsheet_data(): array|WP_Error {
		pushup_dashboard_refresh_token();

		$token_info     = get_site_transient( 'pd_oauth_token' );
		$access_token   = $token_info['body']['access_token'] ?? false;
		$spreadsheet_id = defined( 'PD_SHEET_ID' ) ? PD_SHEET_ID : '';

		if ( ! $access_token ) {
			return new WP_Error( 'error', 'OAuth 인증이 필요합니다.' );
		}

		if ( ! $spreadsheet_id ) {
			return new WP_Error( 'error', 'PD_SHEET_ID 상수가 필요합니다.' );
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
			return new WP_Error( 'error', 'API 호출 에러.' );
		}


		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}

if ( ! function_exists( 'pushup_dashboard_query_pushup_count' ) ) {
	function pushup_dashboard_query_pushup_count( string|array $args = [] ): array {
		global $wpdb;

		$defaults = [
			'email' => '',
			'year'  => '',
			'month' => '',
		];

		$args = wp_parse_args( $args, $defaults );

		// Build where clause.
		$where = "WHERE 1=1";
		if ( ! empty( $args['email'] ) ) {
			$where .= $wpdb->prepare( " AND email=%s", $args['email'] );
		}
		if ( ! empty( $args['year'] ) ) {
			$where .= $wpdb->prepare( " AND YEAR(submit_date)=%d", $args['year'] );
		}
		if ( ! empty( $args['month'] ) ) {
			$where .= $wpdb->prepare( " AND MONTH(submit_date)=%d", $args['month'] );
		}

		$query = "SELECT * FROM {$wpdb->prefix}pushup_counts $where";

		return $wpdb->get_results( $query );
	}
}

if ( ! function_exists( 'pushup_dashboard_parse_datetime' ) ) {
	function pushup_dashboard_parse_datetime( string $input ): DateTime|false {
		$pattern  = '/(?<year>\d{4}). (?<month>\d{1,2}). (?<day>\d{1,2}) (?<meridiem>오전|오후) (?<hour>\d{1,2}):(?<minute>\d{2}):(?<second>\d{2})/';
		$timezone = new DateTimeZone( 'Asia/Seoul' );

		if ( preg_match( $pattern, $input, $matches ) ) {
			try {
				$output = new DateTime( 'now', $timezone );
			} catch ( Exception ) {
				return false;
			}

			$output->setDate(
				$matches['year'],
				$matches['month'],
				$matches['day'],
			);

			$output->setTime(
				( '오전' == $matches['meridiem'] ? 0 : 12 ) + (int) $matches['hour'],
				(int) $matches['minute'],
				(int) $matches['second'],
			);

			return $output;
		}

		return false;
	}
}

if ( ! function_exists( 'pushup_dashboard_add_menu_page' ) ) {
	function pushup_dashboard_add_menu_page(): void {
		add_menu_page(
			page_title: '푸시업 대시보드',
			menu_title: '푸시업 대시보드',
			capability: 'administrator',
			menu_slug: 'pushup-dashboard',
			callback: 'pushup_dashboard_output_dashboard_page',
			icon_url: 'dashicons-megaphone',
		);
	}

	add_action( 'admin_menu', 'pushup_dashboard_add_menu_page' );
}

if ( ! function_exists( 'pushup_dashboard_update_database' ) ) {
	function pushup_dashboard_update_database(): void {
		global $wpdb;

		$data = pushup_dashboard_get_spreadsheet_data();
		if ( is_wp_error( $data ) ) {
			error_log( $data->get_error_message() );
			wp_die( $data );
		}

		if ( ! isset( $data['values'] ) ) {
			return;
		}

		$values = [];

		foreach ( array_slice( $data['values'], 1 ) as $value ) {
			$datetime    = pushup_dashboard_parse_datetime( $value[0] );
			$email       = sanitize_email( $value[1] );
			$submit_date = date_create_from_format( 'Y. n. j', $value[2] );

			if ( $submit_date ) {
				$submit_date = $submit_date->format( 'Y-m-d' );
			} else {
				$submit_date = '';
			}

			if ( $value[3] ) {
				$submit_time = preg_replace_callback(
					'/(오전|오후) (\d{1,2}):(\d{2}):(\d{2})/',
					function ( $matches ) {
						return sprintf(
							'%02d:%02d:%02d',
							( $matches[1] == '오전' ? 0 : 12 ) + (int) $matches[2],
							(int) $matches[3],
							(int) $matches[4],
						);
					},
					$value[3]
				);
			} else {
				$submit_time = '';
			}

			$count = (int) $value[4];

			$values[] = [
				$datetime->format( "y-m-d H:i:s" ),
				$email,
				$submit_date,
				$submit_time,
				$count,
			];
		}

		if ( $values ) {
			$buffer = [];
			foreach ( $values as $value ) {
				$buffer[] = $wpdb->prepare( '(%s, %s, %s, %s, %d)', $value );
			}
			$query = "INSERT IGNORE INTO {$wpdb->prefix}pushup_counts VALUES ";
			$query .= implode( ', ', $buffer );
			$wpdb->query( $query );
		}
	}
}

if ( ! function_exists( 'pushup_dashboard_output_dashboard_page' ) ) {
	function pushup_dashboard_output_dashboard_page(): void {
		$rows = pushup_dashboard_query_pushup_count();
		?>
        <div class="wrap">
            <h1>푸시업 시트</h1>
            <table class="wp-list-table widefat striped">
                <thead>
                <tr>
                    <th>순서</th>
                    <th>타임스탬프</th>
                    <th>이메일</th>
                    <th>날짜</th>
                    <th>시간 (옵션)</th>
                    <th>횟수</th>
                </tr>
                </thead>
                <tbody>
				<?php
				foreach ( $rows as $idx => $row ) : ?>
                    <tr>
                        <td><?php
							echo absint( $idx + 1 ); ?></td>
                        <td><?php
							echo esc_html( $row->datetime ); ?></td>
                        <td><?php
							echo esc_html( $row->email ); ?></td>
                        <td><?php
							echo esc_html( $row->submit_date ); ?></td>
                        <td><?php
							echo esc_html( $row->submit_time ); ?></td>
                        <td><?php
							echo esc_html( $row->count ); ?></td>
                    </tr>
				<?php
				endforeach; ?>
                </tbody>
            </table>

            <p class="submit">
                <a
                        class="button button-primary"
                        href="<?php
						$url = add_query_arg(
							[
								'action' => 'pushup_dashboard_update_database',
								'nonce'  => wp_create_nonce( 'pushup_dashboard' ),
							],
							admin_url( 'admin-post.php' )
						);
						echo esc_url( $url );
						?>"
                        role="button">데이터베이스 업데이트</a>
            </p>
        </div>
		<?php
	}
}

if ( ! function_exists( 'pushup_dashboard_admin_post_update_database' ) ) {
	function pushup_dashboard_admin_post_update_database(): void {
		check_admin_referer( 'pushup_dashboard', 'nonce' );
		pushup_dashboard_update_database();
		$referer = wp_get_referer();
		echo '<script>alert("업데이트를 마쳤습니다."); location.href="' . esc_url_raw( $referer ) . '";</script>';
	}

	add_action( 'admin_post_pushup_dashboard_update_database', 'pushup_dashboard_admin_post_update_database' );
}

if ( ! function_exists( 'pushup_dashboard_front' ) ) {
	function pushup_dashboard_front(): string {
		$rows   = [];
		$email  = wp_unslash( $_GET['email'] ?? '' );
		$nonce  = wp_unslash( $_GET['_wpnonce'] ?? '' );
		$submit = isset( $_GET['submit'] );

		$now       = date_create_immutable( 'now', wp_timezone() );
		$year      = 2024;
		$cur_month = ( (int) $now->format( 'n' ) ) - 1;
		$month     = wp_unslash( $_GET['month'] ?? '' );

		if ( $email ) {
			if ( ! $submit || ! wp_verify_nonce( $nonce, 'pushup_dashboard' ) ) {
				wp_die( 'Nonce verification error!' );
			}
			$rows = pushup_dashboard_query_pushup_count( "email=$email&year=$year&month=$month" );
		}

		ob_start();
		?>

        <style>
            .pushup-dashboard .result-table {
                border-collapse: collapse;
                width: 100%;

                th, td {
                    padding: 0.5rem 1rem;
                }

                thead th {
                    background-color: var(--wp--preset--color--contrast);
                    border-bottom: 1px solid var(--wp--preset--color--contrast);
                    border-top: 1px solid var(--wp--preset--color--contrast);
                    color: var(--wp--preset--color--base);
                }

                tbody tr:nth-of-type(2n) {
                    background-color: #eaeaea;
                }
            }

            .pushup-dashboard .justify-end {
                justify-content: flex-end;
            }

            .pushup-dashboard-field-wrap {
                display: flex;
                width: 100%;
                margin-top: 1rem;
                margin-bottom: 0.25rem;
            }

            .pushup-dashboard-label {
                display: inline-block;
                align-self: center;
                width: 6rem;
            }

            .pushup-dashboard-input {
                appearance: none;
                border-radius: .33rem;
                border: 1px solid #949494;
                font-size: var(--wp--preset--font-size--small);
                flex-grow: 1;
                margin-left: 0;
                margin-right: 0;
                min-width: 3rem;
                padding: 8px;
                text-decoration: unset !important;
            }

            .pushup-dashboard-field-wrap select {
                appearance: none;
                border-radius: .33rem;
                border: 1px solid #949494;
                font-size: var(--wp--preset--font-size--small);
                margin-left: 0;
                margin-right: 0;
                min-width: 6rem;
                padding: 8px;
            }

        </style>

        <div class="pushup-dashboard">
		<?php
		// Print default query screen.
		if ( empty( $email ) ) : ?>
            <form
                    id="front-dashboard"
                    class="wp-block-search__button-outside wp-block-search__text-button wp-block-search"
                    method="get" action="" role="search"
            >
                <div class="pushup-dashboard-field-wrap">
                    <label class="pushup-dashboard-label" for="email">이메일</label>
                    <input
                            id="email"
                            name="email"
                            class="pushup-dashboard-input"
                            type="email"
                            required="required"
                            value="<?php echo esc_attr( $email ); ?>"
                    >
                </div>
                <div class="pushup-dashboard-field-wrap">
                    <label class="pushup-dashboard-label" for="month">월</label>
                    <select
                            id="month"
                            name="month"
                            required="required"
                    >
						<?php
						// 2월부터 시작해서, 2월이 최하값이다.
						for ( $m = $cur_month; $m > 1; $m -- ): ?>
                            <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $m, $month ); ?>>
								<?php echo esc_html( $m ); ?>월
                            </option>
						<?php endfor; ?>
                    </select>
                </div>
                <div class="pushup-dashboard-field-wrap justify-end">
                    <button
                            id="submit"
                            class="button wp-block-search__button wp-element-button"
                            name="submit">조회하기
                    </button>
                </div>

				<?php
				wp_nonce_field( 'pushup_dashboard', '_wpnonce', false ); ?>
            </form>
            </div>
		<?php
		// Print queried result.
		else : ?>

			<?php
			// Result found.
			if ( $rows ) : ?>

                <section>
                    <h4><?php
						echo esc_html( sprintf( '%s 님의 %d년 %d월 결산', $email, $year, $month ) ); ?>
                    </h4>
                    <ul class="">
                        <li><?php
							echo esc_html( sprintf( '총 제출 횟수는 %d번 입니다.', count( $rows ) ) ); ?>
                        </li>
                        <li><?php
							echo esc_html( sprintf( '총 수행 횟수는 총 %d회 입니다.',
								array_sum( wp_list_pluck( $rows, 'count' ) ) ) ); ?>
                        </li>
                        <li><?php
							[ $streak, $min_date, $max_date ] = pushup_dashboard_get_max_streak( $rows );
							echo esc_html(
								sprintf(
									'최대 연속 제출은 %s 부터 %s 까지 총 %d회 입니다.',
									mysql2date( 'm월 d일', $min_date ),
									mysql2date( 'm월 d일', $max_date ),
									$streak
								)
							); ?>
                        </li>
                    </ul>
                </section>

                <table class="result-table">
                    <thead>
                    <tr>
                        <th class="sequence">번호</th>
                        <th class="submit_date">날짜</th>
                        <th class="submit_time">시간</th>
                        <th class="count">횟수</th>
                    </tr>
                    </thead>
                    <tbody>
					<?php
					// Loop
					foreach ( $rows as $idx => $row ) : ?>
                        <tr>
                            <td class="sequence"><?php
								echo absint( $idx + 1 ); ?>
                            </td>

                            <td class="submit_date"><?php
								echo esc_html( mysql2date( 'm월 d일', $row->submit_date ) ); ?>
                            </td>

                            <td class="submit_time"><?php
								echo esc_html( '00:00:00' !== $row->submit_time ? $row->submit_time : '-' ); ?>
                            </td>

                            <td class="count"><?php
								echo absint( $row->count ); ?>
                            </td>
                        </tr>
					<?php
					endforeach; ?>
                    </tbody>
                </table>

			<?php
			// No results.
			else : ?>
                <p>입력한 이메일로 조회된 내역이 없습니다.</p>

			<?php
			endif; ?>

            <p>
                <a
                        class="button wp-block-search__button wp-element-button"
                        href="<?php
						echo esc_url( remove_query_arg( [ 'email', 'submit', '_wpnonce' ] ) ); ?>"
                >돌아가가</a>
            </p>
		<?php
		endif; ?>

		<?php
		return ob_get_clean();
	}

	add_shortcode( 'pushup_dashboard_front', 'pushup_dashboard_front' );
}

if ( ! function_exists( 'pushup_dashboard_get_max_streak' ) ) {
	function pushup_dashboard_get_max_streak( array $rows ): array {
		if ( ! $rows ) {
			return [ 0, '', '' ];
		}

		$streaks     = [];
		$max_streaks = [];
		$today       = '';
		$tomorrow    = '';

		reset( $rows );
		$row = current( $rows );

		do {
			$submit_date = $row->submit_date;

			if ( $submit_date !== $today && $submit_date !== $tomorrow ) {
				if ( count( $streaks ) > count( $max_streaks ) ) {
					$max_streaks = $streaks;
				}
				$streaks = [ $submit_date ];
			} else {
				if ( $submit_date == $tomorrow ) {
					$streaks[] = $submit_date;
				}
			}

			$today    = $submit_date;
			$date     = date_create_from_format( 'Y-m-d', $submit_date );
			$date     = $date->add( new DateInterval( 'P1D' ) );
			$tomorrow = $date->format( 'Y-m-d' );
		} while ( ( $row = next( $rows ) ) );

		return [
			count( $max_streaks ),
			$max_streaks[0],
			$max_streaks[ count( $max_streaks ) - 1 ],
		];
	}
}