<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class AnnotationsTest extends TestCase
{
    public function testEnum(): void
    {
        $e = new \GQLA\Enum("enumName", "enumDescription", "enumDeprecationReason");
        $this->assertEquals("enumName", $e->name);
    }

    public function testField(): void
    {
        $f = new \GQLA\Field("fieldName");
        $this->assertEquals("fieldName", $f->name);
    }

    public function testInputObjectType(): void
    {
        $i = new \GQLA\InputObjectType("inputObjectTypeName");
        $this->assertEquals("inputObjectTypeName", $i->name);
    }

    public function testInterfaceType(): void
    {
        $i = new \GQLA\InterfaceType("interfaceTypeName");
        $this->assertEquals("interfaceTypeName", $i->name);
    }

    public function testMutation(): void
    {
        $m = new \GQLA\Mutation("mutationName");
        $this->assertEquals("mutationName", $m->name);
    }

    public function testQuery(): void
    {
        $q = new \GQLA\Query("queryName");
        $this->assertEquals("queryName", $q->name);
    }

    public function testType(): void
    {
        $t = new \GQLA\Type("typeName");
        $this->assertEquals("typeName", $t->name);
    }
}
