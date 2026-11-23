<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;
use Symfony\UX\Dropzone\Form\DropzoneType;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class DocumentUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('files', DropzoneType::class, [
            'label' => false,
            'multiple' => true,
            'max_files' => 20,
            'placeholder' => 'form.upload.placeholder',
            'remove_label' => 'form.upload.remove',
            'attr' => ['accept' => '.md,.pdf,.docx'],
            'constraints' => [
                new Count(min: 1, max: 20, minMessage: 'upload.min'),
                new All([
                    new File(
                        maxSize: '32M',
                        extensions: [
                            // finfo reports Markdown containing HTML as text/html; rendering escapes it anyway.
                            'md' => ['text/markdown', 'text/x-markdown', 'text/plain', 'text/html'],
                            'pdf' => ['application/pdf'],
                            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
                        ],
                        extensionsMessage: 'upload.extension',
                        mimeTypesMessage: 'upload.mime_type',
                        maxSizeMessage: 'upload.max_size',
                    ),
                ]),
            ],
        ]);
    }
}
