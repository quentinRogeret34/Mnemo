<?php

namespace App\Form;

use App\Enum\MemoryType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class MemoryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('text', TextareaType::class, [
                'label' => 'form.memory.text',
                'attr' => ['placeholder' => 'form.memory.text_placeholder', 'rows' => 2],
                'constraints' => [new NotBlank(message: 'memory.text.blank'), new Length(max: 1000, maxMessage: 'memory.text.too_long')],
            ])
            ->add('type', EnumType::class, [
                'label' => 'form.memory.type',
                'class' => MemoryType::class,
            ])
            ->add('visibility', ChoiceType::class, ['label' => 'form.memory.visibility', 'choices' => $options['visibilities']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('visibilities', ['memory.visibility.project' => 'project']);
        $resolver->setAllowedTypes('visibilities', 'array');
    }
}
