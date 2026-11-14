<?php

namespace App\Form;

use App\Llm\LlmSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;

final class LlmSettingsType extends AbstractType
{
    public function __construct(
        private readonly LlmSettings $settings,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('apiKey', PasswordType::class, [
                'label' => 'Clé OpenRouter',
                'required' => false,
                'help' => 'Vide : la clé actuelle est conservée. Elle est chiffrée en base et n\'est plus jamais affichée.',
                'attr' => ['autocomplete' => 'off', 'placeholder' => 'sk-or-...'],
                'constraints' => [new Length(max: 500)],
            ])
            ->add('removeKey', CheckboxType::class, ['label' => 'Retirer la clé enregistrée ici', 'required' => false])
            ->add('chatModel', TextType::class, [
                'label' => 'Modèle de chat',
                'required' => false,
                'help' => 'Identifiant OpenRouter, ex. anthropic/claude-sonnet-4.5. Il doit savoir appeler des outils et répondre en JSON. Vide : celui du serveur.',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => $this->settings->defaultChatModel],
                'constraints' => [new Length(max: 150)],
            ])
            ->add('embeddingModel', TextType::class, [
                'label' => 'Modèle d\'embeddings',
                'required' => false,
                'help' => 'À choisir dans la liste, ex. openai/text-embedding-3-small. En changer relance l\'indexation de tout le contenu. Vide : celui du serveur.',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'list' => 'embedding-models', 'placeholder' => $this->settings->defaultEmbeddingModel],
                'constraints' => [new Length(max: 150)],
            ]);
    }
}
