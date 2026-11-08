<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\UX\Dropzone\Form\DropzoneType;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;

final class DocumentUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('files', DropzoneType::class, [
            'label' => false,
            'multiple' => true,
            'max_files' => 20,
            'placeholder' => 'Déposer des fichiers .md, .pdf ou .docx, ou cliquer pour parcourir',
            'remove_label' => 'Retirer',
            'attr' => ['accept' => '.md,.pdf,.docx'],
            'constraints' => [
                new Count(min: 1, minMessage: 'Ajouter au moins un fichier.'),
                new All([
                    new File(
                        maxSize: '32M',
                        extensions: [
                            // finfo reports Markdown containing HTML as text/html; rendering escapes it anyway.
                            'md' => ['text/markdown', 'text/x-markdown', 'text/plain', 'text/html'],
                            'pdf' => ['application/pdf'],
                            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
                        ],
                        extensionsMessage: 'Format non pris en charge ({{ extension }}). Formats acceptés : {{ extensions }}.',
                        mimeTypesMessage: 'Le contenu du fichier ne correspond pas à son extension ({{ type }}).',
                        maxSizeMessage: 'Fichier trop lourd ({{ size }} {{ suffix }}), maximum {{ limit }} {{ suffix }}.',
                    ),
                ]),
            ],
        ]);
    }
}
