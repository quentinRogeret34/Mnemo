<?php

namespace App\Rule;

/**
 * Locked gap categories of AIDD's shadow-areas skill (github.com/ai-driven-dev/framework, MIT).
 */
enum ShadowCategory: string
{
    case UnstatedAssumption = 'unstated_assumption';
    case AmbiguousTerm = 'ambiguous_term';
    case MissingEdgeCase = 'missing_edge_case';
    case MissingActor = 'missing_actor';
    case MissingFailureMode = 'missing_failure_mode';
    case MissingAcceptanceCriterion = 'missing_acceptance_criterion';
    case MissingDependency = 'missing_dependency';

    public function label(): string
    {
        return match ($this) {
            self::UnstatedAssumption => 'Hypothèse implicite',
            self::AmbiguousTerm => 'Terme ambigu',
            self::MissingEdgeCase => 'Cas limite manquant',
            self::MissingActor => 'Acteur manquant',
            self::MissingFailureMode => 'Cas d\'échec manquant',
            self::MissingAcceptanceCriterion => 'Critère d\'acceptation manquant',
            self::MissingDependency => 'Dépendance manquante',
        };
    }

    public function definition(): string
    {
        return match ($this) {
            self::UnstatedAssumption => 'une condition traitée comme évidente sans être écrite',
            self::AmbiguousTerm => 'un mot qui admet plusieurs lectures raisonnables, sans définition',
            self::MissingEdgeCase => 'une valeur limite ou une entrée exceptionnelle non couverte',
            self::MissingActor => 'une personne, un rôle ou un système qui agit ou subit, absent du texte',
            self::MissingFailureMode => 'une façon d\'échouer non décrite, ni la réaction attendue',
            self::MissingAcceptanceCriterion => 'un comportement décrit sans condition testable de réussite',
            self::MissingDependency => 'un système, service ou donnée externe dont la règle dépend, non nommé',
        };
    }
}
