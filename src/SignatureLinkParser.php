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
     * @param VideoPlayerJs|null $playerJs
     * @return StreamFormat[]
     */
    public static function parseLinks(PlayerApiResponse $apiResponse, ?VideoPlayerJs $playerJs = null): array
    {
        $formats_combined = $apiResponse->getAllFormats();

        // final response
        $return = array();

        $n_decoder = new NSigDecoder();
        $s_decoder = new SignatureDecoder();
        $decoded_n = array();
        $decoded_s = array();
        $ciphers = array();
        $n_params = array();
        $signatures = array();

        if (preg_match($n_decoder::REGEX_RETURN_CODE, $playerJs->getResponseBody())) {
            $use_solver = false;
        } else {
            $use_solver = true;
        }

        foreach ($formats_combined as $k=>$format) {
            if (isset($format['url'])) {
                // appear as "url"
                $url = $format['url'];
            } else {
                // appear as either "cipher" or "signatureCipher"
                $cipher = Utils::arrayGet($format, 'cipher', Utils::arrayGet($format, 'signatureCipher', ''));

                $cipherArray = Utils::parseQueryString($cipher);

                // contains ?ip noting which IP can access it, and ?expire containing link expiration timestamp
                $url = Utils::arrayGet($cipherArray, 'url');
                $sp = Utils::arrayGet($cipherArray, 'sp'); // used to be 'sig'

                // needs to be decrypted!
                if ($signature = Utils::arrayGet($cipherArray, 's')) {
                    $signatures[] = $signature;
                    $ciphers[$k] = [$signature, $sp, $url];
                }
            }

            if ($use_solver) {
                if (preg_match('/&n=(.*?)&/', ($url ?? ''), $matches)) {
                    $n_params[] = $matches[1];
                }
                continue;
            }

            // don't use JsChallengeSolver (deprecated but kept as fallback)
            $streamUrl = new StreamFormat($format);

            if ($playerJs) {
                if (preg_match('/&n=(.*?)&/', ($url ?? ''), $matches)) {
                    // decrypt n
                    try {
                        if ((new JsRuntime())->getApp()) {
                            $n_param = $matches[1];

                            if (!array_key_exists($n_param, $decoded_n)) {
                                $decoded_n[$n_param] = $n_decoder->decode($n_param, $playerJs->getResponseBody());
                            }
                            if ($decoded_n[$n_param] != $n_param) {
                                $url = str_replace('&n=' . $n_param . '&', '&n=' . $decoded_n[$n_param] . '&', $url);
                            }
                        }
                    } catch (YouTubeException $e) {
                        $streamUrl->_error[] = "Unable to decrypt nsig: {$e->getMessage()}. This URL may yield HTTP 403 Forbidden error. (player: {$playerJs->getResponse()->info->url})";
                    }
                }

                if (isset($format['url'])) {
                    // some videos do not need signature decryption
                    $streamUrl->url = $url;
                } elseif ($signature) {
                    try {
                        $decoded_signature = $s_decoder->decode($signature, $playerJs->getResponseBody());
                    } catch (YouTubeException $e) {
                        $streamUrl->_error[] = "Unable to decrypt signature: {$e->getMessage()}. This URL may yield HTTP 403 Forbidden error. (player: {$playerJs->getResponse()->info->url})";
                    }
                    $streamUrl->url = $url . (empty($decoded_signature) ? '' : "&$sp=" . urlencode($decoded_signature));
                } else {
                    continue;
                }
            } else {
                $streamUrl->url = $url;
            }

            $return[] = self::detectSR($streamUrl);
        }

        if ($use_solver) {
            $error = null;

            if (($n_params || $signatures) && $playerJs) {
                $n_params = array_unique($n_params);
                $signatures = array_unique($signatures);

                try {
                    if ((new JsRuntime())->getApp()) {
                        $solver = new JsChallengeSolver();
                        if ($result = $solver->solve($n_params, $signatures, $playerJs->getResponseBody())) {
                            $decoded_n = $result[0]['data'];
                            $decoded_s = $result[1]['data'];
                        }
                    }
                } catch (YouTubeException $e) {
                    $error = "Unable to solve JS challenges: {$e->getMessage()}. This URL may yield HTTP 403 Forbidden error. (player: {$playerJs->getResponse()->info->url})";
                }
            }

            foreach ($formats_combined as $k=>$format) {
                $streamUrl = new StreamFormat($format);

                if (array_key_exists($k, $ciphers)) {
                    if (array_key_exists($ciphers[$k][0], $decoded_s)) {
                        $streamUrl->url = "{$ciphers[$k][2]}&{$ciphers[$k][1]}=" . urlencode($decoded_s[$ciphers[$k][0]]);
                    } else {
                        $streamUrl->url = $ciphers[$k][2];
                        if ($error) {
                            $streamUrl->_error[] = $error;
                        }
                    }
                } elseif (empty($format['url'])) {
                    continue;
                }

                if (preg_match('/&n=(.*?)&/', ($streamUrl->url ?? ''), $matches)) {
                    if (array_key_exists($matches[1], $decoded_n)) {
                        $streamUrl->url = str_replace("&n={$matches[1]}&", "&n={$decoded_n[$matches[1]]}&", $streamUrl->url);
                    } elseif ($error) {
                        $streamUrl->_error[] = $error;
                    }
                }

                $return[] = self::detectSR($streamUrl);
            }
        }

        return $return;
    }

    // check whether $format is "super resolution" (AI-upscaled)
    protected static function detectSR(StreamFormat $format): StreamFormat
    {
        if (preg_match('/\Wsr%3D1\W/', $format->url)) {
            $format->isSr = true;
        }

        return $format;
    }
}