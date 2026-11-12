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

final class MemoryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('text', TextareaType::class, [
                'label' => 'Mémoire',
                'attr' => ['placeholder' => 'Un fait court et autonome, ex. : « Les seuils de détection sont centralisés dans catalog.ts. »', 'rows' => 2],
                'constraints' => [new NotBlank(message: 'Écrire la mémoire.'), new Length(max: 1000, maxMessage: '1000 caractères maximum.')],
            ])
            ->add('type', EnumType::class, [
                'label' => 'Type',
                'class' => MemoryType::class,
                'choice_label' => static fn (MemoryType $type): string => $type->label(),
            ])
            ->add('visibility', ChoiceType::class, ['label' => 'Visibilité', 'choices' => $options['visibilities']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('visibilities', ['Visible par le projet' => 'project']);
        $resolver->setAllowedTypes('visibilities', 'array');
    }
}
