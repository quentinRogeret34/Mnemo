<?php

namespace App\Form;

use App\Agent\SkillEvolutionAdvisor;
use App\Enum\AgentAssetKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{request: string, kind: AgentAssetKind}>
 */
final class AgentAssetDraftType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('request', TextareaType::class, [
                'label' => 'form.agent_asset_draft.request',
                'empty_data' => '',
                'help' => 'form.agent_asset_draft.request_help',
                'attr' => ['rows' => 5],
                'constraints' => [new Assert\NotBlank(message: 'agent_asset.request.blank'), new Assert\Length(max: SkillEvolutionAdvisor::MAX_REQUEST)],
            ])
            ->add('kind', EnumType::class, ['label' => 'form.agent_asset.kind', 'class' => AgentAssetKind::class, 'data' => AgentAssetKind::Skill]);
    }
}
