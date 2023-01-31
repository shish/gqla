<?php

namespace GQLA;

use Attribute;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Schema;
use ReflectionFunction;
use ReflectionMethod;

#[Attribute(Attribute::TARGET_CLASS)]
class GraphQLObject
{
}

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
class GraphQLField
{
}

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
class GraphQLQuery
{
}

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
class GraphQLMutation
{
}

function log($s): void
{
    // echo "$s\n";
}

global $_gqla_types;
$_gqla_types = [
    "string" => Type::string(),
    "String" => Type::string(),
    "int" => Type::int(),
    "Int" => Type::int(),
    "float" => Type::float(),
    "Float" => Type::float(),
    "bool" => Type::boolean(),
    "Boolean" => Type::boolean(),
];

/**
 * Go from a graphql type name (eg `"String!"`) to a type
 * object (eg `NonNull(Type::string())`). If we don't currently
 * know of a type object by the given name, then we return
 * a function which does the lookup later.
 */
function maybeGetType($n)
{
    global $_gqla_types;
    if (str_ends_with($n, "!")) {
        return new NonNull(maybeGetType(substr($n, 0, strlen($n)-1)));
    }
    if ($n[0] == "[") {
        return Type::listOf(maybeGetType(substr($n, 1, strlen($n)-2)));
    }
    if ($n == "array") {
        throw new \Exception("Can't use 'array' as a type - you need to use an annotation, eg GraphQLField(type: '[string]')");
    }

    if (array_key_exists($n, $_gqla_types)) {
        return $_gqla_types[$n];
    } else {
        return function () use ($n) {
            global $_gqla_types;
            if (array_key_exists($n, $_gqla_types)) {
                return $_gqla_types[$n];
            }
            $keys = join(", ", array_keys($_gqla_types));
            throw new \Exception("Failed to find deferred type for $n. Known types: $keys");
        };
    }
}

/*
 * When we come across
 *
 *   #[GraphQLObject(name: Foo)]
 *   class Bar {
 *     ...
 *   }
 *
 * then we want to create one new ObjectType named Foo,
 * but add two entries into $_gqla_types for both Foo
 * and Bar, so that later on when somebody does
 *
 *   #[GraphQLQuery]
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
 *   #[GraphQLField(extends: "Foo")]
 *   function blah(Foo $self): int {
 *     return $self->thing;
 *   }
 *
 * then we also want to create and register a Foo object
 * with a blah() field
 */
