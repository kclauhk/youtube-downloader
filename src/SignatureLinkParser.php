<?php

namespace YouTube;

use YouTube\Exception\YouTubeException;
use YouTube\Models\StreamFormat;
use YouTube\Responses\PlayerApiResponse;
use YouTube\Responses\VideoPlayerJs;
use YouTube\Utils\Utils;

class SignatureLinkParser
{
    /**
     * @param PlayerApiResponse $apiResponse
     * @param VideoPlayerJs $playerJs
     * @return array
     */
    public static function parseLinks(PlayerApiResponse $apiResponse, VideoPlayerJs &$playerJs): array
    {
        $player_js = $playerJs->getResponseBody();
        $playerUrl = $playerJs->getResponse()->info->url;

        $error403 = 'This URL may yield HTTP 403 Forbidden error.';
        $nDecoder = new NSigDecoder();
        $sDecoder = new SignatureDecoder();
        $nParams = [];
        $signatures = [];
        $decoded_n = [];
        $decoded_s = [];
        $ciphers = [];

        $streamingUrls = $apiResponse->getStreamingUrls();
        foreach (array_filter($streamingUrls) as $u) {
            if (preg_match('/([&\/])n[=\/]([^&\/]+)\1/', $u, $matches)) {
                $nParams[] = $matches[2];
            }
        }

        $adaptive = [];
        $formats_combined = $apiResponse->getAllFormats();
        foreach ($formats_combined as $k => $format) {
            if (isset($format['url'])) {
                // appear as "url"
                $url = $format['url'];
            } else {
                // appear as either "cipher" or "signatureCipher"
                $cipher = Utils::arrayGet($format, 'cipher', Utils::arrayGet($format, 'signatureCipher', ''));

                $cipherArray = Utils::parseQueryString($cipher);

                $url = Utils::arrayGet($cipherArray, 'url');
                $sp = Utils::arrayGet($cipherArray, 'sp');  // used to be 'sig'

                // needs to be deciphered
                if ($signature = Utils::arrayGet($cipherArray, 's')) {
                    $signatures[] = $signature;
                    $ciphers[$k] = [$signature, $sp, $url];
                }
            }
            if (preg_match('/&n=(.*?)&/', ($url ?? ''), $matches)) {
                $nParams[] = $matches[1];
            }

            // download player js if needed
            if (($nParams || $signatures) && !isset($useSolver)) {
                if ($playerJs->getResponse()->info->http_code === null) {
                    try {
                        $player_js = self::getPlayerScript($playerJs);
                    } catch (YouTubeException $e) {
                        $error = "{$e->getMessage()} {$error403}";
                    }
                }
                $useSolver = ($player_js) && !preg_match(NSigDecoder::REGEX_RETURN_CODE, $player_js);
            }

            if (!empty($useSolver)) {
                continue;   // skip the following if JsChallengeSolver will be used
            }

            // don't use JsChallengeSolver (deprecated but kept as fallback)
            $streamUrl = new StreamFormat($format);

            if ($player_js) {
                if (preg_match('/&n=(.*?)&/', ($url ?? ''), $matches)) {
                    // decipher n
                    try {
                        if ((new JsRuntime())->getApp()) {
                            $nParam = $matches[1];

                            if (!array_key_exists($nParam, $decoded_n)) {
                                $decoded_n[$nParam] = $nDecoder->decode($nParam, $player_js);
                            }
                            if ($decoded_n[$nParam] != $nParam) {
                                $url = str_replace('&n=' . $nParam . '&', '&n=' . $decoded_n[$nParam] . '&', $url);
                            }
                        }
                    } catch (YouTubeException $e) {
                        $streamUrl->_error[] = "Unable to decipher n: {$e->getMessage()}. {$error403} (player: {$playerUrl})";
                    }
                }

                if (isset($format['url'])) {
                    // some videos do not need to be deciphered
                    $streamUrl->url = $url;
                } elseif ($signature) {
                    try {
                        $decoded_signature = $sDecoder->decode($signature, $player_js);
                    } catch (YouTubeException $e) {
                        $streamUrl->_error[] = "Unable to decipher s: {$e->getMessage()}. {$error403} (player: {$playerUrl})";
                    }
                    $streamUrl->url = $url . (empty($decoded_signature) ? '' : "&$sp=" . urlencode($decoded_signature));
                } else {
                    continue;
                }
            } else {
                $streamUrl->url = $url;
            }

            if (!empty($error)) {
                $streamUrl->_error[] = $error;
            }

            $adaptive[] = self::detectSR($streamUrl);
        }

        if (!empty($useSolver)) {
            if (($nParams || $signatures) && $player_js) {
                $nParams = array_unique($nParams);
                $signatures = array_unique($signatures);

                try {
                    $solver = new JsChallengeSolver();
                    if ($result = $solver->solve($nParams, $signatures, $player_js)) {
                        $decoded_n = $result[0]['data'];
                        $decoded_s = $result[1]['data'];
                    }
                } catch (YouTubeException $e) {
                    $error = "Unable to solve JS challenges: {$e->getMessage()}. {$error403} (player: {$playerUrl})";
                }
            }

            foreach ($formats_combined as $k => $format) {
                $streamUrl = new StreamFormat($format);

                if (array_key_exists($k, $ciphers)) {
                    if (array_key_exists($ciphers[$k][0], $decoded_s)) {
                        $streamUrl->url = "{$ciphers[$k][2]}&{$ciphers[$k][1]}=" . urlencode($decoded_s[$ciphers[$k][0]]);
                    } else {
                        $streamUrl->url = $ciphers[$k][2];
                        if (!empty($error)) {
                            $streamUrl->_error[] = $error;
                        }
                    }
                } elseif (empty($format['url'])) {
                    continue;
                }

                if (preg_match('/&n=(.*?)&/', ($streamUrl->url ?? ''), $matches)) {
                    if (array_key_exists($matches[1], $decoded_n)) {
                        $streamUrl->url = str_replace(
                            "&n={$matches[1]}&",
                            "&n={$decoded_n[$matches[1]]}&",
                            $streamUrl->url
                        );
                    } elseif (!empty($error)) {
                        $streamUrl->_error[] = $error;
                    }
                }

                $adaptive[] = self::detectSR($streamUrl);
            }

            foreach (array_filter($streamingUrls) as $k => $u) {
                if (preg_match('/([&\/])n[=\/]([^&\/]+)\1/', $u, $matches)) {
                    if (array_key_exists($matches[2], $decoded_n)) {
                        $streamingUrls[$k] = str_replace(
                            $matches[0],
                            ($matches[1] == '&' ? "&n={$decoded_n[$matches[2]]}&" : "/n/{$decoded_n[$matches[2]]}/"),
                            $u
                        );
                    }
                }
            }
        }

        return array_merge(
            ['adaptive' => $adaptive],
            $streamingUrls
        );
    }

    // check whether $format is "super resolution" (AI-upscaled)
    protected static function detectSR(StreamFormat $format): StreamFormat
    {
        if (preg_match('/\Wsr%3D1\W/', $format->url)) {
            $format->isSr = true;
        }

        return $format;
    }

    protected static function getPlayerScript(VideoPlayerJs &$playerJs): string
    {
        if ($playerUrl = $playerJs->getResponse()->info->url) {
            $response = (new Browser())->get($playerUrl);
            $playerJs = new VideoPlayerJs($response);
            if ($playerJs->isStatusOkay()) {
                return $playerJs->getResponseBody();
            } else {
                throw new YouTubeException("Unable to download player script ({$playerUrl}).");
            }
        } else {
            throw new YouTubeException('Player script URL not found.');
        }
    }
}
