<?php

/**
 * Reads the plugin's declared metadata straight from source, so tests can check
 * that the three places a version number lives all agree.
 */
final class PF_PluginMeta
{
    public static function mainFile(): string
    {
        return file_get_contents(PAYFLEX_PLUGIN_ROOT . '/partpay.php');
    }

    public static function gatewayFile(): string
    {
        return file_get_contents(PAYFLEX_PLUGIN_ROOT . '/includes/class-wc-gateway-payflex.php');
    }

    public static function readme(): string
    {
        return file_get_contents(PAYFLEX_PLUGIN_ROOT . '/readme.txt');
    }

    /** The `Version:` field of the plugin header in partpay.php. */
    public static function headerVersion(): ?string
    {
        return self::match('/^\s*\*?\s*Version:\s*(\S+)/mi', self::mainFile());
    }

    /** The private $version property on WC_Gateway_PartPay. */
    public static function gatewayVersion(): ?string
    {
        return self::match('/private\s+\$version\s*=\s*[\'"]([^\'"]+)[\'"]/', self::gatewayFile());
    }

    /** The `Stable tag:` field of readme.txt. */
    public static function readmeStableTag(): ?string
    {
        return self::match('/^Stable tag:\s*(\S+)/mi', self::readme());
    }

    /** Any plugin-header field from partpay.php, e.g. 'Plugin Name'. */
    public static function header(string $field): ?string
    {
        return self::match('/^\s*\*?\s*' . preg_quote($field, '/') . ':\s*(.+)$/mi', self::mainFile());
    }

    /** Any readme.txt header field, e.g. 'Requires PHP'. */
    public static function readmeField(string $field): ?string
    {
        return self::match('/^' . preg_quote($field, '/') . ':\s*(.+)$/mi', self::readme());
    }

    /** Every PHP file that ships in the plugin (tests and vendor excluded). */
    public static function shippedPhpFiles(): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(PAYFLEX_PLUGIN_ROOT, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if ($file->getExtension() !== 'php') continue;
            if (preg_match('#/(vendor|tests|node_modules|\.git)/#', $path)) continue;

            $files[] = $path;
        }

        sort($files);
        return $files;
    }

    private static function match(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $m) ? trim($m[1]) : null;
    }
}
