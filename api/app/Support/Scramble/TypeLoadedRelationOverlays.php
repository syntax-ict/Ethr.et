<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use Dedoc\Scramble\Support\Generator\Combined\AllOf;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;

/**
 * Gives the "these relations are loaded" overlay its property types.
 *
 * Where a controller eager-loads a resource's `whenLoaded()` relations,
 * Scramble publishes the resource as `allOf: [{$ref}, {type: object,
 * required: [employee, shift]}]` — valid JSON Schema, saying the referenced
 * schema's optional `employee` and `shift` are present here. The overlay names
 * the keys but carries no `properties`, and openapi-typescript renders an
 * object with no properties as `Record<string, never>`. Intersected with the
 * resource, that made every field `never`: 70-odd response types in
 * `generated.ts` were unusable, and the frontend restated them by hand
 * (audit N13).
 *
 * This copies each required key's schema from the referenced component into
 * the overlay, so the TypeScript reads `Resource & { employee: Employee }`.
 * Nothing else changes: an overlay that already has properties, or whose
 * keys the referenced schema does not declare, is left as Scramble built it.
 */
final class TypeLoadedRelationOverlays
{
    /** @var array<int, true> */
    private array $seen = [];

    private Components $components;

    public function __invoke(OpenApi $document): void
    {
        $this->seen = [];
        $this->components = $document->components;
        $this->walk($document);
    }

    private function walk(mixed $node): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                $this->walk($child);
            }

            return;
        }

        // Only the document's own model is walked; a Reference's Components is
        // private and reached through `components` on the document instead.
        if (! is_object($node) || ! str_starts_with($node::class, 'Dedoc\\Scramble\\Support\\Generator\\')) {
            return;
        }

        $id = spl_object_id($node);
        if (isset($this->seen[$id])) {
            return;
        }
        $this->seen[$id] = true;

        if ($node instanceof AllOf) {
            $this->fillOverlay($node);
        }

        foreach (get_object_vars($node) as $child) {
            $this->walk($child);
        }
    }

    private function fillOverlay(AllOf $allOf): void
    {
        $reference = null;
        $overlays = [];

        foreach ($allOf->items as $item) {
            if ($item instanceof Reference && $item->referenceType === 'schemas') {
                $reference = $item;
            } elseif ($item instanceof ObjectType && $item->properties === [] && $item->required !== []) {
                $overlays[] = $item;
            }
        }

        if ($reference === null || $overlays === [] || ! $this->components->hasSchema($reference->fullName)) {
            return;
        }

        $schema = $this->components->getSchema($reference->fullName);
        if (! $schema instanceof Schema || ! $schema->type instanceof ObjectType) {
            return;
        }

        foreach ($overlays as $overlay) {
            foreach ($overlay->required as $key) {
                $property = $schema->type->properties[$key] ?? null;
                if ($property !== null) {
                    $overlay->addProperty($key, $property->clone());
                }
            }
        }
    }
}
