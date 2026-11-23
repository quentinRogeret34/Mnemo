<?php

namespace App\Document;

use App\Entity\Folder;
use App\Entity\Node;
use App\Enum\NodeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

final class DocumentStorage
{
    public const FORMATS = ['md', 'pdf', 'docx'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/var/storage/%kernel.environment%/documents')]
        private readonly string $storageDir,
    ) {
    }

    /**
     * Copies the file into the workspace storage and records it as a Document node (not flushed).
     *
     * @param array<string, mixed> $metadata
     */
    public function store(?Folder $folder, \SplFileInfo $source, string $originalName, array $metadata = []): Node
    {
        $format = strtolower(pathinfo($originalName, \PATHINFO_EXTENSION));
        if (!\in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported document format "%s".', $format));
        }

        $id = Uuid::v7()->toRfc4122();
        $storagePath = $id.'.'.$format;
        $this->filesystem->copy($source->getPathname(), $this->storageDir.'/'.$storagePath);

        $title = pathinfo($originalName, \PATHINFO_FILENAME);
        if ('md' === $format && preg_match('/^#\s+(.+)$/m', (string) file_get_contents($source->getPathname()), $heading)) {
            $title = trim($heading[1]);
        }

        $document = new Node(null, NodeType::Document, $id, $title, $originalName, null, [
            'format' => $format,
            'size' => $source->getSize(),
            'storage' => $storagePath,
        ] + $metadata);
        $document->moveTo($folder);
        $this->entityManager->persist($document);

        return $document;
    }

    /**
     * Stores generated content as a document named after its title (not flushed).
     *
     * @param array<string, mixed> $metadata
     */
    public function storeContent(?Folder $folder, string $content, string $title, string $format, array $metadata = []): Node
    {
        $filename = (new AsciiSlugger())->slug($title)->lower()->toString() ?: 'document';

        return $this->withTemporaryFile($content, fn (\SplFileInfo $file): Node => $this->store($folder, $file, $filename.'.'.$format, $metadata));
    }

    /**
     * Creates or updates the document imported from the same origin (a connector item, a repository file...).
     * A non-Markdown document keeps the given title, a Markdown one takes its first heading. Not flushed.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array{Node, 'created'|'updated'|'unchanged'}
     */
    public function upsertContent(string $origin, ?Folder $folder, string $content, string $title, string $format, array $metadata = []): array
    {
        $hash = hash('sha256', $content);
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM node WHERE type = :document AND metadata->>\'origin\' = :origin',
            ['document' => NodeType::Document->value, 'origin' => $origin],
        );
        $document = false === $id ? null : $this->entityManager->find(Node::class, $id);

        if (null !== $document && $hash === $document->getContentHash()) {
            return [$document, 'unchanged'];
        }

        if (null === $document) {
            $document = $this->storeContent($folder, $content, $title, $format, ['origin' => $origin] + $metadata);
            if ('md' !== $format) {
                $document->update($title, $document->getPath(), null, $document->getMetadata());
            }
            $status = 'created';
        } else {
            $this->replaceContent($document, $content);
            $status = 'updated';
        }
        $document->setContentHash($hash);

        return [$document, $status];
    }

    /**
     * Folder from a slash-separated path, created if missing (not flushed). An empty path is the root.
     */
    public function folder(?string $path): ?Folder
    {
        $folder = null;
        foreach (array_filter(array_map('trim', explode('/', (string) $path)), static fn (string $value): bool => '' !== $value) as $name) {
            $child = $this->findFolder($name, $folder);
            if (null === $child) {
                $child = new Folder($name, $folder);
                $this->entityManager->persist($child);
            }
            $folder = $child;
        }

        return $folder;
    }

    private function findFolder(string $name, ?Folder $parent): ?Folder
    {
        $needle = mb_strtolower($name);
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Folder && $entity->getParent() === $parent && mb_strtolower($entity->getName()) === $needle) {
                return $entity;
            }
        }
        if (null !== $parent && null === $parent->getId()) {
            return null;
        }

        $dql = null === $parent
            ? 'SELECT f FROM App\Entity\Folder f WHERE f.parent IS NULL AND LOWER(f.name) = LOWER(:name)'
            : 'SELECT f FROM App\Entity\Folder f WHERE f.parent = :parent AND LOWER(f.name) = LOWER(:name)';
        $query = $this->entityManager->createQuery($dql)->setParameter('name', $name)->setMaxResults(1);
        if (null !== $parent) {
            $query->setParameter('parent', $parent);
        }

        /** @var Folder|null $found */
        $found = $query->getOneOrNullResult();

        return $found;
    }

    public function replaceContent(Node $document, string $content): void
    {
        $this->withTemporaryFile($content, fn (\SplFileInfo $file) => $this->replace($document, $file));
    }

    /**
     * Replaces the stored file of a document with a new version of the same format (not flushed).
     */
    public function replace(Node $document, \SplFileInfo $source): void
    {
        $this->filesystem->copy($source->getPathname(), $this->absolutePath($document), true);

        $title = $document->getLabel();
        if ('md' === $document->getMetadata()['format'] && preg_match('/^#\s+(.+)$/m', (string) file_get_contents($source->getPathname()), $heading)) {
            $title = trim($heading[1]);
        }
        $document->update($title, $document->getPath(), null, ['size' => $source->getSize()] + $document->getMetadata());
    }

    public function absolutePath(Node $document): string
    {
        return $this->storageDir.'/'.$document->getMetadata()['storage'];
    }

    /**
     * Markdown body without its leading title, which the page already shows.
     */
    public function markdownBody(Node $document): string
    {
        $path = $this->absolutePath($document);
        $markdown = is_file($path) ? @file_get_contents($path) : false;
        if (false === $markdown) {
            return '';
        }

        return preg_replace('/\A\s*#\s+.+\R/', '', $markdown, 1) ?? $markdown;
    }

    public function delete(Node $document): void
    {
        $this->filesystem->remove($this->absolutePath($document));
        $this->entityManager->remove($document);
    }

    /**
     * @template T
     *
     * @param callable(\SplFileInfo): T $callback
     *
     * @return T
     */
    private function withTemporaryFile(string $content, callable $callback): mixed
    {
        $file = $this->filesystem->tempnam(sys_get_temp_dir(), 'document');
        $this->filesystem->dumpFile($file, $content);
        try {
            return $callback(new \SplFileInfo($file));
        } finally {
            $this->filesystem->remove($file);
        }
    }
}
