<?php

declare(strict_types=1);

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\NonNull;

class UtilsTest extends \PHPUnit\Framework\TestCase
{
    public function testMaybeGetType()
    {
        global $_gqla_types;
        $_gqla_types["sentinel"] = "test";

        $this->assertEquals(
            \GQLA\maybeGetType("sentinel"),
            "test",
        );
        $this->assertEquals(
            \GQLA\maybeGetType("sentinel!"),
            new NonNull("test"),
        );
        $this->assertEquals(
            \GQLA\maybeGetType("[sentinel]"),
            Type::listOf("test"),
        );
        $this->assertEquals(
            \GQLA\maybeGetType("[sentinel!]!"),
            new NonNull(Type::listOf(new NonNull("test"))),
        );
    }

    public function testGetOrCreateObjectType() {
        $this->assertEquals(
            new ObjectType([
                "name" => "TestNewType",
                "fields" => [],
            ]),
            \GQLA\getOrCreateObjectType("TestNewType", 'MyNameSpace\TestNewTypeClass')
        );
    }

    public function example(?int $foo=null, ?string $bar=null): string {
        return "Example!";
    }

    public function testGetArgs() {
        $this->assertEquals(
            \GQLA\getArgs([], new \ReflectionMethod("UtilsTest::example"), false),
            [
                "foo" => Type::int(),
                "bar" => Type::string(),
            ]
        );
    }

    public function testInspectFunction() {
        $this->assertEquals(
            [[], []],
            \GQLA\inspectFunction(new \ReflectionMethod("UtilsTest::example")),
        );
    }

    public function testPhpTypeToGraphQL() {
        $this->assertEquals(
            "string!",
            \GQLA\phpTypeToGraphQL((new \ReflectionMethod("UtilsTest::example"))->getReturnType()),
        );
    }

    public function inspectClass() {
        $this->assertEquals(
            [[], []],
            \GQLA\inspectClass(new \ReflectionClass("UtilsTest")),
        );
    }
}
