<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;

class InnerTubeClients
{
    public static array $clients;

    function __construct()
    {
        // InnerTube Clients
        // list of known clients: https://github.com/zerodytrash/YouTube-Internal-Clients
        static::$clients = static::$clients ?? [
            "android_vr" => [
                "context" => [
                    "client" => [
                        "androidSdkVersion" => 32,
                        "clientName" => "ANDROID_VR",
                        "clientVersion" => "1.60.19",
                        "deviceMake" => "Oculus",
                        "deviceModel" => "Quest 3",
                        "osName" => "Android",
                        "osVersion" => "12L",
                        "userAgent" => "com.google.android.apps.youtube.vr.oculus/1.60.19 (Linux; U; Android 12L; eureka-user Build/SQ3A.220605.009.A1) gzip",
                    ],
                ],
            ],
            // "android" client is broken and links expire after 30s
            "android" => [
                "context" => [
                    "client" => [
                        "androidSdkVersion" => 30,
                        "clientName" => "ANDROID",
                        "clientVersion" => "19.44.38",
                        "osName" => "Android",
                        "osVersion" => "11",
                        "userAgent" => "com.google.android.youtube/19.44.38 (Linux; U; Android 11) gzip",
                    ],
                ],
            ],
            "ios" => [      // upto 4K resolution (itag 401)
                "context" => [
                    "client" => [
                        "clientName" => "IOS",
                        "clientVersion" => "20.03.02",
                        "deviceMake" => "Apple",
                        "deviceModel" => "iPhone16,2",
                        "osName" => "iPhone",
                        "osVersion" => "18.2.1.22C161",
                        "userAgent" => "com.google.ios.youtube/20.03.02 (iPhone16,2; U; CPU iOS 18_2_1 like Mac OS X;)",
                    ],
                ],
            ],
            "tv" => [       // "tv" client requires nsig
                "context" => [
                    "client" => [
                        "clientName" => "TVHTML5",
                        "clientVersion" => "7.20250219.14.00",
                        "userAgent" => "Mozilla/5.0 (ChromiumStylePlatform) Cobalt/Version,gzip(gfe)",
                    ],
                ],
                "config_url" => "https://www.youtube.com/tv",
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
