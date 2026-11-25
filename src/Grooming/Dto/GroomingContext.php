<?php

namespace App\Grooming\Dto;

/**
 * Snapshot of Mnemo's knowledge graph collected for a given project and grooming topic.
 */
final readonly class GroomingContext
{
    /**
     * @param list<array{code: string, title: string, statement: ?string, status: ?string, shadow_areas: int, files: int}> $rules
     * @param list<array{id: int, text: string, type: ?string, verdict: ?string}>                                          $memories
     * @param list<array{id: int, title: string, format: ?string, excerpt: string}>                                        $documents
     * @param list<array{path: string, label: string}>                                                                     $codeFiles
     */
    public function __construct(
        public string $topic,
        public ?string $extraContext,
        public array $rules,
        public array $memories,
        public array $documents,
        public array $codeFiles,
        public string $searchMode,
    ) {
    }

    public function toPromptText(): string
    {
        $sections = [];

        $sections[] = 'Sujet à groomer : '.$this->topic;
        if (null !== $this->extraContext && '' !== trim($this->extraContext)) {
            $sections[] = "Contexte additionnel fourni par l'équipe :\n".mb_substr(trim($this->extraContext), 0, 4000);
        }

        if ([] === $this->rules) {
            $sections[] = "## Règles métier existantes\n(aucune règle trouvée)";
        } else {
            $lines = [];
            foreach ($this->rules as $rule) {
                $meta = \sprintf(
                    '[%s | fichiers=%d | zones_ombre=%d]',
                    $rule['status'] ?? 'to_review',
                    $rule['files'],
                    $rule['shadow_areas'],
                );
                $statement = null !== $rule['statement'] && '' !== trim($rule['statement'])
                    ? ' — '.mb_substr(trim($rule['statement']), 0, 350)
                    : '';
                $lines[] = \sprintf('- %s (%s) %s%s', $rule['code'], $rule['title'], $meta, $statement);
            }
            $sections[] = "## Règles métier existantes\n".implode("\n", $lines);
        }

        if ([] === $this->memories) {
            $sections[] = "## Mémoires & décisions actives\n(aucune mémoire trouvée)";
        } else {
            $lines = [];
            foreach ($this->memories as $memory) {
                $verdict = null !== $memory['verdict'] ? ', verdict='.$memory['verdict'] : '';
                $lines[] = \sprintf('- [%s%s] %s', $memory['type'] ?? 'fact', $verdict, mb_substr($memory['text'], 0, 350));
            }
            $sections[] = "## Mémoires & décisions actives\n".implode("\n", $lines);
        }

        if ([] === $this->documents) {
            $sections[] = "## Documents fonctionnels\n(aucun document trouvé)";
        } else {
            $blocks = [];
            foreach ($this->documents as $doc) {
                $header = \sprintf('### Document « %s » (%s)', $doc['title'], $doc['format'] ?? 'md');
                $body = '' !== $doc['excerpt'] ? $doc['excerpt'] : '(contenu non extrait)';
                $blocks[] = $header."\n".$body;
            }
            $sections[] = "## Documents fonctionnels\n".implode("\n\n", $blocks);
        }

        if ([] === $this->codeFiles) {
            $sections[] = "## Fichiers de code liés\n(aucun fichier identifié)";
        } else {
            $lines = array_map(
                static fn (array $file): string => '- '.$file['path'],
                $this->codeFiles,
            );
            $sections[] = "## Fichiers de code liés\n".implode("\n", $lines);
        }

        return implode("\n\n", $sections);
    }
}
