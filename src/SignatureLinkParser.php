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

        $s_decoder = new SignatureDecoder();
        $n_decoder = new NSigDecoder();
        $decoded_nsig = array();

        foreach ($formats_combined as $format) {

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
                $signature = Utils::arrayGet($cipherArray, 's');
            }

            $streamUrl = new StreamFormat($format);

            if ($playerJs) {
                if (preg_match('/&n=(.*?)&/', ($url ?? ''), $matches)) {
                    // decrypt nsig
                    try {
                        if ((new JsRuntime())->getApp()) {
                            $n_param = $matches[1];

                            if (!array_key_exists($n_param, $decoded_nsig)) {
                                $decoded_nsig[$n_param] = $n_decoder->decode($n_param, $playerJs->getResponseBody());
                            }
                            if ($decoded_nsig[$n_param] != $n_param) {
                                $url = str_replace('&n=' . $n_param . '&', '&n=' . $decoded_nsig[$n_param] . '&', $url);
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