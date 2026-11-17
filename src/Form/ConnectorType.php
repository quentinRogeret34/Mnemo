<?php

namespace App\Form;

use App\Connector\CredentialResolver;
use App\Entity\Connector;
use App\Entity\Folder;
use App\Entity\Project;
use App\Enum\ConnectorMode;
use App\Enum\ConnectorSchedule;
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

/**
 * @extends AbstractType<Connector>
 */
final class ConnectorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'form.connector.name'])
            ->add('transport', EnumType::class, ['label' => 'form.connector.transport', 'class' => ConnectorTransport::class])
            ->add('endpoint', TextType::class, [
                'label' => 'form.connector.endpoint',
                'help' => 'form.connector.endpoint_help',
            ])
            ->add('credentials', TextareaType::class, [
                'label' => 'form.connector.credentials',
                'required' => false,
                'help' => 'form.connector.credentials_help',
                'attr' => ['rows' => 3, 'placeholder' => 'Authorization: env:NOTION_MCP_TOKEN'],
            ])
            ->add('mode', EnumType::class, ['label' => 'form.connector.mode', 'class' => ConnectorMode::class])
            ->add('tool', TextType::class, ['label' => 'form.connector.tool', 'required' => false, 'help' => 'form.connector.tool_help'])
            ->add('arguments', TextareaType::class, [
                'label' => 'form.connector.arguments',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => '{"user_id": "quentin"}'],
                'invalid_message' => 'connector.arguments.invalid',
            ])
            ->add('itemsPath', TextType::class, ['label' => 'form.connector.items_path', 'required' => false, 'help' => 'form.connector.items_path_help'])
            ->add('textField', TextType::class, ['label' => 'form.connector.text_field', 'required' => false, 'help' => 'form.connector.text_field_help'])
            ->add('memoryType', EnumType::class, ['label' => 'form.connector.memory_type', 'class' => MemoryType::class])
            ->add('schedule', EnumType::class, ['label' => 'form.connector.schedule', 'class' => ConnectorSchedule::class, 'help' => 'form.connector.schedule_help'])
            ->add('project', EntityType::class, ['label' => 'form.connector.project', 'class' => Project::class, 'choice_label' => 'name', 'choice_translation_domain' => false, 'required' => false, 'placeholder' => 'nav.no_project'])
            ->add('folder', EntityType::class, ['label' => 'form.connector.folder', 'class' => Folder::class, 'choice_label' => 'name', 'choice_translation_domain' => false, 'required' => false, 'placeholder' => 'form.connector.folder_root']);

        $builder->get('credentials')->addModelTransformer(new CallbackTransformer(
            static fn (?array $values): string => implode("\n", array_map(static fn (string $name, string $value): string => $name.': '.CredentialResolver::display($value), array_keys($values ?? []), $values ?? [])),
            static function (?string $text): array {
                $values = [];
                foreach (preg_split('/\R/', (string) $text) as $line) {
                    if ('' === trim($line)) {
                        continue;
                    }
                    if (!str_contains($line, ':')) {
                        throw new TransformationFailedException(invalidMessage: 'connector.credentials.invalid');
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
