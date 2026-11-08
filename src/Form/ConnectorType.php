<?php

namespace App\Form;

use App\Entity\Connector;
use App\Entity\Folder;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Enum\ConnectorTransport;
use App\Enum\MemoryType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ConnectorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('transport', EnumType::class, ['label' => 'Transport', 'class' => ConnectorTransport::class, 'choice_label' => static fn (ConnectorTransport $transport): string => $transport->label()])
            ->add('endpoint', TextType::class, [
                'label' => 'URL ou commande',
                'help' => 'HTTP : https://mcp.example.com/mcp. STDIO : commande lancée dans le conteneur, ex. php mon-serveur.php.',
            ])
            ->add('credentials', TextareaType::class, [
                'label' => 'En-têtes HTTP ou variables d\'environnement',
                'required' => false,
                'help' => 'Une ligne par valeur, « Nom: valeur ». Écrire « env:NOM » (ex. « Bearer env:GITHUB_MCP_TOKEN ») pour lire un secret dans .env.local au lieu de le stocker en base.',
                'attr' => ['rows' => 3, 'placeholder' => 'Authorization: env:NOTION_MCP_TOKEN'],
            ])
            ->add('mode', EnumType::class, ['label' => 'Import', 'class' => ConnectorMode::class, 'choice_label' => static fn (ConnectorMode $mode): string => $mode->label()])
            ->add('tool', TextType::class, ['label' => 'Outil à appeler', 'required' => false, 'help' => 'Pour les modes « Outil ». « Tester la connexion » liste les outils disponibles.'])
            ->add('arguments', TextareaType::class, [
                'label' => 'Arguments de l\'outil (JSON)',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => '{"user_id": "quentin"}'],
                'invalid_message' => 'Arguments JSON invalides : un objet est attendu, ex. {"limit": 50}.',
            ])
            ->add('itemsPath', TextType::class, ['label' => 'Chemin de la liste', 'required' => false, 'help' => 'Mode mémoires : chemin de la liste dans la réponse JSON, ex. « results ». Vide si la réponse est la liste.'])
            ->add('textField', TextType::class, ['label' => 'Champ du texte', 'required' => false, 'help' => 'Mode mémoires : champ qui contient le texte. Par défaut : memory, text ou content.'])
            ->add('memoryType', EnumType::class, ['label' => 'Type des mémoires', 'class' => MemoryType::class, 'choice_label' => static fn (MemoryType $type): string => $type->label()])
            ->add('project', EntityType::class, ['label' => 'Rattacher au projet', 'class' => Project::class, 'choice_label' => 'name', 'required' => false, 'placeholder' => 'Aucun projet'])
            ->add('folder', EntityType::class, ['label' => 'Dossier des documents', 'class' => Folder::class, 'choice_label' => 'name', 'required' => false, 'placeholder' => 'Racine']);

        $builder->get('credentials')->addModelTransformer(new CallbackTransformer(
            static fn (?array $values): string => implode("\n", array_map(static fn (string $name, string $value): string => $name.': '.$value, array_keys($values ?? []), $values ?? [])),
            static function (?string $text): array {
                $values = [];
                foreach (preg_split('/\R/', (string) $text) as $line) {
                    if ('' === trim($line)) {
                        continue;
                    }
                    if (!str_contains($line, ':')) {
                        throw new TransformationFailedException(invalidMessage: 'Chaque ligne doit suivre la forme « Nom: valeur ».');
                    }
                    [$name, $value] = explode(':', $line, 2);
                    $values[trim($name)] = trim($value);
                }

                return $values;
            },
        ));

        $builder->get('arguments')->addModelTransformer(new CallbackTransformer(
            static fn (?array $arguments): string => [] === ($arguments ?? []) ? '' : json_encode($arguments, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            static function (?string $json): array {
                if ('' === trim((string) $json)) {
                    return [];
                }
                $arguments = json_decode((string) $json, true);

                return \is_array($arguments) && !array_is_list($arguments) ? $arguments : throw new TransformationFailedException('Not a JSON object.');
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Connector::class]);
    }
}
