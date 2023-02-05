<?php

declare(strict_types=1);

require_once "classes.php";

class SchemaTest extends \PHPUnit\Framework\TestCase
{
    public function testSchemaGen(): void
    {
        $schema = new \GQLA\Schema();
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
  author_name: String @deprecated(reason: \"Use author subfield\")
}

type Mutation {
  create_post(title: String!, body: String!): Post!
  login(username: String!, password: String!): User!
  logout: Boolean!
}
",
            \GraphQL\Utils\SchemaPrinter::doPrint($schema)
        );
    }
}
