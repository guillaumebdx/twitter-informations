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

        // Log content size for debugging
        $contentSize = strlen($feedContent);
        error_log("RssSummarizer: Taille du contenu préparé: {$contentSize} caractères");

        try {
            // Call OpenAI API
            $response = $this->callOpenAI($feedContent);
            
            // Gérer le cas où OpenAI retourne null (aucune info nouvelle)
            if (!$response) {
                error_log("RssSummarizer: Aucune info nouvelle trouvée par le LLM");
                return null;
            }

            // Log de l'URL fournie par le LLM (on fait confiance au LLM pour extraire du XML)
            if (isset($response['url']) && !empty($response['url'])) {
                error_log('RssSummarizer: URL extraite par le LLM du XML RSS: ' . $response['url']);
            } else {
                error_log('RssSummarizer: Aucune URL fournie par le LLM');
            }

            // Vérification de doublon supprimée - on laisse le LLM et les flux RSS gérer la nouveauté

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
        
        // Suppression complète de la vérification des doublons
        
        $content .= "=== NOUVEAUX FLUX RSS À ANALYSER ===\n\n";
        $content .= "IMPORTANT: Choisissez une URL parmi celles-ci pour éviter les hallucinations d'URL:\n\n";

        foreach ($successFeeds as $index => $feedData) {
            $flux = $feedData['flux'];
            $xmlContent = $feedData['content'];
            
            $fluxName = $this->sanitizeUtf8((string) $flux->getName());
            $fluxUrl = $this->sanitizeUtf8((string) $flux->getUrl());
            $content .= "=== FLUX " . ($index + 1) . ": {$fluxName} ===\n";
            $content .= "Source: {$fluxUrl}\n";
            $content .= "Contenu RSS brut (extrait les infos et URLs toi-même):\n";
            $content .= "```xml\n";
            
            // Donner le XML brut mais limité pour éviter la surcharge
            $cleanXml = $this->sanitizeUtf8($xmlContent);
            // Limiter la taille du XML pour éviter les erreurs de tokens
            if (strlen($cleanXml) > 3000) {
                $cleanXml = substr($cleanXml, 0, 3000) . "\n... [XML tronqué] ...";
            }
            $content .= $cleanXml . "\n";
            $content .= "```\n\n";
        }

        $content .= "🔍 INSTRUCTIONS D'EXTRACTION :\n";
        $content .= "- Cherche les balises <item> ou <entry> dans le XML\n";
        $content .= "- Pour chaque article, trouve : <title>, <description> (ou <summary>), <link> (ou <url>)\n";
        $content .= "- Utilise l'URL EXACTE trouvée dans le XML, ne l'invente pas\n\n";

        return $content;
    }



    /**
     * Call OpenAI API to analyze feeds
     */
    private function callOpenAI(string $content): ?array
    {
        // Sanitize content before using in JSON payload
        $content = $this->sanitizeUtf8($content);
        $prompt = $content . "\n\n" . 
            "🎯 MISSION : Tu es un expert en analyse de flux RSS et éditeur Twitter.\n\n" .
            "📋 PROCESSUS SIMPLE :\n" .
            "1. ANALYSE le XML RSS brut ci-dessus pour identifier les articles\n" .
            "2. CHOISIS l'article le plus intéressant et récent\n" .
            "3. EXTRAIS son titre, description ET son URL directement du XML\n" .
            "4. REFORMULE le contenu en style éditorial percutant avec emojis\n" .
            "5. UTILISE l'URL exacte que tu as trouvée dans le XML\n\n" .
            "🚫 RÈGLES IMPORTANTES :\n" .
            "- N'invente RIEN, utilise uniquement ce qui est dans le XML RSS\n" .
            "- L'URL doit venir directement des balises <link>, <url> ou <guid> du XML\n" .
            "- Choisis toujours l'info la plus récente et intéressante\n\n" .
            "🔄 FORMAT DE RÉPONSE (JSON uniquement) :\n" .
            "{\n" .
            "  \"description\": \"🚨 URGENT : La France annonce de nouvelles mesures économiques ! 💰⚡\",\n" .
            "  \"url\": \"https://www.rfi.fr/en/france/20250919-france-announces-new-economic-measures\"\n" .
            "}\n\n" .
            "⚠️ ATTENTION: L'URL ci-dessus est un EXEMPLE. Tu dois utiliser l'URL RÉELLE trouvée dans le XML RSS !\n\n" .
            "OU si aucune info nouvelle/différente, réponds exactement :\n" .
            "{\"skip\": true}\n\n" .
            "📝 RÈGLES IMPORTANTES :\n" .
            "- Réponds UNIQUEMENT en JSON, aucun autre texte\n" .
            "- N'ajoute aucun commentaire ou explication\n" .
            "- Assure-toi que le JSON est valide\n" .
            "- RAPPEL: N'invente JAMAIS d'information, choisis parmi les articles RSS listés\n" .
            "- \"description\": Texte accrocheur de 240 caractères max avec emojis d'alerte\n" .
            "- \"url\": OBLIGATOIRE - Copie l'URL complète trouvée dans une balise <link> du XML\n\n" .
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

        // Vérifier la longueur du prompt (limite plus élevée pour gpt-4o-mini)
        $promptLength = strlen($prompt);
        $maxLength = ($_ENV['OPENAI_MODEL'] === 'gpt-4o-mini') ? 25000 : 15000;
        
        if ($promptLength > $maxLength) {
            throw new \Exception("Le contenu est trop volumineux pour OpenAI ({$promptLength} caractères). Limite: {$maxLength}. Réduisez le nombre de flux ou leur taille.");
        }

        // Vérifier que la clé API est définie
        if (empty($this->openaiApiKey)) {
            throw new \Exception('La clé API OpenAI (OPENAI_API_KEY) n\'est pas configurée.');
        }

        error_log('OpenAI Request - Model: ' . ($_ENV['OPENAI_MODEL'] ?? 'gpt-3.5-turbo'));
        error_log('OpenAI Request - Prompt length: ' . strlen($prompt) . ' chars');
        
        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->openaiApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $_ENV['OPENAI_MODEL'] ?? 'gpt-3.5-turbo',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->sanitizeUtf8('Tu es un éditeur créatif et original. Tu dois TOUJOURS rédiger en FRANÇAIS. Tu dois VARIER tes styles et emojis. Ne copie JAMAIS les patterns des exemples précédents. Sois créatif et original dans tes formulations. Utilise différents emojis d\'alerte : 🚨, ⚡, 🔥, 💡, ⭐, 🎯, 💥, 🌟, ⚠️, 📢, 🔔, 💫. Varie les mots d\'accroche : ALERTE, BREAKING, SCOOP, FLASH, URGENT, BOOM, RÉVÉLATION, etc. IMPORTANT: Réponds EXCLUSIVEMENT en français, même si les sources sont en anglais.')
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->sanitizeUtf8($prompt)
                        ]
                    ],
                    'max_tokens' => ($_ENV['OPENAI_MODEL'] === 'gpt-4o-mini') ? 500 : 300,
                    'temperature' => ($_ENV['OPENAI_MODEL'] === 'gpt-4o-mini') ? 0.7 : 0.9
                ],
                'timeout' => 60,
            ]);

            if ($response->getStatusCode() !== 200) {
                $errorBody = '';
                try {
                    $errorBody = $response->getContent(false);
                    error_log('OpenAI API Error - Status: ' . $response->getStatusCode());
                    error_log('OpenAI API Error - Full Body: ' . $errorBody);
                    
                    // Essayer de parser le JSON d'erreur d'OpenAI
                    $errorData = json_decode($errorBody, true);
                    if ($errorData && isset($errorData['error'])) {
                        error_log('OpenAI Error Type: ' . ($errorData['error']['type'] ?? 'unknown'));
                        error_log('OpenAI Error Message: ' . ($errorData['error']['message'] ?? 'unknown'));
                        error_log('OpenAI Error Code: ' . ($errorData['error']['code'] ?? 'unknown'));
                    }
                } catch (\Exception $e) {
                    error_log('Could not parse OpenAI error response: ' . $e->getMessage());
                }
                
                // Créer un message d'erreur détaillé pour l'utilisateur
                $userErrorMessage = 'Erreur API OpenAI (HTTP ' . $response->getStatusCode() . ')';
                if ($errorData && isset($errorData['error']['message'])) {
                    $userErrorMessage .= ': ' . $errorData['error']['message'];
                    if (isset($errorData['error']['type'])) {
                        $userErrorMessage .= ' (Type: ' . $errorData['error']['type'] . ')';
                    }
                } else {
                    $userErrorMessage .= ': ' . $errorBody;
                }
                
                throw new \Exception($userErrorMessage);
            }

            $data = $response->toArray();
            
            if (!isset($data['choices'][0]['message']['content'])) {
                throw new \Exception('Réponse OpenAI invalide - Structure: ' . json_encode($data, JSON_PRETTY_PRINT));
            }

            $jsonResponse = trim($data['choices'][0]['message']['content']);
            
            // Suppression du debug
            
            if (empty($jsonResponse)) {
                throw new \Exception('Réponse OpenAI vide - Le modèle n\'a retourné aucun contenu');
            }
            
            $decodedResponse = json_decode($jsonResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Réponse JSON invalide de OpenAI: ' . json_last_error_msg() . ' | Contenu brut: "' . $jsonResponse . '"');
            }

            // Gérer le cas où le LLM indique qu'il faut skip (aucune info nouvelle)
            if ($decodedResponse === null || (isset($decodedResponse['skip']) && $decodedResponse['skip'])) {
                error_log('OpenAI a indiqué skip - aucune info nouvelle détectée par le modèle');
                return null;
            }

            return $decodedResponse;

        } catch (TransportExceptionInterface $e) {
            error_log('OpenAI Transport Error: ' . $e->getMessage());
            error_log('Model used: ' . ($_ENV['OPENAI_MODEL'] ?? 'gpt-3.5-turbo'));
            
            // Essayer de récupérer plus de détails sur l'erreur
            if (method_exists($e, 'getResponse')) {
                try {
                    $response = $e->getResponse();
                    if ($response) {
                        $errorBody = $response->getContent(false);
                        error_log('OpenAI Error Response Body: ' . $errorBody);
                    }
                } catch (\Exception $ex) {
                    error_log('Could not get error response body: ' . $ex->getMessage());
                }
            }
            
            // Créer un message d'erreur détaillé avec les infos de la réponse si disponible
            $detailedMessage = 'Erreur de transport OpenAI: ' . $e->getMessage();
            if (method_exists($e, 'getResponse')) {
                try {
                    $response = $e->getResponse();
                    if ($response) {
                        $errorBody = $response->getContent(false);
                        $errorData = json_decode($errorBody, true);
                        if ($errorData && isset($errorData['error']['message'])) {
                            $detailedMessage .= ' | Détail: ' . $errorData['error']['message'];
                        }
                    }
                } catch (\Exception $ex) {
                    // Ignore
                }
            }
            throw new \Exception($detailedMessage);
        } catch (\Exception $e) {
            error_log('OpenAI General Error: ' . $e->getMessage());
            error_log('Model used: ' . ($_ENV['OPENAI_MODEL'] ?? 'gpt-3.5-turbo'));
            throw new \Exception('Erreur générale OpenAI: ' . $e->getMessage());
        }
    }

    /**
     * Check if the OpenAI response represents a duplicate of existing Info
     */
    private function isDuplicate(array $response): bool
    {
        // Si l'info a une URL null, on publie quand même
        if (!isset($response['url']) || $response['url'] === null || $response['url'] === '') {
            return false;
        }

        // Vérification stricte : l'URL sélectionnée pour le tweet n'est pas déjà en DB
        $isDuplicate = $this->infoRepository->existsByUrl($response['url']);
        if ($isDuplicate) {
            error_log('RssSummarizer: Doublon détecté pour URL: ' . $response['url']);
        }
        return $isDuplicate;
    }

    /**
     * Map article ID to corresponding URL from RSS feeds
     */
    private function mapArticleIdToUrl(string $articleId, array $successFeeds): ?string
    {
        // Parse articleId format: FLUX1_ART2 -> flux index 1, article index 2
        if (!preg_match('/^FLUX(\d+)_ART(\d+)$/', $articleId, $matches)) {
            return null;
        }

        $fluxIndex = (int)$matches[1] - 1; // Convert to 0-based index
        $articleIndex = (int)$matches[2] - 1; // Convert to 0-based index

        if (!isset($successFeeds[$fluxIndex])) {
            return null;
        }

        $feedData = $successFeeds[$fluxIndex];
        $xmlContent = $feedData['content'];

        try {
            $items = $this->parseRssAndGetLatestItems($xmlContent, 2);
            if (isset($items[$articleIndex]) && !empty($items[$articleIndex]['link'])) {
                return $items[$articleIndex]['link'];
            }
        } catch (\Throwable $e) {
            error_log('RssSummarizer: Erreur lors du mapping article ID: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Create Info entity from OpenAI response
     */
    private function createInfoFromResponse(array $response): ?Info
    {
        // Description is mandatory; URL is optional (avoid LLM-invented links)
        if (!isset($response['description'])) {
            return null;
        }

        $info = new Info();
        $info->setDescription($response['description']);
        // Sanitize and bound main URL to 255 chars
        if (isset($response['url']) && !empty($response['url'])) {
            $safeUrl = $this->sanitizeAndClampUrl((string)$response['url'], 255);
            if ($safeUrl !== null) {
                $info->setUrl($safeUrl);
            }
        }
        

        // publishedAt n'est plus utilisé - createdAt sera automatiquement défini par le lifecycle callback

        return $info;
    }

    /**
     * Ensure URL is valid and <= maxLen. If too long, try removing query/fragment. Return null if still invalid/too long.
     */
    private function sanitizeAndClampUrl(string $url, int $maxLen = 255): ?string
    {
        $url = trim($this->sanitizeUtf8($url));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        if (strlen($url) <= $maxLen) {
            return $url;
        }
        // Try to strip query and fragment to shorten
        $parts = @parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $rebuilt = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
        if (strlen($rebuilt) <= $maxLen && filter_var($rebuilt, FILTER_VALIDATE_URL)) {
            return $rebuilt;
        }
        // Still too long
        return null;
    }

    /**
     * Collect normalized candidate links from RSS items used in the prompt
     */
    private function collectCandidateLinks(array $successFeeds): array
    {
        $links = [];
        foreach ($successFeeds as $feedData) {
            $xmlContent = $feedData['content'];
            try {
                $items = $this->parseRssAndGetLatestItems($xmlContent, 2);
                foreach ($items as $item) {
                    if (!empty($item['link'])) {
                        $url = trim((string)$item['link']);
                        if ($url !== '') {
                            // Plus permissif: accepter les URLs absolues ET relatives qui ressemblent à des URLs
                            $isAbsoluteUrl = filter_var($url, FILTER_VALIDATE_URL);
                            $looksLikeUrl = (str_starts_with($url, '/') && strlen($url) > 1) || 
                                           str_contains($url, '.') || 
                                           str_starts_with($url, 'http');
                            if ($isAbsoluteUrl || $looksLikeUrl) {
                                $links[] = rtrim($url, "/ ");
                            }
                        }
                    }
                }
            } catch (\Throwable $t) {
                // ignore
            }
        }
        // Unique values
        return array_values(array_unique($links));
    }

    /**
     * Sanitize a string to valid UTF-8, removing invalid bytes and control chars.
     */
    private function sanitizeUtf8(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Remove problematic control characters first
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Ensure string is valid UTF-8
        if (!mb_detect_encoding($text, 'UTF-8', true)) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            } else {
                // Fallback: try utf8_encode which assumes ISO-8859-1 input
                $text = @utf8_encode($text);
            }
        }

        // Normalize if intl is available
        if (class_exists('Normalizer')) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C);
        }

        return $text;
    }
}
