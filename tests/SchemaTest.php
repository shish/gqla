<?php

declare(strict_types=1);

require_once "classes.php";

class SchemaTest extends \PHPUnit\Framework\TestCase
{
    public function testSchemaGen()
    {
        $schema = \GQLA\genSchema();
        $this->assertEquals(
            "type Query {
  post(id: Int!): Post!
  posts: [Post!]!
}

type Post {
  id: Int!
  title: String!
  published: Boolean!
  body: String!
  tags: [String!]!
  author: User!
  comments: [Comment!]!
}

type User {
  id: Int!
  name: String!
}

type Comment {
  id: Int!
  text: String!
  author: User
}

type Mutation {
  create_post(title: String!, body: String!): Post!
}
",
            \GraphQL\Utils\SchemaPrinter::doPrint($schema)
        );
    }
}
