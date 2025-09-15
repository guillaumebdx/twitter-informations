<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class TwitterClient
{
    private const API_BASE_URL = 'https://api.twitter.com/2';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $twitterBearerToken,
        private string $twitterApiKey,
        private string $twitterApiSecret,
        private string $twitterAccessToken,
        private string $twitterAccessTokenSecret
    ) {
    }

    /**
     * Test Twitter API v2 authentication with a simple endpoint that supports Bearer Token
     * 
     * @return array API response data
     * @throws \Exception If API call fails
     */
    public function testAuthentication(): array
    {
        try {
            // Use users/by/username endpoint which supports Application-Only auth
            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/users/by/username/twitter', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->twitterBearerToken,
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'user.fields' => 'id,name,username,public_metrics'
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception(
                    sprintf(
                        'Twitter API v2 error (HTTP %d): %s',
                        $response->getStatusCode(),
                        $response->getContent(false)
                    )
                );
            }

            return $response->toArray();

        } catch (TransportExceptionInterface $e) {
            throw new \Exception('Erreur de transport Twitter API v2: ' . $e->getMessage());
        }
    }

    /**
     * Get authenticated user information
     * 
     * @return array User data
     * @throws \Exception If API call fails
     */
    public function getMe(): array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/users/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->twitterBearerToken,
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'user.fields' => 'id,name,username,description,public_metrics,verified,created_at,location,url,profile_image_url'
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception(
                    sprintf(
                        'Twitter API v2 error (HTTP %d): %s',
                        $response->getStatusCode(),
                        $response->getContent(false)
                    )
                );
            }

            return $response->toArray();

        } catch (TransportExceptionInterface $e) {
            throw new \Exception('Erreur de transport Twitter API v2: ' . $e->getMessage());
        }
    }

    /**
     * Generate OAuth 1.0a signature for Twitter API v2
     */
    private function generateOAuth1Signature(string $method, string $url, array $params): string
    {
        $baseString = $method . '&' . rawurlencode($url) . '&' . rawurlencode(http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        $signingKey = rawurlencode($this->twitterApiSecret) . '&' . rawurlencode($this->twitterAccessTokenSecret);
        return base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));
    }

    /**
     * Generate OAuth 1.0a authorization header for Twitter API v2
     */
    private function generateOAuth1Header(string $method, string $url, array $additionalParams = []): string
    {
        $oauthParams = [
            'oauth_consumer_key' => $this->twitterApiKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time(),
            'oauth_token' => $this->twitterAccessToken,
            'oauth_version' => '1.0'
        ];

        $allParams = array_merge($oauthParams, $additionalParams);
        ksort($allParams);

        $oauthParams['oauth_signature'] = $this->generateOAuth1Signature($method, $url, $allParams);

        $headerParts = [];
        foreach ($oauthParams as $key => $value) {
            $headerParts[] = rawurlencode($key) . '="' . rawurlencode($value) . '"';
        }

        return 'OAuth ' . implode(', ', $headerParts);
    }

    /**
     * Post a tweet using OAuth 1.0a User Context
     * 
     * @param string $text Tweet text content
     * @return array API response data
     * @throws \Exception If API call fails
     */
    public function postTweet(string $text): array
    {
        try {
            $url = self::API_BASE_URL . '/tweets';
            $authHeader = $this->generateOAuth1Header('POST', $url);

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => $authHeader,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'text' => $text
                ]
            ]);

            if ($response->getStatusCode() !== 201) {
                throw new \Exception(
                    sprintf(
                        'Twitter API v2 error (HTTP %d): %s',
                        $response->getStatusCode(),
                        $response->getContent(false)
                    )
                );
            }

            return $response->toArray();

        } catch (TransportExceptionInterface $e) {
            throw new \Exception('Erreur de transport Twitter API v2: ' . $e->getMessage());
        }
    }
}
