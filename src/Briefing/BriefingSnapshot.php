<?php

namespace App\Briefing;

final readonly class BriefingSnapshot
{
    /**
     * @param array{rules_total: int, rules_validated: int, rules_to_review: int, rules_without_code: int, shadow_open: int, shadow_blockers: int, documents_total: int, documents_added: int, alignment_score: ?int} $alignment
     * @param list<array{code: string, title: string, status: string, statement: string}>                                                                                                                             $rulesTouched
     * @param list<array{id: int, text: string, created_at: string}>                                                                                                                                                  $decisions
     * @param list<array{rule_code: string, rule_title: string, severity: string, gap: string, question: string}>                                                                                                     $openQuestions
     * @param list<array{rule_code: string, rule_title: string, question: string, answer: string, resolved_by: ?string, resolved_at: string}>                                                                         $answeredQuestions
     * @param list<array{ref: string, label: string, kind: ?string, state: ?string, author: ?string, url: ?string, created_at: string}>                                                                               $tickets
     * @param list<array{ref: string, label: string, author: ?string, created_at: string}>                                                                                                                            $commits
     */
    public function __construct(
        public string $period,
        public \DateTimeImmutable $since,
        public array $alignment,
        public array $rulesTouched,
        public array $decisions,
        public array $openQuestions,
        public array $answeredQuestions,
        public array $tickets,
        public array $commits,
        public int $commitsTotal,
    ) {
    }
}
