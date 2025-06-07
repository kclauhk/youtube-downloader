<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;

class PlayerApiClients
{
    public static array $clients;

    function __construct()
    {
        // InnerTube Clients
        // list of known clients: https://github.com/zerodytrash/YouTube-Internal-Clients
        static::$clients = static::$clients ?? [
            'android_vr' => [
                'context' => [
                    'client' => [
                        'clientName' => 'ANDROID_VR',
                        'clientVersion' => '1.62.27',
                        'deviceMake' => 'Oculus',
                        'deviceModel' => 'Quest 3',
                        'androidSdkVersion' => 32,
                        'userAgent' => 'com.google.android.apps.youtube.vr.oculus/1.62.27 (Linux; U; Android 12L; eureka-user Build/SQ3A.220605.009.A1) gzip',
                        'osName' => 'Android',
                        'osVersion' => '12L',
                    ],
                ],
                'client_name' => 28,
            ],
            // "android" client is broken and links expire after 30s
            'android' => [
                'context' => [
                    'client' => [
                        'clientName' => 'ANDROID',
                        'clientVersion' => '20.10.38',
                        'androidSdkVersion' => 30,
                        'userAgent' => 'com.google.android.youtube/20.10.38 (Linux; U; Android 11) gzip',
                        'osName' => 'Android',
                        'osVersion' => '11',
                    ],
                ],
                'client_name' => 3,
            ],
            'ios' => [
                'context' => [
                    'client' => [
                        'clientName' => 'IOS',
                        'clientVersion' => '20.10.4',
                        'deviceMake' => 'Apple',
                        'deviceModel' => 'iPhone16,2',
                        'userAgent' => 'com.google.ios.youtube/20.10.4 (iPhone16,2; U; CPU iOS 18_3_2 like Mac OS X;)',
                        'osName' => 'iPhone',
                        'osVersion' => '18.3.2.22D82',
                    ],
                ],
                'client_name' => 5,
            ],
            'tv' => [       // "tv" client requires nsig
                'context' => [
                    'client' => [
                        'clientName' => 'TVHTML5',
                        'clientVersion' => '7.20250326.09.00',
                        'userAgent' => 'Mozilla/5.0 (ChromiumStylePlatform) Cobalt/Version',
                    ],
                ],
                'client_name' => 7,
                'config_url' => 'https://www.youtube.com/tv',
            ],
        ];
    }

    /**
     * @param string $client_id
     * @param array $config_data
     * @param string $config_url    (optional) the URL of client config
     * @return boolean
     */
    public function setClient(string $client_id, array $config_data, string $config_url = null): bool
    {
        $has_name = array_key_exists('clientName', $config_data);
        $has_ver = array_key_exists('clientVersion', $config_data);

        if (!$has_name || !$has_ver) {
            throw new YouTubeException('Invalid client context: ' . ($has_name ? '"clientVersion"' : '"clientName"') . ' is missing');
            return false;
        }

        static::$clients[$client_id] = ['context' => ['client' => $config_data]];

        if (!empty($config_url)) {
            $url = filter_var($url, FILTER_SANITIZE_URL);
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                static::$clients[$client_id]['config_url'] = $url;
            }
        }

        return true;
    }
}
