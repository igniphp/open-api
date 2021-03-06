<?php declare(strict_types=1);

namespace Igni\OpenApi\Annotation\PathItem;

use Doctrine\Common\Annotations\Annotation\Required;
use Doctrine\Common\Annotations\Annotation\Target;
use Igni\OpenApi\Annotation\Annotation;

/**
 * @Annotation
 * @see https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.2.md#operationObject
 * @Target("ALL")
 */
class Operation extends Annotation
{
    /**
     * A list of tags for API documentation control.
     * Tags can be used for logical grouping of operations by resources or any other qualifier.
     *
     * @var string[]
     */
    public $tags;

    /**
     * A short summary of what the operation does.
     * @var string
     */
    public $summary;

    /**
     * A verbose explanation of the operation behavior. CommonMark syntax MAY be used for rich text representation.
     * @var string
     */
    public $description;

    /**
     * Additional external documentation for this operation.
     * @var string
     */
    public $externalDocs;

    /**
     * A list of parameters that are applicable for this operation.
     * @var \Igni\OpenApi\Annotation\Parameter[]
     */
    public $parameters;

    /**
     * The request body applicable for this operation.
     * @var
     */
    public $requestBody;

    /**
     * The list of possible responses as they are returned from executing this operation.
     * @var \Igni\OpenApi\Annotation\Response[]
     * @Required
     */
    public $responses;

    /**
     * Declares this operation to be deprecated. Consumers SHOULD refrain from usage of the declared operation.
     * @var bool
     */
    public $deprecated = false;

    /**
     * @var \Igni\OpenApi\Annotation\SecurityScheme\SecurityRequirement
     */
    public $security;
}