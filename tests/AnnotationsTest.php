<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class AnnotationsTest extends TestCase
{
    public function testEnum()
    {
        $e = new \GQLA\Enum("enumName", "enumDescription", "enumDeprecationReason");
        $this->assertEquals("enumName", $e->name);
    }

    public function testField()
    {
        $f = new \GQLA\Field("fieldName");
        $this->assertEquals("fieldName", $f->name);
    }

    public function testInputObjectType()
    {
        $i = new \GQLA\InputObjectType("inputObjectTypeName");
        $this->assertEquals("inputObjectTypeName", $i->name);
    }

    public function testInterfaceType()
    {
        $i = new \GQLA\InterfaceType("interfaceTypeName");
        $this->assertEquals("interfaceTypeName", $i->name);
    }

    public function testMutation()
    {
        $m = new \GQLA\Mutation("mutationName");
        $this->assertEquals("mutationName", $m->name);
    }

    public function testQuery()
    {
        $q = new \GQLA\Query("queryName");
        $this->assertEquals("queryName", $q->name);
    }

    public function testType()
    {
        $t = new \GQLA\Type("typeName");
        $this->assertEquals("typeName", $t->name);
    }
}
