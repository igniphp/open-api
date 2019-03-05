<?php declare(strict_types=1);

namespace Igni\OpenApi\Annotation\Parser;

use Igni\OpenApi\Annotation\Parser\MetaData\Annotation;
use Igni\OpenApi\Annotation\Parser\MetaData\Enum;
use Igni\OpenApi\Annotation\Parser\MetaData\Required;
use Igni\OpenApi\Annotation\Parser\MetaData\Target;
use Igni\OpenApi\Exception\ParserException;
use Igni\OpenApi\Exception\TokenizerException;
use PhpParser\ParserFactory;
use ReflectionClass;

class Parser
{
    private const VALUE_TOKENS = [
        Token::T_NULL,
        Token::T_STRING,
        Token::T_FLOAT,
        Token::T_INTEGER,
        Token::T_TRUE,
        Token::T_FALSE
    ];

    private const PHP_ANNOTATIONS = [
        // PHP Documentator
        'api',
        'author',
        'category',
        'copyright',
        'deprecated',
        'example',
        'filesource',
        'global',
        'ignore',
        'internal',
        'license',
        'link',
        'method',
        'package',
        'param',
        'property',
        'property-read',
        'property-write',
        'return',
        'see',
        'since',
        'source',
        'subpackage',
        'throws',
        'todo',
        'uses',
        'used-by',
        'var',
        'version',

        // PHP Unit
        'codeCoverageIgnore',
        'codeCoverageIgnoreEnd',
        'codeCoverageIgnoreStart',

        //PhpStorm
        'noinspection',

        //PhpCodeSniffer
        'codingStandardsIgnoreStart',
        'codingStandardsIgnoreEnd',

        // PEAR
        'package_version',

    ];

    private const BUILT_IN = [
        'Annotation' => Annotation::class,
        'Enum' => Enum::class,
        'Required' => Required::class,
        'Target' => Target::class,
    ];

    private $ignoreNotImported = false;
    private $phpParser;
    private $ignored = [];
    private $autoloadNamespaces = [];
    private $metaData = [
        Annotation::class => [
            'target' => [Target::TARGET_CLASS],
            'constructor' => false,
            'validate' => false,
            'properties' => [],
        ],
        Target::class => [
            'target' => [Target::TARGET_CLASS],
            'constructor' => true,
            'validate' => false,
            'properties' => [
                'value' => [
                    'required' => true,
                    'type' => ['string'],
                    'enum' => [
                        Target::TARGET_CLASS,
                        Target::TARGET_PROPERTY,
                        Target::TARGET_METHOD,
                        Target::TARGET_FUNCTION,
                        Target::TARGET_ANNOTATION,
                        Target::TARGET_ALL
                    ]
                ]
            ],
        ],
        Required::class => [
            'target' => [Target::TARGET_PROPERTY],
            'constructor' => false,
            'validate' => false,
            'properties' => [
                'value' => [
                    'default' => true,
                    'type' => 'bool',
                ],
            ],
        ],
        Enum::class => [
            'target' => [Target::TARGET_PROPERTY],
            'constructor' => true,
            'validate' => false,
            'properties' => [
                'value' => [
                    'type' => ['string'],
                    'required' => true,
                ]
            ],
        ]
    ];

    public function __construct()
    {
        $this->phpParser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
    }

    public function registerNamespace(string $namespace, string $alias) : void
    {
        $this->autoloadNamespaces[$alias] = $namespace;
    }

    public function addIgnore(string $name) : void
    {
        $this->ignored[] = $name;
    }

    public function ignoreNotImportedAnnotations(bool $ignore = true) : void
    {
        $this->ignoreNotImported = $ignore;
    }

