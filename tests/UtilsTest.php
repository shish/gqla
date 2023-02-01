<?php

declare(strict_types=1);

require_once "classes.php";

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\NonNull;

class UtilsTest extends \PHPUnit\Framework\TestCase
{
    public function testMaybeGetType()
    {
        $types = ["sentinel" => "test"];

        $this->assertEquals(
            \GQLA\maybeGetType($types, "sentinel"),
            "test",
        );
        $this->assertEquals(
            \GQLA\maybeGetType($types, "sentinel!"),
            new NonNull("test"),
        );
        $this->assertEquals(
            \GQLA\maybeGetType($types, "[sentinel]"),
            Type::listOf("test"),
        );
        $this->assertEquals(
            \GQLA\maybeGetType($types, "[sentinel!]!"),
            new NonNull(Type::listOf(new NonNull("test"))),
        );
    }

    public function testGetOrCreateObjectType()
    {
        $types = [];
        $expectedType = new ObjectType([
            "name" => "TestNewType",
            "fields" => [],
        ]);

        $newType = \GQLA\getOrCreateObjectType($types, "TestNewType", 'MyNameSpace\TestNewTypeClass');
        $this->assertEquals($expectedType, $newType);
        $this->assertEquals($expectedType, $types["TestNewType"]);
        $this->assertEquals($expectedType, $types["MyNameSpace\TestNewTypeClass"]);

        $existingType = \GQLA\getOrCreateObjectType($types, "TestNewType");
        $this->assertEquals($expectedType, $existingType);

        $existingType = \GQLA\getOrCreateObjectType($types, "MyNameSpace\TestNewTypeClass");
        $this->assertEquals($expectedType, $existingType);
    }

    public function example(?int $foo=null, ?string $bar=null): string
    {
        return "Example!";
    }

    public function testGetArgs()
    {
        $types = [
            "int" => Type::int(),
            "string" => Type::string(),
        ];
        $this->assertEquals(
            [
                "foo" => Type::int(),
                "bar" => Type::string(),
            ],
            \GQLA\getArgs($types, [], new \ReflectionMethod("UtilsTest::example"), false),
        );
    }

    public function testPhpTypeToGraphQL()
    {
        $this->assertEquals(
            "string!",
            \GQLA\phpTypeToGraphQL((new \ReflectionMethod("UtilsTest::example"))->getReturnType()),
        );
    }

    public function testInspectFunction()
    {
        // Inspecting a non-annotated function should do nothing
        $types = [];
        \GQLA\inspectFunction($types, new \ReflectionMethod("UtilsTest::example"));
        $this->assertEquals([], $types);

        // Inspecting a function annotated with #[Expose(extends: "Mutation")]
        // should create a new field on the Mutation type
        $types = ["User" => Type::string()];
        \GQLA\inspectFunction($types, new \ReflectionFunction("login"));
        $this->assertArrayHasKey("Mutation", $types);
        $this->assertArrayHasKey("login", $types["Mutation"]->config["fields"]);
        $this->assertEquals(new NonNull(Type::string()), $types["Mutation"]->config["fields"]["login"]["type"]);

        // Inspecting a method of a class should add a new field to that class
        $types = [];
        \GQLA\inspectFunction($types, new \ReflectionMethod("MyPostClass::author"), "Post");
        $this->assertArrayHasKey("Post", $types);
        $this->assertArrayHasKey("author", $types["Post"]->config["fields"]);

        // Inspecting a method of a class annotated with
        // #[Expose(extends: "Query", name: "posts")]
        // should create a new "posts" field on the Query type
        $types = [];
        \GQLA\inspectFunction($types, new \ReflectionMethod("MyPostClass::search_posts"), "Post");
        $this->assertArrayHasKey("Query", $types);
        $this->assertArrayHasKey("posts", $types["Query"]->config["fields"]);
    }

    public function testInspectClass()
    {
        // Inspecting a non-annotated class should do nothing
        $types = [];
        \GQLA\inspectClass($types, new \ReflectionClass("UtilsTest"));
        $this->assertEquals([], $types);

        // Inspecting a class annotated with #[Expose]
        // should create a new type
        $types = [];
        \GQLA\inspectClass($types, new \ReflectionClass("User"));
        $this->assertArrayHasKey("User", $types);
    }
}
