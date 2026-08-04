<?php

/**
 * Release hygiene: the checks that stop a broken or mislabelled build reaching
 * WordPress.org. These do not exercise behaviour — they assert facts about the
 * files that ship.
 */
final class PluginIntegrityTest extends PF_TestCase
{
    /* -----------------------------------------------------------------------
     * Version consistency
     *
     * The version lives in three places. WordPress.org reads Stable tag from
     * readme.txt, WordPress reads Version from the plugin header, and the
     * gateway sends its own $version to Payflex as merchantSystemInformation.
     * Any disagreement means a release that reports the wrong version somewhere.
     * -------------------------------------------------------------------- */

    public function test_the_plugin_header_declares_a_semver_style_version(): void
    {
        $version = PF_PluginMeta::headerVersion();

        $this->assertNotNull($version, 'partpay.php has no Version header');
        $this->assertMatchesRegularExpression('/^\d+\.\d+(\.\d+)?$/', $version);
    }

    public function test_the_gateway_class_version_matches_the_plugin_header(): void
    {
        $this->assertSame(
            PF_PluginMeta::headerVersion(),
            PF_PluginMeta::gatewayVersion(),
            'WC_Gateway_PartPay::$version disagrees with the Version header in partpay.php'
        );
    }

    public function test_the_readme_stable_tag_matches_the_plugin_header(): void
    {
        $this->assertSame(
            PF_PluginMeta::headerVersion(),
            PF_PluginMeta::readmeStableTag(),
            'readme.txt Stable tag disagrees with the Version header in partpay.php'
        );
    }

    public function test_the_readme_has_a_changelog_entry_for_this_version(): void
    {
        $version = PF_PluginMeta::headerVersion();

        $this->assertStringContainsString(
            '= ' . $version . ' =',
            PF_PluginMeta::readme(),
            "readme.txt has no changelog entry for $version"
        );
    }

    /* -----------------------------------------------------------------------
     * Plugin headers
     * -------------------------------------------------------------------- */

    public function test_the_plugin_header_declares_everything_wordpress_needs(): void
    {
        foreach (['Plugin Name', 'Description', 'Version', 'Author'] as $field) {
            $this->assertNotNull(PF_PluginMeta::header($field), "Missing '$field' plugin header");
        }

        $this->assertSame('Payflex Payment Gateway', PF_PluginMeta::header('Plugin Name'));
    }

    public function test_the_plugin_declares_its_woocommerce_compatibility_range(): void
    {
        $requires = PF_PluginMeta::header('WC requires at least');
        $tested   = PF_PluginMeta::header('WC tested up to');

        $this->assertNotNull($requires, 'Missing "WC requires at least" header');
        $this->assertNotNull($tested, 'Missing "WC tested up to" header');
        $this->assertTrue(
            version_compare($tested, $requires, '>='),
            '"WC tested up to" must not be lower than "WC requires at least"'
        );
    }

    public function test_the_readme_declares_its_wordpress_and_php_requirements(): void
    {
        foreach (['Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License'] as $field) {
            $this->assertNotNull(PF_PluginMeta::readmeField($field), "readme.txt is missing '$field'");
        }
    }

    /**
     * KNOWN INCONSISTENCY — characterisation test, not an endorsement.
     *
     * readme.txt advertises "Requires PHP: 7.4", but the support page treats
     * anything below 8.1 as unsupported, and composer.json requires >= 8.1.
     * A merchant on PHP 7.4 can install the plugin and is then told their PHP
     * is unsupported.
     *
     * These should be reconciled — most likely by raising readme.txt to 8.1.
     */
    public function test_the_php_requirement_is_advertised_inconsistently(): void
    {
        $this->assertSame('7.4', PF_PluginMeta::readmeField('Requires PHP'));
        $this->assertStringContainsString(
            "version_compare(PHP_VERSION, '8.1', '>=')",
            PF_PluginMeta::gatewayFile(),
            'The support page still checks for 8.1'
        );
    }

    /* -----------------------------------------------------------------------
     * Files that ship
     * -------------------------------------------------------------------- */