    /**
     * @param string $docBlock
     * @param Context $context
     * @return array
     * @throws
     */
    public function parse(string $docBlock, Context $context = null): array
    {
        if ($context === null) {
            $context = new Context(Target::TARGET_ALL, self::class . '::' . __METHOD__ . '()');
        }

        $tokenizer = new Tokenizer($docBlock);
        $tokenizer->tokenize();

        // Lets search for fist annotation occurrence in docblock
        if (!$tokenizer->seek(Token::T_AT)) {
            // No annotations in docblock.
            return [];
        }
        $annotations = [];

        while ($tokenizer->valid() && $tokenizer->seek(Token::T_AT)) {

            // Annotation must be preceded by a new line token, otherwise it should be ignored
            if ($tokenizer->key() > 1 && $tokenizer->at($tokenizer->key() - 1)->getType() !== Token::T_EOL) {
                $tokenizer->next();
                continue;
            }
            // Skip @
            $tokenizer->next();
            $annotation = $this->parseAnnotation($tokenizer, $context);
            if ($annotation === null) {
                continue;
            }
            $annotations[] = $annotation;
        }

        return $annotations;
    }

    private function parseAnnotation(Tokenizer $tokenizer, Context $context, $nested = false)
    {
        $identifier = $this->parseIdentifier($tokenizer);

        // Ignore one-line utility annotations
        if (in_array($identifier, self::PHP_ANNOTATIONS, true)) {
            return null;
        }

        $arguments = $this->parseArguments($tokenizer, $context);

        // Other ignored annotations have to be parsed before we ignore them.
        if (in_array($identifier, $this->ignored, true)) {
            return null;
        }

        $annotationClass = $this->resolveFullyQualifiedClassName($identifier, $context);

        if (!class_exists($annotationClass)) {
            if ($this->ignoreNotImported) {
                return null;
            }
            throw TokenizerException::forUnknownAnnotationClass($identifier, $context);
        }

        $metaData = $this->getAnnotationMetaData($annotationClass);

        $target = $context->getTarget();
        if ($nested) {
            $target = Target::TARGET_ANNOTATION;
        }

        if (isset($metaData['target']) && 
            !in_array(Target::TARGET_ALL, $metaData['target'], true) &&
            !in_array($target, $metaData['target'])
        ) {

        }

        if (!$metaData['constructor']) {
            $annotation = new $annotationClass();
            $valueArgs = [];
            foreach ($arguments as $key => $value) {
                if (is_numeric($key)) {
                    $valueArgs[] = $value;
                    continue;
                }
                if (property_exists($annotation, $key)) {
                    $annotationClass->{$key} = $value;
                }
            }
            if (property_exists($annotation, 'value')) {
                $annotationClass->value = $valueArgs;
            }
        } else {
            $annotation = new $annotationClass($arguments);
        }

        return $annotation;
    }

    private function parseIdentifier(Tokenizer $tokenizer)
    {
        $identifier = '';

        $next = Token::T_IDENTIFIER;
        while ($tokenizer->valid() && $this->match($tokenizer, $next)) {
            if ($next === Token::T_IDENTIFIER) {
                $next = Token::T_NAMESPACE_SEPARATOR;
            } else {
                $next = Token::T_IDENTIFIER;
            }
            $identifier .= $tokenizer->current()->getValue();
            $tokenizer->next();
        }

        return $identifier;
    }

    private function parseArguments(Tokenizer $tokenizer, Context $context) : array
    {
        $arguments = [];

        if ($tokenizer->current()->getType() !== Token::T_OPEN_PARENTHESIS) {
            return $arguments;
        }

        $this->expect(Token::T_OPEN_PARENTHESIS, $tokenizer, $context);
        $tokenizer->next();

        $this->parseArgument($tokenizer, $context, $arguments);

        while ($this->match($tokenizer, Token::T_COMMA)) {
            $tokenizer->next();
            $this->parseArgument($tokenizer, $context, $arguments);
        }

        $this->expect(Token::T_CLOSE_PARENTHESIS, $tokenizer, $context);

        return $arguments;
    }


    private function parseArgument(Tokenizer $tokenizer, Context $context, array &$arguments) : void
    {
        $this->ignoreEndOfLine($tokenizer);
        // There was a comma with no value afterwards
        if ($this->match($tokenizer, Token::T_CLOSE_PARENTHESIS)) {
            return;
        }

        // key / value pair
        if ($tokenizer->at($tokenizer->key() + 1)->getType() === Token::T_EQUALS) {
            $key = $tokenizer->current()->getValue();
            $this->skip(2, $tokenizer);
            $arguments[$key] = $this->parseValue($tokenizer, $context);
            return;
        }

        // Just value
        $arguments[] = $this->parseValue($tokenizer, $context);
        $this->ignoreEndOfLine($tokenizer);
    }

