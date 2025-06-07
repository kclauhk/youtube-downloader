<?php

namespace YouTube\Responses;

use YouTube\Utils\Utils;

/**
 * Response from: /youtubei/v1/player
 */
class PlayerApiResponse extends HttpResponse
{
    /**
     * @param string $key
     * @return array|mixed|null
     */
    protected function query(string $key)
    {
        return Utils::arrayGet($this->getJson(), $key);
    }

    public function getAllFormats(): array
    {
        // where both video and audio tracks are combined
        $formats = $this->query('streamingData.formats');

        // video only or audio only streams
        $adaptiveFormats = $this->query('streamingData.adaptiveFormats');

        return array_merge((array)$formats, (array)$adaptiveFormats);
    }

    public function getHlsManifestUrl(): ?string
    {
        return $this->query('streamingData.hlsManifestUrl');
    }

    public function getCaptionTracks(): array
    {
        return (array)$this->query('captions.playerCaptionsTracklistRenderer.captionTracks');
    }
}