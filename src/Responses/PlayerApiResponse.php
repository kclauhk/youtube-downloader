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

        return array_merge((array) $formats, (array) $adaptiveFormats);
    }

    public function getStreamingUrls(): array
    {
        return [
            'dash' => $this->query('streamingData.dashManifestUrl'),
            'hls' => $this->query('streamingData.hlsManifestUrl'),
            'sabr' => $this->query('streamingData.serverAbrStreamingUrl'),
        ];
    }

    public function getCaptionTracks(): array
    {
        return (array) $this->query('captions.playerCaptionsTracklistRenderer.captionTracks');
    }

    public function getPlayabilityStatusReason(): ?string
    {
        return $this->query('playabilityStatus.reason') ?: implode(' ', $this->query('playabilityStatus.messages') ?? []);
    }

    public function getErrorMessage(): ?string
    {
        return $this->query('error.message');
    }
}
