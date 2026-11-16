<?php

namespace App\Form;

use App\Llm\LlmSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class LlmSettingsType extends AbstractType
{
    public function __construct(
        private readonly LlmSettings $settings,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('apiKey', PasswordType::class, [
                'label' => 'form.llm.api_key',
                'required' => false,
                'help' => 'form.llm.api_key_help',
                'attr' => ['autocomplete' => 'off', 'placeholder' => 'sk-or-...'],
                'constraints' => [new Length(max: 500)],
            ])
            ->add('removeKey', CheckboxType::class, ['label' => 'form.llm.remove_key', 'required' => false])
            ->add('chatModel', TextType::class, [
                'label' => 'form.llm.chat_model',
                'required' => false,
                'help' => 'form.llm.chat_model_help',
                'attr' => $this->picker('chat') + ['placeholder' => $this->settings->defaultChatModel],
                'constraints' => [new Length(max: 150)],
            ])
            ->add('embeddingModel', TextType::class, [
                'label' => 'form.llm.embedding_model',
                'required' => false,
                'help' => 'form.llm.embedding_model_help',
                'attr' => $this->picker('embedding') + ['placeholder' => $this->settings->defaultEmbeddingModel],
                'constraints' => [new Length(max: 150)],
            ])
            ->add('favoriteModels', TextType::class, [
                'label' => 'form.llm.favorites',
                'required' => false,
                'help' => 'form.llm.favorites_help',
                'attr' => $this->picker('chat', true) + ['placeholder' => 'form.llm.favorites_placeholder'],
                'constraints' => [new Length(max: 5000)],
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function picker(string $kind, bool $multiple = false): array
    {
        return [
            'autocomplete' => 'off',
            'spellcheck' => 'false',
            'data-controller' => 'model-picker',
            'data-model-picker-url-value' => $this->urlGenerator->generate('llm_models', ['kind' => $kind]),
            'data-model-picker-multiple-value' => $multiple ? 'true' : 'false',
        ];
    }
}
