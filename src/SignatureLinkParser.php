<?php

namespace YouTube;

use YouTube\Models\StreamFormat;
use YouTube\Responses\PlayerApiResponse;
use YouTube\Responses\VideoPlayerJs;
use YouTube\Utils\Utils;

class SignatureLinkParser
{
    /**
     * @param PlayerApiResponse $apiResponse
     * @param VideoPlayerJs|null $playerJs
     * @return array
     */
    public static function parseLinks(PlayerApiResponse $apiResponse, ?VideoPlayerJs $playerJs = null): array
    {
        $formats_combined = $apiResponse->getAllFormats();

        $adaptive = [];

        foreach ($formats_combined as $format) {
            // some videos do not need to be deciphered
            if (isset($format['url'])) {
                $adaptive[] = new StreamFormat($format);
                continue;
            }

            continue;   // skip the following because player JS cannot be used without JS runtime

            // appear as either "cipher" or "signatureCipher"
            $cipher = Utils::arrayGet($format, 'cipher', Utils::arrayGet($format, 'signatureCipher', ''));

            $cipherArray = Utils::parseQueryString($cipher);

            $url = Utils::arrayGet($cipherArray, 'url');
            $sp = Utils::arrayGet($cipherArray, 'sp');  // used to be 'sig'

            // needs to be deciphered
            $signature = Utils::arrayGet($cipherArray, 's');

            $streamUrl = new StreamFormat($format);

            if ($signature && $playerJs) {
                $decoded_signature = (new SignatureDecoder())->decode($signature, $playerJs->getResponseBody());
                $decoded_url = $url . '&' . $sp . '=' . $decoded_signature;

                $streamUrl->url = $decoded_url;
            } else {
                $streamUrl->url = $url;
            }

            $adaptive[] = self::detectSR($streamUrl);
        }

        return array_merge(
            ['adaptive' => $adaptive],
            $apiResponse->getStreamingUrls(),
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
}