    private function parseValue(Tokenizer $tokenizer, Context $context)
    {
        $token = $tokenizer->current();

        // Resolve annotation
        if ($token->getType() === Token::T_AT) {
            return $this->parseAnnotation($tokenizer, $context, true);
        }

        // Resolve primitives
        if (in_array($token->getType(), self::VALUE_TOKENS, true)) {
            $value = $token->getValue();
            $tokenizer->next();
            return $value;
        }

        // Identifier
        $this->expect(Token::T_IDENTIFIER, $tokenizer, $context);
        $identifier = $this->parseIdentifier($tokenizer);
        $token = $tokenizer->current();

        // Resolve ::class
        if ($token->getType() === Token::T_COLON) {
            if (strtolower($this->catch(2, $tokenizer)) === ':class') {
                $tokenizer->next();
                return $this->resolveFullyQualifiedClassName($identifier, $context);
            }
            throw ParserException::forUnexpectedToken($tokenizer->current(), $context);
        }

        // Resolve constant
        if ($token->getType() === Token::T_COMMA || $token->getType() === Token::T_CLOSE_PARENTHESIS) {
            if (defined($identifier)) {
                $tokenizer->next();
                return constant($identifier);
            }
            throw ParserException::forUnexpectedToken($token, $context);
        }
    }

    private function resolveFullyQualifiedClassName(string $identifier, Context $context) : ?string
    {
        if (isset(self::BUILT_IN[$identifier])) {
            return self::BUILT_IN[$identifier];
        }

        if (class_exists($identifier)) {
            return $identifier;
        }

        $identifier = explode('\\', $identifier);
        $imports = $context->getImports() + $this->autoloadNamespaces;
        if (isset($imports[$identifier[0]])) {
            $identifier = array_merge(explode('\\', $imports[$identifier[0]]), array_slice($identifier, 1));
        }
        $identifier = implode('\\', $identifier);
        if (class_exists($identifier)) {
            return $identifier;
        }

        return null;
    }

    private function getAnnotationMetaData(string $annotationClass) : array
    {
        if (isset($this->metaData[$annotationClass])) {
            return $this->metaData[$annotationClass];
        }

        $reflection = new ReflectionClass($annotationClass);
        $annotations = $this->parse($reflection->getDocComment(), Context::fromReflectionClass($reflection));

        $foundAnnotationDeclaration = false;
        $target = null;
        foreach ($annotations as $annotation) {
            switch (get_class($annotation)) {
                case Annotation::class:
                    $foundAnnotationDeclaration = true;
                    break;
                case Target::class:
                    $target = $annotation->value;
                    break;
            }
        }
    }

    private function match(Tokenizer $tokenizer, int $type) : bool
    {
        return $tokenizer->current()->getType() === $type;
    }

    private function matchAny(Tokenizer $tokenizer, int ...$types) : bool
    {
        return in_array($tokenizer->current()->getType(), $types, true);
    }

    private function expect(int $expectedType, Tokenizer $tokenizer, Context $context) : void
    {
        if ($expectedType !== $tokenizer->current()->getType()) {
            throw ParserException::forUnexpectedToken($tokenizer->current(), $context);
        }
    }

    private function ignoreEndOfLine(Tokenizer $tokenizer) : bool
    {
        if ($tokenizer->current()->getType() === Token::T_EOL) {
            $this->skip(1, $tokenizer);
            return true;
        }

        return false;
    }

    private function skip(int $length, Tokenizer $tokenizer) : void
    {
        for (;$length > 0; $length--) {
            $tokenizer->next();
            if (!$tokenizer->valid()) {
                return;
            }
        }
    }

    private function catch(int $length, Tokenizer $tokenizer) : string
    {
        $value = '';
        for (;$length > 0; $length--) {
            $tokenizer->next();
            if (!$tokenizer->valid()) {
                return $value;
            }

            $value .= $tokenizer->current()->getValue();
        }

        return $value;
    }
}
