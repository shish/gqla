<?php

declare(strict_types=1);

namespace GQLA;

use GraphQL\Type\Definition\ObjectType as GObjectType;
use GraphQL\Type\Definition\InterfaceType as GInterfaceType;
use GraphQL\Type\Definition\EnumType as GEnumType;
use GraphQL\Type\Definition\InputObjectType as GInputObjectType;
use GraphQL\Type\Definition\InputType as GInputType;
use GraphQL\Type\Definition\Type as GType;
use GraphQL\Type\Schema as GSchema;

function log(string $s): void
{
    // echo "$s\n";
}

/**
 * @phpstan-import-type ObjectConfig from GObjectType
 * @phpstan-import-type EnumTypeConfig from GEnumType
 * @phpstan-import-type InputObjectConfig from GInputObjectType
 */
class Schema extends GSchema
{
    /** @var array<string,string> */
    protected array $cls2type = [];

    /** @var array<string,GType> */
    public array $types = [];

    /**
     * @param class-string[] $classes
     * @param string[] $functions
     */
    public function __construct(?array $classes = null, ?array $functions = null)
    {
        $query_base = new GObjectType([
            "name" => "Query",
            "fields" => [],
        ]);
        $mutation_base = new GObjectType([
            "name" => "Mutation",
            "fields" => [],
        ]);

        $this->types = [
            "ID" => GType::id(),
            "string" => GType::string(),
            "String" => GType::string(),
            "int" => GType::int(),
            "Int" => GType::int(),
            "float" => GType::float(),
            "Float" => GType::float(),
            "bool" => GType::boolean(),
            "Boolean" => GType::boolean(),
            "Query" => $query_base,
            "Mutation" => $mutation_base,
        ];
        $classes ??= get_declared_classes();
        $functions ??= get_defined_functions()["user"];

        // First inspect all the classes, and see if any of them are annotated
        // as GraphQL types. Populate $this->types based on these annotations.
        foreach ($classes as $cls) {
            $this->inspectClassAnnotations(new \ReflectionClass($cls));
        }

        // Check for class properties and methods, turn these into Fields
        // of the types we just created (Do this as a second pass, because
        // a Field on object #1 might refer to a Type created by object #2).
        foreach ($classes as $cls) {
            $this->inspectClassItems(new \ReflectionClass($cls));
        }

        // Check for any stand-alone functions which are annotated to become
        // fields via #[Field(extends: "Type")], or queries via #[Query], or
        // mutations via #[Mutation].
        foreach ($functions as $func) {
            $this->inspectFunction(new \ReflectionFunction($func));
        }

        // var_export(array_keys($this->types));

        parent::__construct([
            'query' => $query_base,
            'mutation' => $mutation_base,
        ]);
    }

    /**
     * Go from a graphql type name (eg `"String!"`) to a type
     * object (eg `NonNull(GType::string())`). If we don't currently
     * know of a type object by the given name, then we return
     * a function which does the lookup later.
     *
     * @return GType|callable():GType
     */
    public function maybeGetType(string $n): GType|callable
    {
        if (str_ends_with($n, "!")) {
            // @phpstan-ignore-next-line - nonNull only accepts nullable Types, not all types
            return GType::nonNull($this->maybeGetType(substr($n, 0, strlen($n) - 1)));
        }
        if ($n[0] == "[") {
            return GType::listOf($this->maybeGetType(substr($n, 1, strlen($n) - 2)));
        }
        if ($n == "array") {
            throw new \Exception("Can't use 'array' as a type - you need to use an attribute, eg Field(type: '[string]')");
        }
        if (array_key_exists($n, $this->cls2type)) {
            $n = $this->cls2type[$n];
        }
        if (array_key_exists($n, $this->types)) {
            return $this->types[$n];
        } else {
            return function () use ($n) {
                if (array_key_exists($n, $this->cls2type)) {
                    $n = $this->cls2type[$n];
                }
                if (array_key_exists($n, $this->types)) {
                    return $this->types[$n];
                }
                $keys = join(", ", array_keys($this->types));
                throw new \Exception("Failed to find deferred type for $n. Known types: $keys");
            };
        }
    }
    /**
     * @return (GInputType&GType)|callable():(GInputType&GType)
     */
    public function maybeGetInputType(string $n): GInputType|callable
    {
        // we trust the user to not mix up ObjectTypes and InputObjectTypes...
        // @phpstan-ignore-next-line
        return $this->maybeGetType($n);
    }


    /**
     * When we come across
     *
     *   #[Type(name: Foo)]
     *   class Bar {
     *     ...
     *   }
     *
     * then we want to create one new ObjectType named Foo,
     * but add two entries into $types for both Foo
     * and Bar, so that later on when somebody does
     *
     *   #[Field]
     *   find_thing(): Bar {
     *     ...
     *   }
     *
     * then we will be able to do a lookup for Bar, and we
     * know that our find_thing() API call should return
     * a Foo object.
     *
     * Additionally, when somebody does
     *
     *   #[Field(extends: "Foo")]
     *   function blah(Foo $self): int {
     *     return $self->thing;
     *   }
     *
     * then we also want to create and register a Foo object
     * with a blah() field
     */
    private function createType(string $name, GType $type): void
    {
        if (array_key_exists($name, $this->types)) {
            throw new \Exception("Type $name already exists");
        }
        $this->types[$name] = $type;
    }

