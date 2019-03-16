<?php
namespace J6s\PhpArch\Parser\Visitor;

use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\TypeResolver;
use phpDocumentor\Reflection\Types\Context;
use phpDocumentor\Reflection\Types\Object_;
use PhpParser\Node;

class DocBlockTypeAnnotations extends NamespaceCollectingVisitor
{
    /** @var string */
    private $lastNamespace;

    /** @var string[] */
    private $useStatements = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->lastNamespace = $node->name ? $node->name->toString() : '';
            $this->useStatements = [];
        } elseif ($node instanceof Node\Stmt\UseUse) {
            $this->useStatements[$this->extractAlias($node)] = $node->name->toString();
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $context = new Context($this->lastNamespace, $this->useStatements);
            if ($node->hasAttribute('comments')) {
                $this->extractDocBlocks((array)$node->getAttribute('comments'), $context);
            }
        }
    }

    private function extractDocBlocks(array $docBlocks, Context $context): void
    {
        $factory  = \phpDocumentor\Reflection\DocBlockFactory::createInstance();

        foreach ($docBlocks as $docBlockString) {
            $docBlock = $factory->create((string) $docBlockString);

            foreach ($docBlock->getTags() as $tag) {
                if (($tag instanceof Param || $tag instanceof Return_) && $tag->getType() !== null) {
                    $type = $this->typeToFullyQualified($tag->getType(), $context);
                    if ($type) {
                        $this->namespaces[] = $type;
                    }
                }
            }
        }
    }

    private function typeToFullyQualified(Type $type, Context $context): ?string
    {
        if (!($type instanceof Object_)) {
            return null;
        }

        $resolvableType = $this->stripLeadingBackslashIfAliasedType((string) $type, $context);
        $resolvedType = (string) (new TypeResolver())->resolve($resolvableType, $context);
        return ltrim($resolvedType, '\\');
    }

    private function extractAlias(Node\Stmt\UseUse $node): string
    {
        if (!method_exists($node, 'getAlias')) {
            // Compatibility mode: nikic/php-parser@3.x
            return (string) $node->alias;
        }

        return $node->getAlias()->toString();
    }

    private function stripLeadingBackslashIfAliasedType(string $namespace, Context $context): string
    {
        $withoutBackslash = ltrim($namespace, '\\');
        [ $firstPart ] = explode('\\', $withoutBackslash);
        if (array_key_exists($firstPart, $context->getNamespaceAliases())) {
            return $withoutBackslash;
        }

        return $namespace;
    }

    private function isImported(string $namespace, Context $context): bool
    {
        [ $firstPart ] = explode($namespace, '\\');
        return array_key_exists((string) $firstPart, $context->getNamespaceAliases());
    }
}
