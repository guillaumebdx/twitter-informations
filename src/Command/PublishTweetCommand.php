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
            $io->text('Récupération de 2 flux aléatoires pour traitement LLM...');
            
            $results = $this->rssFetcher->fetchRandomFeeds(2);
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
                $io->error('❌ Impossible de créer une information à partir des flux.');
                $executionLog->setStatus('fail');
                $executionLog->setErrorOutput('Impossible de créer une information à partir des flux RSS');
                $this->entityManager->persist($executionLog);
                $this->entityManager->flush();
                return Command::FAILURE;
            }
            
            $io->success('✅ Information générée avec succès !');
            $io->definitionList(
                ['Description' => substr($info->getDescription(), 0, 100) . '...'],
                ['URL' => $info->getUrl() ?: 'Non définie'],
                ['Image' => $info->getImageUrl() ?: 'Non définie']
            );

            // Étape 3: Publication du tweet
            $io->section('🐦 Publication du tweet');
            $io->text('Publication du tweet avec la description générée...');
            
            $tweetText = $info->getDescription();
            
            // Vérifier la longueur du tweet (limite Twitter: 280 caractères)
            if (strlen($tweetText) > 280) {
                $tweetText = substr($tweetText, 0, 277) . '...';
                $io->note('Tweet tronqué à 280 caractères.');
            }
            
            $tweetResult = $this->twitterClient->postTweet($tweetText);
            
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
