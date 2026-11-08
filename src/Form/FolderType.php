<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class FolderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nouveau dossier',
            'attr' => ['placeholder' => 'Nom du dossier', 'autocomplete' => 'off'],
            'constraints' => [new NotBlank(message: 'Donner un nom au dossier.'), new Length(max: 255)],
        ]);
    }
}