    /**
     * Get args in graphql format by inspecting a function.
     * Also accepts some manual overrides, eg if a function
     * takes an array as input, then we need to provide an
     * override to tell graphql what kind of array it is.
     *
     *   #[Field(args: ["tags" => "[string]"])]
     *   function foo(int $id, array $tags): int {
     *     ...
     *   }
     *
     * results in
     *
     *   [
     *     "id" => GType::int(),
     *     "tags" => GType::listOf(GType::string())
     *   ]
     *
     * @param array<string,string> $argTypes
     * @return array<string,GType|callable>
     */
    public function getArgs(array $argTypes, \ReflectionMethod|\ReflectionFunction $method, bool $ignoreFirst): array
    {
        $args = [];
        $n = 0;
        foreach ($method->getParameters() as $p) {
            if ($ignoreFirst && $n++ == 0) {
                continue;
            }
            $name = $p->getName();
            $type = $this->maybeGetType($argTypes[$name] ?? $this->phpTypeToGraphQL($p->getType()));
            $args[$name] = $type;
        }
        return $args;
    }

    public function phpTypeToGraphQL(\ReflectionType|null $type): string
    {
        if (is_null($type)) {
            throw new \Exception("PHP Type not specified (TODO: have an error message that doesn't suck)");
        }
        if (!is_a($type, \ReflectionNamedType::class)) {
            throw new \Exception("GQLA only supports named types, not {$type}");
        }
        return $type->getName() . ($type->allowsNull() ? "" : "!");
    }

    /**
     * Look at a function or a method, if it is a query or
     * a mutation, add it to the relevant list
     */
    public function inspectFunction(\ReflectionMethod|\ReflectionFunction $meth, ?string $objName = null): void
    {
        foreach ($meth->getAttributes() as $methAttr) {
            if (in_array($methAttr->getName(), [Field::class, Query::class, Mutation::class])) {
                $methName = $methAttr->getArguments()['name'] ?? $this->noNamespace($meth->name);
                $methType = $methAttr->getArguments()['type'] ?? $this->phpTypeToGraphQL($meth->getReturnType());
                $extends = $methAttr->getArguments()['extends'] ?? $objName;
                if ($methAttr->getName() == Query::class) {
                    $extends = "Query";
                }
                if ($methAttr->getName() == Mutation::class) {
                    $extends = "Mutation";
                }
                if (!$extends) {
                    throw new \Exception(
                        "Can't expose method $methName - it isn't a method of a known object, ".
                        "and it isn't specifying extends: \"Blah\""
                    );
                }

                $extendingOtherObject = ($extends != $objName && $extends != "Query" && $extends != "Mutation");

                $parentType = $this->types[$extends] ?? null;
                if (!$parentType) {
                    throw new \Exception("Can't find parent Type $extends for method $methName (Known types: " .
                        join(", ", array_keys($this->types)) . ")");
                }
                // 'fields' can be a callable, but the objects _we_ create are always arrays
                // @phpstan-ignore-next-line
                $parentType->config['fields'][$methName] = [
                    'type' => $this->maybeGetType($methType),
                    'description' => $methAttr->getArguments()['description'] ?? null,
                    'deprecationReason' => $methAttr->getArguments()['deprecationReason'] ?? null,
                    'args' => $this->getArgs($methAttr->getArguments()['args'] ?? [], $meth, $extendingOtherObject),
                    'resolve' => static function ($rootValue, array $args) use ($meth, $extends, $objName) {
                        // If we're adding a new query or mutation, we ignore
                        // $rootValue and the function has no $this
                        if ($extends == "Query" || $extends == "Mutation") {
                            if (is_a($meth, \ReflectionMethod::class)) {
                                return $meth->invokeArgs(null, $args);
                            } elseif (is_a($meth, \ReflectionFunction::class)) {
                                return $meth->invokeArgs($args);
                            }
                        }
                        // If we're attaching a dynamic field to this object, then
                        // we invoke it with $rootValue as $this.
                        elseif ($extends == $objName) {
                            if (is_a($meth, \ReflectionMethod::class)) {
                                return $meth->invokeArgs($rootValue, $args);
                            } elseif (is_a($meth, \ReflectionFunction::class)) {
                                return $meth->invokeArgs($args);
                            }
                        }
                        // If we're attaching a dynamic field to another object,
                        // then we invoke it as a static method with $rootValue
                        // as the first parameter (except for queries and mutations,
                        // where the function doesn't take the object at all).
                        else {
                            if (is_a($meth, \ReflectionMethod::class)) {
                                return $meth->invokeArgs(null, [$rootValue, ...$args]);
                            } elseif (is_a($meth, \ReflectionFunction::class)) {
                                return $meth->invokeArgs([$rootValue, ...$args]);
                            }
                        }
                    },
                ];

                log("- Found dynamic field $extends.$methName ($methType)");
            }
        }
    }

