<?php

declare(strict_types=1);

namespace Ahmednour\EloquentRag;

/**
 * Purely in-memory, declarative definition of how a model's RAG document is
 * rendered: which of its own attributes feed the content, and which related
 * model paths it depends on. No DB access, no magic relationship discovery
 * — see ADR-0002.
 */
final class RagDefinition
{
    /** @var list<string> */
    private array $content = [];

    /** @var list<string> */
    private array $relations = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  list<string>  $attributes  Model attribute names to render, in order.
     */
    public function content(array $attributes): self
    {
        $this->content = $attributes;

        return $this;
    }

    /**
     * Declares a dependency on a related model's attribute, e.g.
     * 'category.name'. The path is both rendered into the document and
     * (from Phase 2 onward) recorded as a `rag_dependencies` row so the
     * document can be found and invalidated when the related model changes.
     */
    public function relation(string $path): self
    {
        $this->relations[] = $path;

        return $this;
    }

    /** @return list<string> */
    public function contentAttributes(): array
    {
        return $this->content;
    }

    /** @return list<string> */
    public function relations(): array
    {
        return $this->relations;
    }
}
