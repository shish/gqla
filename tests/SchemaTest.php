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
  node(id: ID!): Node!
  post(id: Int!): Post!
  posts: [Post!]!
}

interface Node {
  id: String!
}

type Post implements Node {
  post_id: Int!
  title: String!
  state: State!
  body: String!
  tags: [String!]!
  id: ID!
  author: User!
  comments: [Comment!]!
}

enum State {
  Draft
  Review
  Published
}

type User implements Node {
  user_id: Int!
  name: String!
  id: ID!
  add_id(n: Int!): Int!
  add_comment_id(n: Int!): Int!
}

type Comment {
  comment_id: Int!
  text: String!
  author: User
  author_name: String @deprecated(reason: \"Use author subfield\")
}

type Mutation {
  create_post(title: String!, body: String!): Post!
  create_user(input: CreateUserInputs!): User!
  login(username: String!, password: String!): User!
  logout: Boolean!
}

input CreateUserInputs {
  username: String!
  password: String!
  email: String! = \"no@example.com\"
}
",
            \GraphQL\Utils\SchemaPrinter::doPrint($schema)
        );
    }
}
