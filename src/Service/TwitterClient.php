<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class TwitterClient
{
    private const API_BASE_URL = 'https://api.twitter.com/2';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $twitterBearerToken
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
}
