<?php

namespace App\Form;

use App\Entity\Project;
use App\Entity\UserGroup;
use App\Llm\LlmSettings;
use App\Project\ProjectResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ProjectType extends AbstractType
{
    public function __construct(
        private readonly ProjectResolver $projectResolver,
        private readonly LlmSettings $llmSettings,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, ['label' => 'Nom', 'empty_data' => '', 'attr' => ['autofocus' => $options['create'], 'autocomplete' => 'off']]);
        if ($options['create']) {
            $builder->add('slug', TextType::class, [
                'label' => 'Identifiant',
                'mapped' => false,
                'required' => false,
                'help' => 'Minuscules, chiffres et tirets. Vide : déduit du nom. Il ne change plus ensuite.',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            ]);
        }
        $builder
            ->add('repository', TextType::class, [
                'label' => 'Dépôt',
                'required' => false,
                'help' => 'Remote du dépôt, ex. github.com/acme/boutique : il identifie le projet depuis n\'importe quelle machine.',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('repositoryPath', TextType::class, [
                'label' => 'Chemin local',
                'required' => false,
                'empty_data' => '',
                'help' => 'Dossier du dépôt sur la machine qui héberge le graphe, s\'il y est cloné.',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            ]);
        if (!$options['create']) {
            $builder
                ->add('chatModel', TextType::class, [
                    'label' => 'Modèle de chat',
                    'required' => false,
                    'help' => 'Identifiant OpenRouter, ex. anthropic/claude-sonnet-4.5. Vide : celui de l\'instance.',
                    'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => $this->llmSettings->chatModel(), 'data-controller' => 'model-picker', 'data-model-picker-url-value' => $this->urlGenerator->generate('llm_models', ['kind' => 'chat'])],
                ])
                ->add('embeddingModel', TextType::class, [
                    'label' => 'Modèle d\'embeddings',
                    'required' => false,
                    'help' => 'À choisir dans la liste. En changer réindexe le contenu du projet. Vide : celui de l\'instance.',
                    'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => $this->llmSettings->embeddingModel(), 'data-controller' => 'model-picker', 'data-model-picker-url-value' => $this->urlGenerator->generate('llm_models', ['kind' => 'embedding'])],
                ]);
        }
        if ($options['groups']) {
            $builder->add('groups', EntityType::class, [
                'label' => 'Groupes qui y accèdent',
                'class' => UserGroup::class,
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => true,
                'mapped' => false,
                'required' => false,
            ]);
        }

        $builder->get('repository')->addModelTransformer(new CallbackTransformer(
            static fn (?string $repository): string => (string) $repository,
            fn (?string $repository): ?string => '' === ($normalized = $this->projectResolver->normalize((string) $repository)) ? null : $normalized,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Project::class,
            'create' => false,
            'groups' => false,
            'empty_data' => fn (FormInterface $form): Project => new Project(
                (string) ($form->get('slug')->getData() ?? $this->projectResolver->slug((string) $form->get('name')->getData())),
                (string) $form->get('name')->getData(),
                (string) $form->get('repositoryPath')->getData(),
            ),
        ]);
        $resolver->setAllowedTypes('create', 'bool');
        $resolver->setAllowedTypes('groups', 'bool');
    }
}
