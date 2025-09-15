<?php

namespace App\Service\LLM;

use App\Entity\Info;
use App\Repository\InfoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class RssSummarizer
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private InfoRepository $infoRepository,
        private string $openaiApiKey
    ) {
    }

    /**
     * Process RSS feeds with OpenAI to select and create the most interesting Info
     * 
     * @param array $feedResults Results from RssFetcher::fetchAllFeeds()
     * @return Info|null The created Info entity or null if failed
     */
    public function processFeeds(array $feedResults): ?Info
    {
        if (empty($feedResults['success'])) {
            return null;
        }

        // Prepare content for OpenAI including latest infos for duplicate detection
        $feedContent = $this->prepareFeedContent($feedResults['success']);
        
        if (empty($feedContent)) {
            return null;
        }

        try {
            // Call OpenAI API
            $response = $this->callOpenAI($feedContent);
            if (!$response) {
                throw new \Exception("Échec de l'appel à OpenAI");
            }

            // Check for duplicates before creating the Info (only URL check now, content similarity handled by LLM)
            if ($this->isDuplicate($response)) {
                throw new \Exception("Cette information a déjà été traitée récemment (URL identique détectée)");
            }

            // Create and persist Info entity
            $info = $this->createInfoFromResponse($response);
            if (!$info) {
                throw new \Exception("Impossible de créer l'entité Info à partir de la réponse OpenAI");
            }

            $this->entityManager->persist($info);
            $this->entityManager->flush();

            return $info;
            
        } catch (\Exception $e) {
            throw new \RuntimeException('Erreur lors du traitement OpenAI: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Prepare feed content for OpenAI processing
     */
    private function prepareFeedContent(array $successFeeds): string
    {
        $content = "Voici plusieurs flux RSS récupérés. Analyse-les et sélectionne l'information la plus intéressante:\n\n";
        
        // Add latest 10 infos to avoid duplicates
        $latestInfos = $this->infoRepository->findLatest(10);
        if (!empty($latestInfos)) {
            $content .= "=== INFOS DÉJÀ PUBLIÉES (À ÉVITER) ===\n";
            $content .= "ATTENTION: Ne sélectionne PAS d'info similaire ou identique à celles-ci:\n\n";
            
            foreach ($latestInfos as $index => $info) {
                $content .= "Info " . ($index + 1) . ":\n";
                $content .= "- Description: " . $info->getDescription() . "\n";
                $content .= "- URL: " . $info->getUrl() . "\n";
                $content .= "- Date: " . $info->getCreatedAt()->format('Y-m-d H:i') . "\n\n";
            }
            
            $content .= "=== FIN DES INFOS À ÉVITER ===\n\n";
        }
        
        $content .= "=== NOUVEAUX FLUX RSS À ANALYSER ===\n\n";
        
        foreach ($successFeeds as $index => $feedData) {
            $flux = $feedData['flux'];
            $xmlContent = $feedData['content'];
            
            // Parser le flux RSS et extraire seulement les derniers articles
            $parsedItems = $this->parseRssAndGetLatestItems($xmlContent, 3); // Max 3 items par flux
            
            $content .= "=== FLUX " . ($index + 1) . ": {$flux->getName()} ===\n";
            $content .= "Source: {$flux->getUrl()}\n";
            $content .= "Derniers articles:\n";
            
            foreach ($parsedItems as $itemIndex => $item) {
                $content .= "Article " . ($itemIndex + 1) . ":\n";
                $content .= "- Titre: " . ($item['title'] ?? 'Non défini') . "\n";
                $content .= "- Description: " . ($item['description'] ?? 'Non définie') . "\n";
                $content .= "- Lien: " . ($item['link'] ?? 'Non défini') . "\n";
                $content .= "- Date: " . ($item['pubDate'] ?? 'Non définie') . "\n";
                if (!empty($item['imageUrl'])) {
                    $content .= "- Image: " . $item['imageUrl'] . "\n";
                }
                $content .= "\n";
            }
            
            $content .= "\n";
        }

        return $content;
    }

    /**
     * Parse RSS content and extract latest items - XML agnostic approach
     */
    private function parseRssAndGetLatestItems(string $xmlContent, int $maxItems = 3): array
    {
        try {
            // Nettoyer le XML et supprimer les caractères problématiques
            $xmlContent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $xmlContent);
            
            $xml = new \SimpleXMLElement($xmlContent);
            
            // Approche agnostique : chercher tous les éléments qui ressemblent à des items
            $allItems = $this->findItemsRecursively($xml);
            
            // Limiter au nombre max d'items et extraire le contenu de manière générique
            $items = [];
            $count = 0;
            
            foreach ($allItems as $item) {
                if ($count >= $maxItems) break;
                
                $parsedItem = $this->extractItemContent($item);
                if (!empty($parsedItem['title']) || !empty($parsedItem['description'])) {
                    $items[] = $parsedItem;
                    $count++;
                }
            }
            
            return $items;
            
        } catch (\Exception $e) {
            // En cas d'erreur de parsing, retourner un contenu tronqué simple
            $truncated = strlen($xmlContent) > 1000 ? substr($xmlContent, 0, 1000) . '...' : $xmlContent;
            return [[
                'title' => 'Contenu XML brut (parsing failed)',
                'description' => $truncated,
                'link' => '',
                'pubDate' => ''
            ]];
        }
    }

    /**
     * Find all potential item elements recursively in XML
     */
    private function findItemsRecursively(\SimpleXMLElement $xml): array
    {
        $items = [];
        
        // Noms d'éléments qui peuvent contenir des articles
        $itemNames = ['item', 'entry'];
        
        foreach ($itemNames as $itemName) {
            // Chercher directement
            if (isset($xml->$itemName)) {
                foreach ($xml->$itemName as $item) {
                    $items[] = $item;
                }
            }
            
            // Chercher dans channel (RSS)
            if (isset($xml->channel->$itemName)) {
                foreach ($xml->channel->$itemName as $item) {
                    $items[] = $item;
                }
            }
            
            // Chercher dans feed (Atom)
            if (isset($xml->feed->$itemName)) {
                foreach ($xml->feed->$itemName as $item) {
                    $items[] = $item;
                }
            }
        }
        
        // Si aucun item trouvé avec les noms standards, chercher récursivement
        if (empty($items)) {
            $items = $this->searchItemsInChildren($xml);
        }
        
        return $items;
    }

    /**
     * Search for items in all children recursively
     */
    private function searchItemsInChildren(\SimpleXMLElement $element): array
    {
        $items = [];
        
        foreach ($element->children() as $child) {
            $childName = $child->getName();
            
            // Si l'élément a des enfants qui ressemblent à du contenu d'article
            if ($this->looksLikeArticle($child)) {
                $items[] = $child;
            } else {
                // Chercher récursivement
                $items = array_merge($items, $this->searchItemsInChildren($child));
            }
        }
        
        return $items;
    }

    /**
     * Check if an XML element looks like an article/item
     */
    private function looksLikeArticle(\SimpleXMLElement $element): bool
    {
        $children = $element->children();
        $childNames = [];
        
        foreach ($children as $child) {
            $childNames[] = strtolower($child->getName());
        }
        
        // Un élément ressemble à un article s'il contient des champs typiques
        $articleFields = ['title', 'description', 'summary', 'content', 'link', 'url', 'pubdate', 'published', 'updated', 'date'];
        $matches = array_intersect($childNames, $articleFields);
        
        return count($matches) >= 2; // Au moins 2 champs typiques
    }

    /**
     * Extract content from an item element generically
     */
    private function extractItemContent(\SimpleXMLElement $item): array
    {
        $parsedItem = [
            'title' => '',
            'description' => '',
            'link' => '',
            'pubDate' => ''
        ];
        
        // Extraire le titre de manière générique
        $titleFields = ['title'];
        foreach ($titleFields as $field) {
            if (isset($item->$field) && !empty((string)$item->$field)) {
                $parsedItem['title'] = trim((string)$item->$field);
                break;
            }
        }
        
        // Extraire la description de manière générique
        $descriptionFields = ['description', 'summary', 'content'];
        foreach ($descriptionFields as $field) {
            if (isset($item->$field) && !empty((string)$item->$field)) {
                $description = trim(strip_tags((string)$item->$field));
                $parsedItem['description'] = strlen($description) > 300 ? 
                    substr($description, 0, 300) . '...' : $description;
                break;
            }
        }
        
        // Extraire le lien de manière générique
        $linkFields = ['link', 'url', 'guid'];
        foreach ($linkFields as $field) {
            if (isset($item->$field)) {
                if (is_object($item->$field) && isset($item->$field['href'])) {
                    $parsedItem['link'] = (string)$item->$field['href'];
                } elseif (!empty((string)$item->$field)) {
                    $parsedItem['link'] = (string)$item->$field;
                }
                if (!empty($parsedItem['link'])) break;
            }
        }
        
        // Extraire la date de manière générique
        $dateFields = ['pubDate', 'published', 'updated', 'date', 'lastBuildDate'];
        foreach ($dateFields as $field) {
            if (isset($item->$field) && !empty((string)$item->$field)) {
                $parsedItem['pubDate'] = (string)$item->$field;
                break;
            }
        }
        
        // Extraire l'image de manière générique
        $parsedItem['imageUrl'] = '';
        $imageFields = ['enclosure', 'media:content', 'media:thumbnail', 'image'];
        foreach ($imageFields as $field) {
            if (isset($item->$field)) {
                if (is_object($item->$field)) {
                    // Gérer les attributs comme enclosure[url] ou media:content[url]
                    if (isset($item->$field['url'])) {
                        $parsedItem['imageUrl'] = (string)$item->$field['url'];
                    } elseif (isset($item->$field['href'])) {
                        $parsedItem['imageUrl'] = (string)$item->$field['href'];
                    }
                } elseif (!empty((string)$item->$field)) {
                    $parsedItem['imageUrl'] = (string)$item->$field;
                }
                if (!empty($parsedItem['imageUrl'])) break;
            }
        }
        
        return $parsedItem;
    }

    /**
     * Call OpenAI API to analyze feeds
     */
    private function callOpenAI(string $content): ?array
    {
        $prompt = $content . "\n\n" . 
            "🎯 MISSION : Tu es un éditeur expert en veille informationnelle pour Twitter.\n\n" .
            "🚫 RÈGLE ABSOLUE ANTI-DOUBLON :\n" .
            "- Si tu vois des infos déjà publiées ci-dessus, tu dois les ÉVITER ABSOLUMENT\n" .
            "- Ne sélectionne JAMAIS une info similaire ou sur le même sujet qu'une info déjà publiée\n" .
            "- Si TOUTES les infos des flux RSS sont similaires aux infos déjà publiées, réponds avec null\n\n" .
            "🎨 RÈGLE DE CRÉATIVITÉ ABSOLUE :\n" .
            "- VARIE TOUJOURS tes emojis et mots d'accroche\n" .
            "- N'utilise JAMAIS le même pattern que les infos déjà publiées\n" .
            "- Sois ORIGINAL et créatif dans tes formulations\n" .
            "- Évite de répéter les mêmes structures ou styles\n\n" .
            "📋 INSTRUCTIONS :\n" .
            "- Sélectionne UNE SEULE information parmi tous les flux fournis\n" .
            "- Choisis l'info la plus récente, intéressante et susceptible de générer de l'engagement\n" .
            "- Reformule en style éditorial percutant avec des emojis variés\n" .
            "- Limite la description à 240 caractères maximum (style Twitter)\n" .
            "- Privilégie les scoops, nouveautés technologiques, et infos buzz\n" .
            "- MAIS SURTOUT : évite tout doublon avec les infos déjà publiées\n\n" .
            "🔄 FORMAT DE RÉPONSE (JSON uniquement) :\n" .
            "{\n" .
            "  \"description\": \"Description reformulée avec style éditorial et emojis\",\n" .
            "  \"url\": \"URL de l'article source\",\n" .
            "  \"imageUrl\": \"URL de l'image si disponible (sinon null)\"\n" .
            "}\n\n" .
            "OU si aucune info nouvelle/différente :\n" .
            "null\n\n" .
            "📝 RÈGLES IMPORTANTES :\n" .
            "- Réponds UNIQUEMENT en JSON, aucun autre texte\n" .
            "- \"description\": Texte accrocheur de 240 caractères max avec emojis d'alerte\n" .
            "- \"url\": URL exacte de l'article sélectionné\n" .
            "- \"imageUrl\": URL de l'image si disponible dans N'IMPORTE QUEL flux (sinon null)\n\n" .
            "🖼️ IMPORTANT POUR LES IMAGES :\n" .
            "- Cherche les images dans TOUS les flux fournis\n" .
            "- Si l'article sélectionné n'a pas d'image, mais qu'un autre article du même sujet en a une, utilise-la\n" .
            "- Privilégie les images pertinentes et de qualité\n\n" .
            "💡 EXEMPLES DE STYLES VARIÉS (change à chaque fois) :\n" .
            "Style 1: \"⚡ BREAKING : [Sujet] bouleverse le marché ! [Détail choc]\"\n" .
            "Style 2: \"💥 SCOOP EXCLUSIF : [Entreprise] révèle [Innovation surprenante]\"\n" .
            "Style 3: \"🌟 RÉVÉLATION : [Personnalité] annonce [Changement majeur]\"\n" .
            "Style 4: \"🔔 FLASH INFO : [Secteur] en ébullition après [Événement]\"\n" .
            "Style 5: \"💫 BOOM TECH : [Startup] lève [Montant] pour [Mission folle]\"\n" .
            "Style 6: \"⭐ URGENT : [Géant tech] rachète [Concurrent] pour [Somme]\"\n" .
            "Style 7: \"🎯 EXCLUSIF : [Leader] quitte [Entreprise] pour [Nouveau projet]\"\n" .
            "Style 8: \"📢 ALERTE CRYPTO : [Monnaie] explose de [%] après [Annonce]\"\n\n" .
            "🎯 Choisis l'info qui fera le BUZZ, utilise un style DIFFÉRENT des infos déjà publiées !";

        // Vérifier la longueur du prompt pour éviter les erreurs de tokens
        $promptLength = strlen($prompt);
        if ($promptLength > 12000) {
            throw new \Exception("Le contenu est trop volumineux pour OpenAI ({$promptLength} caractères). Réduisez le nombre de flux ou leur taille.");
        }

        // Vérifier que la clé API est définie
        if (empty($this->openaiApiKey)) {
            throw new \Exception('La clé API OpenAI (OPENAI_API_KEY) n\'est pas configurée.');
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->openaiApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Tu es un éditeur créatif et original. Tu dois VARIER tes styles et emojis. Ne copie JAMAIS les patterns des exemples précédents. Sois créatif et original dans tes formulations. Utilise différents emojis d\'alerte : 🚨, ⚡, 🔥, 💡, ⭐, 🎯, 💥, 🌟, ⚠️, 📢, 🔔, 💫. Varie les mots d\'accroche : ALERTE, BREAKING, SCOOP, FLASH, URGENT, BOOM, RÉVÉLATION, etc.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'max_tokens' => 500,
                    'temperature' => 0.9
                ],
                'timeout' => 60,
            ]);

            if ($response->getStatusCode() !== 200) {
                $errorBody = '';
                try {
                    $errorData = $response->toArray(false);
                    $errorBody = json_encode($errorData, JSON_PRETTY_PRINT);
                } catch (\Exception $e) {
                    $errorBody = $response->getContent(false);
                }
                
                throw new \Exception(sprintf(
                    'OpenAI API error %d: %s',
                    $response->getStatusCode(),
                    $errorBody
                ));
            }

            $data = $response->toArray();
            
            if (!isset($data['choices'][0]['message']['content'])) {
                throw new \Exception('Réponse OpenAI invalide');
            }

            $jsonResponse = trim($data['choices'][0]['message']['content']);
            $decodedResponse = json_decode($jsonResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Réponse JSON invalide de OpenAI: ' . json_last_error_msg());
            }

            return $decodedResponse;

        } catch (TransportExceptionInterface $e) {
            throw new \Exception('Erreur de transport OpenAI: ' . $e->getMessage());
        }
    }

    /**
     * Check if the OpenAI response represents a duplicate of existing Info
     */
    private function isDuplicate(array $response): bool
    {
        if (!isset($response['url'])) {
            return false;
        }

        // Check for exact URL match only
        return $this->infoRepository->existsByUrl($response['url']);
    }

    /**
     * Create Info entity from OpenAI response
     */
    private function createInfoFromResponse(array $response): ?Info
    {
        if (!isset($response['description']) || !isset($response['url'])) {
            return null;
        }

        $info = new Info();
        $info->setDescription($response['description']);
        $info->setUrl($response['url']);
        
        if (isset($response['imageUrl']) && !empty($response['imageUrl'])) {
            $info->setImageUrl($response['imageUrl']);
        }

        // publishedAt n'est plus utilisé - createdAt sera automatiquement défini par le lifecycle callback

        return $info;
    }
}
