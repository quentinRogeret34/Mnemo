<?php

namespace App\Ingest;

use Symfony\Component\Process\Process;

/**
 * Runs graphify on a repository: AST only, no LLM and no API key.
 */
class GraphifyRunner
{
    private const TIMEOUT = 900;

    /**
     * @return string path of the graph.json written under the output directory
     */
    public function extract(string $repository, string $output): string
    {
        $process = new Process(['graphify', 'extract', $repository, '--code-only', '--out', $output], null, null, null, self::TIMEOUT);
        $process->run();
        $graph = $output.'/graphify-out/graph.json';
        if (!$process->isSuccessful() || !is_file($graph)) {
            throw new \RuntimeException(\sprintf('graphify a échoué : %s', mb_substr(trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'code '.$process->getExitCode(), -500)));
        }

        return $graph;
    }
}