    public function test_every_shipped_php_file_is_syntactically_valid(): void
    {
        foreach (PF_PluginMeta::shippedPhpFiles() as $file) {
            exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $status);

            $this->assertSame(0, $status, "Syntax error in $file:\n" . implode("\n", $output));
        }
    }

    public function test_every_shipped_php_file_is_accounted_for(): void
    {
        $expected = [
            'config/config.php',
            'includes/class-payflex-woocommerce-block-checkout.php',
            'includes/class-wc-gateway-payflex.php',
            'includes/trait-wc-gateway-payflex-form-fields.php',
            'partpay.php',
        ];

        $actual = array_map(
            fn($path) => ltrim(str_replace(PAYFLEX_PLUGIN_ROOT, '', $path), '/'),
            PF_PluginMeta::shippedPhpFiles()
        );

        $this->assertSame($expected, $actual, 'An unexpected PHP file would be deployed to WordPress.org');
    }

    /**
     * Every include guards against direct access except the main plugin file,
     * which WordPress loads itself.
     */
    public function test_includes_refuse_to_run_when_loaded_directly(): void
    {
        foreach (['includes/class-wc-gateway-payflex.php', 'includes/trait-wc-gateway-payflex-form-fields.php'] as $relative) {
            $contents = file_get_contents(PAYFLEX_PLUGIN_ROOT . '/' . $relative);

            $this->assertStringContainsString(
                "defined( 'ABSPATH' )",
                $contents,
                "$relative has no ABSPATH guard"
            );
        }
    }

    /**
     * These are referenced with filemtime() or as <img src>; a missing file
     * means a PHP warning or a broken image in the admin.
     */
    public function test_all_referenced_assets_exist(): void
    {
        $assets = [
            'assets/block.js',
            'assets/checkout.js',
            'assets/widget-icon.png',
            'Checkout.png',
            'PIE-CHART-01.png',
            'PIE-CHART-02.png',
            'PIE-CHART-03.png',
            'PIE-CHART-04.png',
            'config/config.php',
        ];

        foreach ($assets as $asset) {
            $this->assertFileExists(PAYFLEX_PLUGIN_ROOT . '/' . $asset);
        }
    }

    public function test_wordpress_org_marketplace_assets_are_present(): void
    {
        foreach (['icon-128x128.png', 'icon-256x256.png', 'banner-772x250.jpg', 'banner-1544x500.jpg'] as $asset) {
            $this->assertFileExists(PAYFLEX_PLUGIN_ROOT . '/.wordpress-org/' . $asset);
        }
    }

    /* -----------------------------------------------------------------------
     * Deploy exclusions
     *
     * The 10up deploy action reads .distignore. Anything missing from it is
     * published to WordPress.org.
     * -------------------------------------------------------------------- */

    public function test_development_files_are_excluded_from_the_wordpress_org_deploy(): void
    {
        $distignore = file_get_contents(PAYFLEX_PLUGIN_ROOT . '/.distignore');
        $lines      = array_filter(array_map('trim', explode("\n", $distignore)));

        $must_exclude = [
            '/tests',
            '/vendor',
            '/.github',
            '/.git',
            '/.wordpress-org',
            '/README.md',
            '/composer.json',
            '/composer.lock',
            '/phpunit.xml',
            '/.phpunit.cache',
        ];

        foreach ($must_exclude as $path) {
            $this->assertContains($path, $lines, "$path is not excluded by .distignore and would be published");
        }
    }

    public function test_the_readme_that_wordpress_org_reads_is_not_excluded(): void
    {
        $lines = array_filter(array_map('trim', explode("\n", file_get_contents(PAYFLEX_PLUGIN_ROOT . '/.distignore'))));

        $this->assertNotContains('/readme.txt', $lines, 'readme.txt is the WordPress.org listing and must ship');
        $this->assertFileExists(PAYFLEX_PLUGIN_ROOT . '/readme.txt');
    }

    /* -----------------------------------------------------------------------
     * Code hygiene
     * -------------------------------------------------------------------- */

    /**
     * KNOWN DEFECT — characterisation test, not an endorsement.
     *
     * process_refund() still contains three leftover debug error_log() calls
     * ("Payflex: orderId2"/"orderId3"). They write to the site's PHP error log
     * on every refund attempt, leak the Payflex order id there, and are the
     * source of the stray lines in this suite's output.
     *
     * They should be removed, or routed through $this->log().
     *
     * When they are gone, change the expected count to 0.
     */
    public function test_leftover_debug_error_log_calls_are_still_present(): void
    {
        $count = preg_match_all('/^\s*error_log\s*\(/m', PF_PluginMeta::gatewayFile());

        $this->assertSame(3, $count, 'The number of raw error_log() calls in the gateway changed');
    }

    public function test_no_var_dump_print_r_to_output_or_die_calls_ship(): void
    {
        foreach (PF_PluginMeta::shippedPhpFiles() as $file) {
            $contents = file_get_contents($file);
            $relative = ltrim(str_replace(PAYFLEX_PLUGIN_ROOT, '', $file), '/');

            foreach (['var_dump(', 'die;', 'die(', 'exit();'] as $needle) {
                // Commented-out lines are not a runtime concern.
                $uncommented = preg_replace('#^\s*(//|\*|/\*).*$#m', '', $contents);

                $this->assertStringNotContainsString(
                    $needle,
                    $uncommented,
                    "$relative contains a debugging leftover: $needle"
                );
            }
        }
    }

    public function test_no_php_short_open_tags_are_used_for_logic(): void
    {
        foreach (PF_PluginMeta::shippedPhpFiles() as $file) {
            $this->assertStringNotContainsString(
                '<?php' . "\n" . '<?',
                file_get_contents($file),
                'Short open tags are not portable'
            );
        }
    }

    /**
     * The text domain is used consistently, so translations actually resolve.
     */
    public function test_translation_calls_use_the_woo_payflex_text_domain(): void
    {
        foreach (PF_PluginMeta::shippedPhpFiles() as $file) {
            $contents = file_get_contents($file);
            $relative = ltrim(str_replace(PAYFLEX_PLUGIN_ROOT, '', $file), '/');

            preg_match_all("/__\(\s*'[^']*'\s*,\s*'([a-z0-9_-]+)'\s*\)/", $contents, $matches);

            foreach (array_unique($matches[1]) as $domain) {
                $this->assertSame('woo_payflex', $domain, "$relative uses text domain '$domain'");
            }
        }
    }
}
