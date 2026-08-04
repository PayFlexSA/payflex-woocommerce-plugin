<?php

/**
 * get_payflex_authorization_code() — every API call depends on it, and it caches
 * the bearer token in a transient.
 */
final class AuthenticationTest extends PF_TestCase
{
    /** A gateway built without a pre-seeded token, so auth actually runs. */
    private function authGateway(array $overrides = []): WC_Gateway_PartPay
    {
        return $this->gateway($overrides, false);
    }

    public function test_returns_false_and_makes_no_request_without_credentials(): void
    {
        $gateway = $this->authGateway(['client_id' => '', 'client_secret' => '']);

        $this->assertFalse($gateway->get_payflex_authorization_code());
        $this->assertSame([], PF_State::$http_log);
    }

    public function test_returns_false_when_only_the_client_id_is_set(): void
    {
        $gateway = $this->authGateway(['client_secret' => '']);

        $this->assertFalse($gateway->get_payflex_authorization_code());
        $this->assertSame([], PF_State::$http_log);
    }

    public function test_fetches_and_returns_a_token(): void
    {
        PF_State::queue_auth_token('shiny-new-token', 3600);
        $gateway = $this->authGateway();

        $this->assertSame('shiny-new-token', $gateway->get_payflex_authorization_code());
    }

    public function test_posts_the_credentials_and_audience_to_the_environment_auth_url(): void
    {
        PF_State::queue_auth_token();
        $gateway = $this->authGateway(['testmode' => 'production']);
        $gateway->get_payflex_authorization_code();

        $request = PF_State::$http_log[0];

        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://auth.payflex.co.za/auth/merchant', $request['url']);
        $this->assertSame('application/json', $request['args']['headers']['Content-Type']);

        $this->assertSame([
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'audience'      => 'https://auth-production.payflex.co.za',
            'grant_type'    => 'client_credentials',
        ], $this->requestBody());
    }

    public function test_sandbox_mode_authenticates_against_the_uat_auth_url(): void
    {
        PF_State::queue_auth_token();
        $gateway = $this->authGateway(['testmode' => 'develop']);
        $gateway->get_payflex_authorization_code();

        $this->assertSame('https://auth-uat.payflex.co.za/auth/merchant', PF_State::$http_log[0]['url']);
        $this->assertSame('https://auth-dev.payflex.co.za', $this->requestBody()['audience']);
    }

    public function test_token_is_cached_so_a_second_call_makes_no_request(): void
    {
        PF_State::queue_auth_token('cached-me', 3600);
        $gateway = $this->authGateway();

        $this->assertSame('cached-me', $gateway->get_payflex_authorization_code());
        $this->assertSame('cached-me', $gateway->get_payflex_authorization_code());
        $this->assertCount(1, PF_State::$http_log);
        $this->assertSame('cached-me', get_transient('payflex_access_token'));
    }

    /**
     * The cached token is deliberately expired two minutes early so an in-flight
     * request cannot be made with a token that dies mid-call.
     */
    public function test_cached_token_expires_two_minutes_before_the_api_expiry(): void
    {
        PF_State::queue_auth_token('short-lived', 600);
        $gateway = $this->authGateway();
        $gateway->get_payflex_authorization_code();

        $expires = PF_State::$transients['payflex_access_token']['expires'];

        $this->assertGreaterThan(time() + 470, $expires);
        $this->assertLessThanOrEqual(time() + 480, $expires);
    }

    public function test_reset_flag_discards_the_cached_token_and_re_authenticates(): void
    {
        set_transient('payflex_access_token', 'stale-token', 3600);
        set_transient('payflex_access_token_date', time() - 60, 3600);

        PF_State::queue_auth_token('fresh-token');
        $gateway = $this->authGateway();

        $this->assertSame('fresh-token', $gateway->get_payflex_authorization_code(true));
        $this->assertCount(1, PF_State::$http_log);
    }

    public function test_returns_false_on_a_401(): void
    {
        PF_State::queue_json(401, ['error' => 'access_denied'], '/auth/merchant');
        $gateway = $this->authGateway();

        $this->assertFalse($gateway->get_payflex_authorization_code());
        $this->assertFalse(get_transient('payflex_access_token'));
    }

    public function test_returns_false_when_the_request_fails_outright(): void
    {
        PF_State::queue_response(new WP_Error('http_request_failed', 'Connection timed out'), '/auth/merchant');
        $gateway = $this->authGateway();

        $this->assertFalse($gateway->get_payflex_authorization_code());
        $this->assertFalse(get_transient('payflex_access_token'));
    }

