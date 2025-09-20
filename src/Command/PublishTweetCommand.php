<?php

namespace App\Command;

use App\Entity\ExecutionLog;
use App\Service\RssFetcher;
use App\Service\LLM\RssSummarizer;
use App\Service\TwitterClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:publish-tweet',
    description: 'Récupère les flux RSS, génère une info avec LLM, publie un tweet et persiste en base'
)]
class PublishTweetCommand extends Command
{
    public function __construct(
        private RssFetcher $rssFetcher,
        private RssSummarizer $rssSummarizer,
        private TwitterClient $twitterClient,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'feeds',
            'f',
            InputOption::VALUE_OPTIONAL,
            'Nombre de flux RSS à traiter (1-4, défaut: 3 pour gpt-4o-mini, 2 pour gpt-3.5-turbo)',
            2
        );

        $this->addOption(
            'dry-run',
            'd',
            InputOption::VALUE_NONE,
            'Mode test: exécute tout le processus (validation et upload image) sans publier le tweet'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🐦 Publication automatique de tweet');

        // Créer le log d'exécution
        $executionLog = new ExecutionLog();
        $info = null;

        try {
            // Étape 1: Récupération des flux RSS
            $io->section('📡 Récupération des flux RSS');
            $feedsCount = (int) $input->getOption('feeds');
            
            // Définir le défaut selon le modèle OpenAI
            if ($feedsCount === 0) {
                $feedsCount = ($_ENV['OPENAI_MODEL'] === 'gpt-4o-mini') ? 3 : 2;
            }
            
            // Valider le nombre de flux (limite plus élevée pour gpt-4o-mini)
            $maxFeeds = ($_ENV['OPENAI_MODEL'] === 'gpt-4o-mini') ? 4 : 2;
            if ($feedsCount < 1 || $feedsCount > $maxFeeds) {
                $feedsCount = ($_ENV['OPENAI_MODEL'] === 'gpt-4o-mini') ? 3 : 2;
            }
            
            $io->text("Récupération de {$feedsCount} flux aléatoire(s) pour traitement LLM...");
            
            $results = $this->rssFetcher->fetchRandomFeeds($feedsCount);
            $successCount = count($results['success']);
            
            if ($successCount === 0) {
                $io->error('❌ Aucun flux RSS n\'a pu être récupéré.');
                return Command::FAILURE;
            }
            
            $io->success(sprintf('✅ %d flux RSS récupérés avec succès !', $successCount));

            // Étape 2: Traitement avec LLM
            $io->section('🤖 Génération du contenu avec OpenAI');
            
            $info = $this->rssSummarizer->processFeeds($results);
            
            if (!$info) {
                $io->warning('⚠️ Aucune information nouvelle trouvée dans les flux RSS.');
                $io->text('Le LLM a déterminé que toutes les informations sont des doublons ou non pertinentes.');
                $executionLog->setStatus('success');
                $executionLog->setErrorOutput('Aucune info nouvelle - tous les articles sont des doublons ou non pertinents');
                $this->entityManager->persist($executionLog);
                $this->entityManager->flush();
                return Command::SUCCESS;
            }
            
            $io->success('✅ Information générée avec succès !');
            $io->definitionList(
                ['Description' => substr($info->getDescription(), 0, 100) . '...'],
                ['URL' => $info->getUrl() ?: 'Non définie']
            );

            // Étape 3: Publication du tweet
            $io->section('🐦 Publication du tweet');
            $io->text('Préparation du contenu du tweet...');

            $tweetText = $info->getDescription();
            
            // Ajouter l'URL à la fin du tweet si elle existe
            if ($info->getUrl()) {
                $tweetText .= ' ' . $info->getUrl();
            }

            // Calculer la longueur réelle du tweet (Twitter raccourcit les URLs en t.co = 23 chars)
            $realTweetLength = strlen($tweetText);
            if ($info->getUrl()) {
                // Soustraire la longueur de l'URL originale et ajouter 23 chars pour t.co + 1 espace
                $realTweetLength = $realTweetLength - strlen($info->getUrl()) + 24;
            }
            
            // Vérifier la longueur réelle du tweet (limite Twitter: 280 caractères)
            if ($realTweetLength > 280) {
                $maxDescLength = 280 - 24; // 24 = 23 (t.co) + 1 (espace)
                if ($info->getUrl()) {
                    $description = $info->getDescription();
                    $tweetText = substr($description, 0, $maxDescLength - 3) . '... ' . $info->getUrl();
                } else {
                    $tweetText = substr($tweetText, 0, 277) . '...';
                }
                $io->note('Tweet tronqué pour respecter la limite de 280 caractères (URLs comptent comme 23 chars).');
            }
            
            // Afficher le contenu du tweet qui sera publié
            $io->section('📝 Contenu du tweet');
            $io->text('Voici le tweet qui sera publié :');
            $io->newLine();
            $io->text('┌─────────────────────────────────────────────────────────────────────────────────┐');
            
            // Découper le tweet en lignes de 77 caractères max pour l'affichage
            $lines = str_split($tweetText, 77);
            foreach ($lines as $line) {
                $io->text('│ ' . str_pad($line, 77) . ' │');
            }
            
            $io->text('└─────────────────────────────────────────────────────────────────────────────────┘');
            $io->newLine();
            
            // Afficher la longueur réelle (avec t.co)
            $displayLength = strlen($tweetText);
            if ($info->getUrl()) {
                $realLength = $displayLength - strlen($info->getUrl()) + 23;
                $io->text('Longueur affichée: ' . $displayLength . ' caractères');
                $io->text('Longueur réelle sur Twitter: ' . $realLength . '/280 caractères (URL → t.co = 23 chars)');
            } else {
                $io->text('Longueur: ' . $displayLength . '/280 caractères');
            }

            $dryRun = (bool) $input->getOption('dry-run');
            if ($dryRun) {
                $io->warning('Mode DRY-RUN activé: aucun appel à l\'API Twitter ne sera effectué.');
                // Sauvegarder le log d'exécution comme succès mais sans publication
                $executionLog->setStatus('success');
                $executionLog->setInfo($info);
                $executionLog->setErrorOutput('DRY-RUN: tweet non publié (aucun appel API Twitter).');
                $this->entityManager->persist($executionLog);
                $this->entityManager->flush();
                $io->success('✅ DRY-RUN terminé: aucun tweet publié.');
                return Command::SUCCESS;
            }

            // Publication texte seul
            $tweetResult = $this->twitterClient->postTweet($tweetText);
            
            if (!$tweetResult) {
                throw new \RuntimeException('La publication du tweet a échoué sans réponse.');
            }
            $io->success('✅ Tweet publié avec succès !');
            
            if (isset($tweetResult['data'])) {
                $tweet = $tweetResult['data'];
                $io->definitionList(
                    ['ID du tweet' => $tweet['id'] ?? 'N/A'],
                    ['Texte' => $tweet['text'] ?? 'N/A']
                );
            }

            // Étape 4: Mise à jour de published_at et persistance
            $io->section('💾 Sauvegarde en base de données');
            
            $info->setPublishedAt(new \DateTimeImmutable());
            $this->entityManager->persist($info);
            
            // Log de succès
            $executionLog->setStatus('success');
            $executionLog->setInfo($info);
            $this->entityManager->persist($executionLog);
            
            $this->entityManager->flush();
            
            $io->success('✅ Information sauvegardée en base avec published_at mis à jour !');
            $io->definitionList(
                ['ID' => $info->getId()],
                ['Publié le' => $info->getPublishedAt()->format('d/m/Y H:i:s')],
                ['Créé le' => $info->getCreatedAt()->format('d/m/Y H:i:s')]
            );

            $io->success('🎉 Publication automatique terminée avec succès !');
            
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('❌ Erreur lors de la publication : ' . $e->getMessage());
            
            // Log de l'erreur
            $executionLog->setStatus('fail');
            $executionLog->setErrorOutput($e->getMessage());
            if ($info) {
                $executionLog->setInfo($info);
            }
            $this->entityManager->persist($executionLog);
            $this->entityManager->flush();
            
            $io->section('🔧 Vérifications à effectuer');
            $io->listing([
                'Vérifiez que les flux RSS sont configurés et accessibles',
                'Vérifiez que OPENAI_API_KEY est correctement défini',
                'Vérifiez que les clés Twitter OAuth 1.0a sont correctement définies',
                'Vérifiez votre connexion internet',
                'Vérifiez que la base de données est accessible'
            ]);

            return Command::FAILURE;
        }
    }
}
