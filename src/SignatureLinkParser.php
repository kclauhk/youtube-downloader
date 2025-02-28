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
                if (preg_match('/&n=(.*?)&/', $url, $matches)) {
                    // decrypt nsig
                    try {
                        if ((new JsRuntime())->getApp()) {
                            $n_param = $matches[1];

                            if (!array_key_exists($n_param, $decoded_nsig)) {
                                $decoded_nsig[$n_param] = (new NSigDecoder())->decode($n_param, $playerJs->getResponseBody());
                            }
                            if ($decoded_nsig[$n_param] != $n_param) {
                                $url = str_replace('&n=' . $n_param . '&', '&n=' . $decoded_nsig[$n_param] . '&', $url);
                            }
                        }
                    } catch (YouTubeException $e) {
                        $streamUrl->_error = 'Unable to decrypt nsig: ' . $e->getMessage() . '. This URL may yield HTTP Error 403.';
                    }
                }

                if (isset($format['url'])) {
                    // some videos do not need signature decryption
                    $streamUrl->url = $url;
                } else {
                    $decoded_signature = (new SignatureDecoder())->decode($signature, $playerJs->getResponseBody());
                    $decoded_url = $url . '&' . $sp . '=' . urlencode($decoded_signature);

                    $streamUrl->url = $decoded_url;
                }
            } else {
                $streamUrl->url = $url;
            }

            $return[] = $streamUrl;
        }

        return $return;
    }
}