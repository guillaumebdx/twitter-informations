<?php

namespace App\Service;

use App\Repository\FluxRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class RssFetcher
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private FluxRepository $fluxRepository
    ) {
    }

    /**
     * Fetch all RSS feeds and return merged content
     * 
     * @return array Array with 'success' feeds and 'errors'
     */
    public function fetchAllFeeds(): array
    {
        $fluxes = $this->fluxRepository->findAllOrderedByCreatedAt();
        $results = [
            'success' => [],
            'errors' => []
        ];

        if (empty($fluxes)) {
            return $results;
        }

        foreach ($fluxes as $flux) {
            try {
                $content = $this->fetchSingleFeed($flux->getUrl());
                $results['success'][] = [
                    'flux' => $flux,
                    'content' => $content
                ];
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'flux' => $flux,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Fetch a single RSS feed
     * 
     * @param string $url
     * @return string
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     */
    private function fetchSingleFeed(string $url): string
    {
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; RSS-Bot/1.0)',
                'Accept' => 'application/rss+xml, application/xml, text/xml, */*'
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \Exception(sprintf('HTTP %d: %s', $response->getStatusCode(), $response->getInfo('response_headers')['status'] ?? 'Unknown error'));
        }

        return $response->getContent();
    }

    /**
     * Merge all successful feed contents into one string
     * 
     * @param array $results Results from fetchAllFeeds()
     * @return string
     */
    public function mergeFeeds(array $results): string
    {
        $mergedContent = '';
        
        foreach ($results['success'] as $feedData) {
            $flux = $feedData['flux'];
            $content = $feedData['content'];
            
            $mergedContent .= str_repeat('=', 80) . "\n";
            $mergedContent .= "FLUX: {$flux->getName()}\n";
            $mergedContent .= "URL: {$flux->getUrl()}\n";
            $mergedContent .= "RÉCUPÉRÉ LE: " . (new \DateTime())->format('d/m/Y H:i:s') . "\n";
            $mergedContent .= str_repeat('=', 80) . "\n\n";
            $mergedContent .= $content . "\n\n";
        }

        return $mergedContent;
    }
}