    /**
     * @template T of object
     * @param \ReflectionClass<T> $reflection
     */
    public function inspectClassAnnotations(\ReflectionClass $reflection): void
    {
        foreach ($reflection->getAttributes() as $objAttr) {
            $attrName = $objAttr->getName();
            if (!in_array($attrName, [Type::class, InterfaceType::class, Enum::class, InputObjectType::class])) {
                continue;
            }

            $objName = $objAttr->getArguments()['name'] ?? $this->noNamespace($reflection->getName());
            $this->cls2type[$reflection->getName()] = $objName;

            log("Found $attrName $objName");
            match ($attrName) {
                Type::class => $this->createType(
                    $objName,
                    new GObjectType([
                        "name" => $objName,
                        "fields" => [],
                        "interfaces" => fn () => array_map(
                            function ($x) use ($objName) {
                                $t = $this->types[$x];
                                if ($t instanceof GInterfaceType) {
                                    return $t;
                                }
                                throw new \Exception("Type $objName has $x as an interface, but $x is not an InterfaceType");
                            },
                            $objAttr->getArguments()['interfaces'] ?? []
                        ),
                    ]),
                ),
                InterfaceType::class => $this->createType(
                    $objName,
                    new GInterfaceType([
                        'name' => $objName,
                        'fields' => [],
                    ])
                ),
                Enum::class => $this->createType(
                    $objName,
                    new GEnumType([
                        "name" => $objName,
                        "values" => $this->getEnumValues($reflection->getConstants())
                    ])
                ),
                InputObjectType::class => $this->createType(
                    $objName,
                    new GInputObjectType([
                        'name' => $objName,
                        'fields' => array_map(function ($p) {
                            $field = [
                                'name' => $p->getName(),
                                'type' => $this->maybeGetInputType($this->phpTypeToGraphQL($p->getType())),
                            ];
                            if ($p->isDefaultValueAvailable()) {
                                $field['defaultValue'] = $p->getDefaultValue();
                            }
                            return $field;
                        }, $reflection->getConstructor()?->getParameters() ?? []),
                        'parseValue' => fn (array $values) => $reflection->newInstanceArgs($values),
                    ])
                ),
            };
        }
    }

    /**
     * @template T of object
     * @param \ReflectionClass<T> $reflection
     */
    public function inspectClassItems(\ReflectionClass $reflection): void
    {
        $objName = null;

        foreach ($reflection->getAttributes() as $objAttr) {
            if (in_array($objAttr->getName(), [Type::class, InterfaceType::class, InputObjectType::class])) {
                $objName = $objAttr->getArguments()['name'] ?? $this->noNamespace($reflection->getName());
            }
        }

        foreach ($reflection->getProperties() as $prop) {
            foreach ($prop->getAttributes() as $propAttr) {
                if ($propAttr->getName() == Field::class) {
                    $propName = $propAttr->getArguments()['name'] ?? $prop->getName();
                    $propType = $propAttr->getArguments()['type'] ?? $this->phpTypeToGraphQL($prop->getType());
                    $extends = $propAttr->getArguments()['extends'] ?? $objName;
                    if (is_null($extends)) {
                        throw new \Exception("Field $propName must be attached to a Type, either implicitly or with 'extends'");
                    }
                    $parentType = $this->types[$extends] ?? null;
                    if (is_null($parentType)) {
                        throw new \Exception("Field $propName is trying to extend $extends, but that Type does not exist");
                    }
                    // 'fields' can be a callable, but the objects _we_ create are always arrays
                    // @phpstan-ignore-next-line
                    $parentType->config['fields'][$propName] = [
                        'type' => $this->maybeGetType($propType),
                    ];
                    log("- Found field $extends.$propName ($propType)");
                }
            }
        }

        foreach ($reflection->getMethods() as $meth) {
            $this->inspectFunction($meth, $objName);
        }
    }

    /**
     * A helper function to convert a PHP Enum's constants
     * into a format that GraphQL expects for GEnumType
     *
     * @param array<string,mixed> $consts
     * @return array<string,array{value:mixed}>
     */
    private function getEnumValues(array $consts): array
    {
        $vals = [];
        foreach ($consts as $k => $v) {
            $vals[$k] = [
                'value' => $v,
                // 'description' =>
            ];
        }
        return $vals;
    }

    /**
     * Helper function to remove the namespace from a class or function name.
     */
    public function noNamespace(string $name): string
    {
        $parts = explode("\\", $name);
        return $parts[count($parts) - 1];
    }
}
