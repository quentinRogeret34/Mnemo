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

final class AgentAssetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('kind', EnumType::class, ['label' => 'Type', 'class' => AgentAssetKind::class, 'choice_label' => static fn (AgentAssetKind $kind): string => $kind->label()])
            ->add('name', TextType::class, ['label' => 'Nom', 'empty_data' => '', 'help' => 'Nom du fichier et, pour une commande, ce qu\'on tape après « / ». Ex. revue-de-pr.'])
            ->add('description', TextType::class, ['label' => 'Description', 'empty_data' => '', 'help' => 'Ce que ça fait et quand s\'en servir : Claude Code choisit un skill ou un sous-agent d\'après cette phrase.'])
            ->add('content', TextareaType::class, [
                'label' => 'Instructions (Markdown)',
                'empty_data' => '',
                'help' => 'Le corps du fichier. Commencer par un bloc « --- » pour fournir son propre front matter (allowed-tools, model...) : il est alors gardé tel quel.',
                'attr' => ['rows' => 18],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AgentAsset::class]);
    }
}
