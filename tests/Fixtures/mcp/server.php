<?php

// Small MCP server over STDIO used by the connector tests. FIXTURE_CHARTE overrides the content of one resource
// so that a test can simulate a change in the source.

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

Server::builder()
    ->setServerInfo('fixture', '1.0.0')
    ->addResource(static fn (): string => getenv('FIXTURE_CHARTE') ?: "# Charte qualité\n\nTout ticket cite la règle qu'il modifie.", 'notes://charte', 'charte', 'Charte qualité', mimeType: 'text/markdown')
    ->addResource(static fn (): string => '{"pente_minimale": 4}', 'config://seuils', 'seuils', 'Seuils de détection', mimeType: 'application/json')
    ->addTool(
        static fn (string $user_id = 'quentin'): string => json_encode(['results' => [
            ['id' => 'a1', 'memory' => 'Préfère les tests fonctionnels aux tests unitaires.'],
            ['id' => 'a2', 'memory' => 'Utilise PostgreSQL 18 avec pgvector.'],
            ['id' => 'a3'],
            ['id' => 'a4', 'memory' => 'utilise PostgreSQL 18 avec pgvector.'],
        ]]),
        'get_memories',
        description: 'mem0-like memory listing',
    )
    ->addTool(static fn (): string => "Semaine calme : 3 tickets fermés.", 'weekly_report', description: 'Weekly report')
    ->build()
    ->run(new StdioTransport());
