<?php declare(strict_types=1);

namespace Igni\OpenApi\Annotation;

use Doctrine\Common\Annotations\Annotation\Required;

/**
 * @Annotation
 * @see https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.2.md#responseObject
 */
class Response extends Annotation
{
    /**
     * A brief description of the response object. CommonMark syntax MAY be used for rich text representation.
     * @var string
     * @Required
     */
    public $description;

    /**
     * @var \Igni\OpenApi\Annotation\Header[]
     */
    public $headers;

    /**
     * @var \Igni\OpenApi\Annotation\Schema|\Igni\OpenApi\Annotation\Reference
     * @Required
     */
    public $schema;

    /**
     * @var \Igni\OpenApi\Annotation\Link[]|\Igni\OpenApi\Annotation\Reference[]
     */
    public $links = [];
}