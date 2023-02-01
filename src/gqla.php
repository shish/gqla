<?php

namespace GQLA;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Schema;

#[\Attribute]
class Expose
{
}

function log($s): void
{
    // echo "$s\n";
}

/**
 * Go from a graphql type name (eg `"String!"`) to a type
 * object (eg `NonNull(Type::string())`). If we don't currently
 * know of a type object by the given name, then we return
 * a function which does the lookup later.
 */
function maybeGetType(array &$types, string $n)
{
    if (str_ends_with($n, "!")) {
        return new NonNull(maybeGetType($types, substr($n, 0, strlen($n)-1)));
    }
    if ($n[0] == "[") {
        return Type::listOf(maybeGetType($types, substr($n, 1, strlen($n)-2)));
    }
    if ($n == "array") {
        throw new \Exception("Can't use 'array' as a type - you need to use an annotation, eg GraphQLField(type: '[string]')");
    }

    if (array_key_exists($n, $types)) {
        return $types[$n];
    } else {
        return function () use (&$types, $n) {
            if (array_key_exists($n, $types)) {
                return $types[$n];
            }
            $keys = join(", ", array_keys($types));
            throw new \Exception("Failed to find deferred type for $n. Known types: $keys");
        };
    }
}

/*
 * When we come across
 *
 *   #[Expose(name: Foo)]
 *   class Bar {
 *     ...
 *   }
 *
 * then we want to create one new ObjectType named Foo,
 * but add two entries into $_gqla_types for both Foo
 * and Bar, so that later on when somebody does
 *
 *   #[Expose]
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
 *   #[Expose(extends: "Foo")]
 *   function blah(Foo $self): int {
 *     return $self->thing;
 *   }
 *
 * then we also want to create and register a Foo object
 * with a blah() field
 */
function getOrCreateObjectType(array &$types, string $n, ?string $cls=null)
{
    if (!array_key_exists($n, $types)) {
        $types[$n] = new ObjectType([
            'name' => $n,
            'fields' => [],
        ]);
    }
    if ($cls && !array_key_exists($cls, $types)) {
        $types[$cls] = $types[$n];
    }
    return $types[$n];
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
 *     "id" => Type::int(),
 *     "tags" => Type::listOf(Type::string())
 *   ]
 */
function getArgs(array &$types, array $argTypes, \ReflectionMethod|\ReflectionFunction $method, bool $ignoreFirst)
{
    $args = [];
    $n = 0;
    foreach ($method->getParameters() as $p) {
        if ($ignoreFirst && $n == 0) {
            continue;
        }
        $n++;
        $name = $p->getName();
        $type = maybeGetType($types, $argTypes[$name] ?? phpTypeToGraphQL($p->getType()));
        $args[$name] = $type;
    }
    // var_dump($args);
    return $args;
}

function phpTypeToGraphQL(\ReflectionNamedType $type): string
{
    return $type->getName() . ($type->allowsNull() ? "" : "!");
}

/**
 * Look at a function or a method, if it is a query or
 * a mutation, add it to the relevant list
 */
function inspectFunction(array &$types, \ReflectionMethod|\ReflectionFunction $meth, ?string $objName=null): void
{
    foreach ($meth->getAttributes() as $methAttr) {
        if ($methAttr->getName() == Expose::class) {
            $methName = $methAttr->getArguments()['name'] ?? $meth->name;
            $methType = $methAttr->getArguments()['type'] ?? phpTypeToGraphQL($meth->getReturnType());
            $extends = $methAttr->getArguments()['extends'] ?? $objName;
            if (!$extends) {
                throw new \Exception(
                    "Can't expose method $methName - it isn't a method of a known object, ".
                    "and it isn't specifying extends: \"Blah\""
                );
            }

            $extendingOtherObject = ($extends != $objName && $extends != "Query" && $extends != "Mutation");
            getOrCreateObjectType($types, $extends)->config['fields'][$methName] = [
                'type' => maybeGetType($types, $methType),
                'description' => $methAttr->getArguments()['description'] ?? null,
                'deprecationReason' => $methAttr->getArguments()['deprecationReason'] ?? null,
                'args' => getArgs($types, $methAttr->getArguments()['args'] ?? [], $meth, $extendingOtherObject),
                'resolve' => static function ($rootValue, array $args) use ($meth, $extends, $objName) {
                    // If we're adding a new query or mutation, we ignore
                    // $rootValue and the function has no $this
                    if ($extends == "Query" || $extends == "Mutation") {
                        return $meth->invokeArgs(null, $args);
                    }
                    // If we're attaching a dynamic field to this object, then
                    // we invoke it with $rootValue as $this.
                    elseif ($extends == $objName) {
                        return $meth->invokeArgs($rootValue, $args);
                    }
                    // If we're attaching a dynamic field to another object,
                    // then we invoke it as a static method with $rootValue
                    // as the first parameter (except for queries and mutations,
                    // where the function doesn't take the object at all).
                    else {
                        return $meth->invokeArgs(null, [$rootValue, ...$args]);
                    }
                },
            ];

            log("- Found dynamic field $extends.$methName ($methType)");
        }
    }
}

function inspectClass(array &$types, \ReflectionClass $reflection): void
{
    $objName = null;

    // Check if the given class is an Object
    foreach ($reflection->getAttributes() as $objAttr) {
        if ($objAttr->getName() == Expose::class) {
            $objName = $objAttr->getArguments()['name'] ?? $reflection->getName();
            log("Found object {$objName}");
            $t = getOrCreateObjectType($types, $objName);
            // TODO: set attributes of $t other than fields
        }
    }

    foreach ($reflection->getProperties() as $prop) {
        foreach ($prop->getAttributes() as $propAttr) {
            if ($propAttr->getName() == Expose::class) {
                $propName = $propAttr->getArguments()['name'] ?? $prop->getName();
                $propType = $propAttr->getArguments()['type'] ?? phpTypeToGraphQL($prop->getType());
                $extends = $propAttr->getArguments()['extends'] ?? $objName;
                getOrCreateObjectType($types, $extends)->config['fields'][$propName] = [
                    'type' => maybeGetType($types, $propType),
                ];
                log("- Found field $extends.$propName ($propType)");
            }
        }
    }

    foreach ($reflection->getMethods() as $meth) {
        inspectFunction($types, $meth, $objName);
    }
}

function genSchemaFromThings(?array &$types, array $classes, array $functions): Schema
{
    if (!$types) {
        $types = [
            "string" => Type::string(),
            "String" => Type::string(),
            "int" => Type::int(),
            "Int" => Type::int(),
            "float" => Type::float(),
            "Float" => Type::float(),
            "bool" => Type::boolean(),
            "Boolean" => Type::boolean(),
        ];
    }

    foreach ($classes as $cls) {
        inspectClass($types, new \ReflectionClass($cls));
    }
    foreach ($functions as $func) {
        inspectFunction($types, new \ReflectionFunction($func));
    }

    return new Schema(
        [
            'query' => getOrCreateObjectType($types, "Query"),
            'mutation' => getOrCreateObjectType($types, "Mutation"),
        ]
    );
}

function genSchema(): Schema
{
    $types = [];
    return genSchemaFromThings(
        $types,
        get_declared_classes(),
        get_defined_functions()["user"]
    );
}
