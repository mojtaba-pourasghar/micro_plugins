<?php
/**
 * License check (RTL-Theme). This file is meant to be protected (encoded).
 *
 * It runs the RTL-Theme license verification and, only when the license is
 * active, unlocks Micro Sync Pro. Without an active license the plugin stays
 * disabled and shows an admin notice.
 *
 * @package MicroStudio\MicroSyncPro
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable

// --------------------------------------------------------------------------------------------------- Start RTL License

$rtlLicenseClassName = 'RTL_License_1f777b937ec5d599';

if (
    class_exists($rtlLicenseClassName, false)
    && method_exists($rtlLicenseClassName, 'isActive')
) {
    try {
        $rtlLicenseReflection = new \ReflectionClass($rtlLicenseClassName);
        $rtlLicenseFilePath   = $rtlLicenseReflection->getFileName();

        if (is_string($rtlLicenseFilePath) && is_file($rtlLicenseFilePath)) {
            $rtlLicenseFileHash = @sha1_file($rtlLicenseFilePath);

            if (
                is_string($rtlLicenseFileHash)
                && hash_equals('f8639319caaa50966185bf6730ce5eacfa432ad4', $rtlLicenseFileHash)
            ) {
                $rtlLicenseClass = new $rtlLicenseClassName();

                if ($rtlLicenseClass->{'isActive'}() === true) {
                    // لایسنس فعال است؛ کد امکانات لایسنس‌دار را اینجا قرار دهید.
                    \MicroStudio\MicroSyncPro\Plugin::instance()->unlock( '003c2d08bf4929e5ab5b32220999e66b985c4ae6' );
                }
            }
        }
    } catch (\ReflectionException $e) {
        // بررسی لایسنس ناموفق بود.
    }
}

// ----------------------------------------------------------------------------------------------------- End RTL License

// phpcs:enable
