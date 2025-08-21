<?php

declare(strict_types=1);

require_once "classes.php";

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\NonNull;

class UtilsTest extends \PHPUnit\Framework\TestCase
{
    private static function reflectMethod(string $name): \ReflectionMethod
    {
        if (PHP_VERSION_ID >= 80300) {
            return \ReflectionMethod::createFromMethodName($name);
        } else {
            return new \ReflectionMethod($name);
        }
    }

    public function testMaybeGetType(): void
    {
        $schema = new \GQLA\Schema([], []);
        $id = Type::id();

        $this->assertEquals(
            $schema->maybeGetType("ID"),
            $id,
        );
        $this->assertEquals(
            $schema->maybeGetType("ID!"),
            new NonNull($id),
        );
        $this->assertEquals(
            $schema->maybeGetType("[ID]"),
            Type::listOf($id),
        );
        $this->assertEquals(
            $schema->maybeGetType("[ID!]!"),
            new NonNull(Type::listOf(new NonNull($id))),
        );
    }

    public function example(?int $foo = null, ?string $bar = null): string
    {
        return "Example!";
    }

    public function testGetArgs(): void
    {
        $schema = new \GQLA\Schema([], []);

        // test getting types from the method
        $this->assertEquals(
            [
                "foo" => Type::int(),
                "bar" => Type::string(),
            ],
            $schema->getArgs([], self::reflectMethod("UtilsTest::example"), false),
        );

        // test getting types from the override
        $this->assertEquals(
            [
                "foo" => Type::int(),
                "bar" => Type::boolean(),
            ],
            $schema->getArgs(["bar" => "bool"], self::reflectMethod("UtilsTest::example"), false),
        );
    }

    public function testPhpTypeToGraphQL(): void
    {
        $schema = new \GQLA\Schema([], []);
        $this->assertEquals(
            "string!",
            $schema->phpTypeToGraphQL((self::reflectMethod("UtilsTest::example"))->getReturnType()),
        );
    }

    public function testNoNamespace(): void
    {
        $schema = new \GQLA\Schema([], []);
        $this->assertEquals(
            "cheese",
            $schema->noNamespace("cheese")
        );
        $this->assertEquals(
            "login",
            $schema->noNamespace("\Demo\login")
        );
        $this->assertEquals(
            "MyPostClass",
            $schema->noNamespace(\Demo\MyPostClass::class)
        );
    }

    public function testInspectFunction_noop(): void
    {
        // Inspecting a non-annotated function should do nothing
        $schema = new \GQLA\Schema([], []);
        $baseTypesCount = count($schema->types);
        $schema->inspectFunction(self::reflectMethod("UtilsTest::example"));
        $this->assertEquals($baseTypesCount, count($schema->types));
    }

    public function testInspectFunction_bare_mutation(): void
    {
        // Inspecting a function annotated with #[Mutation]
        // should create a new field on the Mutation type
        $schema = new \GQLA\Schema([], []);
        $schema->inspectFunction(new \ReflectionFunction("\Demo\logout"));
        $obj = $schema->types["Mutation"];
        $this->assertInstanceOf(ObjectType::class, $obj);
        $fields = $obj->config["fields"];
        assert(is_array($fields));
        $this->assertArrayHasKey("Mutation", $schema->types);
        $this->assertArrayHasKey("logout", $fields);
        assert(is_array($fields["logout"]));
        $this->assertEquals(new NonNull(Type::boolean()), $fields["logout"]["type"]);
    }

    public function testInspectFunction_class_method(): void
    {
        // Inspecting a method of a class should add a new field to that class
        $schema = new \GQLA\Schema(["\Demo\MyPostClass"], []);
        $schema->inspectFunction(self::reflectMethod("\Demo\MyPostClass::author"), "Post");
        $obj = $schema->types["Post"];
        $this->assertInstanceOf(ObjectType::class, $obj);
        $fields = $obj->config["fields"];
        assert(is_array($fields));
        $this->assertArrayHasKey("Post", $schema->types);
        $this->assertArrayHasKey("author", $fields);
    }

    public function testInspectFunction_class_method_params(): void
    {
        // A field added to a type can have parameters
        $schema = new \GQLA\Schema(["\Demo\User"], []);
        $schema->inspectFunction(self::reflectMethod("\Demo\Comment::add_comment_id"), "Comment");
        $obj = $schema->types["User"];
        $this->assertInstanceOf(ObjectType::class, $obj);
        $fields = $obj->config["fields"];
        assert(is_array($fields));
        $this->assertArrayHasKey("User", $schema->types);
        $this->assertArrayHasKey("add_comment_id", $fields);
        assert(is_array($fields["add_comment_id"]));
        $this->assertEquals($fields["add_comment_id"]["args"], [
            "n" => Type::nonNull(Type::int())
        ]);
    }


    public function testInspectFunction_class_query(): void
    {
        // Inspecting a method of a class annotated with
        // #[Query(name: "posts")]
        // should create a new "posts" field on the Query type
        $schema = new \GQLA\Schema([], []);
        $schema->inspectFunction(self::reflectMethod("\Demo\MyPostClass::search_posts"), "Post");
        $obj = $schema->types["Query"];
        $this->assertInstanceOf(ObjectType::class, $obj);
        $fields = $obj->config["fields"];
        assert(is_array($fields));
        $this->assertArrayHasKey("Query", $schema->types);
        $this->assertArrayHasKey("posts", $fields);
    }

    public function testInspectClass_noop(): void
    {
        // Inspecting a non-annotated class should do nothing
        $schema = new \GQLA\Schema([], []);
        $baseTypesCount = count($schema->types);
        $schema->inspectClassAnnotations(new \ReflectionClass(UtilsTest::class));
        $this->assertEquals($baseTypesCount, count($schema->types));
    }

    public function testInspectClass_class(): void
    {
        // Inspecting a class annotated with #[Type]
        // should create a new type
        $schema = new \GQLA\Schema([], []);
        $this->assertArrayNotHasKey("User", $schema->types);
        $schema->inspectClassAnnotations(new \ReflectionClass(\Demo\User::class));
        $this->assertArrayHasKey("User", $schema->types);
    }
}
