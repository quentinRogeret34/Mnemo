<?php

namespace App\Form;

use App\Entity\AgentAsset;
use App\Enum\AgentAssetKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AgentAsset>
 */
final class AgentAssetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('kind', EnumType::class, ['label' => 'form.agent_asset.kind', 'class' => AgentAssetKind::class])
            ->add('name', TextType::class, ['label' => 'form.agent_asset.name', 'empty_data' => '', 'help' => 'form.agent_asset.name_help'])
            ->add('description', TextType::class, ['label' => 'form.agent_asset.description', 'empty_data' => '', 'help' => 'form.agent_asset.description_help'])
            ->add('content', TextareaType::class, [
                'label' => 'form.agent_asset.content',
                'empty_data' => '',
                'help' => 'form.agent_asset.content_help',
                'attr' => ['rows' => 18],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AgentAsset::class]);
    }
}
