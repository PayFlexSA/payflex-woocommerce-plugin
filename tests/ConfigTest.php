<?php

/**
 * config/config.php holds the live API endpoints. A typo here silently sends
 * real payments at the wrong host, so its shape is asserted explicitly.
 */
final class ConfigTest extends PF_TestCase
{
    private const REQUIRED_KEYS = ['name', 'api_url', 'auth_url', 'web_url', 'auth_audience'];

    private function environments(): array
    {
        // config.php declares $environments in the including scope.
        require PAYFLEX_PLUGIN_ROOT . '/config/config.php';
        return $environments;
    }

    public function test_defines_exactly_the_develop_and_production_environments(): void
    {
        $this->assertSame(['develop', 'production'], array_keys($this->environments()));
    }

    public function test_every_environment_has_all_required_keys(): void
    {
        foreach ($this->environments() as $key => $environment) {
            foreach (self::REQUIRED_KEYS as $required) {
                $this->assertArrayHasKey($required, $environment, "$key is missing '$required'");
                $this->assertNotSame('', $environment[$required], "$key has an empty '$required'");
            }
        }
    }

    public function test_all_endpoints_are_https_and_on_a_payflex_domain(): void
    {
        foreach ($this->environments() as $key => $environment) {
            foreach (['api_url', 'auth_url', 'web_url', 'auth_audience'] as $url_key) {
                $url = $environment[$url_key];

                $this->assertStringStartsWith('https://', $url, "$key.$url_key is not HTTPS");
                $this->assertStringEndsWith(
                    'payflex.co.za',
                    (string) parse_url($url, PHP_URL_HOST),
                    "$key.$url_key is not on a payflex.co.za host"
                );
            }
        }
    }

    public function test_production_and_develop_do_not_share_endpoints(): void
    {
        $environments = $this->environments();

        foreach (['api_url', 'auth_url', 'web_url', 'auth_audience'] as $url_key) {
            $this->assertNotSame(
                $environments['develop'][$url_key],
                $environments['production'][$url_key],
                "develop and production share the same $url_key"
            );
        }
    }

    public function test_production_endpoints_do_not_point_at_uat_or_dev_hosts(): void
    {
        foreach ($this->environments()['production'] as $key => $value) {
            if ($key === 'name') continue;

            $this->assertStringNotContainsString('uat', $value, "production.$key points at UAT");
            $this->assertStringNotContainsString('-dev', $value, "production.$key points at dev");
        }
    }

    public function test_environment_names_are_the_labels_shown_in_the_settings_dropdown(): void
    {
        $environments = $this->environments();

        $this->assertSame('Sandbox', $environments['develop']['name']);
        $this->assertSame('Production', $environments['production']['name']);
    }

    /**
     * The gateway derives its order and configuration endpoints from api_url.
     */
    public function test_gateway_builds_its_endpoints_from_the_selected_environment(): void
    {
        $environments = $this->environments();

        foreach (['develop', 'production'] as $env) {
            $gateway = $this->gateway(['testmode' => $env]);

            $this->assertSame($environments[$env]['api_url'] . '/order', $gateway->getOrderUrl());
        }
    }

    /**
     * With no environment selected the gateway must not fall back to a
     * host-relative URL that would resolve against the merchant's own site.
     */
    public function test_unset_environment_leaves_endpoints_without_a_host(): void
    {
        $gateway = $this->gateway(['testmode' => '']);

        $this->assertSame('/order', $gateway->getOrderUrl());
    }
}
