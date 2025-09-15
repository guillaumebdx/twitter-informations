<?php

namespace App\Command;

use App\Service\TwitterClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:twitter-debug',
    description: 'Debug Twitter API v2 connection and display account information'
)]
class TwitterDebugCommand extends Command
{
    public function __construct(
        private TwitterClient $twitterClient
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tweet', null, InputOption::VALUE_NONE, 'Post a test tweet "bonjour"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🐦 Twitter API v2 Debug');

        try {
            $io->section('📡 Test d\'authentification Twitter API v2');
            $io->text('Test de connexion avec Bearer Token sur un endpoint public...');

            $testResult = $this->twitterClient->testAuthentication();

            $io->success('✅ Authentification réussie !');

            $io->section('📊 Résultat du test');
            
            // Debug: afficher la structure complète de la réponse
            $io->text('Structure de la réponse API:');
            $io->text(json_encode($testResult, JSON_PRETTY_PRINT));
            
            if (isset($testResult['data'])) {
                $user = $testResult['data'];
                
                // Afficher les infos du compte Twitter officiel (utilisé pour le test)
                $infoTable = [
                    ['ID', $user['id'] ?? 'N/A'],
                    ['Nom d\'utilisateur', '@' . ($user['username'] ?? 'N/A')],
                    ['Nom affiché', $user['name'] ?? 'N/A'],
                ];

                // Ajouter les métriques publiques si disponibles
                if (isset($user['public_metrics'])) {
                    $metrics = $user['public_metrics'];
                    $infoTable[] = ['Followers', number_format($metrics['followers_count'] ?? 0)];
                    $infoTable[] = ['Following', number_format($metrics['following_count'] ?? 0)];
                    $infoTable[] = ['Tweets', number_format($metrics['tweet_count'] ?? 0)];
                }

                $io->table(['Propriété', 'Valeur'], $infoTable);
                $io->note('Test effectué en récupérant les informations du compte @twitter');
            } else {
                $io->warning('Aucune donnée trouvée dans testResult[\'data\']');
            }

            // Vérifier si l'option --tweet est activée
            if ($input->getOption('tweet')) {
                $io->section('🐦 Publication d\'un tweet de test');
                $io->text('Publication du tweet "bonjour"...');
                
                $tweetResult = $this->twitterClient->postTweet('bonjour');
                
                $io->success('✅ Tweet publié avec succès !');
                
                if (isset($tweetResult['data'])) {
                    $tweet = $tweetResult['data'];
                    $tweetTable = [
                        ['ID du tweet', $tweet['id'] ?? 'N/A'],
                        ['Texte', $tweet['text'] ?? 'N/A'],
                    ];
                    
                    $io->table(['Propriété', 'Valeur'], $tweetTable);
                }
                
                $io->text('Debug de la réponse tweet:');
                $io->text(json_encode($tweetResult, JSON_PRETTY_PRINT));
            }

            $io->success('🎉 Debug Twitter API v2 terminé avec succès !');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('❌ Erreur lors de la connexion à Twitter API v2: ' . $e->getMessage());
            
            $io->section('🔧 Vérifications à effectuer');
            $io->listing([
                'Vérifiez que TWITTER_BEARER_TOKEN est correctement défini dans .env',
                'Vérifiez que votre Bearer Token est valide et non expiré',
                'Vérifiez que votre application Twitter a accès à l\'API v2',
                'Vérifiez votre connexion internet',
                'Assurez-vous d\'avoir les bonnes permissions sur votre app Twitter'
            ]);

            return Command::FAILURE;
        }
    }
}
