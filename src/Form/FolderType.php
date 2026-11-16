<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class FolderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'form.folder.name',
            'attr' => ['placeholder' => 'form.folder.name_placeholder', 'autocomplete' => 'off'],
            'constraints' => [new NotBlank(message: 'folder.name.blank'), new Length(max: 255)],
        ]);
    }
}
