<?php declare(strict_types=1);

namespace Igni\OpenApi\Annotation;

/**
 * @Annotation
 * @see https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.2.md#schemaObject
 */
class Schema extends Annotation
{
    use DataType;

    /**
     * Title schema
     * @var string
     */
    public $title;

    /**
     * A brief description of the schema. CommonMark syntax MAY be used for rich text representation.
     * @var string
     */
    public $description;

    /**
     * List of required properties in the schema
     * @var string[]
     */
    public $required = [];

    /**
     * @var Property[]
     */
    public $properties;

    protected function getRequiredParameters(): array
    {
        return [];
    }
}