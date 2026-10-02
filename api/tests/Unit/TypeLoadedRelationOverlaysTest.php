<?php

declare(strict_types=1);

use App\Support\Scramble\TypeLoadedRelationOverlays;
use Dedoc\Scramble\Support\Generator\Combined\AllOf;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * A document with one resource schema whose `employee` is optional, and an
 * array of that resource in the "employee is loaded" form Scramble emits.
 *
 * @return array{OpenApi, ObjectType}
 */
function documentWithLoadedOverlay(array $required): array
{
    $components = new Components;
    $employee = (new ObjectType)->addProperty('name', new StringType)->setRequired(['name']);
    $resource = (new ObjectType)
        ->addProperty('public_id', new StringType)
        ->addProperty('employee', $employee)
        ->setRequired(['public_id']);
    $reference = $components->addSchema('RecordResource', Schema::fromType($resource));

    $overlay = (new ObjectType)->setRequired($required);
    $list = (new ArrayType)->setItems((new AllOf)->setItems([$reference, $overlay]));

    $document = new OpenApi('3.1.0');
    $document->setComponents($components);
    $components->addSchema('Listing', Schema::fromType((new ObjectType)->addProperty('data', $list)));

    return [$document, $overlay];
}

it('gives a loaded-relations overlay the referenced property schemas', function () {
    [$document, $overlay] = documentWithLoadedOverlay(['employee']);

    (new TypeLoadedRelationOverlays)($document);

    // Without properties, openapi-typescript renders the overlay as
    // Record<string, never>, which turns every field of the resource to never.
    expect(array_keys($overlay->properties))->toBe(['employee'])
        ->and($overlay->toArray())->toBe([
            'type' => 'object',
            'properties' => [
                'employee' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string']],
                    'required' => ['name'],
                ],
            ],
            'required' => ['employee'],
        ]);
});

it('leaves a key the referenced schema does not declare alone', function () {
    [$document, $overlay] = documentWithLoadedOverlay(['unknown_relation']);

    (new TypeLoadedRelationOverlays)($document);

    expect($overlay->properties)->toBe([])
        ->and($overlay->required)->toBe(['unknown_relation']);
});