    public function test_records_the_token_issue_time_for_the_support_page(): void
    {
        PF_State::queue_auth_token();
        $gateway = $this->authGateway();

        $this->assertFalse($gateway->get_access_token_date());

        $gateway->get_payflex_authorization_code();

        $this->assertGreaterThanOrEqual(PF_State::$now, (int) $gateway->get_access_token_date());
    }

    public function test_logs_a_truncated_token_never_the_full_value(): void
    {
        $token = str_repeat('A', 20) . 'SECRETMIDDLE' . str_repeat('B', 20);
        PF_State::queue_auth_token($token);

        $gateway = $this->authGateway();
        $gateway->get_payflex_authorization_code();

        $log = PF_State::log_text();

        $this->assertStringContainsString('Storing new token in cache', $log);
        $this->assertStringNotContainsString('SECRETMIDDLE', $log);
        $this->assertStringNotContainsString($token, $log);
    }

    public function test_credentials_are_never_written_to_the_log(): void
    {
        PF_State::queue_auth_token();
        $gateway = $this->authGateway(['client_secret' => 'super-secret-value', 'payflex_debug' => 'yes']);
        $gateway->get_payflex_authorization_code();

        $this->assertStringNotContainsString('super-secret-value', PF_State::log_text());
    }

    public function test_debug_mode_logs_the_token_fetch(): void
    {
        PF_State::queue_auth_token();
        $gateway = $this->authGateway(['payflex_debug' => 'yes']);
        $gateway->get_payflex_authorization_code();

        $this->assertLogged('Getting new access token');
    }

    public function test_debug_mode_logs_when_credentials_are_missing(): void
    {
        $gateway = $this->authGateway(['payflex_debug' => 'yes', 'client_id' => '', 'client_secret' => '']);

        PF_State::$logs = [];
        $gateway->get_payflex_authorization_code();

        $this->assertLogged('No api keys available');
        $this->assertLogged('API keys not available.');
    }

    /**
     * KNOWN DEFECT — characterisation test, not an endorsement.
     *
     * The constructor calls init_form_fields() (line ~100) before
     * init_settings() (line ~103). form_fields() asks
     * get_payflex_authorization_code() whether the API is reachable, but
     * $this->settings is still empty at that point, so apiKeysAvailable()
     * reports no credentials and no auth request is ever attempted.
     *
     * Consequence: whenever the cached token transient has expired, the
     * "Client ID" field on the settings screen reads "Connection failed, please
     * check your credentials" even though the credentials are valid. It only
     * looks correct while a token happens to be cached (e.g. straight after
     * saving, which re-authenticates via on_save_settings()).
     *
     * Fix would be to call init_settings() before init_form_fields().
     */
    public function test_connection_status_reports_failure_when_no_token_is_cached(): void
    {
        // Valid credentials, a working auth endpoint, but nothing cached yet.
        PF_State::stub_json(200, ['access_token' => 'valid', 'expires_in' => 3600], '/auth/merchant');

        $gateway = $this->authGateway();

        // $gateway->form_fields is the array built during construction — it is
        // what generate_settings_html() renders on the settings screen.
        $this->assertStringContainsString('Connection failed', $gateway->form_fields['client_id']['description']);
        $this->assertSame([], PF_State::$http_log, 'No auth attempt is made during construction');

        // Rebuilt after construction, with settings loaded, the status is correct.
        $this->assertStringContainsString('Successfully connected', $gateway->form_fields()['client_id']['description']);
        $this->assertSame('valid', $gateway->get_payflex_authorization_code());
    }

    /**
     * KNOWN DEFECT — characterisation test, not an endorsement.
     *
     * The success branch only excludes HTTP 401, so a 500 (or any other
     * non-401) response is treated as success. With no access_token in the
     * body the gateway caches and returns an empty string, which downstream
     * code then sends as `Authorization: Bearer `.
     *
     * The correct check is a 2xx status plus a non-empty access_token.
     */
    public function test_non_401_error_responses_are_treated_as_successful_auth(): void
    {
        PF_State::queue_json(500, ['message' => 'Internal Server Error'], '/auth/merchant');
        $gateway = $this->authGateway();

        $this->assertSame('', $gateway->get_payflex_authorization_code());
    }
}
