<?php

namespace App\Form;

use App\Entity\Project;
use App\Entity\UserGroup;
use App\Enum\WebhookFormat;
use App\Llm\LlmSettings;
use App\Notification\WorkspaceNotifier;
use App\Project\ProjectResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @extends AbstractType<Project>
 */
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
        $builder->add('name', TextType::class, ['label' => 'form.project.name', 'empty_data' => '', 'attr' => ['autofocus' => $options['create'], 'autocomplete' => 'off']]);
        if ($options['create']) {
            $builder->add('slug', TextType::class, [
                'label' => 'form.project.slug',
                'mapped' => false,
                'required' => false,
                'help' => 'form.project.slug_help',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            ]);
        }
        $builder
            ->add('repository', TextType::class, [
                'label' => 'form.project.repository',
                'required' => false,
                'help' => 'form.project.repository_help',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('repositoryPath', TextType::class, [
                'label' => 'form.project.repository_path',
                'required' => false,
                'empty_data' => '',
                'help' => 'form.project.repository_path_help',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            ]);
        if (!$options['create']) {
            $builder
                ->add('chatModel', TextType::class, [
                    'label' => 'form.llm.chat_model',
                    'required' => false,
                    'help' => 'form.project.chat_model_help',
                    'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => $this->llmSettings->chatModel(), 'data-controller' => 'model-picker', 'data-model-picker-url-value' => $this->urlGenerator->generate('llm_models', ['kind' => 'chat'])],
                ])
                ->add('embeddingModel', TextType::class, [
                    'label' => 'form.llm.embedding_model',
                    'required' => false,
                    'help' => 'form.project.embedding_model_help',
                    'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => $this->llmSettings->embeddingModel(), 'data-controller' => 'model-picker', 'data-model-picker-url-value' => $this->urlGenerator->generate('llm_models', ['kind' => 'embedding'])],
                ])
                ->add('autoGarden', CheckboxType::class, [
                    'label' => 'form.project.auto_garden',
                    'required' => false,
                    'help' => 'form.project.auto_garden_help',
                ])
                ->add('webhookFormat', EnumType::class, [
                    'class' => WebhookFormat::class,
                    'label' => 'form.project.webhook_format',
                    'expanded' => true,
                ])
                ->add('webhookUrl', TextType::class, [
                    'label' => 'form.project.webhook_url',
                    'mapped' => false,
                    'required' => false,
                    'help' => 'form.project.webhook_url_help',
                    'attr' => [
                        'autocomplete' => 'off',
                        'spellcheck' => 'false',
                        'placeholder' => $options['has_webhook'] ? '••••••••••••' : 'https://hooks.slack.com/services/...',
                    ],
                ])
                ->add('clearWebhook', CheckboxType::class, [
                    'label' => 'form.project.webhook_clear',
                    'mapped' => false,
                    'required' => false,
                ])
                ->add('webhookEvents', ChoiceType::class, [
                    'label' => 'form.project.webhook_events',
                    'multiple' => true,
                    'expanded' => true,
                    'required' => false,
                    'choices' => [
                        'form.project.webhook_event.shadow_blocker' => WorkspaceNotifier::EVENT_SHADOW_BLOCKER,
                        'form.project.webhook_event.pr_contradiction' => WorkspaceNotifier::EVENT_PR_CONTRADICTION,
                        'form.project.webhook_event.briefing' => WorkspaceNotifier::EVENT_BRIEFING,
                    ],
                ]);
        }
        if ($options['groups']) {
            $builder->add('groups', EntityType::class, [
                'label' => 'form.project.groups',
                'class' => UserGroup::class,
                'choice_label' => 'name',
                'choice_translation_domain' => false,
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
            'has_webhook' => false,
            'empty_data' => fn (FormInterface $form): Project => new Project(
                (string) ($form->get('slug')->getData() ?? $this->projectResolver->slug((string) $form->get('name')->getData())),
                (string) $form->get('name')->getData(),
                (string) $form->get('repositoryPath')->getData(),
            ),
        ]);
        $resolver->setAllowedTypes('create', 'bool');
        $resolver->setAllowedTypes('groups', 'bool');
        $resolver->setAllowedTypes('has_webhook', 'bool');
    }
}
