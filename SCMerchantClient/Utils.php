<?php
/**
 * SpectroCoin Module
 *
 * Copyright (C) 2014-2025 SpectroCoin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software Foundation,
 * Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @author SpectroCoin
 * @copyright 2014-2025 SpectroCoin
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */
declare(strict_types=1);

namespace SpectroCoin\SCMerchantClient;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Utils
{
    /**
     * Get the plugin folder name.
     *
     * @return string The plugin folder name
     */
    public static function getPluginFolderName(): string
    {
        return basename(dirname(__FILE__));
    }

    /**
     * Formats currency amount with '0.0#######' format.
     *
     * @param mixed $amount The amount to format
     * @return string The formatted currency amount
     */
    public static function formatCurrency($amount): string
    {
        $decimals = strlen(substr(strrchr(rtrim(sprintf('%.8f', $amount), '0'), '.'), 1));
        $decimals = $decimals < 1 ? 1 : $decimals;
        return number_format((float) $amount, $decimals, '.', '');
    }

    /**
     * Encrypts the given data using the given encryption key.
     *
     * @param string $data The data to encrypt
     * @param string $encryptionKey The encryption key to use
     * @return string The encrypted data
     */
    public static function encryptAuthData(string $data, string $encryptionKey): string
    {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encryptedData = openssl_encrypt($data, 'aes-256-cbc', $encryptionKey, 0, $iv);
        return base64_encode($encryptedData . '::' . $iv);
    }

    /**
     * Decrypts the given encrypted data using the given encryption key.
     *
     * @param string $encryptedDataWithIv The encrypted data to decrypt
     * @param string $encryptionKey The encryption key to use
     * @return string The decrypted data
     */
    public static function decryptAuthData(string $encryptedDataWithIv, string $encryptionKey): string
    {
        list($encryptedData, $iv) = explode('::', base64_decode($encryptedDataWithIv), 2);
        return openssl_decrypt($encryptedData, 'aes-256-cbc', $encryptionKey, 0, $iv);
    }

    /**
     * Generates a random 128-bit secret key for AES-128-CBC encryption.
     *
     * @return string The generated secret key encoded in base64
     */
    public static function generateEncryptionKey(): string
    {
        $key = openssl_random_pseudo_bytes(32);
        return base64_encode($key);
    }

    /**
     * Sanitize float values.
     *
     * @param mixed $value The value to sanitize
     * @return float|null The sanitized float value or `null` if the sanitization fails
     */
    public static function sanitizeFloat($value): ?float
    {
        if ($value === null) {
            return null;
        }
        $sanitized = filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        return $sanitized === false ? null : (float) $sanitized;
    }

    /**
     * Sanitize URL values.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function sanitizeUrl($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $sanitized = filter_var($value, FILTER_SANITIZE_URL);
        return $sanitized === false ? null : $sanitized;
    }

    public static function generateRandomStr($length): string
    {
        $random_str = substr(md5((string) rand(1, pow(2, 16))), 0, $length);

        return $random_str;
    }

    /**
     * 1. Checks for invalid UTF-8 characters
     * 2. Converts single less-than characters (<) to entities
     * 3. Strips all HTML and PHP tags
     * 4. Removes line breaks, tabs, and extra whitespace
     * 5. Strips percent-encoded characters
     * 6. Removes any remaining invalid UTF-8 characters
     *
     * @param string $str The text to be sanitized
     * @return string The sanitized text
     */
    public static function sanitize_text_field(string $str): string
    {
        $str = mb_check_encoding($str, 'UTF-8') ? $str : '';
        $str = preg_replace('/<(?=[^a-zA-Z\/\?\!\%])/u', '&lt;', $str);
        $str = strip_tags($str);
        $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
        $str = trim($str);
        $str = preg_replace('/%[a-f0-9]{2}/i', '', $str);
        $str = preg_replace('/[^\x20-\x7E]/', '', $str);
        return $str;
    }
}
