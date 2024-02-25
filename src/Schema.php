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

class Schema extends GSchema
{
    /** @var array<string,string> */
    protected array $cls2type = [];

    /** @var array<string,GType> */
    public array $types = [];

    /**
     * @param array<string,GType> $types
     * @param class-string[] $classes
     * @param string[] $functions
     */
    public function __construct(?array $types = null, ?array $classes = null, ?array $functions = null)
    {
        $this->types = $types ?? [
            "ID" => GType::id(),
            "string" => GType::string(),
            "String" => GType::string(),
            "int" => GType::int(),
            "Int" => GType::int(),
            "float" => GType::float(),
            "Float" => GType::float(),
            "bool" => GType::boolean(),
            "Boolean" => GType::boolean(),
        ];
        $classes ??= get_declared_classes();
        $functions ??= get_defined_functions()["user"];

        foreach ($classes as $cls) {
            $this->inspectClass(new \ReflectionClass($cls));
        }
        foreach ($functions as $func) {
            $this->inspectFunction(new \ReflectionFunction($func));
        }

        // var_export(array_keys($this->types));

        $config = [];
        if (in_array("Query", $this->types)) {
            $config["query"] = $this->getOrCreateObjectType("Query");
        }
        if (in_array("Mutation", $this->types)) {
            $config["mutation"] = $this->getOrCreateObjectType("Mutation");
        }
        parent::__construct($config);
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
     * but add two entries into $_gqla_types for both Foo
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
    public function getOrCreateObjectType(string $n): GObjectType
    {
        if (!array_key_exists($n, $this->types)) {
            $this->types[$n] = new GObjectType([
                'name' => $n,
                'fields' => [],
            ]);
        }
        if (!is_a($this->types[$n], GObjectType::class)) {
            throw new \Exception("Type $n exists, but is not an ObjectType");
        }
        return $this->types[$n];
    }
    public function getOrCreateInterfaceType(string $n): GInterfaceType
    {
        if (!array_key_exists($n, $this->types)) {
            $this->types[$n] = new GInterfaceType([
                'name' => $n,
                'fields' => [],
            ]);
        }
        if (!is_a($this->types[$n], GInterfaceType::class)) {
            throw new \Exception("Type $n exists, but is not an InterfaceType");
        }
        return $this->types[$n];
    }
    public function getOrCreateEnumType(string $n): GEnumType
    {
        if (!array_key_exists($n, $this->types) || !is_a($this->types[$n], GEnumType::class)) {
            $this->types[$n] = new GEnumType([
                'name' => $n,
                'values' => [],
            ]);
        }
        return $this->types[$n];
    }
    public function getOrCreateInputObjectType(string $n): GInputObjectType
    {
        if (!array_key_exists($n, $this->types) || !is_a($this->types[$n], GInputObjectType::class)) {
            $this->types[$n] = new GInputObjectType([
                'name' => $n,
                'fields' => [],
            ]);
        }
        return $this->types[$n];
    }

    /**
     * Get args in graphql format by inspecting a function.
     * Also accepts some manual overrides, eg if a function
     * takes an array as input, then we need to provide an
     * override to tell graphql what kind of array it is.
     *
     *   #[GraphQLField(args: ["tags" => "[string]"])]
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

    public function noNamespace(string $name): string
    {
        $parts = explode("\\", $name);
        return $parts[count($parts) - 1];
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

                $parentType = $this->getOrCreateObjectType($extends);
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
                            if(is_a($meth, \ReflectionMethod::class)) {
                                return $meth->invokeArgs(null, $args);
                            } elseif(is_a($meth, \ReflectionFunction::class)) {
                                return $meth->invokeArgs($args);
                            }
                        }
                        // If we're attaching a dynamic field to this object, then
                        // we invoke it with $rootValue as $this.
                        elseif ($extends == $objName) {
                            if(is_a($meth, \ReflectionMethod::class)) {
                                return $meth->invokeArgs($rootValue, $args);
                            } elseif(is_a($meth, \ReflectionFunction::class)) {
                                return $meth->invokeArgs($args);
                            }
                        }
                        // If we're attaching a dynamic field to another object,
                        // then we invoke it as a static method with $rootValue
                        // as the first parameter (except for queries and mutations,
                        // where the function doesn't take the object at all).
                        else {
                            if(is_a($meth, \ReflectionMethod::class)) {
                                return $meth->invokeArgs(null, [$rootValue, ...$args]);
                            } elseif(is_a($meth, \ReflectionFunction::class)) {
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
    public function inspectClass(\ReflectionClass $reflection): void
    {
        $objName = null;

        // Check if the given class is an Object
        foreach ($reflection->getAttributes() as $objAttr) {
            if (in_array($objAttr->getName(), [Type::class, InterfaceType::class, Enum::class, InputObjectType::class])) {
                $objName = $objAttr->getArguments()['name'] ?? $this->noNamespace($reflection->getName());
                switch($objAttr->getName()) {
                    case Type::class:
                        log("Found object {$objName}");
                        $t = $this->getOrCreateObjectType($objName);
                        break;
                    case InterfaceType::class:
                        log("Found interface {$objName}");
                        $t = $this->getOrCreateInterfaceType($objName);
                        break;
                    case Enum::class:
                        log("Found enum {$objName}");
                        $t = $this->getOrCreateEnumType($objName);
                        $vals = [];
                        foreach ($reflection->getConstants() as $k => $v) {
                            $vals[$k] = [
                                'value' => $v,
                                // 'description' =>
                            ];
                        }
                        $t->config['values'] = $vals;
                        break;
                    case InputObjectType::class:
                        log("Found input object {$objName}");
                        $t = $this->getOrCreateInputObjectType($objName);
                        $ctor = $reflection->getConstructor();
                        if(is_null($ctor)) {
                            throw new \Exception("InputObjectTypes must have a constructor");
                        }
                        $params = $ctor->getParameters();
                        $fields = [];
                        foreach($params as $p) {
                            $field = [
                                'name' => $p->getName(),
                                'type' => $this->maybeGetInputType($this->phpTypeToGraphQL($p->getType())),
                            ];
                            if($p->isDefaultValueAvailable()) {
                                $field['defaultValue'] = $p->getDefaultValue();
                            }
                            $fields[] = $field;
                        }
                        $t->config['fields'] = $fields;
                        $t->config['parseValue'] = fn (array $values) => $reflection->newInstanceArgs($values);
                        break;
                    default:
                        throw new \Exception("Invalid object type: " . $objAttr->getName());
                }
                $this->cls2type[$reflection->getName()] = $objName;
                $t->config['interfaces'] = array_map(
                    fn ($x) => $this->getOrCreateInterfaceType($x),
                    $objAttr->getArguments()['interfaces'] ?? []
                );
            }
        }

        foreach ($reflection->getProperties() as $prop) {
            foreach ($prop->getAttributes() as $propAttr) {
                if ($propAttr->getName() == Field::class) {
                    $propName = $propAttr->getArguments()['name'] ?? $prop->getName();
                    $propType = $propAttr->getArguments()['type'] ?? $this->phpTypeToGraphQL($prop->getType());
                    $extends = $propAttr->getArguments()['extends'] ?? $objName;
                    if (is_null($extends)) {
                        throw new \Exception("Field must be attached to a Type, either implicitly or with 'extends'");
                    }
                    $parentType = $this->types[$extends] ?? $this->getOrCreateObjectType($extends);
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
}