function getOrCreateObjectType($n, $cls=null)
{
    global $_gqla_types;
    if (!array_key_exists($n, $_gqla_types)) {
        $_gqla_types[$n] = new ObjectType([
            'name' => $n,
            'fields' => [],
        ]);
    }
    if ($cls && !array_key_exists($cls, $_gqla_types)) {
        $_gqla_types[$cls] = $_gqla_types[$n];
    }
    return $_gqla_types[$n];
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
function getArgs(array $argTypes, \ReflectionMethod $method, bool $ignoreFirst)
{
    $args = [];
    $n = 0;
    foreach ($method->getParameters() as $p) {
        if ($ignoreFirst && $n == 0) {
            continue;
        }
        $n++;
        $name = $p->getName();
        $type = maybeGetType($argTypes[$name] ?? phpTypeToGraphQL($p->getType()));
        $args[$name] = $type;
    }
    // var_dump($args);
    return $args;
}

/**
 * Look at a function or a method, if it is a query or
 * a mutation, add it to the relevant list
 */
function inspectFunction(ReflectionMethod|ReflectionFunction $meth): array
{
    $queries = [];
    $mutations = [];

    foreach ($meth->getAttributes() as $methAttr) {
        if ($methAttr->getName() == GraphQLQuery::class || $methAttr->getName() == GraphQLMutation::class) {
            $methName = $methAttr->getArguments()['name'] ?? $meth->name;
            $methType = $methAttr->getArguments()['type'] ?? phpTypeToGraphQL($meth->getReturnType());
            $f = [
                'type' => maybeGetType($methType),
                'description' => $methAttr->getArguments()['description'] ?? null,
                'args' => getArgs($methAttr->getArguments()['args'] ?? [], $meth, false),
                'resolve' => static fn ($rootValue, array $args) => $meth->invokeArgs(null, $args),
            ];
            $args = [];
            foreach ($f['args'] as $argname => $argtype) {
                $args[] = $argname;
            }
            $args = \json_encode($args);
            if ($methAttr->getName() == GraphQLQuery::class) {
                $queries[$methName] = $f;
                log("- Found query $methName ($args -> $methType)");
            } else {
                $mutations[$methName] = $f;
                log("- Found mutation $methName ($args -> $methType)");
            }
        }
    }

    return [$queries, $mutations];
}

function phpTypeToGraphQL(\ReflectionNamedType $type): string
{
    return $type->getName() . ($type->allowsNull() ? "" : "!");
}

function inspectClass(\ReflectionClass $reflection): array
{
    $queries = [];
    $mutations = [];

    // Check if the given class is an object
    foreach ($reflection->getAttributes() as $objAttr) {
        if ($objAttr->getName() == GraphQLObject::class) {
            $objName = $objAttr->getArguments()['name'] ?? $reflection->getName();
            log("Found object {$objName}");
            $t = getOrCreateObjectType($objName);
            // TODO: set attributes of $t other than fields

            foreach ($reflection->getProperties() as $prop) {
                foreach ($prop->getAttributes() as $propAttr) {
                    if ($propAttr->getName() == GraphQLField::class) {
                        $propName = $propAttr->getArguments()['name'] ?? $prop->getName();
                        $propType = $propAttr->getArguments()['type'] ?? phpTypeToGraphQL($prop->getType());
                        $extends = $propAttr->getArguments()['extends'] ?? $objName;
                        getOrCreateObjectType($extends)->config['fields'][$propName] = [
                            'type' => maybeGetType($propType),
                        ];
                        log("- Found field $extends.$propName ($propType)");
                    }
                }
            }

            foreach ($reflection->getMethods() as $meth) {
                foreach ($meth->getAttributes() as $methAttr) {
                    if ($methAttr->getName() == GraphQLField::class) {
                        $methName = $methAttr->getArguments()['name'] ?? $meth->getName();
                        $methType = $methAttr->getArguments()['type'] ?? phpTypeToGraphQL($meth->getReturnType());
                        $extends = $methAttr->getArguments()['extends'] ?? $objName;
                        // If we're attaching a dynamic field to this object, then
                        // we invoke it with $rootValue as $this
                        if ($extends == $objName) {
                            getOrCreateObjectType($extends)->config['fields'][$methName] = [
                                'type' => maybeGetType($methType),
                                'description' => $methAttr->getArguments()['description'] ?? null,
                                'args' => getArgs($methAttr->getArguments()['args'] ?? [], $meth, false),
                                'resolve' => static fn ($rootValue, array $args) => $meth->invokeArgs($rootValue, $args),
                            ];
                        }
                        // If we're attaching a dynamic field to another object,
                        // then we invoke it as a static method with $rootValue
                        // as the first parameter
                        else {
                            getOrCreateObjectType($extends)->config['fields'][$methName] = [
                                'type' => maybeGetType($methType),
                                'description' => $methAttr->getArguments()['description'] ?? null,
                                'args' => getArgs($methAttr->getArguments()['args'] ?? [], $meth, true),
                                'resolve' => static fn ($rootValue, array $args) => $meth->invokeArgs(null, [$rootValue, ...$args]),
                            ];
                        }
                        log("- Found dynamic field $extends.$methName ($methType)");
                    }
                }
            }
        }
    }

    // Search the given class for queries and mutations
    foreach ($reflection->getMethods() as $meth) {
        [$mq, $mm] = inspectFunction($meth);
        $queries = array_merge($queries, $mq);
        $mutations = array_merge($mutations, $mm);
    }

    return [$queries, $mutations];
}

function genSchema()
{
    $all_queries = [];
    $all_mutations = [];
    foreach (get_declared_classes() as $cls) {
        [$queries, $mutations] = inspectClass(new \ReflectionClass($cls));
        $all_queries = array_merge($all_queries, $queries);
        $all_mutations = array_merge($all_mutations, $mutations);
    }
    /*
    foreach(get_declared_functions() as $func) {
        [$queries, $mutations] = inspectFunction(new \ReflectionFunction($func));
        $all_queries = array_merge($all_queries, $queries);
        $all_mutations = array_merge($all_mutations, $mutations);
    }
    */

    return new Schema(
        [
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => $all_queries,
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => $all_mutations,
            ]),
        ]
    );
}
