<?php

namespace YouTube\Models;

class YouTubeConfigData extends JsonObject
{
    public function getGoogleVisitorId(): ?string
    {
        return $this->deepGet('VISITOR_DATA');
    }

    public function getClientName(): ?string
    {
        return $this->deepGet('INNERTUBE_CONTEXT_CLIENT_NAME');
    }

    public function getClientVersion(): ?string
    {
        return $this->deepGet('INNERTUBE_CONTEXT_CLIENT_VERSION');
    }

    public function getApiKey(): ?string
    {
        return $this->deepGet('INNERTUBE_API_KEY');
    }

    public function getSignatureTimestamp(): ?string
    {
        return $this->deepGet('STS');
    }

    public function getContext(): ?array
    {
        return $this->deepGet('INNERTUBE_CONTEXT');
    }

    public function getDelegatedSessionId(): ?string
    {
        return $this->deepGet('DELEGATED_SESSION_ID');
    }

    public function getSessionIndex(): ?string
    {
        return $this->deepGet('SESSION_INDEX');
    }

    public function getUserSessionId(): ?string
    {
        return $this->deepGet('USER_SESSION_ID');
    }
}