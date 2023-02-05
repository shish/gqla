<?php

declare(strict_types=1);

require_once "src/schema.php";
require_once "classes.php";

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\NonNull;

class UtilsTest extends \PHPUnit\Framework\TestCase
{
    public function testMaybeGetType(): void
    {
        $sentinel = Type::id();
        $types = ["sentinel" => $sentinel];
        $schema = new \GQLA\Schema($types, [], []);

        $this->assertEquals(
            $schema->maybeGetType("sentinel"),
            $sentinel,
        );
        $this->assertEquals(
            $schema->maybeGetType("sentinel!"),
            new NonNull($sentinel),
        );
        $this->assertEquals(
            $schema->maybeGetType("[sentinel]"),
            Type::listOf($sentinel),
        );
        $this->assertEquals(
            $schema->maybeGetType("[sentinel!]!"),
            new NonNull(Type::listOf(new NonNull($sentinel))),
        );
    }

    public function testGetOrCreateObjectType(): void
    {
        $expectedType = new ObjectType([
            "name" => "TestNewType",
            "fields" => [],
        ]);
        $schema = new \GQLA\Schema([], [], []);

        $newType = $schema->getOrCreateObjectType("TestNewType");
        $this->assertEquals($expectedType, $newType);
        $this->assertEquals($expectedType, $schema->types["TestNewType"]);

        $existingType = $schema->getOrCreateObjectType("TestNewType");
        $this->assertEquals($expectedType, $existingType);
    }

    public function example(?int $foo=null, ?string $bar=null): string
    {
        return "Example!";
    }

    public function testGetArgs(): void
    {
        $types = [
            "int" => Type::int(),
            "string" => Type::string(),
            "boolean" => Type::boolean(),
        ];
        $schema = new \GQLA\Schema($types, [], []);

        // test getting types from the method
        $this->assertEquals(
            [
                "foo" => Type::int(),
                "bar" => Type::string(),
            ],
            $schema->getArgs([], new \ReflectionMethod("UtilsTest::example"), false),
        );

        // test getting types from the override
        $this->assertEquals(
            [
                "foo" => Type::int(),
                "bar" => Type::boolean(),
            ],
            $schema->getArgs(["bar"=>"boolean"], new \ReflectionMethod("UtilsTest::example"), false),
        );
    }

    public function testPhpTypeToGraphQL(): void
    {
        $types = [];
        $schema = new \GQLA\Schema($types, [], []);
        $this->assertEquals(
            "string!",
            $schema->phpTypeToGraphQL((new \ReflectionMethod("UtilsTest::example"))->getReturnType()),
        );
    }

    public function testInspectFunction(): void
    {
        // Inspecting a non-annotated function should do nothing
        $schema = new \GQLA\Schema([], [], []);
        $schema->inspectFunction(new \ReflectionMethod("UtilsTest::example"));
        $this->assertEquals([], $schema->types);

        // Inspecting a function annotated with #[Mutation]
        // should create a new field on the Mutation type
        $schema = new \GQLA\Schema(["User" => Type::string()], [], []);
        $schema->inspectFunction(new \ReflectionFunction("login"));
        $fields = $schema->getOrCreateObjectType("Mutation")->config["fields"];
        assert(is_array($fields));
        $this->assertArrayHasKey("Mutation", $schema->types);
        $this->assertArrayHasKey("login", $fields);
        $this->assertEquals(new NonNull(Type::string()), $fields["login"]["type"]);

        // Inspecting a method of a class should add a new field to that class
        $schema = new \GQLA\Schema([], [], []);
        $schema->inspectFunction(new \ReflectionMethod("MyPostClass::author"), "Post");
        $fields = $schema->getOrCreateObjectType("Post")->config["fields"];
        assert(is_array($fields));
        $this->assertArrayHasKey("Post", $schema->types);
        $this->assertArrayHasKey("author", $fields);

        // Inspecting a method of a class annotated with
        // #[Query(name: "posts")]
        // should create a new "posts" field on the Query type
        $schema = new \GQLA\Schema([], [], []);
        $schema->inspectFunction(new \ReflectionMethod("MyPostClass::search_posts"), "Post");
        $fields = $schema->getOrCreateObjectType("Query")->config["fields"];
        assert(is_array($fields));
        $this->assertArrayHasKey("Query", $schema->types);
        $this->assertArrayHasKey("posts", $fields);
    }

    public function testInspectClass(): void
    {
        // Inspecting a non-annotated class should do nothing
        $schema = new \GQLA\Schema([], [], []);
        $schema->inspectClass(new \ReflectionClass("UtilsTest"));
        $this->assertEquals([], $schema->types);

        // Inspecting a class annotated with #[Type]
        // should create a new type
        $schema = new \GQLA\Schema([], [], []);
        $schema->inspectClass(new \ReflectionClass("User"));
        $this->assertArrayHasKey("User", $schema->types);
    }
}
